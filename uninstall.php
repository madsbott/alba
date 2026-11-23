<?php
/**
 * Executed by WordPress when the plugin is deleted in the plugin list
 * (not on deactivation and not when replacing it with an uploaded new version).
 *
 * Setting: Languages > Settings > Uninstall (ask | delete | keep).
 * Removes settings, strings and the translation data (meta) on posts and terms.
 * Own translation posts (copies) remain as normal content.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

function alba_uninstall_site() {
	global $wpdb;

	if (get_option('alba_uninstall', 'ask') === 'keep') {
		return;
	}

	$options = [
		'alba_languages', 'alba_default', 'alba_post_types', 'alba_taxonomies', 'alba_update_url',
		'alba_menu_location', 'alba_display', 'alba_hide_missing', 'alba_menu_style', 'alba_dd_arrow',
		'alba_nav_switcher', 'alba_strings', 'alba_scan', 'alba_scan_domains', 'alba_version', 'alba_uninstall', 'alba_cache_purge', 'alba_autodetect', 'alba_autodetect_method', 'alba_glossary', 'alba_tm', 'alba_t_attrs', 'alba_t_blocks', 'alba_t_acf', 'alba_meta_keys', 'alba_extra_attrs', 'alba_mt_provider', 'alba_mt_email', 'alba_mt_url', 'alba_mt_key', 'alba_log_db', 'alba_log_days', 'alba_open_cache', 'alba_search', 'alba_comments', 'alba_menu_map', 'alba_srch_v', 'alba_cmt_v', 'alba_translator_publish', 'alba_forms', 'alba_wf_mail', 'alba_wf_cc', 'alba_sitemap', 'alba_ogloc', 'alba_sm_v', 'alba_roles_v',
	];
	foreach ($options as $o) {
		delete_option($o);
	}
	delete_site_transient('alba_update_info');

	wp_clear_scheduled_hook('alba_wf_daily');

	// Leftovers of older versions and caches
	$wpdb->query($wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like('alba_deepl_') . '%',
		$wpdb->esc_like('_transient_alba_') . '%',
		$wpdb->esc_like('_transient_timeout_alba_') . '%'
	));

	// Translation data on posts (language, group, editor translations) and terms
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like('_alba_') . '%'));
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $wpdb->esc_like('_alba_') . '%'));

	remove_role('alba_translator');
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", 'alba_langs'));

	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->commentmeta} WHERE meta_key = %s", '_alba_lang'));

	$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'alba_log');

	// Leftovers of versions before 0.12.0 (old prefix), in case the data was never migrated
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('mml_') . '%'));
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like('_mml_') . '%'));
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $wpdb->esc_like('_mml_') . '%'));
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", 'mml_langs'));
	remove_role('mml_translator');
	$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'mml_log');

	delete_option('alba_db_version');
	wp_cache_flush();
}

if (is_multisite()) {
	foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $alba_site_id) {
		switch_to_blog((int) $alba_site_id);
		alba_uninstall_site();
		restore_current_blog();
	}
} else {
	alba_uninstall_site();
}
