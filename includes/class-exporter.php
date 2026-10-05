<?php
/**
 * Builds the TOC JSON and the migration report; serves downloads.
 *
 * @package LMSable_Migrator_For_LearnDash
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exporter.
 */
class LMFL_Exporter {

	const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
	const SITE_URL   = 'https://lmsable.com/?utm_source=plugin&utm_medium=learndash-migrator';

	/**
	 * Builds everything needed by the preview and the downloads.
	 *
	 * @param int $course_id Course id.
	 * @return array
	 */
	public function build( $course_id ) {
		$mapper = new LMFL_Course_Mapper();
		$map    = $mapper->map( $course_id );

		$toc    = array( 'modules' => array() );
		$manual = array();
		$stats  = array(
			'modules' => 0,
			'items'   => 0,
			'lesson'  => 0,
			'video'   => 0,
			'quiz'    => 0,
			'test'    => 0,
		);

		foreach ( $map['modules'] as $module ) {
			$lessons = array();
			foreach ( $module['lessons'] as $item ) {
				++$stats['items'];
				if ( isset( $stats[ $item['type'] ] ) ) {
					++$stats[ $item['type'] ];
				}
				if ( ! empty( $item['_notes'] ) ) {
					$manual[] = array(
						'context' => $module['title'] . ' › ' . $item['title'],
						'type'    => $item['type'],
						'notes'   => $item['_notes'],
					);
				}
				$lessons[] = $this->public_fields( $item );
			}
			$toc['modules'][] = array(
				'title'   => $module['title'],
				'lessons' => $lessons,
			);
			++$stats['modules'];
		}

		foreach ( $map['extra_notes'] as $extra ) {
			$manual[] = array(
				'context' => $extra['context'],
				'type'    => '',
				'notes'   => $extra['notes'],
			);
		}

		$json   = wp_json_encode( $toc, self::JSON_FLAGS );
		$errors = $map['errors'];
		if ( false === $json ) {
			$json     = '';
			$errors[] = __( 'Could not encode the TOC JSON.', 'lmsable-migrator-for-learndash' );
		}

		$data = array(
			'course'  => $map['course'],
			'modules' => $map['modules'],
			'errors'  => $errors,
			'manual'  => $manual,
			'stats'   => $stats,
			'toc'     => $toc,
			'json'    => $json,
			'slug'    => $this->course_slug( $map['course'] ),
		);

		$data['report'] = $this->report( $data );
		return $data;
	}

	/**
	 * Sends a download and exits.
	 *
	 * @param int    $course_id Course id.
	 * @param string $kind      toc|report.
	 */
	public function send_download( $course_id, $kind ) {
		$data = $this->build( $course_id );

		if ( 'report' === $kind ) {
			$body     = $data['report'];
			$filename = $data['slug'] . '-report.md';
			$type     = 'text/markdown; charset=utf-8';
		} else {
			$body     = $data['json'];
			$filename = $data['slug'] . '-toc.json';
			$type     = 'application/json; charset=utf-8';
		}

		// Drop anything other code may have buffered (stray output would corrupt the file).
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- file download body.
		exit;
	}

