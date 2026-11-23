<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Clear the page cache when translations or language settings change.
 *
 * Supported: WP Rocket, LiteSpeed Cache (plugin) and LiteSpeed server cache via header
 * (z. B. Hostinger), W3 Total Cache, WP Super Cache, WP Fastest Cache, Breeze, SG Optimizer, Nginx Helper.
 * Own integration: action `alba_purge_all` or `alba_purge_urls` (array of URLs).
 *
 * Setting (Languages > Settings > Cache): smart (page, translations, home pages) | all | off
 */
class ALBA_Cache {
	private $core;
	private $all_done = false;

	/** Settings whose change clears the whole cache */
	private static $options = [
		'alba_languages', 'alba_default', 'alba_post_types', 'alba_taxonomies', 'alba_menu_location', 'alba_display',
		'alba_hide_missing', 'alba_autodetect', 'alba_autodetect_method', 'alba_t_attrs', 'alba_t_blocks', 'alba_t_acf', 'alba_meta_keys', 'alba_extra_attrs', 'alba_menu_style', 'alba_dd_arrow', 'alba_nav_switcher', 'alba_strings', 'alba_search', 'alba_comments', 'alba_menu_map',
	];

	public function __construct(ALBA_Core $core) {
		$this->core = $core;
		$core->page_cache = $this;

		add_action('alba_translation_saved', [$this, 'on_post'], 10, 1);
		add_action('alba_post_links_saved', [$this, 'on_post'], 10, 1);
		add_action('updated_option', [$this, 'on_option'], 10, 1);
		add_action('added_option', [$this, 'on_option'], 10, 1);
		add_action('admin_post_alba_purge_cache', [$this, 'handle_purge']);
	}

	public static function mode() {
		$m = get_option('alba_cache_purge', 'smart');
		return in_array($m, ['all', 'off'], true) ? $m : 'smart';
	}

	public static function purge_url() {
		return wp_nonce_url(admin_url('admin-post.php?action=alba_purge_cache'), 'alba_purge_cache');
	}

	/* ---------------------------------------------------------------
	 * Triggers
	 * ------------------------------------------------------------- */

	public function on_post($post_id) {
		$mode = self::mode();
		if ($mode === 'off') {
			return;
		}
		if ($mode === 'all') {
			$this->purge_all();
			return;
		}
		$this->purge_post((int) $post_id);
	}

	public function on_option($name) {
		if (!is_admin() || !in_array($name, self::$options, true) || !current_user_can('manage_options') || self::mode() === 'off') {
			return;
		}
		$this->purge_all();
	}

	public function handle_purge() {
		check_admin_referer('alba_purge_cache');
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		$this->all_done = false;
		$this->purge_all();
		wp_safe_redirect(admin_url('admin.php?page=alba-settings&alba_purged=1'));
		exit;
	}

	/* ---------------------------------------------------------------
	 * Leeren
	 * ------------------------------------------------------------- */

	private function is_litespeed() {
		return (isset($_SERVER['SERVER_SOFTWARE']) && stripos(sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])), 'litespeed') !== false)
			|| isset($_SERVER['LSWS_EDITION']) || isset($_SERVER['X_LSCACHE']);
	}

	/** LiteSpeed server cache (without plugin, e.g. Hostinger): clear everything of this site via response header */
	private function ls_header() {
		if (!headers_sent() && $this->is_litespeed()) {
			header('X-LiteSpeed-Purge: *', false);
		}
	}

	/** URLs eines Inhalts in allen Sprachen plus Startseiten (Umschalter, Listen) */
	private function post_urls($id) {
		$c = $this->core;
		$urls = [];
		$link = get_permalink($id);
		foreach ($c->langs as $code => $l) {
			if ($link) {
				$urls[] = $c->prefix_url($link, $code);
				$urls[] = $c->with_lang($code, function () use ($id) {
					return get_permalink($id);
				});
			}
			$urls[] = $c->home_in($code);
		}
		foreach ($c->translations($id) as $code => $tid) {
			if ((int) $tid !== (int) $id) {
				$tl = get_permalink($tid);
				if ($tl) {
					$urls[] = $c->prefix_url($tl, $code);
				}
			}
		}
		return array_values(array_unique(array_filter($urls)));
	}

	public function purge_post($id) {
		$id = (int) $id;
		if (!$id || get_post_status($id) === false) {
			return;
		}
		$urls = $this->post_urls($id);

		// Caches that can clear by exact URL
		if (function_exists('rocket_clean_files')) {
			rocket_clean_files($urls);
		}
		if (defined('LSCWP_V')) {
			foreach ($urls as $u) {
				do_action('litespeed_purge_url', $u); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core or cache plugin hook, intentionally called.
			}
		} else {
			$this->ls_header();
		}
		if (function_exists('w3tc_flush_url')) {
			foreach ($urls as $u) {
				w3tc_flush_url($u);
			}
		}
		if (function_exists('wp_cache_post_change')) {
			wp_cache_post_change($id);
		}
		if (function_exists('wpfc_clear_post_cache_by_id')) {
			wpfc_clear_post_cache_by_id($id);
		}

		// Caches without a URL interface: clear completely
		do_action('breeze_clear_all_cache'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core or cache plugin hook, intentionally called.
		if (function_exists('sg_cachepress_purge_cache')) {
			sg_cachepress_purge_cache();
		}
		do_action('rt_nginx_helper_purge_all'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core or cache plugin hook, intentionally called.

		do_action('alba_purge_urls', $urls, $id);
	}

	public function purge_all() {
		if ($this->all_done) {
			return;
		}
		$this->all_done = true;

		if (function_exists('rocket_clean_domain')) {
			rocket_clean_domain();
		}
		if (defined('LSCWP_V')) {
			do_action('litespeed_purge_all'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core or cache plugin hook, intentionally called.
		} else {
			$this->ls_header();
		}
		if (function_exists('w3tc_flush_all')) {
			w3tc_flush_all();
		}
		if (function_exists('wp_cache_clear_cache')) {
			wp_cache_clear_cache();
		}
		if (function_exists('wpfc_clear_all_cache')) {
			wpfc_clear_all_cache(true);
		}
		do_action('breeze_clear_all_cache'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core or cache plugin hook, intentionally called.
		if (function_exists('sg_cachepress_purge_cache')) {
			sg_cachepress_purge_cache();
		}
		do_action('rt_nginx_helper_purge_all'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core or cache plugin hook, intentionally called.

		do_action('alba_purge_all');
	}
}
