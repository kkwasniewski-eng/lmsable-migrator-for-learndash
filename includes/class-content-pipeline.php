<?php
/**
 * Converts LearnDash post content to plain text for the LMSable TOC import.
 *
 * @package LMSable_Migrator_For_LearnDash
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content pipeline: blocks -> shortcode removal -> plain text, with migration notes.
 */
class LMFL_Content_Pipeline {

	/**
	 * Processes a post.
	 *
	 * @param WP_Post $post Lesson or topic post.
	 * @return array{content:string,notes:string[]}
	 */
	public function process( $post ) {
		$notes = array();

		if ( ! $post instanceof WP_Post ) {
			return array(
				'content' => '',
				'notes'   => $notes,
			);
		}

		if ( 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			$notes[] = __( 'Elementor content – not exported in this version', 'lmsable-migrator-for-learndash' );
			return array(
				'content' => '',
				'notes'   => $notes,
			);
		}

		$raw  = (string) $post->post_content;
		$html = $this->render_blocks( $raw, $notes );

		$this->detect_media( $raw, $html, $notes );

		$shortcodes = array();
		$html       = $this->strip_shortcodes( $html, $shortcodes );

		if ( $this->has_scorm( $post, $raw, $shortcodes ) ) {
			$notes[] = __( 'SCORM / Tin Canny content detected – upload the package to LMSable manually', 'lmsable-migrator-for-learndash' );
		}

		foreach ( $shortcodes as $name ) {
			/* translators: %s: shortcode name. */
			$notes[] = sprintf( __( 'Shortcode [%s] removed', 'lmsable-migrator-for-learndash' ), $name );
		}

		return array(
			'content' => $this->to_plain_text( $html ),
			'notes'   => array_values( array_unique( $notes ) ),
		);
	}

	/**
	 * Runs do_blocks() safely; falls back to raw content on any error.
	 *
	 * @param string   $raw   Raw post content.
	 * @param string[] $notes Notes (by reference).
	 * @return string
	 */
	private function render_blocks( $raw, array &$notes ) {
		if ( ! function_exists( 'do_blocks' ) ) {
			return $raw;
		}

		$level = ob_get_level();
		try {
			// Swallow anything a render callback echoes, so downloads stay clean.
			ob_start();
			$html = do_blocks( $raw );
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			return is_string( $html ) ? $html : $raw;
		} catch ( Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			$notes[] = __( 'Block rendering failed – raw content was used instead', 'lmsable-migrator-for-learndash' );
			return $raw;
		}
	}

	/**
	 * Detects images, attachments and H5P in the original HTML.
	 *
	 * @param string   $raw   Raw post content.
	 * @param string   $html  Rendered HTML (before stripping).
	 * @param string[] $notes Notes (by reference).
	 */
	private function detect_media( $raw, $html, array &$notes ) {
		$img_count = preg_match_all( '~<img\b~i', $html );
		if ( $img_count ) {
			$names = array();
			if ( preg_match_all( '~<img\b[^>]*?\bsrc\s*=\s*(["\'])(.*?)\1~is', $html, $m ) ) {
				foreach ( $m[2] as $src ) {
					$name = $this->url_basename( $src );
					if ( '' !== $name ) {
						$names[] = $name;
					}
				}
			}
			$names   = array_unique( $names );
			$notes[] = sprintf(
				/* translators: 1: number of images, 2: comma-separated file names. */
				_n( '%1$d image not exported: %2$s', '%1$d images not exported: %2$s', $img_count, 'lmsable-migrator-for-learndash' ),
				$img_count,
				$names ? implode( ', ', $names ) : '-'
			);
		}

		if ( preg_match_all( '~<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1~is', $html, $m ) ) {
			$files = array();
			foreach ( $m[2] as $href ) {
				if ( false !== stripos( $href, '/wp-content/uploads/' ) ) {
					$name = $this->url_basename( $href );
					if ( '' !== $name ) {
						$files[] = $name;
					}
				}
			}
			$files = array_unique( $files );
			if ( $files ) {
				/* translators: %s: comma-separated file names. */
				$notes[] = sprintf( __( 'Attachments to upload manually: %s', 'lmsable-migrator-for-learndash' ), implode( ', ', $files ) );
			}
		}

		if ( false !== stripos( $raw, '[h5p' ) || false !== stripos( $html, '[h5p' ) ) {
			$notes[] = __( 'H5P content detected ([h5p]) – recreate it manually in LMSable', 'lmsable-migrator-for-learndash' );
		}
	}

	/**
	 * Detects SCORM / Tin Canny via shortcode names, raw content or post meta keys.
	 *
	 * @param WP_Post  $post       Post.
	 * @param string   $raw        Raw content.
	 * @param string[] $shortcodes Removed shortcode names.
	 * @return bool
	 */
	private function has_scorm( $post, $raw, array $shortcodes ) {
		foreach ( $shortcodes as $name ) {
			if ( false !== strpos( $name, 'tincanny' ) || false !== strpos( $name, 'snc' ) ) {
				return true;
			}
		}
		if ( false !== stripos( $raw, 'tincanny' ) ) {
			return true;
		}
		$keys = get_post_custom_keys( $post->ID );
		if ( is_array( $keys ) ) {
			foreach ( $keys as $key ) {
				$key = strtolower( (string) $key );
				if ( false !== strpos( $key, 'tincanny' ) || 0 === strpos( ltrim( $key, '_' ), 'snc' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Removes registered and unregistered shortcode tags; keeps enclosed text.
	 *
	 * @param string   $html  HTML.
	 * @param string[] $names Removed shortcode names (by reference, unique).
	 * @return string
	 */
	private function strip_shortcodes( $html, array &$names ) {
		global $shortcode_tags;

		if ( function_exists( 'get_shortcode_regex' ) && ! empty( $shortcode_tags ) ) {
			$pattern = get_shortcode_regex();
			$result  = preg_replace_callback(
				"/$pattern/",
				function ( $m ) use ( &$names ) {
					$names[] = strtolower( $m[2] );
					return isset( $m[5] ) ? $m[5] : '';
				},
				$html
			);
			if ( is_string( $result ) ) {
				$html = $result;
			}
		}

		$result = preg_replace_callback(
			'~\[/?([a-zA-Z0-9_-]+)[^\]]*\]~',
			function ( $m ) use ( &$names ) {
				$names[] = strtolower( $m[1] );
				return '';
			},
			$html
		);
		if ( is_string( $result ) ) {
			$html = $result;
		}

		$names = array_values( array_unique( $names ) );
		return $html;
	}

	/**
	 * HTML -> plain text with paragraphs separated by a blank line.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function to_plain_text( $html ) {
		$html = preg_replace( '~<br\b[^>]*>~i', "\n", $html );
		$html = preg_replace( '~<(p|h[2-4]|ul|ol|blockquote)\b~i', "\n\n<$1", $html );
		$html = preg_replace( '~</(p|h[2-4]|li|blockquote|div)\s*>~i', "</$1>\n\n", $html );

		$text = wp_strip_all_tags( (string) $html, false );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = str_replace( array( "\r\n", "\r", "\xC2\xA0" ), array( "\n", "\n", ' ' ), $text );
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/ *\n */', "\n", $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );

		return trim( (string) $text );
	}

	/**
	 * Returns the file name of a URL without any path.
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	private function url_basename( $url ) {
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $url || 0 === stripos( $url, 'data:' ) ) {
			return '';
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}
		return wp_strip_all_tags( rawurldecode( wp_basename( $path ) ) );
	}
}
