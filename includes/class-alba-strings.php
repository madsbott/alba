<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * String-Übersetzung:
 *  - alba__('Text', 'Domain', 'optionaler-name') / alba_e() / [alba_string]
 *  - Site-Titel, Untertitel und Widget-Titel automatisch
 *  - optional: gettext-Scan für ausgewählte Textdomains (Theme/Plugins)
 * Speicherung: Option "alba_strings" (autoload = no), ID = md5(domain \0 ctx \0 name-oder-text)
 */
class ALBA_Strings {
	const OPT = 'alba_strings';
	const MAX = 6000;
	const PER_PAGE = 40;

	private $core;
	private $map = [];      // id => Übersetzung (aktuelle Sprache)
	private $found = [];    // in diesem Request gesehene Strings
	private $lazy = false;  // Zuordnung im Backend-/AJAX-Kontext nachträglich aufgebaut
	const OPT_DOMAIN = 'Theme settings';
	private $scan = false;
	private $domains = [];

	public function __construct(ALBA_Core $core) {
		$this->core = $core;
		$core->strings = $this;

		add_shortcode('alba_string', [$this, 'shortcode']);
		add_shortcode('mml_string', [$this, 'shortcode']); // alias: shortcode name used before 0.12.0

		if (is_admin()) {
			add_action('admin_menu', [$this, 'menu'], 11);
			add_action('admin_init', [$this, 'register']);
			add_action('admin_post_alba_save_strings', [$this, 'save']);
			add_action('admin_post_alba_clear_strings', [$this, 'clear']);
			add_action('admin_post_alba_scan', [$this, 'scan_action']);
			add_action('admin_post_alba_opt_add', [$this, 'opt_add']);
			add_action('admin_post_alba_text_add', [$this, 'text_add']);
			return;
		}

		$this->domains = array_values(array_filter(array_map('trim', explode(',', (string) get_option('alba_scan_domains', '')))));
		$this->scan = ((int) get_option('alba_scan', 0) === 1) && !empty($this->domains);
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

		// Texte, die in Theme-Einstellungen (Optionen) eingetragen sind
		$ot = get_option('alba_option_texts', []);
		if (is_array($ot)) {
			foreach ($ot as $opt => $texts) {
				if (is_string($opt) && is_array($texts) && $texts) {
					add_filter('option_' . $opt, function ($v) use ($texts) {
						return $this->replace_texts($v, $texts, 0);
					}, 20);
				}
			}
		}
	}

