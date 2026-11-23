<?php
/**
 * Plugin Name: Alba
 * Plugin URI: https://www.madsbott.com
 * Description: Lightweight multilingual plugin: language URLs, translation editor, XLIFF, translator role, per-language sitemaps and WPML import.
 * Version: 0.12.0
 * Author: Mads
 * Author URI: https://www.madsbott.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: alba
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 5.9
 */

if (!defined('ABSPATH')) {
	exit;
}

// Umzug von „Mads Multilang“ (Ordner mads-multilang): Die alte Version hält dieselben Daten und darf nicht gleichzeitig laufen (Konstante MML_VERSION).
// Ist die alte Version noch aktiv, lädt Alba nicht und deaktiviert die alte Version direkt nach der Aktivierung (Daten bleiben erhalten).
if (defined('MML_VERSION')) {
	add_action('activated_plugin', function ($plugin) {
		if ($plugin === 'alba/alba.php') {
			deactivate_plugins('mads-multilang/mads-multilang.php', true);
		}
	});
	add_action('admin_notices', function () {
		echo '<div class="notice notice-warning"><p><strong>Alba:</strong> ' . esc_html__('The old version "Mads Multilang" is still active. Please deactivate it (do not delete it) and then activate Alba. Settings and translations are kept.', 'alba') . '</p></div>';
	});
	return;
}

define('ALBA_VERSION', '0.12.0');
define('ALBA_FILE', __FILE__);
define('ALBA_DIR', plugin_dir_path(__FILE__));
define('ALBA_URL', plugin_dir_url(__FILE__));

require_once ALBA_DIR . 'includes/class-alba-i18n.php';
require_once ALBA_DIR . 'includes/class-alba-migrate.php';
require_once ALBA_DIR . 'includes/class-alba-core.php';
require_once ALBA_DIR . 'includes/class-alba-frontend.php';
require_once ALBA_DIR . 'includes/class-alba-admin.php';
require_once ALBA_DIR . 'includes/class-alba-strings.php';
require_once ALBA_DIR . 'includes/class-alba-terms.php';
require_once ALBA_DIR . 'includes/class-alba-po.php';
require_once ALBA_DIR . 'includes/class-alba-blocks.php';
require_once ALBA_DIR . 'includes/class-alba-switcher-block.php';
require_once ALBA_DIR . 'includes/class-alba-editor.php';
require_once ALBA_DIR . 'includes/class-alba-cache.php';
require_once ALBA_DIR . 'includes/class-alba-dashboard.php';
require_once ALBA_DIR . 'includes/class-alba-detect.php';
require_once ALBA_DIR . 'includes/class-alba-glossary.php';
require_once ALBA_DIR . 'includes/class-alba-mt.php';
require_once ALBA_DIR . 'includes/class-alba-log.php';
require_once ALBA_DIR . 'includes/class-alba-xliff.php';
require_once ALBA_DIR . 'includes/class-alba-areas.php';
require_once ALBA_DIR . 'includes/class-alba-roles.php';
require_once ALBA_DIR . 'includes/class-alba-seo.php';
require_once ALBA_DIR . 'includes/class-alba-workflow.php';
require_once ALBA_DIR . 'includes/class-alba-bulk.php';
require_once ALBA_DIR . 'includes/class-alba-forms.php';
require_once ALBA_DIR . 'includes/class-alba-media.php';
require_once ALBA_DIR . 'includes/class-alba-wpml.php';
// Eigener Updater (GitHub/JSON). In der Variante für wordpress.org fehlt die Datei; dort aktualisiert wordpress.org.
if (file_exists(ALBA_DIR . 'includes/class-alba-updater.php')) {
	require_once ALBA_DIR . 'includes/class-alba-updater.php';
}

function alba() {
	return ALBA_Core::instance();
}

// Template-Funktionen für Themes
function alba_current_lang() {
	return alba()->lang;
}

function alba_get_translation($post_id, $lang) {
	return alba()->translation((int) $post_id, $lang);
}

function alba_get_term_translation($term_id, $lang) {
	return alba()->terms ? alba()->terms->translation((int) $term_id, $lang) : 0;
}

function alba_switcher($args = []) {
	return alba()->frontend ? alba()->frontend->render($args) : '';
}

if (class_exists('ALBA_Updater')) {
	new ALBA_Updater();
}

new ALBA_I18n();

add_action('plugins_loaded', function () {
	ALBA_Migrate::maybe_run();
	alba()->boot();
	new ALBA_Roles(alba());
	new ALBA_Workflow(alba());
	new ALBA_Forms(alba());   // vor den Zeichenfolgen: setzt bei AJAX/REST-Absendungen die Sprache aus dem Referer
	new ALBA_Editor(alba());
	new ALBA_Frontend(alba());
	new ALBA_Terms(alba());
	new ALBA_Strings(alba());
	new ALBA_Po(alba());
	new ALBA_Blocks(alba());
	new ALBA_Switcher_Block(alba());
	new ALBA_Dashboard(alba());
	new ALBA_Detect(alba());
	new ALBA_Areas(alba());
	new ALBA_Seo(alba());
	new ALBA_Media(alba());
	if (is_admin()) {
		new ALBA_Glossary(alba());
		new ALBA_MT(alba());
		new ALBA_Bulk(alba());
		new ALBA_Wpml(alba());
		new ALBA_Log(alba());
		new ALBA_Xliff(alba());
		new ALBA_Cache(alba());
		new ALBA_Admin(alba());
	}
}, 1);
