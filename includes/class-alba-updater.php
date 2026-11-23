<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Updates direkt in WordPress (Dashboard > Aktualisierungen / Plugins), ohne neu zu installieren.
 *
 * Update-Quelle (Sprachen > Einstellungen > Updates oder Konstante ALBA_UPDATE_URL in der wp-config.php):
 *  a) GitHub-Repository, z. B. https://github.com/benutzer/alba
 *     -> es wird das neueste Release gelesen; angehängtes .zip-Asset wird bevorzugt
 *  b) URL einer JSON-Datei:
 *     {"version":"0.8.0","download_url":"https://…/alba.zip","changelog":"…","homepage":"https://…",
 *      "requires":"6.0","tested":"6.8","requires_php":"7.4"}
 * Das ZIP muss den Ordner "alba/" enthalten (andere Ordnernamen werden automatisch korrigiert).
 */
class ALBA_Updater {
	const SLUG = 'alba';
	const OPT = 'alba_update_url';
	const CACHE = 'alba_update_info';

	public function __construct() {
		add_filter('pre_set_site_transient_update_plugins', [$this, 'check']);
		add_filter('plugins_api', [$this, 'info'], 20, 3);
		add_filter('upgrader_source_selection', [$this, 'fix_folder'], 10, 4);
		add_action('upgrader_process_complete', [$this, 'after'], 10, 2);

		if (is_admin()) {
			add_action('admin_init', [$this, 'maybe_upgrade']);
			add_action('admin_post_alba_check_update', [$this, 'handle_check']);
			add_filter('plugin_row_meta', [$this, 'row_meta'], 10, 2);
			add_action('admin_notices', [$this, 'notices']);
		}
	}

	/* ---------------------------------------------------------------
	 * Quelle
	 * ------------------------------------------------------------- */

	public static function url() {
		if (defined('ALBA_UPDATE_URL') && ALBA_UPDATE_URL !== '') {
			return (string) ALBA_UPDATE_URL;
		}
		return trim((string) get_option(self::OPT, ''));
	}

	public static function check_url() {
		return wp_nonce_url(admin_url('admin-post.php?action=alba_check_update'), 'alba_check_update');
	}

	private function valid_version($v) {
		return (bool) preg_match('/^\d+(\.\d+){0,3}([\-+.][0-9A-Za-z.\-]+)?$/', $v);
	}

	private function fetch($force = false) {
		$url = self::url();
		if ($url === '') {
			return null;
		}
		if (!$force) {
			$c = get_site_transient(self::CACHE);
			if (is_array($c) && isset($c['url']) && $c['url'] === $url) {
				return $c['info'] ? $c['info'] : null;
			}
		}
		$info = $this->load($url);
		set_site_transient(self::CACHE, ['url' => $url, 'info' => $info ? $info : []], $info ? 6 * HOUR_IN_SECONDS : 30 * MINUTE_IN_SECONDS);
		return $info;
	}

	/** Liest Versionsinfos aus GitHub-Release oder JSON-Datei. Gibt null bei Fehlern zurück. */
	private function load($url) {
		$args = ['timeout' => 10, 'headers' => ['User-Agent' => 'Alba/' . ALBA_VERSION, 'Accept' => 'application/json']];

		$url = trim($url);
		if (preg_match('~^([\w.\-]+)/([\w.\-]+)$~', $url, $sh)) { // Kurzform "owner/repo"
			$url = 'https://github.com/' . $sh[1] . '/' . $sh[2];
		}
		if (preg_match('~^https?://github\.com/([\w.\-]+)/([\w.\-]+?)(?:\.git)?/?$~i', $url, $m)) {
			$args['headers']['Accept'] = 'application/vnd.github+json';
			// /releases/latest ignoriert Vorabversionen (Beta); darum die Liste lesen und die erste veröffentlichte nehmen.
			$r = wp_remote_get('https://api.github.com/repos/' . $m[1] . '/' . $m[2] . '/releases?per_page=10', $args);
			if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
				return null;
			}
			$list = json_decode(wp_remote_retrieve_body($r), true);
			$d = null;
			if (is_array($list)) {
				foreach ($list as $rel) {
					if (is_array($rel) && empty($rel['draft']) && !empty($rel['tag_name'])) {
						$d = $rel;
						break;
					}
				}
			}
			if (!is_array($d)) {
				return null;
			}
			$version = ltrim((string) $d['tag_name'], 'vV');
			$zip = '';
			if (!empty($d['assets']) && is_array($d['assets'])) {
				foreach ($d['assets'] as $asset) {
					if (!empty($asset['browser_download_url']) && substr((string) $asset['name'], -4) === '.zip') {
						$zip = (string) $asset['browser_download_url'];
						break;
					}
				}
			}
			if ($zip === '' && !empty($d['zipball_url'])) {
				$zip = (string) $d['zipball_url'];
			}
			return $this->clean([
				'version'      => $version,
				'download_url' => $zip,
				'changelog'    => isset($d['body']) ? (string) $d['body'] : '',
				'homepage'     => isset($d['html_url']) ? (string) $d['html_url'] : 'https://github.com/' . $m[1] . '/' . $m[2],
			]);
		}

