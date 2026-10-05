<?php
/**
 * Content package for Claude + LMSable MCP: lesson HTML, video URLs, ProQuiz questions, instructions, ZIP.
 *
 * @package LMSable_Migrator_For_LearnDash
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content package builder. Works on the mapper result; never maps the course again.
 */
class LMFL_Content_Package {

	// Limits from the LMSable quiz contract (quiz-kontrakt.md).
	const QUESTIONS_CAP = 40;
	const OPTIONS_MIN   = 2;
	const OPTIONS_MAX   = 6;
	const QUESTION_CAP  = 500;
	const OPTION_CAP    = 300;
	const FEEDBACK_CAP  = 500;

	/**
	 * Builds the content package array.
	 *
	 * @param array $data Exporter build data.
	 * @return array
	 */
	public function build( array $data ) {
		$course  = $data['course'];
		$lessons = array();

		foreach ( $data['modules'] as $module ) {
			foreach ( $module['lessons'] as $item ) {
				$entry = array(
					'module' => $module['title'],
					'title'  => $item['title'],
					'type'   => $item['type'],
				);
				if ( ! empty( $item['_html'] ) ) {
					$entry['html'] = $item['_html'];
				}
				if ( ! empty( $item['_video_url'] ) ) {
					$entry['video_url'] = $item['_video_url'];
				}
				if ( ! empty( $item['_quiz'] ) ) {
					$entry['quiz'] = $item['_quiz'];
				}
				$notes          = array_merge( $item['_notes'], isset( $item['_html_notes'] ) ? $item['_html_notes'] : array() );
				$entry['notes'] = array_values( array_unique( $notes ) );
				$lessons[]      = $entry;
			}
		}

		return array(
			'version' => 1,
			'course'  => array(
				'title'       => $course instanceof WP_Post ? $this->flatten( $course->post_title ) : '',
				'lang'        => $this->lang(),
				'source'      => 'learndash',
				'exported_at' => gmdate( 'c' ),
			),
			'lessons' => $lessons,
		);
	}

	/**
	 * Exports ProQuiz questions of a quiz in the LMSable quiz format.
	 *
	 * @param WP_Post|null $post Quiz post.
	 * @param string       $type quiz|test.
	 * @return array{quiz:array|null,exported:int,skipped:int,notes:string[]}
	 */
	public function export_quiz( $post, $type ) {
		$out = array(
			'quiz'     => null,
			'exported' => 0,
			'skipped'  => 0,
			'notes'    => array(),
		);
		if ( ! $post instanceof WP_Post ) {
			return $out;
		}
		if ( ! class_exists( 'WpProQuiz_Model_QuestionMapper' ) ) {
			$out['notes'][] = __( 'LearnDash quiz API (WpProQuiz) not available – questions not exported', 'lmsable-migrator-for-learndash' );
			return $out;
		}

		$pro_id = $this->pro_quiz_id( $post );
		if ( ! $pro_id ) {
			$out['notes'][] = __( 'Quiz has no question data (quiz_pro_id) – questions not exported', 'lmsable-migrator-for-learndash' );
			return $out;
		}

		try {
			$mapper    = new WpProQuiz_Model_QuestionMapper();
			$questions = $mapper->fetchAll( $pro_id );
		} catch ( Throwable $e ) {
			$out['notes'][] = __( 'Reading quiz questions failed – questions not exported', 'lmsable-migrator-for-learndash' );
			return $out;
		}

		$mapped = array();
		foreach ( is_array( $questions ) ? $questions : array() as $question ) {
			if ( ! is_object( $question ) ) {
				continue;
			}
			$row = $this->map_question( $question, $out['notes'] );
			if ( null === $row ) {
				++$out['skipped'];
				continue;
			}
			$mapped[] = $row;
		}

		$total = count( $mapped );
		if ( $total > self::QUESTIONS_CAP ) {
			$out['skipped'] += $total - self::QUESTIONS_CAP;
			$mapped          = array_slice( $mapped, 0, self::QUESTIONS_CAP );
			/* translators: 1: cap, 2: number of portable questions. */
			$out['notes'][] = sprintf( __( 'Only the first %1$d of %2$d questions exported (LMSable limit)', 'lmsable-migrator-for-learndash' ), self::QUESTIONS_CAP, $total );
		}

		foreach ( $mapped as $i => $row ) {
			$mapped[ $i ] = array_merge( array( 'id' => 'q' . ( $i + 1 ) ), $row );
		}
		$out['exported'] = count( $mapped );

		if ( ! $mapped ) {
			$out['notes'][] = __( 'No portable questions – recreate this quiz manually in LMSable', 'lmsable-migrator-for-learndash' );
			return $out;
		}

		$out['quiz'] = array(
			'version'   => 1,
			'settings'  => $this->quiz_settings( $post, $type ),
			'questions' => $mapped,
		);
		return $out;
	}

