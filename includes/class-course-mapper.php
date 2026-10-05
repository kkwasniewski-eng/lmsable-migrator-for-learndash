<?php
/**
 * Maps a LearnDash course to LMSable modules and lessons.
 *
 * @package LMSable_Migrator_For_LearnDash
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LearnDash course -> modules/lessons structure.
 *
 * Lesson items carry internal keys prefixed with "_" (post id, notes, video URL);
 * the exporter strips them before building the TOC JSON.
 */
class LMFL_Course_Mapper {

	const CONTENT_CAP     = 20000;
	const DESCRIPTION_CAP = 500;
	const TITLE_CAP       = 200;
	const WPM             = 200;

	/**
	 * Content pipeline.
	 *
	 * @var LMFL_Content_Pipeline
	 */
	private $pipeline;

	/**
	 * Current course id.
	 *
	 * @var int
	 */
	private $course_id = 0;

	/**
	 * Error/warning messages for the screen.
	 *
	 * @var string[]
	 */
	private $errors = array();

	/**
	 * Notes not attached to any exported item: [ ['context' => string, 'notes' => string[]] ].
	 *
	 * @var array
	 */
	private $extra_notes = array();

	/**
	 * Quiz ids already placed under a lesson or topic.
	 *
	 * @var int[]
	 */
	private $seen_quizzes = array();

	/**
	 * Constructor.
	 *
	 * @param LMFL_Content_Pipeline|null $pipeline Pipeline.
	 */
	public function __construct( $pipeline = null ) {
		$this->pipeline = $pipeline instanceof LMFL_Content_Pipeline ? $pipeline : new LMFL_Content_Pipeline();
	}

	/**
	 * Maps a course.
	 *
	 * @param int $course_id Course id.
	 * @return array{course:WP_Post|null,modules:array,errors:string[],extra_notes:array}
	 */
	public function map( $course_id ) {
		$this->course_id    = absint( $course_id );
		$this->errors       = array();
		$this->extra_notes  = array();
		$this->seen_quizzes = array();

		$course  = get_post( $this->course_id );
		$modules = array();

		if ( ! $course || 'sfwd-courses' !== $course->post_type ) {
			$this->errors[] = __( 'Course not found.', 'lmsable-migrator-for-learndash' );
			return $this->result( null, $modules );
		}

		$lesson_ids = $this->get_lesson_ids();
		if ( null === $lesson_ids ) {
			$this->errors[] = __( 'The LearnDash course steps API is not available (learndash_course_get_steps_by_type / learndash_get_course_lessons_list). Please update LearnDash.', 'lmsable-migrator-for-learndash' );
			return $this->result( $course, $modules );
		}

		$topics       = array();
		$total_topics = 0;
		foreach ( $lesson_ids as $lesson_id ) {
			$topics[ $lesson_id ] = $this->get_children( $lesson_id, 'sfwd-topic' );
			$total_topics        += count( $topics[ $lesson_id ] );
		}

		if ( 0 === $total_topics ) {
			// No topics at all: one module named after the course.
			$items = array();
			foreach ( $lesson_ids as $lesson_id ) {
				$items = array_merge( $items, $this->step_with_quizzes( $lesson_id, 'lesson' ) );
			}
			if ( $items ) {
				$modules[] = array(
					'title'   => $this->module_title( $course->post_title ),
					'lessons' => $items,
				);
			}
		} else {
			foreach ( $lesson_ids as $lesson_id ) {
				$lesson = get_post( $lesson_id );
				if ( ! $lesson ) {
					continue;
				}
				$module = array(
					'title'   => $this->module_title( $lesson->post_title ),
					'lessons' => array(),
				);

				if ( empty( $topics[ $lesson_id ] ) ) {
					$module['lessons'] = $this->step_with_quizzes( $lesson_id, 'lesson' );
				} else {
					$lesson_item = $this->build_item( $lesson, 'lesson' );
					if ( $lesson_item ) {
						// Lesson body becomes the first lesson of the module only when it has content (or a video).
						if ( isset( $lesson_item['content'] ) || 'video' === $lesson_item['type'] ) {
							$module['lessons'][] = $lesson_item;
						} elseif ( $lesson_item['_notes'] ) {
							$this->extra_notes[] = array(
								'context' => $module['title'],
								'notes'   => $lesson_item['_notes'],
							);
						}
					}
					foreach ( $topics[ $lesson_id ] as $topic_id ) {
						$module['lessons'] = array_merge( $module['lessons'], $this->step_with_quizzes( $topic_id, 'lesson' ) );
					}
					$module['lessons'] = array_merge( $module['lessons'], $this->quiz_items( $lesson_id ) );
				}

				if ( $module['lessons'] ) {
					$modules[] = $module;
				}
			}
		}

		// Course-level (final) quizzes -> "test" at the end of the last module.
		$tests = array();
		foreach ( $this->get_course_quiz_ids() as $quiz_id ) {
			if ( in_array( $quiz_id, $this->seen_quizzes, true ) ) {
				continue;
			}
			$this->seen_quizzes[] = $quiz_id;
			$item                 = $this->build_item( get_post( $quiz_id ), 'test' );
			if ( $item ) {
				$tests[] = $item;
			}
		}
		if ( $tests ) {
			if ( ! $modules ) {
				$modules[] = array(
					'title'   => $this->module_title( $course->post_title ),
					'lessons' => array(),
				);
			}
			$last                        = count( $modules ) - 1;
			$modules[ $last ]['lessons'] = array_merge( $modules[ $last ]['lessons'], $tests );
		}

		if ( ! $modules ) {
			$this->errors[] = __( 'No lessons or quizzes were found in this course.', 'lmsable-migrator-for-learndash' );
		}

		return $this->result( $course, $modules );
	}