		$r = wp_remote_get($url, $args);
		if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
			return null;
		}
		$d = json_decode(wp_remote_retrieve_body($r), true);
		return is_array($d) ? $this->clean($d) : null;
	}

	private function clean(array $d) {
		$version = isset($d['version']) ? trim((string) $d['version']) : '';
		$zip = isset($d['download_url']) ? esc_url_raw((string) $d['download_url']) : '';
		if (!$this->valid_version($version) || $zip === '' || strpos($zip, 'https://') !== 0) {
			return null;
		}
		return [
			'version'      => $version,
			'download_url' => $zip,
			'changelog'    => isset($d['changelog']) ? (string) $d['changelog'] : '',
			'homepage'     => isset($d['homepage']) ? esc_url_raw((string) $d['homepage']) : '',
			'requires'     => isset($d['requires']) ? sanitize_text_field((string) $d['requires']) : '',
			'tested'       => isset($d['tested']) ? sanitize_text_field((string) $d['tested']) : '',
			'requires_php' => isset($d['requires_php']) ? sanitize_text_field((string) $d['requires_php']) : '',
		];
	}

	/* ---------------------------------------------------------------
	 * WordPress-Update-System
	 * ------------------------------------------------------------- */

	public function check($transient) {
		if (!is_object($transient)) {
			return $transient;
		}
		$info = $this->fetch();
		if (!$info) {
			return $transient;
		}
		$base = plugin_basename(ALBA_FILE);
		$item = (object) [
			'id'           => $base,
			'slug'         => self::SLUG,
			'plugin'       => $base,
			'new_version'  => $info['version'],
			'url'          => $info['homepage'],
			'package'      => $info['download_url'],
			'tested'       => isset($info['tested']) ? $info['tested'] : '',
			'requires_php' => isset($info['requires_php']) ? $info['requires_php'] : '',
			'icons'        => [],
			'banners'      => [],
		];
		if (version_compare($info['version'], ALBA_VERSION, '>')) {
			if (!isset($transient->response) || !is_array($transient->response)) {
				$transient->response = [];
			}
			$transient->response[$base] = $item;
		} else {
			if (!isset($transient->no_update) || !is_array($transient->no_update)) {
				$transient->no_update = [];
			}
			$transient->no_update[$base] = $item;
		}
		return $transient;
	}

	/** Detailfenster ("Details ansehen") */
	public function info($result, $action, $args) {
		if ($action !== 'plugin_information' || !is_object($args) || !isset($args->slug) || $args->slug !== self::SLUG) {
			return $result;
		}
		$info = $this->fetch();
		if (!$info) {
			return $result;
		}
		$changelog = trim($info['changelog']) !== '' ? wpautop(esc_html($info['changelog'])) : '<p>Keine Angaben.</p>';
		return (object) [
			'name'          => 'Alba',
			'slug'          => self::SLUG,
			'version'       => $info['version'],
			'author'        => 'Mads',
			'homepage'      => $info['homepage'],
			'requires'      => $info['requires'],
			'tested'        => $info['tested'],
			'requires_php'  => $info['requires_php'],
			'download_link' => $info['download_url'],
			'sections'      => [
				'description' => '<p>' . esc_html__('Lean multilingual plugin: languages, posts, taxonomies, strings, .po import/export and block navigation.', 'alba') . '</p>',
				'changelog'   => $changelog,
			],
		];
	}

	/** GitHub-Zipball hat einen anderen Ordnernamen: auf "alba" korrigieren */
	public function fix_folder($source, $remote_source, $upgrader, $hook_extra = []) {
		if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(ALBA_FILE)) {
			return $source;
		}
		if (basename(untrailingslashit($source)) === self::SLUG) {
			return $source;
		}
		global $wp_filesystem;
		if (!$wp_filesystem) {
			return $source;
		}
		$new = trailingslashit($remote_source) . self::SLUG . '/';
		if ($wp_filesystem->move($source, $new, true)) {
			return $new;
		}
		return new WP_Error('alba_update', __('The plugin folder could not be renamed.', 'alba'));
	}

	public function after($upgrader, $options) {
		if (!is_array($options) || empty($options['type']) || $options['type'] !== 'plugin') {
			return;
		}
		$base = plugin_basename(ALBA_FILE);
		$list = [];
		if (!empty($options['plugins']) && is_array($options['plugins'])) {
			$list = $options['plugins'];
		} elseif (!empty($options['plugin'])) {
			$list = [$options['plugin']];
		}
		if (in_array($base, $list, true)) {
			delete_site_transient(self::CACHE);
		}
	}

	/** Wird nach einem Update einmalig ausgelöst (Platz für spätere Datenbank-Anpassungen) */
	public function maybe_upgrade() {
		$old = get_option('alba_version');
		if ($old !== ALBA_VERSION) {
			update_option('alba_version', ALBA_VERSION);
			do_action('alba_upgraded', $old ? $old : '', ALBA_VERSION);
		}
	}

	/* ---------------------------------------------------------------
	 * Manuell prüfen
	 * ------------------------------------------------------------- */

	public function row_meta($meta, $file) {
		if ($file === plugin_basename(ALBA_FILE) && current_user_can('update_plugins')) {
			$meta[] = '<a href="' . esc_url(self::check_url()) . __('">Check for updates</a>', 'alba');
		}
		return $meta;
	}

	public function handle_check() {
		check_admin_referer('alba_check_update');
		if (!current_user_can('update_plugins')) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		delete_site_transient(self::CACHE);
		delete_site_transient('update_plugins');
		if (self::url() === '') {
			wp_safe_redirect(admin_url('plugins.php?alba_update=nosource'));
			exit;
		}
		$info = $this->fetch(true);
		if (!function_exists('wp_update_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		wp_update_plugins();
		if (!$info) {
			wp_safe_redirect(admin_url('plugins.php?alba_update=error'));
		} elseif (version_compare($info['version'], ALBA_VERSION, '>')) {
			wp_safe_redirect(admin_url('plugins.php?alba_update=new&alba_v=' . rawurlencode($info['version'])));
		} else {
			wp_safe_redirect(admin_url('plugins.php?alba_update=current'));
		}
		exit;
	}

	public function notices() {
		if (empty($_GET['alba_update']) || !current_user_can('update_plugins')) {
			return;
		}
		$state = sanitize_key(wp_unslash($_GET['alba_update']));
		$v = isset($_GET['alba_v']) ? sanitize_text_field(wp_unslash($_GET['alba_v'])) : '';
		switch ($state) {
			case 'new':
				$msg = __('Alba: Version ', 'alba') . $v . __(' is available. You can update it now in the plugin list.', 'alba');
				$type = 'success';
				break;
			case 'current':
				$msg = __('Alba is up to date (version ', 'alba') . ALBA_VERSION . ').';
				$type = 'info';
				break;
			case 'nosource':
				$msg = __('Alba: No update source is set (Languages > Settings > Updates).', 'alba');
				$type = 'warning';
				break;
			default:
				$msg = __('Alba: The update source could not be read. Please check the address and release.', 'alba');
				$type = 'error';
		}
		echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
	}
}
