# Alba

Lightweight multilingual plugin for WordPress (own implementation, no WPML code). Status: **beta**.

The admin interface is English (text domain `alba`) and ships with a German translation (`languages/alba-de_DE.po`).

## Installation
1. Upload the ZIP under Plugins > Add New > Upload Plugin and activate it.
2. Languages > Settings: add languages, choose the default language, post types and taxonomies.
3. Save Settings > Permalinks once (pretty permalinks recommended).

## Features
- Language URLs: default language without prefix, others as `/en/`, `/fr/` …
- Posts, pages, custom post types and taxonomies per language, linked via translation groups
- Side-by-side translation editor, XLIFF export/import, .po import/export, glossary and translation memory
- Language switcher: block, shortcode `[alba_switcher]`, template function `alba_switcher()`, menu and block-navigation integration
- hreflang, canonical, og:locale and an XML sitemap per language
- Translator role, assignments with deadlines, review status, email notifications
- Forms (Contact Form 7, WPForms, Gravity Forms, Fluent Forms), media per language
- WPML importer
- Block themes: one navigation for all languages

See `readme.txt` for details, privacy notes (external services) and the changelog.

## Translating
Source strings are English. Update `languages/alba.pot` and copy it to `alba-<locale>.po`.

## License
GPL-2.0-or-later, see `LICENSE`.
