=== Alba ===
Contributors: madsbott
Tags: multilingual, translation, language switcher, hreflang, xliff
Requires at least: 5.9
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.11.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight multilingual plugin with language URLs, translation editor, XLIFF, translator role, per-language sitemaps and WPML import.

== Description ==

**Status: Beta.** The plugin has been tested in a test environment but not yet widely on live sites. Please try it on a copy first and make a database backup beforehand.

Alba makes a WordPress site multilingual without bloating it.

* Languages with directory URLs (`/en/`), default language without prefix, language switcher (block, shortcode, menu)
* Posts, pages, custom post types and taxonomies; translate as a separate post or in the side-by-side translation editor
* Strings (theme and plugin texts, site title, tagline, widget titles), .po import and export
* hreflang, canonical, og:locale and an XML sitemap per language (coordinated with Yoast SEO and Rank Math)
* XLIFF export and import for external translators
* "Translator" role, assignments with deadlines, "Needs review" status, email notifications
* Bulk actions for many posts at once
* Forms: Contact Form 7, WPForms, Gravity Forms, Fluent Forms
* Media: alt text, title, caption and description per language, optionally a different image per language
* Importer for existing WPML data (language assignments, translation groups, menus, media texts, string translations)
* Glossary, translation memory, change log

The interface is in English and ships with a German translation. Other languages can be added via translate.wordpress.org or a .po file.

= External services =

The "Automatic translation suggestions" feature is **off by default** (setting "Off") and only sends texts that you explicitly select for translation in the translation editor or the bulk actions. Depending on the setting, the text is sent to:

* **MyMemory** (api.mymemory.translated.net), operated by Translated S.r.l. — [Privacy policy](https://mymemory.translated.net/doc/privacy.php)
* **a LibreTranslate server** whose address you enter yourself (your own server or a provider of your choice)

The text to be translated and the language pair are transmitted. Please check before use that this is compatible with your data protection obligations (GDPR), especially for personal data.

The GitHub variant can additionally ask GitHub (api.github.com) for updates if you enter an update source there. This feature is not included in the wordpress.org version.

== Installation ==

1. Upload the plugin and activate it.
2. Under "Languages", add your languages and set the default language.
3. Open Settings > Permalinks and click "Save" once.
4. Under "Languages", choose the post types and taxonomies to translate.

= Moving from "Mads Multilang" =

Alba replaces the earlier "Mads Multilang". Activate Alba (the old version is deactivated automatically) and do not delete the old version until everything has been checked. Settings and translations are kept.

= Moving from WPML =

As long as the WPML tables are still in the database, a "WPML import" item appears under "Languages". Do a dry run first, then the import. Make a database backup beforehand.

== Frequently Asked Questions ==

= Is the plugin connected to WPML? =

No. The plugin is independent. "WPML" is a trademark of its owner and is only mentioned here to describe the import feature.

= Which form plugins are supported? =

Contact Form 7, WPForms, Gravity Forms and Fluent Forms. So far only Contact Form 7 has been tested; the other three are connected to the documented hooks and have not yet been checked on real installations.

= What happens when I delete the plugin? =

You decide under "Languages > Settings > Uninstall": remove data (with or without confirmation) or keep it. Your translation posts remain as normal content.

== Changelog ==

= 0.11.8 =
* Output escaping completed for all admin screens; sentences that were split around markup are now whole translatable texts
* Database queries documented and checked

= 0.11.7 =
* Security hardening: request data is sanitised and unslashed consistently, safe redirect for the language redirect, wp_parse_url
* Translator comments for all texts with placeholders

= 0.11.6 =
* Glossary: neutral example term in the input placeholder

= 0.11.5 =
* Strings: texts found in theme files can be added to the table with one click, even if the scan did not collect them
* Strings: higher limit for collected texts and a notice when it is reached

= 0.11.4 =
* Dropdown arrow: "Automatic" now follows the Salient option "header-dropdown-arrows" (value "inherit" counts as on)

= 0.11.3 =
* Strings: "Look for a specific text" shows where a text comes from (theme file, text domain or setting) and can translate texts typed into theme settings
* Dropdown arrow: the settings page lists the related theme options it found

= 0.11.2 =
* Fixed: automatic translation suggestions did not work in the translation editor (script error since 0.11.0)
* Strings: new "Find texts of your theme and plugins" panel with theme/plugin selection and a "Scan now" button
* Dropdown arrow: "Automatic" is more reliable and the settings page shows why an arrow is or is not shown

= 0.11.1 =
* GitHub updater now also finds pre-releases (beta) and accepts "owner/repo" as update source

= 0.11.0 =
* The interface is now in English (text domain `alba`), with a complete German translation

= 0.10.0 =
* The plugin is now called Alba (formerly Mads Multilang)
* New: forms (CF7, WPForms, Gravity Forms, Fluent Forms)
* New: per-language sitemaps, canonical and og:locale
* New: bulk actions, translator workflow, per-language media
* New: WPML importer
* The updater is optional (not included in the wordpress.org variant)

= 0.9.25 =
* "Translator" role

== Upgrade Notice ==

= 0.11.8 =
Hardening release, no feature changes.

= 0.11.7 =
Hardening release, no feature changes.

= 0.11.6 =
Minor text change.

= 0.11.5 =
Makes theme texts such as the Salient search placeholder translatable.

= 0.11.4 =
Fixes the dropdown arrow with Salient.

= 0.11.3 =
Makes it possible to translate texts typed into theme settings (e.g. a search placeholder).

= 0.11.2 =
Fixes the translation suggestions in the editor and makes finding theme texts easier.

= 0.11.1 =
Beta. Fixes update detection for GitHub pre-releases.

= 0.11.0 =
Beta. The admin interface is now English by default; German sites keep German text. Make a database backup before updating.