	/** Ersetzt in einem Optionswert (auch verschachtelt) genau passende Texte durch ihre Übersetzung */
	private function replace_texts($v, $texts, $depth) {
		if (is_string($v)) {
			return in_array($v, $texts, true) ? $this->translate($v, self::OPT_DOMAIN) : $v;
		}
		if (is_array($v) && $depth < 6) {
			foreach ($v as $k => $x) {
				if (is_string($x) || is_array($x)) {
					$v[$k] = $this->replace_texts($x, $texts, $depth + 1);
				}
			}
		}
		return $v;
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
		$a = shortcode_atts(['domain' => 'Strings', 'name' => ''], $atts, 'alba_string');
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
		register_setting('alba_str', 'alba_scan', ['sanitize_callback' => 'absint']);
		register_setting('alba_str', 'alba_scan_domains', ['sanitize_callback' => 'sanitize_text_field']);
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

		$dom = isset($_GET['alba_domain']) ? sanitize_text_field(wp_unslash($_GET['alba_domain'])) : '';
		$q = isset($_GET['alba_s']) ? sanitize_text_field(wp_unslash($_GET['alba_s'])) : '';
		$open = !empty($_GET['alba_open']);

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
			<?php settings_errors(); $this->scan_notice(); ?>
			<?php if (!empty($_GET['alba_saved'])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Saved.', 'alba'); ?></p></div>
			<?php endif; ?>

			<?php $this->scan_panel(); ?>

			<form method="get" style="margin:12px 0">
				<input type="hidden" name="page" value="alba-strings">
				<select name="alba_domain">
					<option value=""><?php echo esc_html__('All domains', 'alba'); ?></option>
					<?php foreach (array_keys($domains) as $d) : ?>
						<option value="<?php echo esc_attr($d); ?>" <?php selected($dom, $d); ?>><?php echo esc_html($d); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="alba_s" value="<?php echo esc_attr($q); ?>" placeholder="<?php echo esc_attr__('Search …', 'alba'); ?>">
				<label><input type="checkbox" name="alba_open" value="1" <?php checked($open); ?>> <?php echo esc_html__('untranslated only', 'alba'); ?></label>
				<button class="button"><?php echo esc_html__('Filter', 'alba'); ?></button>
				<span style="margin-left:12px"><?php echo (int) $total; ?> <?php echo esc_html__('Entries', 'alba'); ?></span>
			</form>

			<?php if (!$slice) : ?>
				<p><?php echo esc_html__('No texts yet. Choose your theme under "Find texts of your theme and plugins" above and click "Save and scan now".', 'alba'); ?></p>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="alba_save_strings">
				<?php wp_nonce_field('alba_str_save'); ?>
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
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() returns safe markup.
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
			<h2><?php echo esc_html__('Clean up', 'alba'); ?></h2>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Delete all entries without a translation?', 'alba')); ?>');">
				<input type="hidden" name="action" value="alba_clear_strings">
				<?php wp_nonce_field('alba_str_clear'); ?>
				<?php submit_button(__('Delete untranslated entries', 'alba'), 'delete', 'submit', false); ?>
			</form>

			<h2><?php echo esc_html__('Usage in the theme', 'alba'); ?></h2>
			<pre style="background:#fff;padding:12px;border:1px solid #c3c4c7;max-width:760px">&lt;?php echo alba__('Jetzt anfragen', 'Theme'); ?&gt;
&lt;?php alba_e('Willkommen', 'Theme', 'hero-title'); ?&gt;   // fester Name als Schlüssel
[alba_string domain="Theme" name="footer-claim"]Wir freuen uns auf Sie[/alba_string]</pre>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------
	 * Texte von Theme und Plugins finden (gettext-Scan)
	 * ------------------------------------------------------------- */

	/** Mögliche Textdomains: Domain => Beschriftung */
	private function detect_domains() {
		$out = [];
		$t = wp_get_theme();
		$td = (string) $t->get('TextDomain');
		$out[$td !== '' ? $td : $t->get_stylesheet()] = sprintf(
			/* translators: %s: theme name */
			__('Active theme: %s', 'alba'),
			$t->get('Name')
		);
		if ($t->parent()) {
			$p = $t->parent();
			$pd = (string) $p->get('TextDomain');
			$out[$pd !== '' ? $pd : $p->get_stylesheet()] = sprintf(
				/* translators: %s: theme name */
				__('Parent theme: %s', 'alba'),
				$p->get('Name')
			);
		}
		$out['default'] = __('WordPress core (many texts, e.g. "Search", "Read more")', 'alba');
		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = (array) get_option('active_plugins', []);
		foreach (get_plugins() as $file => $data) {
			if (!in_array($file, $active, true)) {
				continue;
			}
			$d = (string) $data['TextDomain'];
			if ($d === '') {
				$d = dirname($file) === '.' ? basename($file, '.php') : dirname($file);
			}
			if ($d === 'alba' || isset($out[$d])) {
				continue;
			}
			/* translators: %s: plugin name */
			$out[$d] = sprintf(__('Plugin: %s', 'alba'), $data['Name']);
		}
		return $out;
	}

	private function scan_panel() {
		$saved = array_values(array_filter(array_map('trim', explode(',', (string) get_option('alba_scan_domains', '')))));
		$detected = $this->detect_domains();
		$extra = array_diff($saved, array_keys($detected));
		?>
		<div class="card" style="max-width:none;margin:12px 0;padding:12px 16px">
			<h2 style="margin-top:0"><?php echo esc_html__('Find texts of your theme and plugins', 'alba'); ?></h2>
			<ol style="margin:0 0 8px 18px">
				<li><?php echo esc_html__('Choose where the texts come from (usually your theme).', 'alba'); ?></li>
				<li><?php echo esc_html__('Click "Save and scan now". Alba visits your site in the default language and collects the texts.', 'alba'); ?></li>
				<li><?php echo esc_html__('Translate the texts in the table below and save.', 'alba'); ?></li>
			</ol>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="alba_scan">
				<?php wp_nonce_field('alba_scan'); ?>
				<p style="margin:8px 0 4px"><strong><?php echo esc_html__('Collect texts from these sources', 'alba'); ?></strong></p>
				<?php foreach ($detected as $d => $label) : ?>
					<label style="display:block;margin:2px 0"><input type="checkbox" name="alba_dom[]" value="<?php echo esc_attr($d); ?>" <?php checked(in_array($d, $saved, true)); ?>> <?php echo esc_html($label); ?> <code><?php echo esc_html($d); ?></code></label>
				<?php endforeach; ?>
				<p style="margin:8px 0 4px"><label><?php echo esc_html__('Other text domains (comma-separated)', 'alba'); ?><br><input type="text" class="regular-text" name="alba_dom_extra" value="<?php echo esc_attr(implode(', ', $extra)); ?>"></label></p>
				<p>
					<button type="submit" class="button button-primary" name="alba_do" value="scan"><?php echo esc_html__('Save and scan now', 'alba'); ?></button>
					<button type="submit" class="button" name="alba_do" value="save"><?php echo esc_html__('Only save the selection', 'alba'); ?></button>
				</p>
				<p class="description"><?php echo esc_html__('Texts are collected whenever a page is built, so also while visitors browse. Pages delivered from a cache (e.g. LiteSpeed) collect nothing; "Scan now" asks for your pages without the cache.', 'alba'); ?></p>
				<p class="description"><?php echo esc_html__('Cannot find a text? Use "Look for a specific text" below: Alba tells you where the text comes from and how to translate it.', 'alba'); ?></p>
			</form>
			<?php $this->find_box(); ?>
		</div>
		<?php
	}

	/** Seiten, die der Scan besucht (Standardsprache) */
	private function scan_urls() {
		$home = home_url('/');
		$urls = [$home, add_query_arg('s', 'a', $home), add_query_arg('p', 999999999, $home)];
		$def = $this->core->default;
		foreach (['page' => 6, 'post' => 3] as $type => $n) {
			$ids = get_posts(['post_type' => $type, 'post_status' => 'publish', 'numberposts' => 40, 'fields' => 'ids', 'orderby' => $type === 'page' ? 'menu_order title' : 'date', 'order' => $type === 'page' ? 'ASC' : 'DESC', 'suppress_filters' => true]);
			$k = 0;
			foreach ($ids as $id) {
				if ($this->core->post_lang($id) !== $def) {
					continue;
				}
				$u = get_permalink($id);
				if ($u) {
					$urls[] = $u;
				}
				if (++$k >= $n) {
					break;
				}
			}
		}
		$stamp = (string) time();
		return array_map(function ($u) use ($stamp) {
			return add_query_arg('alba_scan', $stamp, $u);
		}, array_values(array_unique($urls)));
	}

	/* ---------------------------------------------------------------
	 * „Woher kommt dieser Text?“
	 * ------------------------------------------------------------- */

	private function find_box() {
		$needle = isset($_GET['alba_find']) ? trim(sanitize_text_field(wp_unslash($_GET['alba_find']))) : '';
		?>
		<hr>
		<h3><?php echo esc_html__('Look for a specific text', 'alba'); ?></h3>
		<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
			<input type="hidden" name="page" value="alba-strings">
			<input type="text" class="regular-text" name="alba_find" value="<?php echo esc_attr($needle); ?>" placeholder="<?php echo esc_attr__('Text exactly as it appears on the site', 'alba'); ?>">
			<button type="submit" class="button"><?php echo esc_html__('Where does this text come from?', 'alba'); ?></button>
		</form>
		<?php
		if (mb_strlen($needle) >= 3) {
			$this->find_report($needle);
		}
	}

	private function find_report($needle) {
		global $wpdb;
		$sel = array_values(array_filter(array_map('trim', explode(',', (string) get_option('alba_scan_domains', '')))));
		$any = false;

		// 1. bereits gesammelt
		$all = get_option(self::OPT, []);
		$hits = [];
		if (is_array($all)) {
			foreach ($all as $s) {
				if (isset($s['text']) && stripos($s['text'], $needle) !== false) {
					$hits[] = $s['domain'] . ': ' . $s['text'];
				}
			}
		}
		echo '<div style="margin-top:8px">';
		if ($hits) {
			$any = true;
			echo '<p><strong>' . esc_html__('Already in the table below:', 'alba') . '</strong></p><ul style="list-style:disc;margin-left:20px">';
			foreach (array_slice($hits, 0, 8) as $h) {
				echo '<li>' . esc_html($h) . '</li>';
			}
			echo '</ul>';
		}

		// 2. Theme-Dateien
		$files = $this->scan_theme_files($needle);
		if ($files) {
			$any = true;
			echo '<p><strong>' . esc_html__('Found in theme files:', 'alba') . '</strong></p><ul style="list-style:disc;margin-left:20px">';
			foreach ($files as $h) {
				echo '<li><code>' . esc_html($h['file'] . ':' . $h['line']) . '</code> – ';
				if ($h['domain'] !== '') {
					/* translators: %s: text domain */
					echo esc_html(sprintf(__('translatable text of the text domain "%s"', 'alba'), $h['domain']));
					if (!in_array($h['domain'], $sel, true)) {
						echo ' <strong>' . esc_html__('– select this source above and scan again.', 'alba') . '</strong>';
					}
					if ($h['msgid'] !== '') {
						echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;margin-left:8px">';
						echo '<input type="hidden" name="action" value="alba_text_add">';
						wp_nonce_field('alba_text_add');
						echo '<input type="hidden" name="domain" value="' . esc_attr($h['domain']) . '"><input type="hidden" name="text" value="' . esc_attr($h['msgid']) . '">';
						echo '<button type="submit" class="button button-small">' . esc_html__('Add to the table', 'alba') . '</button></form>';
					}
				} else {
					echo esc_html__('written directly in the code (not translatable by text domain)', 'alba');
				}
				echo '</li>';
			}
			echo '</ul>';
		}

		// 3. Optionen (Theme-Einstellungen, Widgets, …)
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_value LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name <> %s LIMIT 12",
			'%' . $wpdb->esc_like($needle) . '%',
			$wpdb->esc_like('_transient') . '%',
			$wpdb->esc_like('_site_transient') . '%',
			$wpdb->esc_like('alba_') . '%',
			self::OPT
		));
		$opt_hits = [];
		foreach ((array) $rows as $row) {
			$val = maybe_unserialize($row->option_value);
			$this->walk_option($val, $needle, [], $row->option_name, $opt_hits);
		}
		if ($opt_hits) {
			$any = true;
			echo '<p><strong>' . esc_html__('Found in site or theme settings:', 'alba') . '</strong></p>';
			echo '<p class="description">' . esc_html__('These texts are typed into a settings screen and are not translatable theme texts. Alba can translate such a text for you: click "Translate this text", then translate it in the table below.', 'alba') . '</p>';
			echo '<table class="widefat striped" style="max-width:900px"><tbody>';
			foreach (array_slice($opt_hits, 0, 10) as $h) {
				echo '<tr><td><code>' . esc_html($h['option'] . ($h['path'] ? ' → ' . $h['path'] : '')) . '</code></td><td>' . esc_html(mb_substr($h['value'], 0, 120)) . '</td><td>';
				if (is_string($h['option']) && $h['whole']) {
					echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0">';
					echo '<input type="hidden" name="action" value="alba_opt_add">';
					wp_nonce_field('alba_opt_add');
					echo '<input type="hidden" name="option" value="' . esc_attr($h['option']) . '"><input type="hidden" name="text" value="' . esc_attr($h['value']) . '">';
					echo '<button type="submit" class="button button-small">' . esc_html__('Translate this text', 'alba') . '</button></form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		if (!$any) {
			echo '<p>' . esc_html__('Not found in the collected texts, the theme files or the settings. The text may come from a plugin, from page content (translate that page in the translation editor) or from an element of your page builder.', 'alba') . '</p>';
		}
		echo '</div>';
	}

	private function walk_option($v, $needle, $path, $option, &$out) {
		if (count($out) >= 12) {
			return;
		}
		if (is_string($v)) {
			if (stripos($v, $needle) !== false) {
				$out[] = ['option' => $option, 'path' => implode(' → ', $path), 'value' => $v, 'whole' => mb_strlen($v) <= 400];
			}
		} elseif (is_array($v) && count($path) < 6) {
			foreach ($v as $k => $x) {
				$this->walk_option($x, $needle, array_merge($path, [(string) $k]), $option, $out);
			}
		}
	}

	/** Sucht den Text in den PHP-Dateien des aktiven Themes (und Eltern-Themes) */
	private function scan_theme_files($needle) {
		$dirs = array_unique([get_stylesheet_directory(), get_template_directory()]);
		$res = [];
		$n = 0;
		foreach ($dirs as $dir) {
			if (!is_dir($dir)) {
				continue;
			}
			$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
			foreach ($it as $file) {
				if (++$n > 4000 || count($res) >= 12) {
					break 2;
				}
				$p = $file->getPathname();
				if (substr($p, -4) !== '.php' || $file->getSize() > 1500000 || preg_match('#/(node_modules|vendor|\.git)/#', $p)) {
					continue;
				}
				$lines = @file($p, FILE_IGNORE_NEW_LINES);
				if (!$lines) {
					continue;
				}
				foreach ($lines as $i => $line) {
					if (stripos($line, $needle) === false) {
						continue;
					}
					$domain = '';
					$msgid = '';
					if (preg_match_all('/\b(__|_e|_x|_ex|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*([\'"])(.*?)\2\s*(?:,\s*([\'"])(.*?)\4)?/', $line, $m, PREG_SET_ORDER)) {
						foreach ($m as $g) {
							if (stripos($g[3], $needle) !== false && isset($g[5])) {
								$domain = $g[5];
								if (!in_array($g[1], ['_x', '_ex'], true) && strpos($g[3], '$') === false) {
									$msgid = $g[2] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $g[3]) : stripcslashes($g[3]);
								}
								break;
							}
						}
					}
					$res[] = ['file' => ltrim(str_replace(wp_normalize_path(WP_CONTENT_DIR), '', wp_normalize_path($p)), '/'), 'line' => $i + 1, 'domain' => $domain, 'msgid' => $msgid];
					if (count($res) >= 12) {
						break 3;
					}
				}
			}
		}
		return $res;
	}

