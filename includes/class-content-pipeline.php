<?php
/**
 * Converts LearnDash post content to plain text (TOC import) or whitelisted HTML (content package).
 *
 * @package LMSable_Migrator_For_LearnDash
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content pipeline: blocks/Elementor -> shortcode removal -> plain text or safe HTML, with migration notes.
 */
class LMFL_Content_Pipeline {

	const HTML_CAP = 20000;

	/**
	 * Tags accepted by the LMSable text block.
	 */
	const HTML_TAGS = array( 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'a', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'hr', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td' );

	/**
	 * Elementor widgets without content (no note when skipped).
	 */
	const ELEMENTOR_SILENT = array( 'spacer', 'divider' );

	/**
	 * Post id of the cached prepared result.
	 *
	 * @var int
	 */
	private $cache_id = 0;

	/**
	 * Cached prepared result (shared by process() and process_html()).
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * Processes a post to plain text.
	 *
	 * @param WP_Post $post Lesson or topic post.
	 * @return array{content:string,notes:string[]}
	 */
	public function process( $post ) {
		$prepared = $this->prepare( $post );
		return array(
			'content' => '' !== $prepared['html'] ? $this->to_plain_text( $prepared['html'] ) : '',
			'notes'   => $prepared['notes'],
		);
	}

	/**
	 * Processes a post to HTML limited to the LMSable tag whitelist.
	 *
	 * @param WP_Post $post Lesson or topic post.
	 * @return array{html:string,notes:string[]}
	 */
	public function process_html( $post ) {
		$prepared = $this->prepare( $post );
		$notes    = $prepared['notes'];
		$html     = $this->to_safe_html( $prepared['html'] );

		$length = $this->strlen( $html );
		if ( $length > self::HTML_CAP ) {
			$html = $this->truncate_html( $html, self::HTML_CAP );
			/* translators: 1: cap, 2: original length. */
			$notes[] = sprintf( __( 'HTML truncated to %1$d characters (original: %2$d)', 'lmsable-migrator-for-learndash' ), self::HTML_CAP, $length );
		}

		return array(
			'html'  => $html,
			'notes' => $notes,
		);
	}

	/**
	 * Common part: source HTML (blocks or Elementor), media detection, shortcode removal.
	 *
	 * @param WP_Post $post Post.
	 * @return array{html:string,notes:string[]}
	 */
	private function prepare( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return array(
				'html'  => '',
				'notes' => array(),
			);
		}
		if ( null !== $this->cache && $this->cache_id === $post->ID ) {
			return $this->cache;
		}

		$notes = array();

		if ( 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			$raw = $this->elementor_html( $post->ID, $notes );
			if ( null === $raw ) {
				$notes[] = __( 'Elementor content – not exported in this version', 'lmsable-migrator-for-learndash' );
				return $this->remember( $post->ID, '', $notes );
			}
		} else {
			$raw = (string) $post->post_content;
		}

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

		return $this->remember( $post->ID, $html, array_values( array_unique( $notes ) ) );
	}

	/**
	 * Stores and returns a prepared result.
	 *
	 * @param int      $post_id Post id.
	 * @param string   $html    HTML.
	 * @param string[] $notes   Notes.
	 * @return array{html:string,notes:string[]}
	 */
	private function remember( $post_id, $html, array $notes ) {
		$this->cache_id = $post_id;
		$this->cache    = array(
			'html'  => $html,
			'notes' => $notes,
		);
		return $this->cache;
	}

