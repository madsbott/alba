<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * String-Übersetzung:
 *  - mml__('Text', 'Domain', 'optionaler-name') / mml_e() / [mml_string]
 *  - Site-Titel, Untertitel und Widget-Titel automatisch
 *  - optional: gettext-Scan für ausgewählte Textdomains (Theme/Plugins)
 * Speicherung: Option "mml_strings" (autoload = no), ID = md5(domain \0 ctx \0 name-oder-text)
 */
class MML_Strings {
	const OPT = 'mml_strings';
	const MAX = 3000;
	const PER_PAGE = 40;

	private $core;
	private $map = [];      // id => Übersetzung (aktuelle Sprache)
	private $found = [];    // in diesem Request gesehene Strings
	private $lazy = false;  // Zuordnung im Backend-/AJAX-Kontext nachträglich aufgebaut
	private $scan = false;
	private $domains = [];

	public function __construct(MML_Core $core) {
		$this->core = $core;
		$core->strings = $this;

		add_shortcode('mml_string', [$this, 'shortcode']);

		if (is_admin()) {
			add_action('admin_menu', [$this, 'menu'], 11);
			add_action('admin_init', [$this, 'register']);
			add_action('admin_post_mml_save_strings', [$this, 'save']);
			add_action('admin_post_mml_clear_strings', [$this, 'clear']);
			return;
		}

		$this->domains = array_values(array_filter(array_map('trim', explode(',', (string) get_option('mml_scan_domains', '')))));
		$this->scan = ((int) get_option('mml_scan', 0) === 1) && !empty($this->domains);
		$this->build_map();

		if ($this->map || $this->scan) {
			add_filter('gettext', [$this, 'gettext'], 10, 3);
			add_filter('gettext_with_context', [$this, 'gettext_ctx'], 10, 4);
		}
		add_filter('option_blogname', function ($v) {
			return $this->translate($v, 'Site', 'blogname');
		});
		add_filter('option_blogdescription', function ($v) {
			return $this->translate($v, 'Site', 'blogdescription');
		});
		add_filter('widget_title', function ($v) {
			return (is_string($v) && $v !== '') ? $this->translate($v, 'Widgets') : $v;
		});
		add_action('shutdown', [$this, 'save_found']);
	}

	/* ---------------------------------------------------------------
	 * Frontend
	 * ------------------------------------------------------------- */

	private function id($domain, $ctx, $key) {
		return md5($domain . "\0" . $ctx . "\0" . $key);
	}

	private function build_map() {
		$all = get_option(self::OPT, []);
		if (!is_array($all)) {
			return;
		}
		$lang = $this->core->lang;
		foreach ($all as $id => $s) {
			if (!empty($s['tr'][$lang])) {
				$this->map[$id] = $s['tr'][$lang];
			}
		}
	}

	public function gettext($tr, $text, $domain) {
		return $this->lookup($tr, $text, '', $domain);
	}

	public function gettext_ctx($tr, $text, $ctx, $domain) {
		return $this->lookup($tr, $text, $ctx, $domain);
	}

	private function lookup($tr, $text, $ctx, $domain) {
		$id = $this->id($domain, $ctx, $text);
		if ($this->scan && !isset($this->found[$id]) && in_array($domain, $this->domains, true)) {
			$this->found[$id] = ['domain' => $domain, 'ctx' => $ctx, 'name' => '', 'text' => $text];
		}
		return isset($this->map[$id]) ? $this->map[$id] : $tr;
	}

	/** Eigene Strings: registriert und übersetzt */
	public function translate($text, $domain = 'Strings', $name = '') {
		$text = (string) $text;
		if ($text === '') {
			return $text;
		}
		if (is_admin() && !$this->lazy) {
			// AJAX-Anfragen (z. B. Formular-Absendungen) laufen im Admin-Kontext: Zuordnung hier nachholen
			$this->lazy = true;
			$this->build_map();
			add_action('shutdown', [$this, 'save_found']);
		}
		$id = $this->id($domain, '', $name !== '' ? $name : $text);
		if (!isset($this->found[$id])) {
			$this->found[$id] = ['domain' => $domain, 'ctx' => '', 'name' => $name, 'text' => $text];
		}
		return isset($this->map[$id]) ? $this->map[$id] : $text;
	}

	public function shortcode($atts, $content = '') {
		$a = shortcode_atts(['domain' => 'Strings', 'name' => ''], $atts, 'mml_string');
		return $this->translate(trim((string) $content), $a['domain'], $a['name']);
	}

	public function save_found() {
		if (!$this->found) {
			return;
		}
		$all = get_option(self::OPT, []);
		if (!is_array($all)) {
			$all = [];
		}
		$dirty = false;
		foreach ($this->found as $id => $s) {
			if (isset($all[$id])) {
				if ($s['name'] !== '' && $all[$id]['text'] !== $s['text']) {
					$all[$id]['text'] = $s['text'];
					$dirty = true;
				}
				continue;
			}
			if (count($all) >= self::MAX) {
				break;
			}
			$all[$id] = $s + ['tr' => []];
			$dirty = true;
		}
		if ($dirty) {
			update_option(self::OPT, $all, false);
		}
	}