	/**
	 * Builds the result array.
	 *
	 * @param WP_Post|null $course  Course.
	 * @param array        $modules Modules.
	 * @return array
	 */
	private function result( $course, array $modules ) {
		return array(
			'course'      => $course,
			'modules'     => $modules,
			'errors'      => array_values( array_unique( $this->errors ) ),
			'extra_notes' => $this->extra_notes,
		);
	}

	/**
	 * A lesson/topic item followed by its quizzes.
	 *
	 * @param int    $step_id Step id.
	 * @param string $type    Item type.
	 * @return array[]
	 */
	private function step_with_quizzes( $step_id, $type ) {
		$items = array();
		$item  = $this->build_item( get_post( $step_id ), $type );
		if ( $item ) {
			$items[] = $item;
		}
		return array_merge( $items, $this->quiz_items( $step_id ) );
	}

	/**
	 * Quiz items attached to a lesson or topic.
	 *
	 * @param int $step_id Step id.
	 * @return array[]
	 */
	private function quiz_items( $step_id ) {
		$items = array();
		foreach ( $this->get_children( $step_id, 'sfwd-quiz' ) as $quiz_id ) {
			if ( in_array( $quiz_id, $this->seen_quizzes, true ) ) {
				continue;
			}
			$this->seen_quizzes[] = $quiz_id;
			$item                 = $this->build_item( get_post( $quiz_id ), 'quiz' );
			if ( $item ) {
				$items[] = $item;
			}
		}
		return $items;
	}