	/**
	 * HTML built from Elementor data, or null when it cannot be parsed.
	 *
	 * @param int      $post_id Post id.
	 * @param string[] $notes   Notes (by reference).
	 * @return string|null
	 */
	private function elementor_html( $post_id, array &$notes ) {
		try {
			$data = get_post_meta( $post_id, '_elementor_data', true );
			if ( is_string( $data ) ) {
				$decoded = json_decode( $data, true );
				if ( ! is_array( $decoded ) ) {
					// Sometimes stored double-slashed.
					$decoded = json_decode( wp_unslash( $data ), true );
				}
				$data = $decoded;
			}
			if ( ! is_array( $data ) ) {
				return null;
			}

			$parts   = array();
			$skipped = array();
			$this->walk_elementor( $data, $parts, $skipped, 0 );

			foreach ( array_unique( $skipped ) as $widget ) {
				/* translators: %s: Elementor widget type. */
				$notes[] = sprintf( __( 'Elementor widget %s skipped', 'lmsable-migrator-for-learndash' ), $widget );
			}
			return implode( "\n", $parts );
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Walks Elementor elements recursively, in document order.
	 *
	 * @param array    $elements Elements.
	 * @param string[] $parts    HTML parts (by reference).
	 * @param string[] $skipped  Skipped widget types (by reference).
	 * @param int      $depth    Recursion depth.
	 */
	private function walk_elementor( array $elements, array &$parts, array &$skipped, $depth ) {
		if ( $depth > 50 ) {
			return;
		}
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['elType'] ) && 'widget' === $element['elType'] ) {
				$this->elementor_widget( $element, $parts, $skipped );
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->walk_elementor( $element['elements'], $parts, $skipped, $depth + 1 );
			}
		}
	}

	/**
	 * Extracts HTML from a single Elementor widget.
	 *
	 * @param array    $element Widget element.
	 * @param string[] $parts   HTML parts (by reference).
	 * @param string[] $skipped Skipped widget types (by reference).
	 */
	private function elementor_widget( array $element, array &$parts, array &$skipped ) {
		$type     = isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '';
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

		switch ( $type ) {
			case 'text-editor':
				if ( isset( $settings['editor'] ) && is_string( $settings['editor'] ) ) {
					$parts[] = $this->autop( $settings['editor'] );
				}
				break;

			case 'heading':
				if ( isset( $settings['title'] ) && is_string( $settings['title'] ) && '' !== trim( $settings['title'] ) ) {
					$parts[] = '<h3>' . $settings['title'] . '</h3>';
				}
				break;

			case 'icon-list':
				$items = array();
				if ( isset( $settings['icon_list'] ) && is_array( $settings['icon_list'] ) ) {
					foreach ( $settings['icon_list'] as $row ) {
						if ( is_array( $row ) && isset( $row['text'] ) && is_string( $row['text'] ) && '' !== trim( $row['text'] ) ) {
							$items[] = '<li>' . $row['text'] . '</li>';
						}
					}
				}
				if ( $items ) {
					$parts[] = '<ul>' . implode( '', $items ) . '</ul>';
				}
				break;

			case 'toggle':
			case 'accordion':
				if ( isset( $settings['tabs'] ) && is_array( $settings['tabs'] ) ) {
					foreach ( $settings['tabs'] as $tab ) {
						if ( ! is_array( $tab ) ) {
							continue;
						}
						if ( isset( $tab['tab_title'] ) && is_string( $tab['tab_title'] ) && '' !== trim( $tab['tab_title'] ) ) {
							$parts[] = '<h3>' . $tab['tab_title'] . '</h3>';
						}
						if ( isset( $tab['tab_content'] ) && is_string( $tab['tab_content'] ) ) {
							$parts[] = $this->autop( $tab['tab_content'] );
						}
					}
				}
				break;

			default:
				$type = (string) preg_replace( '/[^a-z0-9_.-]/i', '', $type );
				if ( '' !== $type && ! in_array( $type, self::ELEMENTOR_SILENT, true ) ) {
					$skipped[] = $type;
				}
		}
	}

	/**
	 * Adds paragraphs to editor text (Elementor stores it without autop).
	 *
	 * @param string $text Text or HTML.
	 * @return string
	 */
	private function autop( $text ) {
		return function_exists( 'wpautop' ) ? wpautop( $text ) : $text;
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
	 * HTML limited to HTML_TAGS; only href is kept (on <a>).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function to_safe_html( $html ) {
		if ( '' === trim( $html ) ) {
			return '';
		}

		// kses keeps the inner text of removed tags, so drop non-content elements entirely.
		$html = preg_replace( '~<(script|style|noscript|template|svg|iframe|object|select|textarea|button)\b[^>]*>.*?</\1\s*>~is', '', $html );
		$html = preg_replace( '~<!--.*?-->~s', '', (string) $html );

		$allowed = array();
		foreach ( self::HTML_TAGS as $tag ) {
			$allowed[ $tag ] = array();
		}
		$allowed['a'] = array( 'href' => true );

		$html = wp_kses( (string) $html, $allowed );

		// Drop empty elements left behind by removed images/widgets.
		for ( $i = 0; $i < 3; $i++ ) {
			$html = preg_replace( '~<(p|h[2-4]|li|ul|ol|strong|b|em|i|u|a|blockquote)\b[^>]*>(?:\s|&nbsp;|&#160;|\xC2\xA0)*</\1>~i', '', (string) $html );
		}
		$html = preg_replace( '/\n\s*\n+/', "\n", (string) $html );
		$html = trim( (string) $html );

		return '' === trim( wp_strip_all_tags( $html ) ) ? '' : $html;
	}

	/**
	 * Cuts HTML to at most $cap characters without leaving broken tags or entities.
	 *
	 * @param string $html HTML.
	 * @param int    $cap  Cap.
	 * @return string
	 */
	private function truncate_html( $html, $cap ) {
		$limit = $cap;
		do {
			$cut = $this->substr( $html, $limit );
			$lt  = strrpos( $cut, '<' );
			$gt  = strrpos( $cut, '>' );
			if ( false !== $lt && ( false === $gt || $lt > $gt ) ) {
				$cut = substr( $cut, 0, $lt );
			}
			$cut = (string) preg_replace( '/&[#a-zA-Z0-9]*$/', '', $cut );
			if ( function_exists( 'force_balance_tags' ) ) {
				$cut = force_balance_tags( $cut );
			}
			$limit -= 200;
		} while ( $this->strlen( $cut ) > $cap && $limit > 0 );

		return $cut;
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

	/**
	 * Multibyte-safe length.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private function strlen( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * Multibyte-safe prefix.
	 *
	 * @param string $text Text.
	 * @param int    $len  Length.
	 * @return string
	 */
	private function substr( $text, $len ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $len, 'UTF-8' ) : substr( $text, 0, $len );
	}
}