	/* ---------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------- */

	public function menu() {
		add_submenu_page('alba', __('Strings', 'alba'), __('Strings', 'alba'), 'manage_options', 'alba-strings', [$this, 'page']);
	}

	public function register() {
		register_setting('mml_str', 'mml_scan', ['sanitize_callback' => 'absint']);
		register_setting('mml_str', 'mml_scan_domains', ['sanitize_callback' => 'sanitize_text_field']);
	}

	public function seed(array &$all) {
		$dirty = false;
		foreach (['blogname' => __('Site title', 'alba'), 'blogdescription' => __('Tagline', 'alba')] as $name => $label) {
			$id = $this->id('Site', '', $name);
			$text = (string) get_option($name);
			if (!isset($all[$id])) {
				$all[$id] = ['domain' => 'Site', 'ctx' => '', 'name' => $name, 'text' => $text, 'tr' => []];
				$dirty = true;
			} elseif ($all[$id]['text'] !== $text) {
				$all[$id]['text'] = $text;
				$dirty = true;
			}
		}
		if ($dirty) {
			update_option(self::OPT, $all, false);
		}
	}

	public function page() {
		if (!current_user_can('manage_options')) {
			return;
		}
		$c = $this->core;
		$all = get_option(self::OPT, []);
		if (!is_array($all)) {
			$all = [];
		}
		$this->seed($all);

		$domains = [];
		foreach ($all as $s) {
			$domains[$s['domain']] = true;
		}
		ksort($domains);

		$dom = isset($_GET['mml_domain']) ? sanitize_text_field(wp_unslash($_GET['mml_domain'])) : '';
		$q = isset($_GET['mml_s']) ? sanitize_text_field(wp_unslash($_GET['mml_s'])) : '';
		$open = !empty($_GET['mml_open']);

		$rows = [];
		foreach ($all as $id => $s) {
			if ($dom !== '' && $s['domain'] !== $dom) {
				continue;
			}
			if ($q !== '' && stripos($s['text'] . ' ' . $s['name'], $q) === false) {
				continue;
			}
			if ($open && !empty($s['tr'])) {
				continue;
			}
			$rows[$id] = $s;
		}
		uasort($rows, function ($a, $b) {
			return strcmp($a['domain'] . $a['text'], $b['domain'] . $b['text']);
		});

		$total = count($rows);
		$pages = max(1, (int) ceil($total / self::PER_PAGE));
		$paged = isset($_GET['paged']) ? max(1, min($pages, (int) $_GET['paged'])) : 1;
		$slice = array_slice($rows, ($paged - 1) * self::PER_PAGE, self::PER_PAGE, true);
		?>
		<div class="wrap">
			<h1><?php echo esc_html__('Translate strings', 'alba'); ?></h1>
			<?php settings_errors(); ?>
			<?php if (!empty($_GET['mml_saved'])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Saved.', 'alba'); ?></p></div>
			<?php endif; ?>

			<form method="get" style="margin:12px 0">
				<input type="hidden" name="page" value="alba-strings">
				<select name="mml_domain">
					<option value=""><?php echo esc_html__('All domains', 'alba'); ?></option>
					<?php foreach (array_keys($domains) as $d) : ?>
						<option value="<?php echo esc_attr($d); ?>" <?php selected($dom, $d); ?>><?php echo esc_html($d); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="mml_s" value="<?php echo esc_attr($q); ?>" placeholder="<?php echo esc_attr__('Search …', 'alba'); ?>">
				<label><input type="checkbox" name="mml_open" value="1" <?php checked($open); ?>> <?php echo esc_html__('untranslated only', 'alba'); ?></label>
				<button class="button"><?php echo esc_html__('Filter', 'alba'); ?></button>
				<span style="margin-left:12px"><?php echo (int) $total; ?> <?php echo esc_html__('Entries', 'alba'); ?></span>
			</form>

			<?php if (!$slice) : ?>
				<p><?php echo esc_html__('No strings yet. Use', 'alba'); ?> <code>mml__()</code>, <code>[mml_string]</code> <?php echo esc_html__('or enable the scan for a text domain below.', 'alba'); ?></p>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="mml_save_strings">
				<?php wp_nonce_field('mml_str_save'); ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width:110px"><?php echo esc_html__('Domain', 'alba'); ?></th>
							<th style="width:25%"><?php echo esc_html__('Original', 'alba'); ?></th>
							<?php foreach ($c->langs as $code => $l) : ?>
								<th><?php echo esc_html($l['name']); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
					<?php foreach ($slice as $id => $s) : ?>
						<tr>
							<td><?php echo esc_html($s['domain']); ?></td>
							<td>
								<?php if (!empty($s['name'])) : ?><code><?php echo esc_html($s['name']); ?></code><br><?php endif; ?>
								<?php if (!empty($s['ctx'])) : ?><em><?php echo esc_html($s['ctx']); ?></em><br><?php endif; ?>
								<?php echo esc_html($s['text']); ?>
								<input type="hidden" name="ids[]" value="<?php echo esc_attr($id); ?>">
							</td>
							<?php foreach ($c->langs as $code => $l) : ?>
								<td><textarea rows="2" style="width:100%" name="tr[<?php echo esc_attr($id); ?>][<?php echo esc_attr($code); ?>]"><?php echo esc_textarea(isset($s['tr'][$code]) ? $s['tr'][$code] : ''); ?></textarea></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><?php submit_button(__('Save translations', 'alba'), 'primary', 'submit', false); ?></p>
			</form>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo paginate_links([
					'base'    => str_replace('999999999', '%#%', add_query_arg('paged', 999999999)),
					'format'  => '',
					'total'   => $pages,
					'current' => $paged,
				]);
				?>
			</div></div>
			<?php endif; ?>


			<hr>
			<h2><?php echo esc_html__('Scan (gettext)', 'alba'); ?></h2>
			<p><?php echo esc_html__('While visitors browse frontend pages, this collects all texts of the specified text domains (e.g. the slug of your theme) so you can translate them here. At most', 'alba'); ?> <?php echo (int) self::MAX; ?> <?php echo esc_html__('entries. Switch the scan off again after collecting.', 'alba'); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields('mml_str'); ?>
				<p><label><input type="checkbox" name="mml_scan" value="1" <?php checked(1, (int) get_option('mml_scan', 0)); ?>> <?php echo esc_html__('Scan active', 'alba'); ?></label></p>
				<p><label><?php echo esc_html__('Text domains (comma-separated)', 'alba'); ?><br><input type="text" class="regular-text" name="mml_scan_domains" value="<?php echo esc_attr(get_option('mml_scan_domains', '')); ?>" placeholder="<?php echo esc_attr__('my-theme, woocommerce', 'alba'); ?>"></label></p>
				<?php submit_button(__('Save scan settings', 'alba'), 'secondary', 'submit', false); ?>
			</form>

			<h2><?php echo esc_html__('Clean up', 'alba'); ?></h2>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Delete all entries without a translation?', 'alba')); ?>');">
				<input type="hidden" name="action" value="mml_clear_strings">
				<?php wp_nonce_field('mml_str_clear'); ?>
				<?php submit_button(__('Delete untranslated entries', 'alba'), 'delete', 'submit', false); ?>
			</form>

			<h2><?php echo esc_html__('Usage in the theme', 'alba'); ?></h2>
			<pre style="background:#fff;padding:12px;border:1px solid #c3c4c7;max-width:760px">&lt;?php echo mml__('Jetzt anfragen', 'Theme'); ?&gt;
&lt;?php mml_e('Willkommen', 'Theme', 'hero-title'); ?&gt;   // fester Name als Schlüssel
[mml_string domain="Theme" name="footer-claim"]Wir freuen uns auf Sie[/mml_string]</pre>
		</div>
		<?php
	}