	/**
	 * Builds a single lesson item.
	 *
	 * @param WP_Post|null $post Post.
	 * @param string       $type lesson|quiz|test.
	 * @return array|null
	 */
	private function build_item( $post, $type ) {
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$notes   = array();
		$title   = $this->clean_title( $post->post_title, $notes );
		$content = '';

		if ( 'lesson' === $type ) {
			$processed = $this->pipeline->process( $post );
			$content   = $processed['content'];
			$notes     = array_merge( $notes, $processed['notes'] );

			$video = $this->get_video( $post );
			if ( $video['enabled'] ) {
				$type = 'video';
				if ( '' === $video['url'] ) {
					$notes[] = __( 'Video enabled but no URL set – add the video manually', 'lmsable-migrator-for-learndash' );
				} elseif ( $video['portable'] ) {
					/* translators: %s: video URL. */
					$notes[] = sprintf( __( 'Video: %s (YouTube/Vimeo/Loom – portable, paste the link in LMSable)', 'lmsable-migrator-for-learndash' ), $video['url'] );
				} else {
					/* translators: %s: video URL. */
					$notes[] = sprintf( __( 'Video: %s (self-hosted or other host – upload the video manually)', 'lmsable-migrator-for-learndash' ), $video['url'] );
				}
			}
		}

		$length = $this->strlen( $content );
		if ( $length > self::CONTENT_CAP ) {
			$content = $this->substr( $content, self::CONTENT_CAP );
			/* translators: 1: cap, 2: original length. */
			$notes[] = sprintf( __( 'Content truncated to %1$d characters (original: %2$d)', 'lmsable-migrator-for-learndash' ), self::CONTENT_CAP, $length );
		}

		$time = $this->forced_time( $post );
		if ( '' === $time ) {
			$time = $this->estimate_time( $content );
		}

		$item = array(
			'title' => $title,
			'type'  => $type,
			'time'  => $time,
		);

		$description = $this->description( $post, $content, $notes );
		if ( '' !== $description ) {
			$item['description'] = $description;
		}
		if ( '' !== $content ) {
			$item['content'] = $content;
		}

		$item['_post_id']   = $post->ID;
		$item['_video_url'] = isset( $video['url'] ) ? $video['url'] : '';
		$item['_notes']     = array_values( array_unique( $notes ) );

		return $item;
	}

	/**
	 * Lesson ids of the course in builder order, or null when no API is available.
	 *
	 * @return int[]|null
	 */
	private function get_lesson_ids() {
		if ( function_exists( 'learndash_course_get_steps_by_type' ) ) {
			return $this->to_ids( learndash_course_get_steps_by_type( $this->course_id, 'sfwd-lessons' ) );
		}
		if ( function_exists( 'learndash_get_course_lessons_list' ) ) {
			return $this->to_ids( learndash_get_course_lessons_list( $this->course_id, null, array( 'num' => -1 ) ) );
		}
		return null;
	}

	/**
	 * Child step ids (topics or quizzes) of a lesson/topic.
	 *
	 * @param int    $step_id    Step id.
	 * @param string $child_type sfwd-topic|sfwd-quiz.
	 * @return int[]
	 */
	private function get_children( $step_id, $child_type ) {
		if ( function_exists( 'learndash_course_get_children_of_step' ) ) {
			return $this->to_ids( learndash_course_get_children_of_step( $this->course_id, $step_id, $child_type ) );
		}
		if ( 'sfwd-topic' === $child_type && function_exists( 'learndash_get_topic_list' ) ) {
			return $this->to_ids( learndash_get_topic_list( $step_id, $this->course_id ) );
		}
		if ( 'sfwd-quiz' === $child_type && function_exists( 'learndash_get_lesson_quiz_list' ) ) {
			return $this->to_ids( learndash_get_lesson_quiz_list( $step_id, null, $this->course_id ) );
		}
		$this->errors[] = 'sfwd-topic' === $child_type
			? __( 'The LearnDash topics API is not available – topics were skipped.', 'lmsable-migrator-for-learndash' )
			: __( 'The LearnDash quizzes API is not available – lesson quizzes were skipped.', 'lmsable-migrator-for-learndash' );
		return array();
	}

