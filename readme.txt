=== LMSable Migrator for LearnDash ===
Contributors: etechnologie
Tags: learndash, lms, export, migration, course
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export a LearnDash course structure and text content to LMSable, with a report of what needs manual migration.

== Description ==

LMSable Migrator for LearnDash adds an "Export to LMSable" screen to the LearnDash menu (and a row action on the course list).

* Maps lessons, topics and quizzes to LMSable modules and lessons.
* Converts lesson content to plain text with paragraphs.
* Downloads a TOC JSON file ready to paste into the LMSable Course Builder (Lessons step, Import).
* Downloads a migration report (Markdown) listing images, attachments, videos, H5P, SCORM and shortcodes that need manual work.

Everything is generated locally. The plugin sends no data anywhere, stores nothing in the database and requires no account.

A content package (.zip with lesson HTML, video links, single/multiple choice quiz questions and INSTRUCTIONS.md) can be used with Claude and the LMSable connector to fill the lessons. Other quiz question types are listed in the report.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it from the Plugins screen.
2. Activate it. LearnDash LMS must be active.
3. Go to LearnDash LMS > Export to LMSable.

== Changelog ==

= 0.2.0 =
* Content package: lesson HTML (LMSable tag whitelist), video URLs and notes per lesson, downloadable as a .zip with INSTRUCTIONS.md for Claude + LMSable MCP (or as JSON without ZipArchive).
* Quiz export: single and multiple choice ProQuiz questions in the LMSable quiz format; other types are skipped and reported.
* Elementor: text, headings, icon lists, toggles and accordions are now exported; other widgets are reported.
* Preview shows exported/skipped question counts per quiz.

= 0.1.0 =
* First release: TOC JSON export, migration report, course row action.
