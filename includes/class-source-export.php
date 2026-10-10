<?php
/**
 * Course source material (markdown) for an AI rebuild in the LMSable Course Builder.
 *
 * @package LMSable_Migrator_For_LearnDash
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Source material renderer. Works on the exporter build data; never maps the course again.
 */
class LMFL_Source_Export {

	// Source material limit of the LMSable Course Builder (characters).
	const SOURCE_LIMIT = 60000;

	// Longest course title used in headings (keeps part headers within the limit).
	const TITLE_CAP = 200;

	/**
	 * Whole source material as one markdown document.
	 *
	 * @param array $data Exporter build data.
	 * @return string
	 */
	public function markdown( array $data ) {
		$blocks = array( '# ' . $this->course_title( $data ) );
		foreach ( $this->modules( $data ) as $module ) {
			$blocks[] = $this->module_block( $module['heading'], $module['lessons'] );
		}
		return implode( "\n\n", $blocks ) . "\n";
	}

	/**
	 * Source material split into parts of at most SOURCE_LIMIT characters each.
	 *
	 * Splits on module boundaries; a module longer than the limit is split on lesson
	 * boundaries (and a single oversized lesson on line boundaries).
	 *
	 * @param array $data Exporter build data.
	 * @return string[]
	 */
	public function parts( array $data ) {
		$full = $this->markdown( $data );
		if ( $this->length( $full ) <= self::SOURCE_LIMIT ) {
			return array( $full );
		}

		$title = $this->course_title( $data );
		// Room for the widest possible part header, the blank line after it and the final newline.
		$budget = self::SOURCE_LIMIT - $this->length( '# ' . $title . ' (part 999/999)' ) - 3;

		$pieces = array();
		foreach ( $this->modules( $data ) as $module ) {
			$block = $this->module_block( $module['heading'], $module['lessons'] );
			if ( $this->length( $block ) <= $budget ) {
				$pieces[] = $block;
			} else {
				$pieces = array_merge( $pieces, $this->split_module( $module['heading'], $module['lessons'], $budget ) );
			}
		}

		$chunks  = array();
		$current = '';
		foreach ( $pieces as $piece ) {
			$candidate = '' === $current ? $piece : $current . "\n\n" . $piece;
			if ( '' !== $current && $this->length( $candidate ) > $budget ) {
				$chunks[] = $current;
				$current  = $piece;
			} else {
				$current = $candidate;
			}
		}
		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		$total = count( $chunks );
		$parts = array();
		foreach ( $chunks as $i => $chunk ) {
			$parts[] = '# ' . $title . ' (part ' . ( $i + 1 ) . '/' . $total . ')' . "\n\n" . $chunk . "\n";
		}
		return $parts;
	}

	/**
	 * Download files of the source material: name => body.
	 *
	 * @param array    $data  Exporter build data.
	 * @param string[] $parts Parts from parts().
	 * @return array<string,string>
	 */
	public function files( array $data, array $parts ) {
		if ( 1 === count( $parts ) ) {
			return array( $data['slug'] . '-source.md' => $parts[0] );
		}
		$files = array();
		foreach ( $parts as $i => $part ) {
			$files[ $data['slug'] . '-source-part' . ( $i + 1 ) . '.md' ] = $part;
		}
		return $files;
	}

	/**
	 * Number of text lessons that have content (used to recommend an export mode).
	 *
	 * @param array $data Exporter build data.
	 * @return int
	 */
	public function count_text_lessons( array $data ) {
		$count = 0;
		foreach ( $data['modules'] as $module ) {
			foreach ( $module['lessons'] as $item ) {
				if ( 'lesson' === $item['type'] && ! empty( $item['_html'] ) && '' !== $this->html_to_text( $item['_html'] ) ) {
					++$count;
				}
			}
		}
		return $count;
	}

