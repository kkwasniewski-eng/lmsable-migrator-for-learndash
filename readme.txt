=== LMSable Migrator for LearnDash ===
Contributors: etechnologie
Tags: learndash, migration, export, scorm, course
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export a LearnDash course structure and text content to LMSable, with a report of what needs manual migration.

== Description ==

LMSable Migrator for LearnDash exports a LearnDash course so you can rebuild it in the [LMSable Course Builder](https://lmsable.com/course) as a portable SCORM 1.2 package. It adds an "Export to LMSable" screen to the LearnDash menu and a row action on the course list.

**What it exports**

* Lessons, topics and quizzes mapped to LMSable modules and lessons, in course-builder order.
* Lesson text (Gutenberg, Classic and common Elementor widgets) as clean paragraphs.
* Single and multiple choice quiz questions, with the pass mark from LearnDash.
* Video links (YouTube, Vimeo, Loom) detected from LearnDash video progression settings.

**What you download**

* A TOC JSON file you paste into the LMSable Course Builder (Lessons step, Import).
* A content package (.zip): lesson HTML, video links, quiz questions and an INSTRUCTIONS.md describing how to fill the lessons with Claude and the LMSable connector (MCP).
* A migration report (Markdown) listing everything that needs manual work: images, attachments, H5P, SCORM packages, unsupported quiz question types and removed shortcodes.

**Privacy**

Everything is generated locally on your server when you click a button. The plugin makes no network requests, sends no data anywhere, stores nothing in the database and requires no account. Your course content stays yours.

LMSable is a separate service at lmsable.com where you import the exported files yourself. This plugin is developed by eTechnologie, the maker of LMSable, and is not affiliated with or endorsed by LearnDash LLC. "LearnDash" is a trademark of its respective owner and is used only to describe compatibility.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it from the Plugins screen.
2. Activate it. LearnDash LMS must be active, otherwise the plugin only shows a notice.
3. Go to LearnDash LMS → Export to LMSable, pick a course and click Preview.

== Frequently Asked Questions ==

= Does the plugin send my course content anywhere? =

No. All files are generated on your own server and downloaded by your browser. There are no API calls, no tracking and no account requirement.

= What happens to SCORM, H5P and images? =

They cannot be converted automatically. The migration report lists each of them per lesson so you can re-upload or rebuild them in LMSable.

= Which quiz question types are exported? =

Single choice and multiple choice. Free text, sorting, matrix, cloze, essay and assessment questions are skipped and listed in the report.

= Does it work with Elementor? =

Text editor, heading, icon list, toggle and accordion widgets are exported. Other widgets are listed in the report.

= Do I need an LMSable account? =

Only to import the course on lmsable.com. The free plan is enough for courses up to 20 lessons; the plugin itself never asks for an account.

== Screenshots ==

1. Export screen: course preview with modules, lessons, types and notes.
2. Download buttons: TOC JSON, migration report and content package.
3. Migration report listing items that need manual work.

== Changelog ==

= 0.2.0 =
* Content package: lesson HTML (LMSable tag whitelist), video URLs and notes per lesson, downloadable as a .zip with INSTRUCTIONS.md for Claude + LMSable MCP (or as JSON without ZipArchive).
* Quiz export: single and multiple choice ProQuiz questions in the LMSable quiz format; other types are skipped and reported.
* Elementor: text, headings, icon lists, toggles and accordions are now exported; other widgets are reported.
* Preview shows exported/skipped question counts per quiz.

= 0.1.0 =
* First release: TOC JSON export, migration report, course row action.

== Upgrade Notice ==

= 0.2.0 =
Adds the content package (lesson HTML + quiz questions) and Elementor support. Exports from 0.1.0 stay valid.
