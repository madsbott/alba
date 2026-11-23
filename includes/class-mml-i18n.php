<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Übersetzungen: lädt die Textdomain „alba“ (Ordner languages/) und stellt den JavaScript-Dateien ihre Texte bereit.
 * Quelltexte sind englisch; die deutsche Übersetzung liegt als alba-de_DE.mo bei.
 */
class MML_I18n {
	public function __construct() {
		add_action('init', [$this, 'load'], 0);
		add_action('init', [$this, 'register'], 1);
	}

	public function load() {
		load_plugin_textdomain('alba', false, dirname(plugin_basename(MML_FILE)) . '/languages');
	}

	/** Skript-Handle „mml-i18n“: setzt window.mmlI18n (englischer Text => übersetzter Text) für assets/*.js */
	public function register() {
		wp_register_script('mml-i18n', false, [], MML_VERSION, false);
		wp_add_inline_script('mml-i18n', 'window.mmlI18n = ' . wp_json_encode($this->js()) . ';', 'after');
	}

	private function js() {
		return [
			__('Choose image', 'alba') => __('Choose image', 'alba'),
			__('Nothing to translate.', 'alba') => __('Nothing to translate.', 'alba'),
			__('Suggestions inserted, please check and save.', 'alba') => __('Suggestions inserted, please check and save.', 'alba'),
			__('Error.', 'alba') => __('Error.', 'alba'),
			__('Network error.', 'alba') => __('Network error.', 'alba'),
			__('Language switcher', 'alba') => __('Language switcher', 'alba'),
			__('Appearance', 'alba') => __('Appearance', 'alba'),
			__('List of links', 'alba') => __('List of links', 'alba'),
			__('Dropdown (select field)', 'alba') => __('Dropdown (select field)', 'alba'),
			__('Dropdown menu (submenu)', 'alba') => __('Dropdown menu (submenu)', 'alba'),
			__('Label', 'alba') => __('Label', 'alba'),
			__('Language name (full)', 'alba') => __('Language name (full)', 'alba'),
			__('Abbreviation (DE)', 'alba') => __('Abbreviation (DE)', 'alba'),
			__('Hide current language', 'alba') => __('Hide current language', 'alba'),
			__('Hide languages without translation', 'alba') => __('Hide languages without translation', 'alba'),
			__('Only affects the frontend, not this preview.', 'alba') => __('Only affects the frontend, not this preview.', 'alba'),
			__('Links or a dropdown for switching the language.', 'alba') => __('Links or a dropdown for switching the language.', 'alba'),
			__('Language', 'alba') => __('Language', 'alba'),
			__('Fetching suggestion …', 'alba') => __('Fetching suggestion …', 'alba'),
			__('No suggestion:', 'alba') => __('No suggestion:', 'alba'),
			__('Apply', 'alba') => __('Apply', 'alba'),
			__('Discard', 'alba') => __('Discard', 'alba'),
			__('Unknown error.', 'alba') => __('Unknown error.', 'alba'),
			__('The service is not reachable.', 'alba') => __('The service is not reachable.', 'alba'),
			/* translators: 1: translated count, 2: total count */ __('%1$s of %2$s translated', 'alba') => /* translators: 1: translated count, 2: total count */ __('%1$s of %2$s translated', 'alba'),
			/* translators: %s: name of the translation service */ __('Automatic suggestion (%s) – please check:', 'alba') => /* translators: %s: name of the translation service */ __('Automatic suggestion (%s) – please check:', 'alba'),
			__('Languages', 'alba') => __('Languages', 'alba'),
			__('Show block only in these languages. Nothing selected: in all languages.', 'alba') => __('Show block only in these languages. Nothing selected: in all languages.', 'alba'),
		];
	}
}