	public function save() {
		check_admin_referer('mml_str_save');
		if (!current_user_can('manage_options')) {
			wp_die(__('Not allowed.', 'alba'));
		}
		$all = get_option(self::OPT, []);
		if (!is_array($all)) {
			$all = [];
		}
		$ids = isset($_POST['ids']) ? array_map('sanitize_key', (array) wp_unslash($_POST['ids'])) : [];
		$tr = isset($_POST['tr']) && is_array($_POST['tr']) ? wp_unslash($_POST['tr']) : [];
		$raw_ok = current_user_can('unfiltered_html');

		foreach ($ids as $id) {
			if (!isset($all[$id])) {
				continue;
			}
			$new = [];
			foreach ($this->core->langs as $code => $l) {
				$v = isset($tr[$id][$code]) ? trim((string) $tr[$id][$code]) : '';
				if ($v === '') {
					continue;
				}
				$new[$code] = $raw_ok ? $v : wp_kses_post($v);
			}
			$all[$id]['tr'] = $new;
		}
		update_option(self::OPT, $all, false);

		$back = wp_get_referer() ?: admin_url('admin.php?page=alba-strings');
		wp_safe_redirect(add_query_arg('mml_saved', 1, $back));
		exit;
	}

	public function clear() {
		check_admin_referer('mml_str_clear');
		if (!current_user_can('manage_options')) {
			wp_die(__('Not allowed.', 'alba'));
		}
		$all = get_option(self::OPT, []);
		if (is_array($all)) {
			$all = array_filter($all, function ($s) {
				return !empty($s['tr']);
			});
			update_option(self::OPT, $all, false);
		}
		wp_safe_redirect(add_query_arg('mml_saved', 1, admin_url('admin.php?page=alba-strings')));
		exit;
	}
}

/* Template-Funktionen */

function mml__($text, $domain = 'Strings', $name = '') {
	$s = mml()->strings;
	return $s ? $s->translate($text, $domain, $name) : $text;
}

function mml_e($text, $domain = 'Strings', $name = '') {
	echo wp_kses_post(mml__($text, $domain, $name));
}