	/**
	 * Multibyte-safe length.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * INSTRUCTIONS-source.md: how to rebuild the course from the source material.
	 *
	 * @param array    $data  Exporter build data.
	 * @param string[] $files Source file names.
	 * @return string
	 */
	public function instructions( array $data, array $files ) {
		$title = $this->course_title( $data );
		$names = '`' . implode( '`, `', $files ) . '`';
		$lines = array(
			'# ' . __( 'How to rebuild this course with LMSable AI', 'lmsable-migrator-for-learndash' ),
			'',
			/* translators: %s: course title. */
			sprintf( __( 'Course: %s', 'lmsable-migrator-for-learndash' ), $title ),
			'',
			/* translators: %s: source file names. */
			sprintf( __( 'Source material: %s – the plain text of the course (modules, lessons, video links, quiz questions).', 'lmsable-migrator-for-learndash' ), $names ),
			'',
			'## ' . __( 'Option A: LMSable Course Builder', 'lmsable-migrator-for-learndash' ),
			'',
			'1. ' . __( 'In the LMSable Course Builder click New course.', 'lmsable-migrator-for-learndash' ),
			'2. ' . __( 'In the Brief/Settings step set Source to Paste text.', 'lmsable-migrator-for-learndash' ),
			'3. ' . __( 'Paste the whole content of the source file into the source field.', 'lmsable-migrator-for-learndash' ),
			'4. ' . __( 'Set fidelity to 1-2 to stay close to the original course.', 'lmsable-migrator-for-learndash' ),
			'5. ' . __( 'Turn Draft lessons ON.', 'lmsable-migrator-for-learndash' ),
			'6. ' . __( 'Click Generate table of contents and review the proposed modules and lessons.', 'lmsable-migrator-for-learndash' ),
			'7. ' . __( 'Continue in the builder: generate the content and the quizzes lesson by lesson.', 'lmsable-migrator-for-learndash' ),
			'',
			'## ' . __( 'Option B: Claude with the LMSable connector', 'lmsable-migrator-for-learndash' ),
			'',
			'1. ' . __( 'In Claude, connect the LMSable connector (MCP).', 'lmsable-migrator-for-learndash' ),
			'2. ' . __( 'Attach the source file(s) to the chat and ask Claude to create the course from them, for example:', 'lmsable-migrator-for-learndash' ),
			'',
			/* translators: %s: course title. */
			'   > ' . sprintf( __( 'Create an LMSable course "%s" with create_course based on the attached source material. Keep the module and lesson order, write the lesson content from the material and add the quiz questions to the matching lessons.', 'lmsable-migrator-for-learndash' ), $title ),
			'',
			'## ' . __( 'Notes', 'lmsable-migrator-for-learndash' ),
			'',
			'- ' . __( 'Source material requires the Pro plan in LMSable.', 'lmsable-migrator-for-learndash' ),
			'- ' . __( 'Video lessons must be attached manually: the video URLs are in the material, on the lines starting with "Video:".', 'lmsable-migrator-for-learndash' ),
			'- ' . __( 'If the material is split into several parts, paste the parts one after another into the same source field, or use Option B and attach all parts.', 'lmsable-migrator-for-learndash' ),
			'',
		);
		return implode( "\n", $lines );
	}

	/**
	 * Modules as a heading and rendered lesson blocks.
	 *
	 * @param array $data Exporter build data.
	 * @return array<int,array{heading:string,lessons:string[]}>
	 */
	private function modules( array $data ) {
		$modules = array();
		foreach ( $data['modules'] as $module ) {
			$lessons = array();
			foreach ( $module['lessons'] as $item ) {
				$lessons[] = $this->lesson_block( $item );
			}
			$modules[] = array(
				'heading' => '## ' . $this->flatten( $module['title'] ),
				'lessons' => $lessons,
			);
		}
		return $modules;
	}

	/**
	 * One module: heading plus its lessons.
	 *
	 * @param string   $heading Module heading line.
	 * @param string[] $lessons Lesson blocks.
	 * @return string
	 */
	private function module_block( $heading, array $lessons ) {
		return implode( "\n\n", array_merge( array( $heading ), $lessons ) );
	}

	/**
	 * Splits an oversized module on lesson boundaries; every piece repeats the module heading.
	 *
	 * @param string   $heading Module heading line.
	 * @param string[] $lessons Lesson blocks.
	 * @param int      $budget  Max characters per piece.
	 * @return string[]
	 */
	private function split_module( $heading, array $lessons, $budget ) {
		$lesson_budget = $budget - $this->length( $heading ) - 2;
		$segments      = array();
		foreach ( $lessons as $lesson ) {
			if ( $this->length( $lesson ) > $lesson_budget ) {
				$segments = array_merge( $segments, $this->split_text( $lesson, $lesson_budget ) );
			} else {
				$segments[] = $lesson;
			}
		}

		$pieces  = array();
		$current = $heading;
		foreach ( $segments as $segment ) {
			$candidate = $current . "\n\n" . $segment;
			if ( $current !== $heading && $this->length( $candidate ) > $budget ) {
				$pieces[] = $current;
				$current  = $heading . "\n\n" . $segment;
			} else {
				$current = $candidate;
			}
		}
		$pieces[] = $current;
		return $pieces;
	}