	/**
	 * All quiz ids of the course (nested ones are filtered out by the caller).
	 *
	 * @return int[]
	 */
	private function get_course_quiz_ids() {
		if ( function_exists( 'learndash_course_get_steps_by_type' ) ) {
			return $this->to_ids( learndash_course_get_steps_by_type( $this->course_id, 'sfwd-quiz' ) );
		}
		if ( function_exists( 'learndash_get_course_quiz_list' ) ) {
			return $this->to_ids( learndash_get_course_quiz_list( $this->course_id ) );
		}
		$this->errors[] = __( 'The LearnDash course quizzes API is not available – final quizzes were skipped.', 'lmsable-migrator-for-learndash' );
		return array();
	}

	/**
	 * Normalizes LearnDash list formats (ids, WP_Post, ['post' => WP_Post]) to ids.
	 *
	 * @param mixed $list List.
	 * @return int[]
	 */
	private function to_ids( $list ) {
		$ids = array();
		if ( ! is_array( $list ) ) {
			return $ids;
		}
		foreach ( $list as $entry ) {
			if ( is_numeric( $entry ) ) {
				$ids[] = absint( $entry );
			} elseif ( $entry instanceof WP_Post ) {
				$ids[] = $entry->ID;
			} elseif ( is_array( $entry ) && isset( $entry['post'] ) && $entry['post'] instanceof WP_Post ) {
				$ids[] = $entry['post']->ID;
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Reads a LearnDash post setting.
	 *
	 * @param WP_Post $post Post.
	 * @param string  $key  Setting key without the post type prefix.
	 * @return string
	 */
	private function get_setting( $post, $key ) {
		if ( function_exists( 'learndash_get_setting' ) ) {
			$value = learndash_get_setting( $post, $key );
			return is_scalar( $value ) ? trim( (string) $value ) : '';
		}
		$meta   = get_post_meta( $post->ID, '_' . $post->post_type, true );
		$prefix = $post->post_type . '_' . $key;
		if ( is_array( $meta ) && isset( $meta[ $prefix ] ) && is_scalar( $meta[ $prefix ] ) ) {
			return trim( (string) $meta[ $prefix ] );
		}
		return '';
	}

	/**
	 * Video settings of a lesson/topic.
	 *
	 * @param WP_Post $post Post.
	 * @return array{enabled:bool,url:string,portable:bool}
	 */
	private function get_video( $post ) {
		$video = array(
			'enabled'  => false,
			'url'      => '',
			'portable' => false,
		);

		$enabled = strtolower( $this->get_setting( $post, 'lesson_video_enabled' ) );
		if ( ! in_array( $enabled, array( 'on', '1', 'yes', 'true' ), true ) ) {
			return $video;
		}
		$video['enabled'] = true;

		// The setting may hold a URL, an iframe or a shortcode.
		$raw = $this->get_setting( $post, 'lesson_video_url' );
		if ( preg_match( '~https?://[^\s"\'<>\]]+~i', $raw, $m ) ) {
			$video['url'] = esc_url_raw( $m[0] );
		} elseif ( '' !== $raw ) {
			$video['url'] = wp_strip_all_tags( $raw );
		}

		$host = strtolower( (string) wp_parse_url( $video['url'], PHP_URL_HOST ) );
		foreach ( array( 'youtube.com', 'youtu.be', 'youtube-nocookie.com', 'vimeo.com', 'loom.com' ) as $portable ) {
			if ( $host === $portable || substr( $host, -strlen( '.' . $portable ) ) === '.' . $portable ) {
				$video['portable'] = true;
				break;
			}
		}

		return $video;
	}

	/**
	 * LearnDash Forced Lesson Timer as "N min" (seconds, mm:ss, hh:mm:ss or "1h 5m 20s").
	 *
	 * @param WP_Post $post Post.
	 * @return string Empty when not set.
	 */
	private function forced_time( $post ) {
		if ( 'sfwd-quiz' === $post->post_type ) {
			return '';
		}
		$raw = $this->get_setting( $post, 'forced_lesson_time' );
		if ( '' === $raw ) {
			return '';
		}

		$seconds = 0;
		if ( ctype_digit( $raw ) ) {
			$seconds = (int) $raw;
		} elseif ( preg_match( '/^(\d+):(\d{1,2})(?::(\d{1,2}))?$/', $raw, $m ) ) {
			$seconds = isset( $m[3] ) && '' !== $m[3]
				? (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3]
				: (int) $m[1] * 60 + (int) $m[2];
		} elseif ( preg_match_all( '/(\d+)\s*([hms])/i', $raw, $parts, PREG_SET_ORDER ) ) {
			$mult = array(
				'h' => 3600,
				'm' => 60,
				's' => 1,
			);
			foreach ( $parts as $part ) {
				$seconds += (int) $part[1] * $mult[ strtolower( $part[2] ) ];
			}
		}

		if ( $seconds <= 0 ) {
			return '';
		}
		return max( 1, (int) ceil( $seconds / 60 ) ) . ' min';
	}

	/**
	 * Reading time estimate: words / 200 wpm, minimum 2 min.
	 *
	 * @param string $content Plain text.
	 * @return string
	 */
	private function estimate_time( $content ) {
		$words = preg_split( '/\s+/u', trim( $content ), -1, PREG_SPLIT_NO_EMPTY );
		$count = is_array( $words ) ? count( $words ) : 0;
		return max( 2, (int) ceil( $count / self::WPM ) ) . ' min';
	}

	/**
	 * Description: excerpt, otherwise the first two sentences of the content.
	 *
	 * @param WP_Post  $post    Post.
	 * @param string   $content Processed content.
	 * @param string[] $notes   Notes (by reference).
	 * @return string
	 */
	private function description( $post, $content, array &$notes ) {
		$text = $this->flatten( $post->post_excerpt );

		if ( '' === $text && '' !== $content ) {
			$flat      = $this->flatten( $content );
			$sentences = preg_split( '/(?<=[.!?…])\s+/u', $flat, 3 );
			$text      = is_array( $sentences ) ? trim( implode( ' ', array_slice( $sentences, 0, 2 ) ) ) : $flat;
		}

		if ( $this->strlen( $text ) > self::DESCRIPTION_CAP ) {
			$text = rtrim( $this->substr( $text, self::DESCRIPTION_CAP ) );
			/* translators: %d: cap. */
			$notes[] = sprintf( __( 'Description truncated to %d characters', 'lmsable-migrator-for-learndash' ), self::DESCRIPTION_CAP );
		}
		return $text;
	}

	/**
	 * Plain single-line text.
	 *
	 * @param string $text Text or HTML.
	 * @return string
	 */
	private function flatten( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text, true ), ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/', ' ', str_replace( "\xC2\xA0", ' ', $text ) ) );
	}

	/**
	 * Clean, capped title.
	 *
	 * @param string   $title Raw title.
	 * @param string[] $notes Notes (by reference).
	 * @return string
	 */
	private function clean_title( $title, array &$notes ) {
		$title = $this->flatten( $title );
		if ( '' === $title ) {
			$title = __( '(no title)', 'lmsable-migrator-for-learndash' );
		}
		if ( $this->strlen( $title ) > self::TITLE_CAP ) {
			$title = rtrim( $this->substr( $title, self::TITLE_CAP ) );
			/* translators: %d: cap. */
			$notes[] = sprintf( __( 'Title truncated to %d characters', 'lmsable-migrator-for-learndash' ), self::TITLE_CAP );
		}
		return $title;
	}

	/**
	 * Module title; truncation notes go to extra notes.
	 *
	 * @param string $title Raw title.
	 * @return string
	 */
	private function module_title( $title ) {
		$notes = array();
		$clean = $this->clean_title( $title, $notes );
		if ( $notes ) {
			$this->extra_notes[] = array(
				'context' => $clean,
				'notes'   => $notes,
			);
		}
		return $clean;
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
