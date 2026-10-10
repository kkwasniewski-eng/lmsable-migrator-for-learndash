<?php
/**
 * Builds the TOC JSON, the migration report and the content package; serves downloads.
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

		// Quiz questions for the content package; skipped questions go to the item notes.
		$package = new LMFL_Content_Package();
		foreach ( $map['modules'] as $m => $module ) {
			foreach ( $module['lessons'] as $l => $item ) {
				if ( 'quiz' !== $item['type'] && 'test' !== $item['type'] ) {
					continue;
				}
				$quiz = $package->export_quiz( get_post( $item['_post_id'] ), $item['type'] );

				$map['modules'][ $m ]['lessons'][ $l ]['_quiz']       = $quiz['quiz'];
				$map['modules'][ $m ]['lessons'][ $l ]['_quiz_stats'] = array(
					'exported' => $quiz['exported'],
					'skipped'  => $quiz['skipped'],
				);
				$map['modules'][ $m ]['lessons'][ $l ]['_notes']      = array_values( array_unique( array_merge( $item['_notes'], $quiz['notes'] ) ) );
			}
		}

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
	 * Download body, file name and content type.
	 *
	 * @param array  $data Build data.
	 * @param string $kind toc|report|content|package|source.
	 * @return array{body:string,filename:string,type:string}|null Null when the file cannot be built.
	 */
	public function export_file( array $data, $kind ) {
		if ( 'source' === $kind ) {
			return $this->source_file( $data );
		}
		if ( 'report' === $kind ) {
			return array(
				'body'     => $data['report'],
				'filename' => $data['slug'] . '-report.md',
				'type'     => 'text/markdown; charset=utf-8',
			);
		}
		if ( 'content' !== $kind && 'package' !== $kind ) {
			return array(
				'body'     => $data['json'],
				'filename' => $data['slug'] . '-toc.json',
				'type'     => 'application/json; charset=utf-8',
			);
		}

		$package = new LMFL_Content_Package();
		$content = wp_json_encode( $package->build( $data ), self::JSON_FLAGS );
		if ( false === $content ) {
			return null;
		}
		if ( 'content' === $kind || ! class_exists( 'ZipArchive' ) ) {
			return array(
				'body'     => $content,
				'filename' => $data['slug'] . '-content.json',
				'type'     => 'application/json; charset=utf-8',
			);
		}

		$source       = new LMFL_Source_Export();
		$source_files = $source->files( $data, $source->parts( $data ) );

		$zip = $package->zip(
			array_merge(
				array(
					$data['slug'] . '-toc.json'     => $data['json'],
					$data['slug'] . '-content.json' => $content,
					$data['slug'] . '-report.md'    => $data['report'],
					'INSTRUCTIONS.md'               => $package->instructions( $data ),
				),
				$source_files,
				array(
					'INSTRUCTIONS-source.md' => $source->instructions( $data, array_keys( $source_files ) ),
				)
			)
		);
		if ( false === $zip ) {
			return null;
		}
		return array(
			'body'     => $zip,
			'filename' => $data['slug'] . '-lmsable-package.zip',
			'type'     => 'application/zip',
		);
	}

	/**
	 * Source material download: one .md, or a ZIP of parts when it exceeds the limit
	 * (one .md with part markers when ZipArchive is missing).
	 *
	 * @param array $data Build data.
	 * @return array{body:string,filename:string,type:string}|null
	 */
	private function source_file( array $data ) {
		$source = new LMFL_Source_Export();
		$parts  = $source->parts( $data );
		$total  = count( $parts );

		if ( 1 === $total ) {
			return array(
				'body'     => $parts[0],
				'filename' => $data['slug'] . '-source.md',
				'type'     => 'text/markdown; charset=utf-8',
			);
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			$body = array();
			foreach ( $parts as $i => $part ) {
				$body[] = '<!-- PART ' . ( $i + 1 ) . '/' . $total . ' -->' . "\n" . $part;
			}
			return array(
				'body'     => implode( "\n", $body ),
				'filename' => $data['slug'] . '-source.md',
				'type'     => 'text/markdown; charset=utf-8',
			);
		}

		$package = new LMFL_Content_Package();
		$zip     = $package->zip( $source->files( $data, $parts ) );
		if ( false === $zip ) {
			return null;
		}
		return array(
			'body'     => $zip,
			'filename' => $data['slug'] . '-source-parts.zip',
			'type'     => 'application/zip',
		);
	}

	/**
	 * Sends a download and exits.
	 *
	 * @param int    $course_id Course id.
	 * @param string $kind      toc|report|content|package|source.
	 */
	public function send_download( $course_id, $kind ) {
		$file = $this->export_file( $this->build( $course_id ), $kind );
		if ( null === $file ) {
			wp_die( esc_html__( 'Could not create the export file.', 'lmsable-migrator-for-learndash' ), '', array( 'response' => 500 ) );
		}
		$body     = $file['body'];
		$filename = $file['filename'];
		$type     = $file['type'];

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
		$lines[] = ( $stats['quiz'] + $stats['test'] ) > 0
			? __( 'Single and multiple choice quiz questions are exported in the content package; skipped questions are listed below.', 'lmsable-migrator-for-learndash' )
			: __( 'Quiz questions are not exported in this version – recreate them in LMSable.', 'lmsable-migrator-for-learndash' );

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