	/**
	 * Splits text on line boundaries into chunks of at most $max characters (hard cut for longer lines).
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters per chunk.
	 * @return string[]
	 */
	private function split_text( $text, $max ) {
		$chunks  = array();
		$current = '';
		foreach ( explode( "\n", $text ) as $line ) {
			while ( $this->length( $line ) > $max ) {
				if ( '' !== $current ) {
					$chunks[] = $current;
					$current  = '';
				}
				$chunks[] = $this->substr( $line, 0, $max );
				$line     = $this->substr( $line, $max, null );
			}
			$candidate = '' === $current ? $line : $current . "\n" . $line;
			if ( '' !== $current && $this->length( $candidate ) > $max ) {
				$chunks[] = $current;
				$current  = $line;
			} else {
				$current = $candidate;
			}
		}
		if ( '' !== trim( $current ) ) {
			$chunks[] = $current;
		}
		return $chunks;
	}

	/**
	 * One lesson: heading, video link, text and quiz questions. Notes are left out.
	 *
	 * @param array $item Mapped item.
	 * @return string
	 */
	private function lesson_block( array $item ) {
		$lines = array( '### ' . $this->flatten( $item['title'] ) );

		if ( ! empty( $item['_video_url'] ) ) {
			$lines[] = 'Video: ' . $item['_video_url'];
		}

		$text = ! empty( $item['_html'] ) ? $this->html_to_text( $item['_html'] ) : '';
		if ( '' !== $text ) {
			$lines[] = '';
			$lines[] = $text;
		}

		if ( ! empty( $item['_quiz']['questions'] ) ) {
			$pass    = isset( $item['_quiz']['settings']['passPercent'] ) ? (int) $item['_quiz']['settings']['passPercent'] : 0;
			$lines[] = '';
			$lines[] = 'Quiz (pass ' . $pass . '%):';
			foreach ( array_values( $item['_quiz']['questions'] ) as $i => $question ) {
				$lines[] = '';
				$lines[] = ( $i + 1 ) . '. ' . $this->flatten( $question['text'] );
				foreach ( $question['options'] as $option ) {
					$lines[] = '- [' . ( ! empty( $option['correct'] ) ? 'x' : ' ' ) . '] ' . $this->flatten( $option['text'] );
				}
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Clean lesson HTML to plain text, keeping paragraphs and list items.
	 *
	 * @param string $html Lesson HTML.
	 * @return string
	 */
	private function html_to_text( $html ) {
		$text = (string) preg_replace( '/\s+/u', ' ', (string) $html );
		$text = (string) preg_replace( '#<br\s*/?>#i', "\n", $text );
		$text = (string) preg_replace( '#<li\b[^>]*>#i', "\n- ", $text );
		$text = (string) preg_replace( '#</li>#i', "\n", $text );
		$text = (string) preg_replace( '#</?(p|h[1-6]|ul|ol|blockquote|table|thead|tbody|tr|div)\b[^>]*>|<hr\b[^>]*>#i', "\n\n", $text );
		$text = (string) preg_replace( '#</t[dh]>#i', ' ', $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text );

		$lines = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$lines[] = trim( (string) preg_replace( '/[ \t]+/u', ' ', $line ) );
		}
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $lines ) );
		// A list item directly after a paragraph keeps a single line break.
		$text = (string) preg_replace( "/\n\n(?=- )/", "\n", $text );
		return trim( $text );
	}

	/**
	 * Course title for the headings.
	 *
	 * @param array $data Exporter build data.
	 * @return string
	 */
	private function course_title( array $data ) {
		$title = $data['course'] instanceof WP_Post ? $this->flatten( $data['course']->post_title ) : '';
		return $this->length( $title ) > self::TITLE_CAP ? rtrim( $this->substr( $title, 0, self::TITLE_CAP ) ) : $title;
	}

	/**
	 * Plain single-line text.
	 *
	 * @param mixed $text Text or HTML.
	 * @return string
	 */
	private function flatten( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text, true ), ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', str_replace( "\xC2\xA0", ' ', $text ) ) );
	}

	/**
	 * Multibyte-safe substring.
	 *
	 * @param string   $text  Text.
	 * @param int      $start Start.
	 * @param int|null $len   Length (null = to the end).
	 * @return string
	 */
	private function substr( $text, $start, $len ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, $start, $len, 'UTF-8' ) : (string) substr( $text, $start, null === $len ? strlen( $text ) : $len );
	}
}