	public function text_add() {
		check_admin_referer('alba_text_add');
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		$domain = isset($_POST['domain']) ? sanitize_text_field(wp_unslash($_POST['domain'])) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The text is a message id and must stay exactly as found in the code.
		$text = isset($_POST['text']) ? (string) wp_unslash($_POST['text']) : '';
		$back = admin_url('admin.php?page=alba-strings');
		if ($domain === '' || $text === '') {
			wp_safe_redirect(add_query_arg('alba_saved', 0, $back));
			exit;
		}
		$all = get_option(self::OPT, []);
		if (!is_array($all)) {
			$all = [];
		}
		$id = $this->id($domain, '', $text);
		if (!isset($all[$id])) {
			$all[$id] = ['domain' => $domain, 'ctx' => '', 'name' => '', 'text' => $text, 'tr' => []];
			update_option(self::OPT, $all, false);
		}
		wp_safe_redirect(add_query_arg(['alba_scanmsg' => 'txt', 'alba_s' => mb_substr($text, 0, 60)], $back));
		exit;
	}

	public function opt_add() {
		check_admin_referer('alba_opt_add');
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		$opt = isset($_POST['option']) ? sanitize_text_field(wp_unslash($_POST['option'])) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The text is a stored option value and must stay exactly as found.
		$text = isset($_POST['text']) ? (string) wp_unslash($_POST['text']) : '';
		$back = admin_url('admin.php?page=alba-strings');
		if ($opt === '' || $text === '' || get_option($opt, null) === null) {
			wp_safe_redirect(add_query_arg('alba_saved', 0, $back));
			exit;
		}
		$ot = get_option('alba_option_texts', []);
		if (!is_array($ot)) {
			$ot = [];
		}
		if (!isset($ot[$opt]) || !in_array($text, $ot[$opt], true)) {
			$ot[$opt][] = $text;
		}
		update_option('alba_option_texts', $ot, true);
		$all = get_option(self::OPT, []);
		if (!is_array($all)) {
			$all = [];
		}
		$id = $this->id(self::OPT_DOMAIN, '', $text);
		if (!isset($all[$id])) {
			$all[$id] = ['domain' => self::OPT_DOMAIN, 'ctx' => '', 'name' => '', 'text' => $text, 'tr' => []];
			update_option(self::OPT, $all, false);
		}
		wp_safe_redirect(add_query_arg(['alba_scanmsg' => 'opt', 'alba_s' => mb_substr($text, 0, 60)], $back));
		exit;
	}