	/**
	 * TOC fields only (drops internal "_" keys).
	 *
	 * @param array $item Mapped item.
	 * @return array
	 */
	private function public_fields( array $item ) {
		$out = array();
		foreach ( $item as $key => $value ) {
			if ( 0 !== strpos( $key, '_' ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * File-safe course slug.
	 *
	 * @param WP_Post|null $course Course.
	 * @return string
	 */
	private function course_slug( $course ) {
		if ( ! $course instanceof WP_Post ) {
			return 'course';
		}
		$slug = $course->post_name ? $course->post_name : sanitize_title( $course->post_title );
		$slug = sanitize_file_name( (string) $slug );
		return '' !== $slug ? $slug : 'course-' . $course->ID;
	}

	/**
	 * Migration report (markdown).
	 *
	 * @param array $data Build data.
	 * @return string
	 */
	private function report( array $data ) {
		$course = $data['course'];
		$stats  = $data['stats'];
		$title  = $course instanceof WP_Post ? wp_strip_all_tags( $course->post_title ) : '';
		$lines  = array();

		/* translators: %s: course title. */
		$lines[] = '# ' . sprintf( __( 'Migration report: %s', 'lmsable-migrator-for-learndash' ), $title );
		$lines[] = '';
		/* translators: 1: date, 2: plugin version. */
		$lines[] = sprintf( __( 'Generated %1$s by LMSable Migrator for LearnDash %2$s.', 'lmsable-migrator-for-learndash' ), wp_date( 'Y-m-d H:i' ), LMFL_VERSION );
		$lines[] = '';
		$lines[] = '## ' . __( 'Summary', 'lmsable-migrator-for-learndash' );
		$lines[] = '';
		/* translators: %d: count. */
		$lines[] = '- ' . sprintf( __( 'Modules: %d', 'lmsable-migrator-for-learndash' ), $stats['modules'] );
		/* translators: %d: count. */
		$lines[] = '- ' . sprintf( __( 'Lessons (all TOC items): %d', 'lmsable-migrator-for-learndash' ), $stats['items'] );
		/* translators: %d: count. */
		$lines[] = '- ' . sprintf( __( 'Text lessons: %d', 'lmsable-migrator-for-learndash' ), $stats['lesson'] );
		/* translators: %d: count. */
		$lines[] = '- ' . sprintf( __( 'Video lessons: %d', 'lmsable-migrator-for-learndash' ), $stats['video'] );
		/* translators: %d: count. */
		$lines[] = '- ' . sprintf( __( 'Quizzes: %d', 'lmsable-migrator-for-learndash' ), $stats['quiz'] );
		/* translators: %d: count. */
		$lines[] = '- ' . sprintf( __( 'Final tests: %d', 'lmsable-migrator-for-learndash' ), $stats['test'] );
		$lines[] = '';
		$lines[] = __( 'Quiz questions are not exported in this version – recreate them in LMSable.', 'lmsable-migrator-for-learndash' );

		if ( $data['errors'] ) {
			$lines[] = '';
			$lines[] = '## ' . __( 'Export problems', 'lmsable-migrator-for-learndash' );
			$lines[] = '';
			foreach ( $data['errors'] as $error ) {
				$lines[] = '- ' . $error;
			}
		}

		$lines[] = '';
		$lines[] = '## ' . __( 'Manual migration required', 'lmsable-migrator-for-learndash' );
		$lines[] = '';
		if ( ! $data['manual'] ) {
			$lines[] = __( 'Nothing found that needs manual migration.', 'lmsable-migrator-for-learndash' );
		} else {
			foreach ( $data['manual'] as $entry ) {
				$lines[] = '### ' . $entry['context'] . ( '' !== $entry['type'] ? ' (' . $entry['type'] . ')' : '' );
				$lines[] = '';
				foreach ( $entry['notes'] as $note ) {
					$lines[] = '- ' . $note;
				}
				$lines[] = '';
			}
		}

		$lines[] = '';
		$lines[] = '## ' . __( 'Next steps', 'lmsable-migrator-for-learndash' );
		$lines[] = '';
		/* translators: %s: LMSable URL. */
		$lines[] = '1. ' . sprintf( __( 'Create a project at %s.', 'lmsable-migrator-for-learndash' ), self::SITE_URL );
		/* translators: %s: TOC file name. */
		$lines[] = '2. ' . sprintf( __( 'In the Lessons step choose Import and paste the TOC JSON (%s).', 'lmsable-migrator-for-learndash' ), $data['slug'] . '-toc.json' );
		$lines[] = '3. ' . __( 'Complete the items listed under "Manual migration required" above.', 'lmsable-migrator-for-learndash' );
		$lines[] = '';

		return implode( "\n", $lines );
	}
}
