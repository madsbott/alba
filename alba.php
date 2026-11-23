<?php
/**
 * Plugin Name: Alba
 * Plugin URI: https://www.madsbott.com
 * Description: Lightweight multilingual plugin: language URLs, translation editor, XLIFF, translator role, per-language sitemaps and WPML import.
 * Version: 0.11.6
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

// Umzug von „Mads Multilang“ (Ordner mads-multilang): Beide Versionen nutzen dieselben Namen und dürfen nicht gleichzeitig laufen.
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

define('MML_VERSION', '0.11.6');
define('MML_FILE', __FILE__);
define('MML_DIR', plugin_dir_path(__FILE__));
define('MML_URL', plugin_dir_url(__FILE__));

require_once MML_DIR . 'includes/class-mml-i18n.php';
require_once MML_DIR . 'includes/class-mml-core.php';
require_once MML_DIR . 'includes/class-mml-frontend.php';
require_once MML_DIR . 'includes/class-mml-admin.php';
require_once MML_DIR . 'includes/class-mml-strings.php';
require_once MML_DIR . 'includes/class-mml-terms.php';
require_once MML_DIR . 'includes/class-mml-po.php';
require_once MML_DIR . 'includes/class-mml-blocks.php';
require_once MML_DIR . 'includes/class-mml-switcher-block.php';
require_once MML_DIR . 'includes/class-mml-editor.php';
require_once MML_DIR . 'includes/class-mml-cache.php';
require_once MML_DIR . 'includes/class-mml-dashboard.php';
require_once MML_DIR . 'includes/class-mml-detect.php';
require_once MML_DIR . 'includes/class-mml-glossary.php';
require_once MML_DIR . 'includes/class-mml-mt.php';
require_once MML_DIR . 'includes/class-mml-log.php';
require_once MML_DIR . 'includes/class-mml-xliff.php';
require_once MML_DIR . 'includes/class-mml-areas.php';
require_once MML_DIR . 'includes/class-mml-roles.php';
require_once MML_DIR . 'includes/class-mml-seo.php';
require_once MML_DIR . 'includes/class-mml-workflow.php';
require_once MML_DIR . 'includes/class-mml-bulk.php';
require_once MML_DIR . 'includes/class-mml-forms.php';
require_once MML_DIR . 'includes/class-mml-media.php';
require_once MML_DIR . 'includes/class-mml-wpml.php';
// Eigener Updater (GitHub/JSON). In der Variante für wordpress.org fehlt die Datei; dort aktualisiert wordpress.org.
if (file_exists(MML_DIR . 'includes/class-mml-updater.php')) {
	require_once MML_DIR . 'includes/class-mml-updater.php';
}

function mml() {
	return MML_Core::instance();
}

// Template-Funktionen für Themes
function mml_current_lang() {
	return mml()->lang;
}

function mml_get_translation($post_id, $lang) {
	return mml()->translation((int) $post_id, $lang);
}

function mml_get_term_translation($term_id, $lang) {
	return mml()->terms ? mml()->terms->translation((int) $term_id, $lang) : 0;
}

function mml_switcher($args = []) {
	return mml()->frontend ? mml()->frontend->render($args) : '';
}

if (class_exists('MML_Updater')) {
	new MML_Updater();
}

new MML_I18n();

add_action('plugins_loaded', function () {
	mml()->boot();
	new MML_Roles(mml());
	new MML_Workflow(mml());
	new MML_Forms(mml());   // vor den Zeichenfolgen: setzt bei AJAX/REST-Absendungen die Sprache aus dem Referer
	new MML_Editor(mml());
	new MML_Frontend(mml());
	new MML_Terms(mml());
	new MML_Strings(mml());
	new MML_Po(mml());
	new MML_Blocks(mml());
	new MML_Switcher_Block(mml());
	new MML_Dashboard(mml());
	new MML_Detect(mml());
	new MML_Areas(mml());
	new MML_Seo(mml());
	new MML_Media(mml());
	if (is_admin()) {
		new MML_Glossary(mml());
		new MML_MT(mml());
		new MML_Bulk(mml());
		new MML_Wpml(mml());
		new MML_Log(mml());
		new MML_Xliff(mml());
		new MML_Cache(mml());
		new MML_Admin(mml());
	}
}, 1);
