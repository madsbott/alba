<?php
/**
 * Wird von WordPress ausgeführt, wenn das Plugin in der Plugin-Liste gelöscht wird
 * (nicht beim Deaktivieren und nicht beim Ersetzen durch eine hochgeladene neue Version).
 *
 * Einstellung: Sprachen > Einstellungen > Deinstallation (ask | delete | keep).
 * Entfernt Einstellungen, Zeichenfolgen und die Übersetzungsdaten (Meta) an Beiträgen und Begriffen.
 * Eigene Übersetzungs-Beiträge (Kopien) bleiben als normale Inhalte bestehen.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

function mml_uninstall_site() {
	global $wpdb;

	if (get_option('mml_uninstall', 'ask') === 'keep') {
		return;
	}

	$options = [
		'mml_languages', 'mml_default', 'mml_post_types', 'mml_taxonomies', 'mml_update_url',
		'mml_menu_location', 'mml_display', 'mml_hide_missing', 'mml_menu_style', 'mml_dd_arrow',
		'mml_nav_switcher', 'mml_strings', 'mml_scan', 'mml_scan_domains', 'mml_version', 'mml_uninstall', 'mml_cache_purge', 'mml_autodetect', 'mml_autodetect_method', 'mml_glossary', 'mml_tm', 'mml_t_attrs', 'mml_t_blocks', 'mml_t_acf', 'mml_meta_keys', 'mml_extra_attrs', 'mml_mt_provider', 'mml_mt_email', 'mml_mt_url', 'mml_mt_key', 'mml_log_db', 'mml_log_days', 'mml_open_cache', 'mml_search', 'mml_comments', 'mml_menu_map', 'mml_srch_v', 'mml_cmt_v', 'mml_translator_publish', 'mml_forms', 'mml_wf_mail', 'mml_wf_cc', 'mml_sitemap', 'mml_ogloc', 'mml_sm_v', 'mml_roles_v',
	];
	foreach ($options as $o) {
		delete_option($o);
	}
	delete_site_transient('mml_update_info');

	wp_clear_scheduled_hook('mml_wf_daily');

	// Reste älterer Versionen und Zwischenspeicher
	$wpdb->query($wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like('mml_deepl_') . '%',
		$wpdb->esc_like('_transient_mml_') . '%',
		$wpdb->esc_like('_transient_timeout_mml_') . '%'
	));

	// Übersetzungsdaten an Beiträgen (Sprache, Gruppe, Editor-Übersetzungen) und Begriffen
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like('_mml_') . '%'));
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $wpdb->esc_like('_mml_') . '%'));

	remove_role('mml_translator');
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", 'mml_langs'));

	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->commentmeta} WHERE meta_key = %s", '_mml_lang'));

	$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'mml_log');

	wp_cache_flush();
}

if (is_multisite()) {
	foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $blog_id) {
		switch_to_blog((int) $blog_id);
		mml_uninstall_site();
		restore_current_blog();
	}
} else {
	mml_uninstall_site();
}
