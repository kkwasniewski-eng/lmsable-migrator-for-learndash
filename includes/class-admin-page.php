<?php
/**
 * Admin screen "Export to LMSable" and the course row action.
 *
 * @package LMSable_Migrator_For_LearnDash
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page.
 */
class LMFL_Admin_Page {

	const SLUG      = 'lmfl-export';
	const FREE_CAP  = 20;
	const PRO_CAP   = 120;
	const LD_PARENT = 'learndash-lms';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Parent menu slug actually used.
	 *
	 * @var string
	 */
	private static $parent = '';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		// Late priority: LearnDash registers its menu on admin_menu as well.
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 1000 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_download' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
	}

	/**
	 * Capability required for the screen.
	 *
	 * @return string
	 */
	public static function capability() {
		return current_user_can( 'edit_courses' ) ? 'edit_courses' : 'manage_options';
	}

	/**
	 * Adds the submenu under LearnDash, or under Tools as a fallback.
	 */
	public static function register_menu() {
		global $admin_page_hooks;

		$title = __( 'Export to LMSable', 'lmsable-migrator-for-learndash' );

		if ( ! empty( $admin_page_hooks[ self::LD_PARENT ] ) ) {
			$hook = add_submenu_page( self::LD_PARENT, $title, $title, self::capability(), self::SLUG, array( __CLASS__, 'render' ) );
			if ( $hook ) {
				self::$hook   = $hook;
				self::$parent = self::LD_PARENT;
				return;
			}
		}

		$hook = add_management_page( $title, $title, self::capability(), self::SLUG, array( __CLASS__, 'render' ) );
		if ( $hook ) {
			self::$hook   = $hook;
			self::$parent = 'tools.php';
		}
	}

	/**
	 * Screen URL (unescaped).
	 *
	 * @param int $course_id Optional course to preselect.
	 * @return string
	 */
	public static function page_url( $course_id = 0 ) {
		$base = 'tools.php' === self::$parent ? 'tools.php' : 'admin.php';
		$args = array( 'page' => self::SLUG );
		if ( $course_id ) {
			$args['course_id'] = absint( $course_id );
		}
		return add_query_arg( $args, admin_url( $base ) );
	}

	/**
	 * Valid LearnDash course the current user may export.
	 *
	 * @param int $course_id Course id.
	 * @return bool
	 */
	private static function can_export( $course_id ) {
		return $course_id > 0
			&& 'sfwd-courses' === get_post_type( $course_id )
			&& current_user_can( self::capability() )
			&& current_user_can( 'edit_post', $course_id );
	}

	/**
	 * Row action on the course list.
	 *
	 * @param array   $actions Actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public static function row_action( $actions, $post ) {
		if ( ! $post instanceof WP_Post || 'sfwd-courses' !== $post->post_type || ! self::$hook || ! self::can_export( $post->ID ) ) {
			return $actions;
		}
		$actions['lmfl_export'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::page_url( $post->ID ) ),
			esc_html__( 'Export to LMSable', 'lmsable-migrator-for-learndash' )
		);
		return $actions;
	}

	/**
	 * Inline "Copy to clipboard" and export mode switch scripts on our screen only.
	 *
	 * @param string $hook_suffix Current screen hook.
	 */
	public static function enqueue( $hook_suffix ) {
		if ( ! self::$hook || $hook_suffix !== self::$hook ) {
			return;
		}
		wp_register_script( 'lmfl-admin', false, array(), LMFL_VERSION, true );
		wp_enqueue_script( 'lmfl-admin' );
		wp_add_inline_script(
			'lmfl-admin',
			"document.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('#lmfl-copy');if(!b){return;}e.preventDefault();var t=document.getElementById('lmfl-toc-json'),s=document.getElementById('lmfl-copy-status');if(!t){return;}var done=function(){if(s){s.textContent=b.getAttribute('data-copied');}};var fallback=function(){t.focus();t.select();try{document.execCommand('copy');done();}catch(x){}};if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t.value).then(done,fallback);}else{fallback();}});"
		);
		wp_add_inline_script(
			'lmfl-admin',
			"document.addEventListener('change',function(e){var r=e.target;if(!r||'lmfl_mode'!==r.name){return;}['toc','source'].forEach(function(m){var el=document.getElementById('lmfl-mode-'+m);if(el){el.hidden=(m!==r.value);}});});"
		);
	}

	/**
	 * Handles download POSTs before any output.
	 */
	public static function maybe_download() {
		if ( empty( $_POST['lmfl_download'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked below.
			return;
		}
		check_admin_referer( 'lmfl_download', 'lmfl_download_nonce' );

		$kind      = sanitize_key( wp_unslash( $_POST['lmfl_download'] ) );
		$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;

		if ( ! in_array( $kind, array( 'toc', 'report', 'content', 'package', 'source' ), true ) ) {
			wp_die( esc_html__( 'Unknown export type.', 'lmsable-migrator-for-learndash' ), '', array( 'response' => 400 ) );
		}
		if ( ! self::can_export( $course_id ) ) {
			wp_die( esc_html__( 'You are not allowed to export this course.', 'lmsable-migrator-for-learndash' ), '', array( 'response' => 403 ) );
		}

		$exporter = new LMFL_Exporter();
		$exporter->send_download( $course_id, $kind );
	}

	/**
	 * Renders the screen.
	 */
	public static function render() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'lmsable-migrator-for-learndash' ) );
		}

		$selected = 0;
		$data     = null;
		$error    = '';

		if ( isset( $_POST['lmfl_preview'] ) ) {
			check_admin_referer( 'lmfl_preview', 'lmfl_preview_nonce' );
			$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;
			if ( self::can_export( $course_id ) ) {
				$selected = $course_id;
				$exporter = new LMFL_Exporter();
				$data     = $exporter->build( $course_id );
			} else {
				$error = __( 'Please choose a course you are allowed to export.', 'lmsable-migrator-for-learndash' );
			}
		} elseif ( isset( $_GET['course_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preselection.
			$course_id = absint( wp_unslash( $_GET['course_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( self::can_export( $course_id ) ) {
				$selected = $course_id;
			}
		}

		$courses = get_posts(
			array(
				'post_type'      => 'sfwd-courses',
				'post_status'    => array( 'publish', 'draft' ),
				'orderby'        => 'title',
				'order'          => 'ASC',
				'posts_per_page' => -1,
			)
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Export to LMSable', 'lmsable-migrator-for-learndash' ) . '</h1>';
		echo '<p>' . esc_html__( 'Choose a LearnDash course to preview how it maps to LMSable modules and lessons. Files are generated locally; nothing is sent anywhere.', 'lmsable-migrator-for-learndash' ) . '</p>';

		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( self::page_url() ) . '">';
		wp_nonce_field( 'lmfl_preview', 'lmfl_preview_nonce' );
		echo '<label for="lmfl-course" class="screen-reader-text">' . esc_html__( 'Course', 'lmsable-migrator-for-learndash' ) . '</label>';
		echo '<select id="lmfl-course" name="course_id">';
		echo '<option value="0">' . esc_html__( '— Select a course —', 'lmsable-migrator-for-learndash' ) . '</option>';
		foreach ( $courses as $course ) {
			$label = get_the_title( $course );
			if ( 'draft' === $course->post_status ) {
				/* translators: %s: course title. */
				$label = sprintf( __( '%s (draft)', 'lmsable-migrator-for-learndash' ), $label );
			}
			printf(
				'<option value="%d"%s>%s</option>',
				absint( $course->ID ),
				selected( $selected, $course->ID, false ),
				esc_html( wp_strip_all_tags( $label ) )
			);
		}
		echo '</select> ';
		submit_button( __( 'Preview', 'lmsable-migrator-for-learndash' ), 'secondary', 'lmfl_preview', false );
		echo '</form>';

		if ( is_array( $data ) ) {
			self::render_preview( $data, $selected );
		}

		echo '</div>';
	}

	/**
	 * Renders the preview, download buttons and manual-migration list.
	 *
	 * @param array $data      Exporter data.
	 * @param int   $course_id Course id.
	 */
	private static function render_preview( array $data, $course_id ) {
		$stats = $data['stats'];

		echo '<hr />';
		/* translators: %s: course title. */
		echo '<h2>' . esc_html( sprintf( __( 'Preview: %s', 'lmsable-migrator-for-learndash' ), get_the_title( $course_id ) ) ) . '</h2>';

		foreach ( $data['errors'] as $msg ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $msg ) . '</p></div>';
		}

		echo '<p>';
		printf(
			/* translators: 1: modules, 2: lessons, 3: text lessons, 4: videos, 5: quizzes, 6: final tests. */
			esc_html__( 'Modules: %1$d · Lessons: %2$d (text: %3$d, video: %4$d, quiz: %5$d, test: %6$d)', 'lmsable-migrator-for-learndash' ),
			absint( $stats['modules'] ),
			absint( $stats['items'] ),
			absint( $stats['lesson'] ),
			absint( $stats['video'] ),
			absint( $stats['quiz'] ),
			absint( $stats['test'] )
		);
		echo '</p>';

		if ( $stats['items'] > self::PRO_CAP ) {
			/* translators: 1: lesson count, 2: Pro limit. */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'This course has %1$d lessons, more than the LMSable Pro plan limit of %2$d lessons per course. Consider splitting it.', 'lmsable-migrator-for-learndash' ), $stats['items'], self::PRO_CAP ) ) . '</p></div>';
		} elseif ( $stats['items'] > self::FREE_CAP ) {
			/* translators: 1: lesson count, 2: Free limit. */
			echo '<div class="notice notice-info inline"><p>' . esc_html( sprintf( __( 'This course has %1$d lessons, more than the LMSable Free plan limit of %2$d lessons per course (information only).', 'lmsable-migrator-for-learndash' ), $stats['items'], self::FREE_CAP ) ) . '</p></div>';
		}

		if ( $data['modules'] ) {
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Module / lesson', 'lmsable-migrator-for-learndash' ) . '</th>';
			echo '<th>' . esc_html__( 'Type', 'lmsable-migrator-for-learndash' ) . '</th>';
			echo '<th>' . esc_html__( 'Time', 'lmsable-migrator-for-learndash' ) . '</th>';
			echo '<th>' . esc_html__( 'Content length', 'lmsable-migrator-for-learndash' ) . '</th>';
			echo '<th>' . esc_html__( 'Notes', 'lmsable-migrator-for-learndash' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $data['modules'] as $module ) {
				echo '<tr><td colspan="5"><strong>' . esc_html( $module['title'] ) . '</strong></td></tr>';
				foreach ( $module['lessons'] as $item ) {
					$length = isset( $item['content'] ) ? ( function_exists( 'mb_strlen' ) ? mb_strlen( $item['content'], 'UTF-8' ) : strlen( $item['content'] ) ) : 0;
					$notes  = $item['_notes'];
					if ( isset( $item['_quiz_stats'] ) ) {
						/* translators: 1: exported questions, 2: skipped questions. */
						array_unshift( $notes, sprintf( __( 'Questions exported: %1$d, skipped: %2$d', 'lmsable-migrator-for-learndash' ), $item['_quiz_stats']['exported'], $item['_quiz_stats']['skipped'] ) );
					}
					echo '<tr>';
					echo '<td>&nbsp;&nbsp;&nbsp;' . esc_html( $item['title'] ) . '</td>';
					echo '<td>' . esc_html( $item['type'] ) . '</td>';
					echo '<td>' . esc_html( $item['time'] ) . '</td>';
					echo '<td>' . esc_html( number_format_i18n( $length ) ) . '</td>';
					echo '<td>' . esc_html( implode( '; ', $notes ) ) . '</td>';
					echo '</tr>';
				}
			}
			echo '</tbody></table>';
		}

		if ( '' !== $data['json'] && $data['modules'] ) {
			$source       = new LMFL_Source_Export();
			$source_len   = $source->length( $source->markdown( $data ) );
			$source_parts = count( $source->parts( $data ) );
			// Video-heavy courses keep their structure; text courses gain from an AI rebuild.
			$mode = $stats['video'] > $source->count_text_lessons( $data ) ? 'toc' : 'source';

			$modes = array(
				'toc'    => array(
					'title' => __( 'Faithful structure (TOC import)', 'lmsable-migrator-for-learndash' ),
					'desc'  => __( 'Maps modules and lessons 1:1, video lessons keep their links. Best for video and quiz courses.', 'lmsable-migrator-for-learndash' ),
				),
				'source' => array(
					'title' => __( 'Content for AI rebuild (source material)', 'lmsable-migrator-for-learndash' ),
					'desc'  => __( 'Plain text of the course; LMSable AI builds a new course from it (requires the Pro plan in LMSable). Best for text courses.', 'lmsable-migrator-for-learndash' ),
				),
			);

			echo '<h2>' . esc_html__( 'Export', 'lmsable-migrator-for-learndash' ) . '</h2>';
			echo '<fieldset><legend><strong>' . esc_html__( 'Export mode', 'lmsable-migrator-for-learndash' ) . '</strong></legend>';
			foreach ( $modes as $key => $info ) {
				echo '<label style="display:block;max-width:640px;margin:8px 0;padding:12px;border:1px solid #c3c4c7;background:#fff">';
				echo '<input type="radio" name="lmfl_mode" value="' . esc_attr( $key ) . '"' . checked( $mode, $key, false ) . ' /> ';
				echo '<strong>' . esc_html( $info['title'] ) . '</strong>';
				if ( $mode === $key ) {
					echo ' <em>' . esc_html__( 'Recommended for this course', 'lmsable-migrator-for-learndash' ) . '</em>';
				}
				echo '<br /><span class="description">' . esc_html( $info['desc'] ) . '</span>';
				echo '</label>';
			}
			echo '</fieldset>';

			echo '<form method="post" action="' . esc_url( self::page_url() ) . '">';
			wp_nonce_field( 'lmfl_download', 'lmfl_download_nonce' );
			echo '<input type="hidden" name="course_id" value="' . esc_attr( (string) absint( $course_id ) ) . '" />';

			echo '<div id="lmfl-mode-source"' . ( 'source' === $mode ? '' : ' hidden' ) . '>';
			echo '<p>';
			if ( $source_parts > 1 ) {
				/* translators: 1: character count, 2: limit, 3: number of parts. */
				echo esc_html( sprintf( __( 'Source material: %1$s characters — exceeds the %2$s limit, the download splits it into %3$d parts.', 'lmsable-migrator-for-learndash' ), number_format_i18n( $source_len ), number_format_i18n( LMFL_Source_Export::SOURCE_LIMIT ), $source_parts ) );
			} else {
				/* translators: 1: character count, 2: limit. */
				echo esc_html( sprintf( __( 'Source material: %1$s characters — fits the %2$s limit.', 'lmsable-migrator-for-learndash' ), number_format_i18n( $source_len ), number_format_i18n( LMFL_Source_Export::SOURCE_LIMIT ) ) );
			}
			echo '</p>';
			echo '<button type="submit" class="button button-primary" name="lmfl_download" value="source">';
			echo ( $source_parts > 1 && class_exists( 'ZipArchive' ) )
				? esc_html__( 'Download source material (.zip)', 'lmsable-migrator-for-learndash' )
				: esc_html__( 'Download source material (.md)', 'lmsable-migrator-for-learndash' );
			echo '</button>';
			echo '</div>';

			echo '<div id="lmfl-mode-toc"' . ( 'toc' === $mode ? '' : ' hidden' ) . '>';
			echo '<button type="submit" class="button button-primary" name="lmfl_download" value="toc">' . esc_html__( 'Download TOC JSON', 'lmsable-migrator-for-learndash' ) . '</button> ';
			echo '<button type="submit" class="button" name="lmfl_download" value="report">' . esc_html__( 'Download migration report', 'lmsable-migrator-for-learndash' ) . '</button> ';
			if ( class_exists( 'ZipArchive' ) ) {
				echo '<button type="submit" class="button" name="lmfl_download" value="package">' . esc_html__( 'Download content package (.zip)', 'lmsable-migrator-for-learndash' ) . '</button>';
			} else {
				echo '<button type="submit" class="button" name="lmfl_download" value="content">' . esc_html__( 'Download content JSON', 'lmsable-migrator-for-learndash' ) . '</button>';
			}

			echo '<p><label for="lmfl-toc-json"><strong>' . esc_html__( 'TOC JSON', 'lmsable-migrator-for-learndash' ) . '</strong></label></p>';
			echo '<textarea id="lmfl-toc-json" class="large-text code" rows="12" readonly>' . esc_textarea( $data['json'] ) . '</textarea>';
			echo '<p><button type="button" class="button" id="lmfl-copy" data-copied="' . esc_attr__( 'Copied.', 'lmsable-migrator-for-learndash' ) . '">' . esc_html__( 'Copy JSON to clipboard', 'lmsable-migrator-for-learndash' ) . '</button> <span id="lmfl-copy-status" aria-live="polite"></span></p>';
			echo '</div>';
			echo '</form>';
		}

		echo '<h2>' . esc_html__( 'Requires manual migration', 'lmsable-migrator-for-learndash' ) . '</h2>';
		if ( ! $data['manual'] ) {
			echo '<p>' . esc_html__( 'Nothing found that needs manual migration.', 'lmsable-migrator-for-learndash' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $data['manual'] as $entry ) {
				echo '<li><strong>' . esc_html( $entry['context'] ) . '</strong>';
				if ( '' !== $entry['type'] ) {
					echo ' (' . esc_html( $entry['type'] ) . ')';
				}
				echo '<ul style="list-style:disc;margin-left:2em">';
				foreach ( $entry['notes'] as $note ) {
					echo '<li>' . esc_html( $note ) . '</li>';
				}
				echo '</ul></li>';
			}
			echo '</ul>';
		}

		echo '<h2>' . esc_html__( 'Next steps', 'lmsable-migrator-for-learndash' ) . '</h2>';
		echo '<ol>';
		printf(
			'<li>%s <a href="%s" target="_blank" rel="noopener noreferrer">lmsable.com</a></li>',
			esc_html__( 'Create a project at', 'lmsable-migrator-for-learndash' ),
			esc_url( LMFL_Exporter::SITE_URL )
		);
		echo '<li>' . esc_html__( 'In the Lessons step choose Import and paste the TOC JSON.', 'lmsable-migrator-for-learndash' ) . '</li>';
		echo '<li>' . esc_html__( 'Complete the items listed under "Requires manual migration".', 'lmsable-migrator-for-learndash' ) . '</li>';
		echo '</ol>';
	}
}