	/**
	 * Maps one WpProQuiz_Model_Question; null when not portable (a note explains why).
	 *
	 * @param object   $question Question model.
	 * @param string[] $notes    Notes (by reference).
	 * @return array|null
	 */
	private function map_question( $question, array &$notes ) {
		$text  = method_exists( $question, 'getQuestion' ) ? $this->flatten( $question->getQuestion() ) : '';
		$title = method_exists( $question, 'getTitle' ) ? $this->flatten( $question->getTitle() ) : '';
		if ( '' === $title ) {
			$title = '' !== $text ? $this->cut( $text, 80 ) : '?';
		}

		$answer_type = method_exists( $question, 'getAnswerType' ) ? (string) $question->getAnswerType() : '';
		$types       = array(
			'single'   => 'single',
			'multiple' => 'multi',
		);
		if ( ! isset( $types[ $answer_type ] ) ) {
			/* translators: 1: ProQuiz question type, 2: question title. */
			$notes[] = sprintf( __( 'Question type %1$s not portable: %2$s', 'lmsable-migrator-for-learndash' ), '' !== $answer_type ? $answer_type : '?', $title );
			return null;
		}
		if ( '' === $text ) {
			/* translators: %s: question title. */
			$notes[] = sprintf( __( 'Question skipped (no question text): %s', 'lmsable-migrator-for-learndash' ), $title );
			return null;
		}

		$answers   = method_exists( $question, 'getAnswerData' ) ? $question->getAnswerData() : array();
		$options   = array();
		$correct   = 0;
		$shortened = $this->strlen( $text ) > self::QUESTION_CAP;
		foreach ( is_array( $answers ) ? $answers : array() as $answer ) {
			if ( ! is_object( $answer ) || ! method_exists( $answer, 'getAnswer' ) ) {
				continue;
			}
			$option_text = $this->flatten( $answer->getAnswer() );
			if ( '' === $option_text ) {
				/* translators: %s: question title. */
				$notes[] = sprintf( __( 'Question skipped (answer without text, e.g. an image): %s', 'lmsable-migrator-for-learndash' ), $title );
				return null;
			}
			$is_correct = method_exists( $answer, 'isCorrect' ) && (bool) $answer->isCorrect();
			$correct   += $is_correct ? 1 : 0;
			$shortened  = $shortened || $this->strlen( $option_text ) > self::OPTION_CAP;
			$options[]  = array(
				'text'    => $this->cut( $option_text, self::OPTION_CAP ),
				'correct' => $is_correct,
			);
		}

		$count = count( $options );
		if ( $count < self::OPTIONS_MIN || $count > self::OPTIONS_MAX ) {
			/* translators: 1: number of answers, 2: min, 3: max, 4: question title. */
			$notes[] = sprintf( __( 'Question skipped (%1$d answers, LMSable allows %2$d-%3$d): %4$s', 'lmsable-migrator-for-learndash' ), $count, self::OPTIONS_MIN, self::OPTIONS_MAX, $title );
			return null;
		}
		$type = $types[ $answer_type ];
		if ( ( 'single' === $type && 1 !== $correct ) || ( 'multi' === $type && $correct < 1 ) ) {
			/* translators: 1: number of correct answers, 2: question title. */
			$notes[] = sprintf( __( 'Question skipped (%1$d correct answers marked): %2$s', 'lmsable-migrator-for-learndash' ), $correct, $title );
			return null;
		}

		$row = array(
			'type'    => $type,
			'text'    => $this->cut( $text, self::QUESTION_CAP ),
			'options' => $options,
		);

		$feedback = array();
		$ok_msg   = method_exists( $question, 'getCorrectMsg' ) ? $this->flatten( $question->getCorrectMsg() ) : '';
		$bad_msg  = method_exists( $question, 'getIncorrectMsg' ) ? $this->flatten( $question->getIncorrectMsg() ) : '';
		if ( method_exists( $question, 'isCorrectSameText' ) && $question->isCorrectSameText() ) {
			$bad_msg = $ok_msg;
		}
		if ( '' !== $ok_msg ) {
			$shortened           = $shortened || $this->strlen( $ok_msg ) > self::FEEDBACK_CAP;
			$feedback['correct'] = $this->cut( $ok_msg, self::FEEDBACK_CAP );
		}
		if ( '' !== $bad_msg ) {
			$shortened             = $shortened || $this->strlen( $bad_msg ) > self::FEEDBACK_CAP;
			$feedback['incorrect'] = $this->cut( $bad_msg, self::FEEDBACK_CAP );
		}
		if ( $feedback ) {
			$row['feedback'] = $feedback;
		}

		if ( $shortened ) {
			/* translators: %s: question title. */
			$notes[] = sprintf( __( 'Question texts shortened to LMSable limits: %s', 'lmsable-migrator-for-learndash' ), $title );
		}
		return $row;
	}

	/**
	 * Quiz settings: contract defaults, pass mark from LearnDash when set.
	 *
	 * @param WP_Post $post Quiz post.
	 * @param string  $type quiz|test.
	 * @return array
	 */
	private function quiz_settings( $post, $type ) {
		$pass = 'test' === $type ? 70 : 80;
		if ( function_exists( 'learndash_get_setting' ) ) {
			$raw = learndash_get_setting( $post, 'passingpercentage' );
			if ( is_numeric( $raw ) ) {
				$value = (int) round( (float) $raw );
				if ( $value >= 1 && $value <= 100 ) {
					$pass = $value;
				}
			}
		}
		return array(
			'shuffleQuestions' => true,
			'shuffleAnswers'   => true,
			'maxAttempts'      => 0,
			'passPercent'      => $pass,
		);
	}