	public function scan_action() {
		check_admin_referer('alba_scan');
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		$dom = isset($_POST['alba_dom']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['alba_dom'])) : [];
		$extra = isset($_POST['alba_dom_extra']) ? explode(',', sanitize_text_field(wp_unslash($_POST['alba_dom_extra']))) : [];
		$dom = array_values(array_unique(array_filter(array_map('trim', array_merge($dom, $extra)))));
		update_option('alba_scan_domains', implode(', ', $dom));
		update_option('alba_scan', $dom ? 1 : 0);

		$back = admin_url('admin.php?page=alba-strings');
		if (!$dom) {
			wp_safe_redirect(add_query_arg('alba_scanmsg', 'none', $back));
			exit;
		}
		if (!isset($_POST['alba_do']) || $_POST['alba_do'] !== 'scan') {
			wp_safe_redirect(add_query_arg('alba_saved', 1, $back));
			exit;
		}
		if (function_exists('set_time_limit')) {
			@set_time_limit(180);
		}
		$before = count((array) get_option(self::OPT, []));
		$urls = $this->scan_urls();
		$fail = 0;
		foreach ($urls as $u) {
			$r = wp_remote_get($u, [
				'timeout'     => 20,
				'redirection' => 3,
				'user-agent'  => 'Alba-Scan/' . ALBA_VERSION,
				'sslverify'   => apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core or cache plugin hook, intentionally called.
				'cookies'     => [],
			]);
			if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) >= 500) {
				$fail++;
			}
		}
		wp_cache_delete(self::OPT, 'options');
		wp_cache_delete('alloptions', 'options');
		$after = count((array) get_option(self::OPT, []));
		wp_safe_redirect(add_query_arg(['alba_scanmsg' => 'done', 'alba_new' => max(0, $after - $before), 'alba_pages' => count($urls), 'alba_fail' => $fail, 'alba_full' => $after >= self::MAX ? 1 : 0], $back));
		exit;
	}

	private function scan_notice() {
		$m = isset($_GET['alba_scanmsg']) ? sanitize_key(wp_unslash($_GET['alba_scanmsg'])) : '';
		if ($m === 'txt') {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Done. The text now appears in the table below. Translate it there.', 'alba') . '</p></div>';
		} elseif ($m === 'opt') {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Done. The text now appears in the table below with the domain "Theme settings". Translate it there.', 'alba') . '</p></div>';
		} elseif ($m === 'none') {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Please select at least one source first.', 'alba') . '</p></div>';
		} elseif ($m === 'done') {
			$new = isset($_GET['alba_new']) ? absint($_GET['alba_new']) : 0;
			$pages = isset($_GET['alba_pages']) ? absint($_GET['alba_pages']) : 0;
			$fail = isset($_GET['alba_fail']) ? absint($_GET['alba_fail']) : 0;
			/* translators: 1: number of new texts, 2: number of visited pages */
			$txt = sprintf(__('Scan finished: %1$d new texts found on %2$d pages.', 'alba'), $new, $pages);
			echo '<div class="notice notice-' . ($fail >= $pages ? 'error' : 'success') . ' is-dismissible"><p>' . esc_html($txt);
			if ($fail) {
				/* translators: %d: number of pages that could not be reached */
				echo ' ' . esc_html(sprintf(__('%d pages could not be reached. Your host may block requests to its own site. In that case open your site in the default language once while logged out.', 'alba'), $fail));
			} elseif (!$new) {
				echo ' ' . esc_html__('Nothing new: either everything was found already, or the texts do not come from the selected sources.', 'alba');
			}
			if (!empty($_GET['alba_full'])) {
				echo ' ' . esc_html__('The list of collected texts is full. Use "Delete untranslated entries" at the bottom and scan again with fewer sources (for example without "WordPress core").', 'alba');
			}
			echo '</p></div>';
		}
	}

	public function save() {
		check_admin_referer('alba_str_save');
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		$all = get_option(self::OPT, []);
		if (!is_array($all)) {
			$all = [];
		}
		$ids = isset($_POST['ids']) ? array_map('sanitize_key', (array) wp_unslash($_POST['ids'])) : [];
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Translations may contain HTML; they are filtered with wp_kses below unless the user may post unfiltered HTML.
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
		wp_safe_redirect(add_query_arg('alba_saved', 1, $back));
		exit;
	}

	public function clear() {
		check_admin_referer('alba_str_clear');
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		$all = get_option(self::OPT, []);
		if (is_array($all)) {
			$all = array_filter($all, function ($s) {
				return !empty($s['tr']);
			});
			update_option(self::OPT, $all, false);
		}
		wp_safe_redirect(add_query_arg('alba_saved', 1, admin_url('admin.php?page=alba-strings')));
		exit;
	}
}

/* Template-Funktionen */

function alba__($text, $domain = 'Strings', $name = '') {
	$s = alba()->strings;
	return $s ? $s->translate($text, $domain, $name) : $text;
}

function alba_e($text, $domain = 'Strings', $name = '') {
	echo wp_kses_post(alba__($text, $domain, $name));
}
