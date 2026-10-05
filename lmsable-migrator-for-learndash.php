<?php
/**
 * Plugin Name:       LMSable Migrator for LearnDash
 * Description:       Exports a LearnDash course as an LMSable TOC JSON and a migration report. Everything is generated locally; nothing is sent anywhere.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            eTechnologie
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lmsable-migrator-for-learndash
 *
 * @package LMSable_Migrator_For_LearnDash
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LMFL_VERSION', '0.2.0' );
define( 'LMFL_DIR', plugin_dir_path( __FILE__ ) );

require_once LMFL_DIR . 'includes/class-content-pipeline.php';
require_once LMFL_DIR . 'includes/class-course-mapper.php';
require_once LMFL_DIR . 'includes/class-content-package.php';
require_once LMFL_DIR . 'includes/class-exporter.php';
require_once LMFL_DIR . 'includes/class-admin-page.php';

add_action( 'plugins_loaded', 'lmfl_init' );

/**
 * Boots the plugin only when LearnDash is active.
 */
function lmfl_init() {
	if ( ! defined( 'LEARNDASH_VERSION' ) ) {
		add_action( 'admin_notices', 'lmfl_missing_learndash_notice' );
		return;
	}
	LMFL_Admin_Page::init();
}

/**
 * Admin notice shown when LearnDash is not active.
 */
function lmfl_missing_learndash_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>' . esc_html__( 'LMSable Migrator for LearnDash requires LearnDash LMS to be active. The plugin does nothing until LearnDash is activated.', 'lmsable-migrator-for-learndash' ) . '</p></div>';
}