	/**
	 * ProQuiz id of a quiz post.
	 *
	 * @param WP_Post $post Quiz post.
	 * @return int
	 */
	private function pro_quiz_id( $post ) {
		$id = function_exists( 'learndash_get_setting' ) ? learndash_get_setting( $post, 'quiz_pro' ) : '';
		if ( ! is_numeric( $id ) || (int) $id <= 0 ) {
			$id = get_post_meta( $post->ID, 'quiz_pro_id', true );
		}
		return is_numeric( $id ) ? absint( $id ) : 0;
	}

	/**
	 * INSTRUCTIONS.md for the ZIP package.
	 *
	 * @param array $data Exporter build data.
	 * @return string
	 */
	public function instructions( array $data ) {
		$slug  = $data['slug'];
		$title = $data['course'] instanceof WP_Post ? $this->flatten( $data['course']->post_title ) : '';
		$lines = array(
			'# ' . __( 'How to move this course to LMSable with Claude', 'lmsable-migrator-for-learndash' ),
			'',
			/* translators: %s: course title. */
			sprintf( __( 'Course: %s', 'lmsable-migrator-for-learndash' ), $title ),
			'',
			'## ' . __( 'Files', 'lmsable-migrator-for-learndash' ),
			'',
			'- `' . $slug . '-toc.json` – ' . __( 'course structure (modules and lessons) for the LMSable Course Builder import.', 'lmsable-migrator-for-learndash' ),
			'- `' . $slug . '-content.json` – ' . __( 'lesson content: HTML text, video links, quiz questions and notes per lesson.', 'lmsable-migrator-for-learndash' ),
			'- `' . $slug . '-report.md` – ' . __( 'items that need manual migration.', 'lmsable-migrator-for-learndash' ),
			'',
			'## ' . __( 'Steps', 'lmsable-migrator-for-learndash' ),
			'',
			/* translators: %s: TOC file name. */
			'1. ' . sprintf( __( 'Create the project at lmsable.com (or ask Claude if your account supports creating courses from chat) and import %s in the Lessons step (Import).','lmsable-migrator-for-learndash' ), '`' . $slug . '-toc.json`' ),
			'2. ' . __( 'In Claude, connect the LMSable connector (MCP).', 'lmsable-migrator-for-learndash' ),
			/* translators: %s: content file name. */
			'3. ' . sprintf( __( 'Attach %s to the chat, then ask Claude to fill the lessons, for example:', 'lmsable-migrator-for-learndash' ), '`' . $slug . '-content.json`' ),
			'',
			/* translators: %s: course title. */
			'   > ' . sprintf( __( 'Fill the lessons of my LMSable course "%s" from the attached file. Match lessons by module and title. Put each "html" into text blocks and each "quiz" into the lesson quiz. Do not change lesson order.', 'lmsable-migrator-for-learndash' ), $title ),
			'',
			'4. ' . __( 'Video lessons: paste the "video_url" from the file into the video lesson.', 'lmsable-migrator-for-learndash' ),
			/* translators: %s: report file name. */
			'5. ' . sprintf( __( 'Complete the items listed in %s and the "notes" of each lesson manually (images, files, H5P, SCORM, skipped quiz questions).', 'lmsable-migrator-for-learndash' ), '`' . $slug . '-report.md`' ),
			'',
		);
		return implode( "\n", $lines );
	}

	/**
	 * Builds a ZIP in a temp file and returns its bytes.
	 *
	 * @param array<string,string> $files Name => body.
	 * @return string|false
	 */
	public function zip( array $files ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}
		$tmp = tempnam( get_temp_dir(), 'lmfl' );
		if ( ! $tmp ) {
			return false;
		}

		$body = false;
		$zip  = new ZipArchive();
		if ( true === $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			foreach ( $files as $name => $content ) {
				$zip->addFromString( $name, (string) $content );
			}
			if ( $zip->close() ) {
				$body = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file.
			}
		}
		wp_delete_file( $tmp );

		return is_string( $body ) && '' !== $body ? $body : false;
	}

	/**
	 * Two-letter content language from the site locale.
	 *
	 * @return string
	 */
	private function lang() {
		$lang = strtolower( substr( (string) get_locale(), 0, 2 ) );
		return preg_match( '/^[a-z]{2}$/', $lang ) ? $lang : 'en';
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
	 * Multibyte-safe prefix, trimmed.
	 *
	 * @param string $text Text.
	 * @param int    $len  Length.
	 * @return string
	 */
	private function cut( $text, $len ) {
		if ( $this->strlen( $text ) <= $len ) {
			return $text;
		}
		return rtrim( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $len, 'UTF-8' ) : substr( $text, 0, $len ) );
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
}
