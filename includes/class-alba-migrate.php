<?php
/**
 * One-time migration of stored data from the old "mml_" prefix (Alba up to 0.11.x) to "alba_" (0.12.0).
 *
 * Renames options, post/term/comment/user meta, the log table and the translator role, and rewrites the
 * block attribute and widget setting names that are stored in content. Runs once per site and is safe to
 * repeat: every step skips what is already done.
 *
 * @package Alba
 */

if (!defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- One-time schema/data migration; table names come from $wpdb, every value goes through $wpdb->prepare().

class ALBA_Migrate {
	const FLAG = 'alba_db_version';
	const DB   = '1';

	public static function maybe_run() {
		if (get_option(self::FLAG) === self::DB) {
			return;
		}
		self::run();
		update_option(self::FLAG, self::DB, true);
	}

	private static function like($prefix) {
		global $wpdb;
		return $wpdb->esc_like($prefix) . '%';
	}

	public static function run() {
		global $wpdb;

		// 1. Options (skips names that already exist under the new prefix).
		$rows = $wpdb->get_results($wpdb->prepare("SELECT option_id, option_name FROM {$wpdb->options} WHERE option_name LIKE %s", self::like('mml_')));
		$legacy = !empty($rows);
		foreach ((array) $rows as $row) {
			$new = 'alba_' . substr($row->option_name, 4);
			$exists = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $new));
			if ($exists) {
				$wpdb->delete($wpdb->options, ['option_id' => (int) $row->option_id]);
			} else {
				$wpdb->update($wpdb->options, ['option_name' => $new], ['option_id' => (int) $row->option_id]);
			}
		}
		// Cached values (transients) are rebuilt on demand.
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			self::like('_transient_mml_'),
			self::like('_transient_timeout_mml_'),
			self::like('_site_transient_mml_')
		));
		if (!$legacy && !$wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s LIMIT 1", self::like('_mml_')))) {
			// Fresh installation: nothing else to do.
			return;
		}

		// 2. Meta data.
		foreach (['postmeta', 'termmeta', 'commentmeta'] as $t) {
			$table = $wpdb->$t;
			$wpdb->query($wpdb->prepare("UPDATE {$table} SET meta_key = CONCAT('_alba_', SUBSTRING(meta_key, 6)) WHERE meta_key LIKE %s", self::like('_mml_')));
		}
		$wpdb->query($wpdb->prepare("UPDATE {$wpdb->usermeta} SET meta_key = CONCAT('alba_', SUBSTRING(meta_key, 5)) WHERE meta_key LIKE %s", self::like('mml_')));

		// 3. Log table.
		$old = $wpdb->prefix . 'mml_log';
		$new = $wpdb->prefix . 'alba_log';
		if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $old)) === $old && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $new)) !== $new) {
			$wpdb->query("ALTER TABLE `{$old}` RENAME TO `{$new}`");
		}
		if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $new)) !== $new) {
			// Renaming was not possible: the table is created again on the next use.
			delete_option('alba_log_db');
		}

		// 4. Scheduled event.
		wp_clear_scheduled_hook('mml_wf_daily');

		// 5. Stored names in content: block attribute of the language visibility and widget settings.
		$wpdb->query("UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, '\"mmlLangs\"', '\"albaLangs\"') WHERE post_content LIKE '%\"mmlLangs\"%'");
		$wb = get_option('widget_block');
		if (is_array($wb)) {
			$changed = false;
			array_walk_recursive($wb, static function (&$v) use (&$changed) {
				if (is_string($v) && strpos($v, '"mmlLangs"') !== false) {
					$v = str_replace('"mmlLangs"', '"albaLangs"', $v);
					$changed = true;
				}
			});
			if ($changed) {
				update_option('widget_block', $wb);
			}
		}
		$widgets = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s", self::like('widget_'), 'widget_block'));
		foreach ((array) $widgets as $name) {
			$val = get_option($name);
			if (!is_array($val)) {
				continue;
			}
			$changed = false;
			foreach ($val as $k => $inst) {
				if (is_array($inst) && array_key_exists('mml_langs', $inst)) {
					$val[$k]['alba_langs'] = $inst['mml_langs'];
					unset($val[$k]['mml_langs']);
					$changed = true;
				}
			}
			if ($changed) {
				update_option($name, $val);
			}
		}

		// 6. Users with the old translator role move to the new role (done on "init", when roles are available).
		$ids = get_users(['role' => 'mml_translator', 'fields' => 'ID', 'number' => -1]);
		if ($ids) {
			update_option('alba_migrate_users', array_map('intval', $ids), false);
		}
		add_action('init', [__CLASS__, 'move_roles'], 99);

		wp_cache_flush();
	}

	public static function move_roles() {
		$ids = get_option('alba_migrate_users');
		if (is_array($ids)) {
			if (!get_role(ALBA_Roles::ROLE)) {
				add_role(ALBA_Roles::ROLE, __('Translator', 'alba'), ['read' => true, ALBA_Roles::CAP => true]);
			}
			foreach ($ids as $id) {
				$u = get_userdata((int) $id);
				if ($u) {
					$u->add_role(ALBA_Roles::ROLE);
					$u->remove_role('mml_translator');
				}
			}
			delete_option('alba_migrate_users');
		}
		if (get_role('mml_translator')) {
			remove_role('mml_translator');
		}
	}
}
