<?php
if (!defined('ABSPATH')) {
	exit;
}

class MML_Admin {
	private $core;

	public function __construct(MML_Core $core) {
		$this->core = $core;

		add_action('admin_menu', [$this, 'menu']);
		add_action('admin_init', [$this, 'register']);
		add_action('admin_init', [$this, 'list_hooks']);
		add_action('add_meta_boxes', [$this, 'add_box']);
		add_action('save_post', [$this, 'save'], 10, 2);
		add_action('admin_post_mml_new_translation', [$this, 'create_translation']);
		add_action('restrict_manage_posts', [$this, 'filter_dropdown']);
		add_action('admin_head', [$this, 'css']);
		add_action('admin_footer-plugins.php', [$this, 'delete_confirm']);
	}

	/* ---------------------------------------------------------------
	 * Einstellungen
	 * ------------------------------------------------------------- */

	public function menu() {
		add_submenu_page('alba', __('Languages – Settings', 'alba'), __('Settings', 'alba'), 'manage_options', 'alba-settings', [$this, 'page']);
	}

	public function register() {
		register_setting('mml', 'mml_languages', ['sanitize_callback' => [$this, 'sanitize_langs']]);
		register_setting('mml', 'mml_default', ['sanitize_callback' => [$this, 'sanitize_default']]);
		register_setting('mml', 'mml_post_types', ['sanitize_callback' => [$this, 'sanitize_types']]);
		register_setting('mml', 'mml_taxonomies', ['sanitize_callback' => [$this, 'sanitize_types']]);
		register_setting('mml', 'mml_update_url', ['sanitize_callback' => function ($v) {
			$v = esc_url_raw(trim((string) $v));
			return strpos($v, 'https://') === 0 ? $v : '';
		}]);
		register_setting('mml', 'mml_menu_location', ['sanitize_callback' => 'sanitize_key']);
		register_setting('mml', 'mml_display', ['sanitize_callback' => function ($v) {
			return $v === 'code' ? 'code' : 'name';
		}]);
		register_setting('mml', 'mml_hide_missing', ['sanitize_callback' => 'absint']);
		register_setting('mml', 'mml_dd_arrow', ['sanitize_callback' => function ($v) {
			return in_array($v, ['on', 'off'], true) ? $v : 'auto';
		}]);
		register_setting('mml', 'mml_autodetect', ['sanitize_callback' => function ($v) {
			return in_array($v, ['home', 'all'], true) ? $v : 'off';
		}]);
		register_setting('mml', 'mml_autodetect_method', ['sanitize_callback' => function ($v) {
			return $v === 'js' ? 'js' : 'server';
		}]);
		foreach (['mml_t_attrs', 'mml_t_blocks', 'mml_t_acf'] as $o) {
			register_setting('mml', $o, ['sanitize_callback' => function ($v) {
				return (int) !empty($v);
			}]);
		}
		foreach (['mml_meta_keys', 'mml_extra_attrs'] as $o) {
			register_setting('mml', $o, ['sanitize_callback' => function ($v) {
				$out = [];
				foreach (preg_split('/[\s,;]+/', (string) $v, -1, PREG_SPLIT_NO_EMPTY) as $x) {
					$x = preg_replace('/[^A-Za-z0-9_\-*.:]/', '', $x);
					if ($x !== '') {
						$out[] = $x;
					}
				}
				return implode("\n", array_unique($out));
			}]);
		}
		register_setting('mml', 'mml_mt_provider', ['sanitize_callback' => function ($v) {
			return in_array($v, ['mymemory', 'libre'], true) ? $v : 'off';
		}]);
		register_setting('mml', 'mml_mt_email', ['sanitize_callback' => function ($v) {
			$v = sanitize_email((string) $v);
			return is_email($v) ? $v : '';
		}]);
		register_setting('mml', 'mml_mt_url', ['sanitize_callback' => function ($v) {
			return esc_url_raw(trim((string) $v));
		}]);
		register_setting('mml', 'mml_mt_key', ['sanitize_callback' => function ($v) {
			return sanitize_text_field((string) $v);
		}]);
		register_setting('mml', 'mml_log_days', ['sanitize_callback' => function ($v) {
			$v = (int) $v;
			return in_array($v, [0, 30, 90, 180, 365], true) ? $v : 365;
		}]);
		register_setting('mml', 'mml_cache_purge', ['sanitize_callback' => function ($v) {
			return in_array($v, ['all', 'off'], true) ? $v : 'smart';
		}]);
		register_setting('mml', 'mml_uninstall', ['sanitize_callback' => function ($v) {
			return in_array($v, ['delete', 'keep'], true) ? $v : 'ask';
		}]);
		register_setting('mml', 'mml_menu_style', ['sanitize_callback' => function ($v) {
			return $v === 'dropdown' ? 'dropdown' : 'list';
		}]);
		register_setting('mml', 'mml_nav_switcher', ['sanitize_callback' => function ($v) {
			return in_array($v, ['first', 'all'], true) ? $v : '';
		}]);
	}

	private function clean_code($code) {
		$code = strtolower(preg_replace('/[^a-z0-9\-]/i', '', (string) $code));
		$code = substr($code, 0, 10);
		if (in_array($code, ['wp-admin', 'wp-content', 'wp-includes', 'wp-json'], true)) {
			return '';
		}
		return $code;
	}

	public function sanitize_langs($in) {
		$out = [];
		if (!is_array($in)) {
			return $out;
		}
		foreach ($in as $key => $row) {
			if (!is_array($row)) {
				continue;
			}
			// WordPress ruft den Sanitizer beim ersten Speichern einer neuen Option zweimal auf:
			// beim zweiten Mal ist der Code der Array-Schlüssel (bereits bereinigte Form)
			$raw = isset($row['code']) ? $row['code'] : (is_string($key) ? $key : '');
			$code = $this->clean_code($raw);
			if ($code === '' || isset($out[$code])) {
				continue;
			}
			$name = isset($row['name']) ? sanitize_text_field($row['name']) : '';
			$out[$code] = [
				'name'   => $name !== '' ? $name : $code,
				'locale' => preg_replace('/[^A-Za-z0-9_\-]/', '', isset($row['locale']) ? $row['locale'] : ''),
			];
		}
		$this->core->flush();
		return $out;
	}

	/** Das Radio-Feld enthält den Zeilen-Index, daraus wird der Code */
	public function sanitize_default($v) {
		if (isset($_POST['mml_languages']) && is_array($_POST['mml_languages'])) {
			$rows = wp_unslash($_POST['mml_languages']);
			if (isset($rows[$v]['code'])) {
				return $this->clean_code($rows[$v]['code']);
			}
			// bereits ein Code (zweiter Aufruf bei der ersten Speicherung)
			$code = $this->clean_code($v);
			foreach ($rows as $row) {
				if (is_array($row) && isset($row['code']) && $this->clean_code($row['code']) === $code && $code !== '') {
					return $code;
				}
			}
			return '';
		}
		return sanitize_key($v);
	}

	public function sanitize_types($v) {
		return array_values(array_map('sanitize_key', (array) $v));
	}

	public function page() {
		if (!current_user_can('manage_options')) {
			return;
		}
		$c = $this->core;
		$presets = MML_Core::presets();
		$types = get_post_types(['public' => true], 'objects');
		unset($types['attachment']);
		$menus = get_registered_nav_menus();
		$loc = get_option('mml_menu_location', '');
		?>
		<div class="wrap mml-wrap">
			<h1><?php echo esc_html__('Languages – Settings', 'alba'); ?></h1>
			<?php settings_errors(); ?>
			<style>
				.mml-wrap{max-width:960px}
				.mml-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:16px 0 18px;padding:6px;background:#fff;border:1px solid #dcdcde;border-radius:8px}
				.mml-tabs a{display:flex;align-items:center;gap:6px;padding:8px 14px;border-radius:6px;color:#3c434a;text-decoration:none;font-weight:500}
				.mml-tabs a:hover{background:#f0f6fc;color:#2271b1}
				.mml-tabs a.is-active{background:#2271b1;color:#fff}
				.mml-tabs .dashicons{font-size:18px;width:18px;height:18px}
				.mml-card{display:none;background:#fff;border:1px solid #dcdcde;border-radius:8px;margin:0 0 18px;box-shadow:0 1px 2px rgba(0,0,0,.04);overflow:hidden}
				.mml-card.is-active{display:block}
				.mml-card-h{display:flex;gap:14px;align-items:flex-start;padding:16px 22px;background:#f6f7f7;border-bottom:1px solid #dcdcde}
				.mml-card-h>.dashicons{font-size:22px;width:22px;height:22px;margin-top:2px;color:#2271b1}
				.mml-card-h h2{margin:0 0 2px;padding:0;font-size:16px;line-height:1.4}
				.mml-card-h p{margin:0;color:#646970}
				.mml-card-b{padding:6px 22px 18px}
				.mml-card-b>p{color:#50575e}
				.mml-fields{margin:0 -22px}
				.mml-field{display:grid;grid-template-columns:220px 1fr;gap:8px 24px;padding:16px 22px;border-top:1px solid #f0f0f1}
				.mml-field:first-child{border-top:0}
				.mml-label{font-weight:600;color:#1d2327;padding-top:4px}
				.mml-ctl select,.mml-ctl input[type=text],.mml-ctl input[type=url]{max-width:100%}
				.mml-ctl select{min-width:260px}
				.mml-hint{margin:8px 0 0;color:#646970;font-size:13px;line-height:1.55;max-width:640px}
				.mml-langs{margin-top:14px;border-radius:6px;overflow:hidden}
				.mml-langs th{font-weight:600}
				.mml-checks{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0 4px}
				.mml-check{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid #dcdcde;border-radius:999px;background:#fff;cursor:pointer}
				.mml-check:hover{border-color:#2271b1}
				.mml-check:has(input:checked){background:#f0f6fc;border-color:#2271b1;color:#0a4b78}
				.mml-save{position:sticky;bottom:0;z-index:5;display:flex;align-items:center;gap:12px;padding:12px 22px;margin-top:6px;background:rgba(255,255,255,.96);border:1px solid #dcdcde;border-radius:8px;box-shadow:0 -2px 8px rgba(0,0,0,.06)}
				.mml-save .submit{margin:0;padding:0}
				.mml-save span{color:#646970}
				@media (max-width:782px){.mml-field{grid-template-columns:1fr}.mml-ctl select{min-width:0;width:100%}}
			</style>
			<nav class="mml-tabs" id="mml-tabs">
				<a href="#lang" data-tab="lang"><span class="dashicons dashicons-translation"></span><?php echo esc_html__('Languages & Content', 'alba'); ?></a>
				<a href="#switch" data-tab="switch"><span class="dashicons dashicons-randomize"></span><?php echo esc_html__('Language switcher', 'alba'); ?></a>
				<a href="#detect" data-tab="detect"><span class="dashicons dashicons-visibility"></span><?php echo esc_html__('Language detection', 'alba'); ?></a>
				<a href="#areas" data-tab="areas"><span class="dashicons dashicons-search"></span><?php echo esc_html__('Search, Comments & Menus', 'alba'); ?></a>
				<a href="#seo" data-tab="seo"><span class="dashicons dashicons-chart-area"></span><?php echo esc_html__('Sitemap & SEO', 'alba'); ?></a>
				<a href="#mt" data-tab="mt"><span class="dashicons dashicons-admin-site-alt3"></span><?php echo esc_html__('Translation tools', 'alba'); ?></a>
				<a href="#cache" data-tab="cache"><span class="dashicons dashicons-performance"></span><?php echo esc_html__('Cache', 'alba'); ?></a>
				<a href="#system" data-tab="system"><span class="dashicons dashicons-admin-tools"></span><?php echo esc_html__('Updates & System', 'alba'); ?></a>
			</nav>

			<?php if (!empty($_GET['mml_purged'])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__('The page cache has been cleared.', 'alba'); ?></p></div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields('mml'); ?>

								<section class="mml-card" data-tab="lang">
				<header class="mml-card-h"><span class="dashicons dashicons-translation"></span><div><h2><?php echo esc_html__('Languages', 'alba'); ?></h2><p><?php echo esc_html__('Choose the languages your website appears in.', 'alba'); ?></p></div></header>
				<div class="mml-card-b">
				<p><?php echo esc_html__('The', 'alba'); ?> <strong><?php echo esc_html__('default language', 'alba'); ?></strong> <?php echo esc_html__('has no URL prefix (', 'alba'); ?><code>/seite/</code><?php echo esc_html__('), all others get one (', 'alba'); ?><code>/en/seite/</code><?php echo esc_html__('). The locale controls the theme/plugin translation on the frontend (the language pack must be installed).', 'alba'); ?></p>
				<table class="widefat striped mml-langs">
					<thead><tr><th><?php echo esc_html__('Default', 'alba'); ?></th><th><?php echo esc_html__('Code (URL)', 'alba'); ?></th><th><?php echo esc_html__('Name', 'alba'); ?></th><th><?php echo esc_html__('Locale', 'alba'); ?></th><th></th></tr></thead>
					<tbody id="mml-rows">
					<?php $i = 0; foreach ($c->langs as $code => $l) : ?>
						<tr>
							<td><input type="radio" name="mml_default" value="<?php echo (int) $i; ?>" <?php checked($code, $c->default); ?>></td>
							<td><input type="text" size="8" name="mml_languages[<?php echo (int) $i; ?>][code]" value="<?php echo esc_attr($code); ?>"></td>
							<td><input type="text" name="mml_languages[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr($l['name']); ?>"></td>
							<td><input type="text" size="10" name="mml_languages[<?php echo (int) $i; ?>][locale]" value="<?php echo esc_attr($l['locale']); ?>"></td>
							<td><button type="button" class="button-link-delete mml-del"><?php echo esc_html__('Remove', 'alba'); ?></button></td>
						</tr>
					<?php $i++; endforeach; ?>
					</tbody>
				</table>
				<p>
					<select id="mml-preset">
						<option value=""><?php echo esc_html__('Custom language …', 'alba'); ?></option>
						<?php foreach ($presets as $code => $p) : ?>
							<option value="<?php echo esc_attr($code); ?>" data-name="<?php echo esc_attr($p[0]); ?>" data-locale="<?php echo esc_attr($p[1]); ?>"><?php echo esc_html($p[0] . ' (' . $code . ')'); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button" id="mml-add"><?php echo esc_html__('Add language', 'alba'); ?></button>
				</p>

								</div></section>
				<section class="mml-card" data-tab="lang">
				<header class="mml-card-h"><span class="dashicons dashicons-admin-page"></span><div><h2><?php echo esc_html__('Translatable content types', 'alba'); ?></h2><p><?php echo esc_html__('You can create translations for these content types.', 'alba'); ?></p></div></header>
				<div class="mml-card-b">
				<p class="mml-checks">
					<?php foreach ($types as $slug => $obj) : ?>
						<label class="mml-check">
							<input type="checkbox" name="mml_post_types[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $c->post_types, true)); ?>>
							<?php echo esc_html($obj->labels->name); ?>
						</label>
					<?php endforeach; ?>
				</p>

								</div></section>
				<section class="mml-card" data-tab="lang">
				<header class="mml-card-h"><span class="dashicons dashicons-category"></span><div><h2><?php echo esc_html__('Translatable taxonomies', 'alba'); ?></h2><p><?php echo esc_html__('Categories, tags and other terms that can be translated.', 'alba'); ?></p></div></header>
				<div class="mml-card-b">
				<p class="mml-checks">
					<?php foreach (get_taxonomies(['public' => true], 'objects') as $slug => $obj) : if ($slug === 'post_format') { continue; } ?>
						<label class="mml-check">
							<input type="checkbox" name="mml_taxonomies[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $c->taxonomies, true)); ?>>
							<?php echo esc_html($obj->labels->name); ?>
						</label>
					<?php endforeach; ?>
				</p>

								</div></section>
				<section class="mml-card" data-tab="lang">
				<header class="mml-card-h"><span class="dashicons dashicons-text-page"></span><div><h2><?php echo esc_html__('Additional translatable texts', 'alba'); ?></h2><p><?php echo esc_html__('Besides the page text, these texts appear as separate rows in the translation editor.', 'alba'); ?></p></div></header>
				<div class="mml-card-b">
				<div class="mml-fields">
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('Images & attributes', 'alba'); ?></div><div class="mml-ctl">
						<input type="hidden" name="mml_t_attrs" value="0">
						<label><input type="checkbox" name="mml_t_attrs" value="1" <?php checked(1, (int) get_option('mml_t_attrs', 1)); ?>> <?php echo esc_html__('Alt texts, title attributes, ARIA labels and placeholders', 'alba'); ?></label>
						<p class="mml-hint"><?php echo esc_html__('Image captions are normal text and already appear in the editor. This adds the hidden texts that search engines and screen readers read.', 'alba'); ?></p>
					</div></div>
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('Block attributes', 'alba'); ?></div><div class="mml-ctl">
						<input type="hidden" name="mml_t_blocks" value="0">
						<label><input type="checkbox" name="mml_t_blocks" value="1" <?php checked(1, (int) get_option('mml_t_blocks', 1)); ?>> <?php echo esc_html__('Texts in block settings (including ACF blocks)', 'alba'); ?></label>
						<p class="mml-hint"><?php echo esc_html__('Known text fields such as label, title, text and alt text are translated. For ACF blocks, every value with several words is included as well. URLs, colors and field keys are left untouched.', 'alba'); ?></p>
					</div></div>
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('Custom fields', 'alba'); ?></div><div class="mml-ctl">
						<input type="hidden" name="mml_t_acf" value="0">
						<label><input type="checkbox" name="mml_t_acf" value="1" <?php checked(1, (int) get_option('mml_t_acf', 1)); ?>> <?php echo esc_html__('Detect ACF text fields automatically (text, text area, WYSIWYG)', 'alba'); ?></label>
						<?php if (!function_exists('acf_get_field')) : ?><p class="mml-hint"><?php echo esc_html__('ACF is not active on this website; the option takes effect as soon as it is.', 'alba'); ?></p><?php endif; ?>
						<p style="margin:12px 0 4px"><strong><?php echo esc_html__('More field names', 'alba'); ?></strong> <?php echo esc_html__('(meta keys, one per line;', 'alba'); ?> <code>*</code> <?php echo esc_html__('as a wildcard):', 'alba'); ?></p>
						<textarea name="mml_meta_keys" rows="4" class="large-text code" placeholder="untertitel&#10;hero_*"><?php echo esc_textarea((string) get_option('mml_meta_keys', '')); ?></textarea>
						<p class="mml-hint"><?php echo esc_html__('For custom fields and fields from other plugins. On the frontend the text is replaced by the translation when it is read (', 'alba'); ?><code>get_post_meta</code>, <code>get_field</code>).</p>
					</div></div>
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('More attribute names', 'alba'); ?></div><div class="mml-ctl">
						<textarea name="mml_extra_attrs" rows="3" class="large-text code" placeholder="headline&#10;cta_label"><?php echo esc_textarea((string) get_option('mml_extra_attrs', '')); ?></textarea>
						<p class="mml-hint"><?php echo esc_html__('Names of attributes that contain text in WPBakery elements (e.g.', 'alba'); ?> <code>[nectar_btn text="…"]</code><?php echo esc_html__(') and in block settings that should be translated in addition to the default names (title, text, heading, label, caption, header, btn_text …). One per line.', 'alba'); ?></p>
					</div></div>
				</div>
				</div></section>

				<?php if ($c->roles) { $c->roles->card(); } ?>
				<?php if ($c->workflow) { $c->workflow->card(); } ?>
				<?php if ($c->seo) { $c->seo->card(); } ?>
				<section class="mml-card" data-tab="switch">
				<header class="mml-card-h"><span class="dashicons dashicons-randomize"></span><div><h2><?php echo esc_html__('Language switcher', 'alba'); ?></h2><p><?php echo esc_html__('Where and how visitors switch the language.', 'alba'); ?></p></div></header>
				<div class="mml-card-b">
				<div class="mml-fields">
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('Append to menu automatically', 'alba'); ?></div><div class="mml-ctl">
							<select name="mml_menu_location">
								<option value=""><?php echo esc_html__('– not automatic –', 'alba'); ?></option>
								<?php foreach ($menus as $slug => $label) : ?>
									<option value="<?php echo esc_attr($slug); ?>" <?php selected($loc, $slug); ?>><?php echo esc_html($label); ?></option>
								<?php endforeach; ?>
							</select>
						</div></div>
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('Display', 'alba'); ?></div><div class="mml-ctl">
							<select name="mml_display">
								<option value="name" <?php selected(get_option('mml_display', 'name'), 'name'); ?>><?php echo esc_html__('Language name', 'alba'); ?></option>
								<option value="code" <?php selected(get_option('mml_display', 'name'), 'code'); ?>><?php echo esc_html__('Abbreviation (DE, EN)', 'alba'); ?></option>
							</select>
						</div></div>
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('Menu layout', 'alba'); ?></div><div class="mml-ctl">
							<select name="mml_menu_style">
								<option value="list" <?php selected(get_option('mml_menu_style', 'list'), 'list'); ?>><?php echo esc_html__('Languages side by side as menu items', 'alba'); ?></option>
								<option value="dropdown" <?php selected(get_option('mml_menu_style', 'list'), 'dropdown'); ?>><?php echo esc_html__('Dropdown menu (current language with submenu)', 'alba'); ?></option>
							</select>
							<p class="mml-hint"><?php echo esc_html__('The dropdown menu uses the menu classes of your theme or navigation blocks and therefore appears in your theme\'s design. Applies to "Append to menu" and "Block navigation".', 'alba'); ?></p>
						</div></div>
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('Dropdown arrow', 'alba'); ?></div><div class="mml-ctl">
							<select name="mml_dd_arrow">
								<option value="auto" <?php selected(get_option('mml_dd_arrow', 'auto'), 'auto'); ?>><?php echo esc_html__('Automatic (as set in the theme)', 'alba'); ?></option>
								<option value="on" <?php selected(get_option('mml_dd_arrow', 'auto'), 'on'); ?>><?php echo esc_html__('Always show', 'alba'); ?></option>
								<option value="off" <?php selected(get_option('mml_dd_arrow', 'auto'), 'off'); ?>><?php echo esc_html__('Never show', 'alba'); ?></option>
							</select>
							<p class="mml-hint"><?php echo esc_html__('"Automatic" shows the arrow if your theme (e.g. Salient: option for dropdown arrows in the header navigation) marks menu items that have a submenu with an arrow. If it does not appear even though the theme option is enabled, choose "Always show". Applies to the dropdown in the menu, not to block navigation (WordPress draws the arrow itself there).', 'alba'); ?></p>
													<?php $arr = $this->core->frontend ? $this->core->frontend->arrow_report() : null; if ($arr) : ?>
								<p class="mml-hint"><strong><?php echo esc_html__('Automatic currently means:', 'alba'); ?></strong> <?php echo $arr[0] ? esc_html__('arrow shown', 'alba') : esc_html__('no arrow', 'alba'); ?> – <?php echo esc_html($arr[1]); ?></p>
							<?php endif; ?>
						</div></div>
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('Block navigation (block themes)', 'alba'); ?></div><div class="mml-ctl">
							<select name="mml_nav_switcher">
								<option value="" <?php selected(get_option('mml_nav_switcher', ''), ''); ?>><?php echo esc_html__('– not automatic –', 'alba'); ?></option>
								<option value="first" <?php selected(get_option('mml_nav_switcher', ''), 'first'); ?>><?php echo esc_html__('Append to the first navigation', 'alba'); ?></option>
								<option value="all" <?php selected(get_option('mml_nav_switcher', ''), 'all'); ?>><?php echo esc_html__('Append to all navigations', 'alba'); ?></option>
							</select>
							<p class="mml-hint"><?php echo esc_html__('Appends the language links to the navigation blocks. The links of the navigation itself are translated automatically regardless of this setting.', 'alba'); ?></p>
						</div></div>
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('Languages without translation', 'alba'); ?></div><div class="mml-ctl"><label><input type="checkbox" name="mml_hide_missing" value="1" <?php checked(1, (int) get_option('mml_hide_missing', 0)); ?>> <?php echo esc_html__('hide in the menu switcher', 'alba'); ?></label></div></div>
				</div>
				<p><?php echo esc_html__('Everywhere else: shortcode', 'alba'); ?> <code>[mml_switcher style="menu" display="code" hide_missing="1"]</code> (<code>style</code>: <code>list</code>, <code>dropdown</code> <?php echo esc_html__('= select field,', 'alba'); ?> <code>menu</code> <?php echo esc_html__('= dropdown menu) or in the theme', 'alba'); ?> <code>&lt;?php echo mml_switcher(); ?&gt;</code>.</p>

								</div></section>
				<section class="mml-card" data-tab="detect">
				<header class="mml-card-h"><span class="dashicons dashicons-visibility"></span><div><h2><?php echo esc_html__('Language detection', 'alba'); ?></h2><p><?php echo esc_html__('Automatically redirect visitors to their browser language on their first visit.', 'alba'); ?></p></div></header>
				<div class="mml-card-b">
				<div class="mml-fields">
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('Redirect', 'alba'); ?></div><div class="mml-ctl">
							<select name="mml_autodetect">
								<option value="off" <?php selected(MML_Detect::mode(), 'off'); ?>><?php echo esc_html__('Off', 'alba'); ?></option>
								<option value="home" <?php selected(MML_Detect::mode(), 'home'); ?>><?php echo esc_html__('Home page only (recommended)', 'alba'); ?></option>
								<option value="all" <?php selected(MML_Detect::mode(), 'all'); ?>><?php echo esc_html__('Every page, if a translation exists', 'alba'); ?></option>
							</select>
							<p class="mml-hint"><?php echo esc_html__('On the first visit, visitors are redirected to the matching language based on their browser language. If there is no matching language, the page stays unchanged.', 'alba'); ?></p>
						</div></div>
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('Method', 'alba'); ?></div><div class="mml-ctl">
							<select name="mml_autodetect_method">
								<option value="server" <?php selected(MML_Detect::method(), 'server'); ?>><?php echo esc_html__('via server (HTTP redirect)', 'alba'); ?></option>
								<option value="js" <?php selected(MML_Detect::method(), 'js'); ?>><?php echo esc_html__('via JavaScript (cache-friendly)', 'alba'); ?></option>
							</select>
							<p class="mml-hint"><?php echo esc_html__('With a page cache (e.g. LiteSpeed on Hostinger) the home page is often served without PHP: in that case choose "via JavaScript".', 'alba'); ?></p>
						</div></div>
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('Memory & exceptions', 'alba'); ?></div><div class="mml-ctl">
							<p class="mml-hint" style="margin-top:4px"><?php echo esc_html__('A cookie (', 'alba'); ?><code>mml_lang</code><?php echo esc_html__(') remembers the language; after that there is never another redirect, and visitors can switch freely using the switcher. Search engines and other bots are never redirected; neither are requests with', 'alba'); ?> <code>?mml_noredirect=1</code> <?php echo esc_html__('in the address.', 'alba'); ?></p>
						</div></div>
				</div>

								</div></section>
				<?php if ($c->areas) { $c->areas->cards(); } ?>
				<?php if ($c->forms) { $c->forms->card(); } ?>

				<section class="mml-card" data-tab="mt">
				<header class="mml-card-h"><span class="dashicons dashicons-admin-site-alt3"></span><div><h2><?php echo esc_html__('Automatic translation', 'alba'); ?></h2><p><?php echo esc_html__('Suggestions in the translation editor, without a DeepL key. You confirm every text yourself.', 'alba'); ?></p></div></header>
				<div class="mml-card-b">
				<div class="mml-fields">
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('Provider', 'alba'); ?></div><div class="mml-ctl">
						<select name="mml_mt_provider" id="mml-mt-provider">
							<option value="off" <?php selected(MML_MT::provider(), 'off'); ?>><?php echo esc_html__('Off', 'alba'); ?></option>
							<option value="mymemory" <?php selected(MML_MT::provider(), 'mymemory'); ?>><?php echo esc_html__('MyMemory (free, no key)', 'alba'); ?></option>
							<option value="libre" <?php selected(MML_MT::provider(), 'libre'); ?>><?php echo esc_html__('LibreTranslate (own server or service)', 'alba'); ?></option>
						</select>
						<p class="mml-hint"><?php echo esc_html__('In the editor, every field has a "Suggest automatically" button. The suggestion is only displayed; it is put into the field only when you click "Apply", and it is saved as usual with "Save". Glossary terms are protected and inserted correctly afterwards.', 'alba'); ?></p>
						<p class="mml-hint"><strong><?php echo esc_html__('Privacy:', 'alba'); ?></strong> <?php echo esc_html__('For suggestions, the original texts are sent to the selected service. This only happens when you click in the editor, never automatically in the background.', 'alba'); ?></p>
					</div></div>
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('MyMemory: email', 'alba'); ?></div><div class="mml-ctl">
						<input type="email" class="regular-text" name="mml_mt_email" value="<?php echo esc_attr(get_option('mml_mt_email', '')); ?>" placeholder="<?php echo esc_attr__('name@example.com', 'alba'); ?>">
						<p class="mml-hint"><?php echo esc_html__('Optional. With an email address MyMemory allows considerably more characters per day (about 50,000 instead of 5,000). The address is only sent to MyMemory.', 'alba'); ?></p>
					</div></div>
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('LibreTranslate: URL', 'alba'); ?></div><div class="mml-ctl">
						<input type="url" class="regular-text" name="mml_mt_url" value="<?php echo esc_attr(get_option('mml_mt_url', '')); ?>" placeholder="https://libretranslate.example.com">
						<p class="mml-hint"><?php echo esc_html__('URL of your LibreTranslate server (without', 'alba'); ?> <code>/translate</code><?php echo esc_html__('). The public service libretranslate.com requires an API key; your own servers often do not.', 'alba'); ?></p>
					</div></div>
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('LibreTranslate: API key', 'alba'); ?></div><div class="mml-ctl">
						<input type="text" class="regular-text" name="mml_mt_key" value="<?php echo esc_attr(get_option('mml_mt_key', '')); ?>" autocomplete="off">
						<p class="mml-hint"><?php echo esc_html__('Optional, depending on the server. It is saved in the settings and only used for requests to your server.', 'alba'); ?></p>
					</div></div>
				</div>
				</div></section>

				<section class="mml-card" data-tab="cache">
				<header class="mml-card-h"><span class="dashicons dashicons-performance"></span><div><h2><?php echo esc_html__('Page cache', 'alba'); ?></h2><p><?php echo esc_html__('So visitors see changes immediately.', 'alba'); ?></p></div></header>
				<div class="mml-card-b">
				<div class="mml-fields">
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('Clear page cache', 'alba'); ?></div><div class="mml-ctl">
							<select name="mml_cache_purge">
								<option value="smart" <?php selected(MML_Cache::mode(), 'smart'); ?>><?php echo esc_html__('When saving a translation: affected pages and home pages', 'alba'); ?></option>
								<option value="all" <?php selected(MML_Cache::mode(), 'all'); ?>><?php echo esc_html__('When saving a translation: the entire cache', 'alba'); ?></option>
								<option value="off" <?php selected(MML_Cache::mode(), 'off'); ?>><?php echo esc_html__('Never automatically', 'alba'); ?></option>
							</select>
							<a class="button" href="<?php echo esc_url(MML_Cache::purge_url()); ?>" style="margin-left:8px"><?php echo esc_html__('Clear cache now', 'alba'); ?></a>
							<p class="mml-hint"><?php echo esc_html__('Supported: WP Rocket, LiteSpeed Cache (including the LiteSpeed server cache, e.g. on Hostinger), W3 Total Cache, WP Super Cache, WP Fastest Cache, Breeze, SG Optimizer and Nginx Helper. The entire cache is also cleared when you change language settings or strings. Caches that cannot clear individual pages (and the LiteSpeed server cache without the plugin) are always cleared completely.', 'alba'); ?></p>
						</div></div>
				</div>

								</div></section>
				<section class="mml-card" data-tab="system">
				<header class="mml-card-h"><span class="dashicons dashicons-backup"></span><div><h2><?php echo esc_html__('Change log', 'alba'); ?></h2><p><?php echo esc_html__('Who saved which translation, and when.', 'alba'); ?></p></div></header>
				<div class="mml-card-b"><div class="mml-fields">
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('Retention', 'alba'); ?></div><div class="mml-ctl">
						<select name="mml_log_days">
							<?php foreach ([30 => __('30 days', 'alba'), 90 => __('90 days', 'alba'), 180 => __('180 days', 'alba'), 365 => __('1 year', 'alba'), 0 => 'unbegrenzt'] as $dv => $dl) : ?><option value="<?php echo (int) $dv; ?>" <?php selected(MML_Log::days(), $dv); ?>><?php echo esc_html($dl); ?></option><?php endforeach; ?>
						</select>
						<p class="mml-hint"><?php echo esc_html__('Older entries are deleted automatically. You can find the log under', 'alba'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=alba-log')); ?>"><?php echo esc_html__('Languages > Log', 'alba'); ?></a>.</p>
					</div></div>
				</div></div></section>

				<section class="mml-card" data-tab="system">
				<header class="mml-card-h"><span class="dashicons dashicons-trash"></span><div><h2><?php echo esc_html__('Uninstall', 'alba'); ?></h2><p><?php echo esc_html__('What happens to your data when the plugin is deleted.', 'alba'); ?></p></div></header>
				<div class="mml-card-b">
				<div class="mml-fields">
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('When deleting the plugin', 'alba'); ?></div><div class="mml-ctl">
							<select name="mml_uninstall">
								<option value="ask" <?php selected(get_option('mml_uninstall', 'ask'), 'ask'); ?>><?php echo esc_html__('Remove data, ask first', 'alba'); ?></option>
								<option value="delete" <?php selected(get_option('mml_uninstall', 'ask'), 'delete'); ?>><?php echo esc_html__('Remove data, without asking', 'alba'); ?></option>
								<option value="keep" <?php selected(get_option('mml_uninstall', 'ask'), 'keep'); ?>><?php echo esc_html__('Keep data', 'alba'); ?></option>
							</select>
							<p class="mml-hint"><?php echo esc_html__('Applies only to "Delete" in the plugin list, not to "Deactivate" and not to replacing the plugin with an uploaded new version. Removed are settings, strings, and language and translation data on posts and terms (including translations from the side-by-side editor). Separate translation posts (copies) remain as normal content but lose their language assignment.', 'alba'); ?></p>
						</div></div>
				</div>

								</div></section>
				<?php if (class_exists('MML_Updater')) : ?>
				<section class="mml-card" data-tab="system">
				<header class="mml-card-h"><span class="dashicons dashicons-update"></span><div><h2><?php echo esc_html__('Updates', 'alba'); ?></h2><p><?php echo esc_html__('Where updates for this plugin come from.', 'alba'); ?></p></div></header>
				<div class="mml-card-b">
				<div class="mml-fields">
					<div class="mml-field"><div class="mml-label"><label for="mml_update_url"><?php echo esc_html__('Update source', 'alba'); ?></label></div><div class="mml-ctl">
							<?php if (defined('MML_UPDATE_URL') && MML_UPDATE_URL !== '') : ?>
								<p><?php echo esc_html__('The source comes from the constant', 'alba'); ?> <code>MML_UPDATE_URL</code> (wp-config.php): <code><?php echo esc_html(MML_UPDATE_URL); ?></code></p>
							<?php else : ?>
								<input type="url" class="regular-text" id="mml_update_url" name="mml_update_url" value="<?php echo esc_attr(get_option('mml_update_url', '')); ?>" placeholder="https://github.com/username/alba">
							<?php endif; ?>
							<p class="mml-hint"><?php echo esc_html__('GitHub repository (latest release) or URL of a JSON file. If a version higher than', 'alba'); ?> <?php echo esc_html(MML_VERSION); ?><?php echo esc_html__('is available there, the update appears like for any other plugin under Dashboard > Updates and in the plugin list.', 'alba'); ?> <a href="<?php echo esc_url(MML_Updater::check_url()); ?>"><?php echo esc_html__('Check for updates now', 'alba'); ?></a></p>
						</div></div>
				</div>

				</div></section>
				<?php endif; ?>

				<div class="mml-save"><?php submit_button(__('Save changes', 'alba'), 'primary', 'submit', false); ?><span><?php echo esc_html__('Saves the settings of all sections.', 'alba'); ?></span></div>
			</form>
		</div>
		<script>
		(function () {
			var tabs = [].slice.call(document.querySelectorAll('#mml-tabs a'));
			var cards = [].slice.call(document.querySelectorAll('.mml-card'));
			function show(t) {
				if (!tabs.some(function (a) { return a.getAttribute('data-tab') === t; })) { t = 'lang'; }
				tabs.forEach(function (a) { a.classList.toggle('is-active', a.getAttribute('data-tab') === t); });
				cards.forEach(function (c) { c.classList.toggle('is-active', c.getAttribute('data-tab') === t); });
			}
			tabs.forEach(function (a) {
				a.addEventListener('click', function (e) {
					e.preventDefault();
					var t = a.getAttribute('data-tab');
					show(t);
					try { history.replaceState(null, '', '#' + t); } catch (x) {}
				});
			});
			show((location.hash || '').replace('#', ''));
		})();
		</script>
		<script>
		(function () {
			var body = document.getElementById('mml-rows');
			var idx = body.children.length + 100;
			function addRow(code, name, locale) {
				idx++;
				var tr = document.createElement('tr');
				tr.innerHTML = '<td><input type="radio" name="mml_default" value="' + idx + '"></td>' +
					'<td><input type="text" size="8" name="mml_languages[' + idx + '][code]"></td>' +
					'<td><input type="text" name="mml_languages[' + idx + '][name]"></td>' +
					'<td><input type="text" size="10" name="mml_languages[' + idx + '][locale]"></td>' +
					'<td><button type="button" class="button-link-delete mml-del">Entfernen</button></td>';
				tr.querySelector('[name$="[code]"]').value = code || '';
				tr.querySelector('[name$="[name]"]').value = name || '';
				tr.querySelector('[name$="[locale]"]').value = locale || '';
				body.appendChild(tr);
			}
			document.getElementById('mml-add').addEventListener('click', function () {
				var sel = document.getElementById('mml-preset');
				var o = sel.options[sel.selectedIndex];
				addRow(o.value, o.getAttribute('data-name'), o.getAttribute('data-locale'));
			});
			body.addEventListener('click', function (e) {
				if (e.target.classList.contains('mml-del')) {
					e.target.closest('tr').remove();
				}
			});
		})();
		</script>
		<?php
	}

	/* ---------------------------------------------------------------
	 * Beitragsliste: Spalte + Filter
	 * ------------------------------------------------------------- */

	public function list_hooks() {
		foreach ($this->core->post_types as $t) {
			add_filter("manage_edit-{$t}_columns", [$this, 'col_head']);
			add_action("manage_{$t}_posts_custom_column", [$this, 'col_body'], 10, 2);
		}
	}

	public function col_head($cols) {
		$cols['mml'] = __('Languages', 'alba');
		return $cols;
	}

	public function col_body($col, $post_id) {
		if ($col !== 'mml') {
			return;
		}
		$c = $this->core;
		if (!$c->is_translatable(get_post_type($post_id))) {
			return;
		}
		$tr = $c->translations($post_id);
		foreach ($c->langs as $code => $l) {
			$label = strtoupper($code);
			if (isset($tr[$code])) {
				$old = ($c->editor && $code !== $c->default && $c->editor->outdated_real($tr[$code]));
				echo '<a class="mml-pill mml-has' . ($old ? ' mml-old' : '') . '" href="' . esc_url(get_edit_post_link($tr[$code])) . '" title="' . esc_attr($l['name'] . ': ' . get_the_title($tr[$code]) . ($old ? __(' – original changed, update translation', 'alba') : '')) . '">' . esc_html($label) . ($old ? ' <span class="dashicons dashicons-update"></span>' : '') . '</a>';
			} else {
				$st = $c->editor ? $c->editor->state($post_id, $code) : '';
				$old = ($st !== '' && $c->editor->outdated($post_id, $code));
				$upd = $old ? ' <span class="dashicons dashicons-update"></span>' : '';
				$note = $old ? __(' – original changed, update translation', 'alba') : '';
				if ($st === 'on') {
					echo '<a class="mml-pill mml-has' . ($old ? ' mml-old' : '') . '" href="' . esc_url($c->editor->url($post_id, $code)) . '" title="' . esc_attr($l['name'] . __(': translated (editor)', 'alba') . $note) . '">' . esc_html($label) . $upd . '</a>';
				} elseif ($st === 'draft') {
					echo '<a class="mml-pill mml-draft' . ($old ? ' mml-old' : '') . '" href="' . esc_url($c->editor->url($post_id, $code)) . '" title="' . esc_attr($l['name'] . __(': translation not published yet', 'alba') . $note) . '">' . esc_html($label) . $upd . '</a>';
				} else {
					echo '<a class="mml-pill mml-add" href="' . esc_url($c->editor ? $c->editor->url($post_id, $code) : $this->new_url($post_id, $code)) . '" title="' . esc_attr(__('Translate: ', 'alba') . $l['name']) . '">+ ' . esc_html($label) . '</a>';
				}
			}
		}
	}

	public function filter_dropdown($post_type) {
		if (!$this->core->is_translatable($post_type)) {
			return;
		}
		$cur = isset($_GET['mml_lang']) ? sanitize_key(wp_unslash($_GET['mml_lang'])) : '';
		echo '<select name="mml_lang"><option value="">' . esc_html__('All languages', 'alba') . '</option>';
		foreach ($this->core->langs as $code => $l) {
			echo '<option value="' . esc_attr($code) . '"' . selected($cur, $code, false) . '>' . esc_html($l['name']) . '</option>';
		}
		echo '</select>';
	}

	/** Rückfrage beim Löschen in der Plugin-Liste (bei Einstellung „vorher nachfragen“) */
	public function delete_confirm() {
		if (get_option('mml_uninstall', 'ask') !== 'ask') {
			return;
		}
		$msg = __('When deleting, all settings, strings, and language and translation data of Alba are removed (including the translations from the side-by-side editor). Separate translation posts remain as normal content. Delete the plugin anyway?', 'alba');
		echo '<script>document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest("tr[data-plugin=\"alba/alba.php\"] .delete a");if(!a)return;if(!window.confirm(' . wp_json_encode($msg) . ')){e.preventDefault();e.stopImmediatePropagation();}},true);</script>';
	}

	public function css() {
		echo '<style>.column-mml{width:130px}.mml-pill{display:inline-block;padding:0 6px;margin:0 3px 3px 0;border-radius:3px;font-size:11px;line-height:18px;text-decoration:none;border:1px solid #c3c4c7;background:#f6f7f7}.mml-has{background:#d7f0dd;border-color:#68b17a}.mml-draft{background:#fcf0c8;border-color:#dba617}.mml-old{background:#fde8d4;border-color:#d63638;color:#8a2424}.mml-pill .dashicons{font-size:13px;width:13px;height:13px;line-height:18px;vertical-align:top}.mml-add{color:#787c82}</style>';
	}

	/* ---------------------------------------------------------------
	 * Metabox im Editor
	 * ------------------------------------------------------------- */

	public function add_box() {
		foreach ($this->core->post_types as $t) {
			add_meta_box('mml_box', __('Language & Translations', 'alba'), [$this, 'box'], $t, 'side', 'high');
		}
	}

	private function new_url($post_id, $code) {
		return wp_nonce_url(
			admin_url('admin-post.php?action=mml_new_translation&post=' . (int) $post_id . '&lang=' . rawurlencode($code)),
			'mml_new_' . (int) $post_id . '_' . $code
		);
	}

	private function candidates($post, $code) {
		return get_posts([
			'post_type'        => $post->post_type,
			'post_status'      => ['publish', 'draft', 'pending', 'private', 'future'],
			'posts_per_page'   => 100,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'post__not_in'     => [$post->ID],
			'suppress_filters' => true,
			'mml_skip'         => 1,
			'meta_query'       => [$this->core->lang_meta_query($code)],
		]);
	}

	public function box($post) {
		$c = $this->core;
		wp_nonce_field('mml_save_' . $post->ID, 'mml_nonce');

		$is_new = ($post->post_status === 'auto-draft');
		$req = isset($_GET['mml_lang']) ? sanitize_key(wp_unslash($_GET['mml_lang'])) : '';
		$cur = ($is_new && isset($c->langs[$req])) ? $req : $c->post_lang($post->ID);

		echo '<p><label for="mml_lang"><strong>' . esc_html__('Language of this content', 'alba') . '</strong></label></p>';
		echo '<select name="mml_lang" id="mml_lang" style="width:100%">';
		foreach ($c->langs as $code => $l) {
			echo '<option value="' . esc_attr($code) . '"' . selected($cur, $code, false) . '>' . esc_html($l['name']) . '</option>';
		}
		echo '</select>';

		if ($is_new) {
			echo '<p class="description">' . esc_html__('Save first, then translations can be created.', 'alba') . '</p>';
			return;
		}

		$tr = $c->translations($post->ID);
		echo '<p style="margin-top:14px"><strong>' . esc_html__('Translations', 'alba') . '</strong></p><ul style="margin:0">';
		foreach ($c->langs as $code => $l) {
			if ($code === $cur) {
				continue;
			}
			echo '<li style="margin-bottom:10px"><strong>' . esc_html($l['name']) . '</strong><br>';
			if (isset($tr[$code])) {
				$tid = $tr[$code];
				echo '<a href="' . esc_url(get_edit_post_link($tid)) . '">' . esc_html(get_the_title($tid) ?: __('(no title)', 'alba')) . '</a> <em>(' . esc_html(get_post_status($tid)) . ')</em>';
				if ($c->editor && $code !== $c->default && $c->editor->outdated_real($tid)) {
					echo '<br><span style="color:#d63638"><span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px"></span> ' . esc_html__('Original changed – update the translation and save', 'alba') . '</span>';
				}
			} else {
				$st = $c->editor ? $c->editor->state($post->ID, $code) : '';
				if ($c->editor) {
					$lbl = ($st !== '' && $c->editor->outdated($post->ID, $code)) ? __('Update translation (original changed)', 'alba') : ($st === 'on' ? __('Edit translation', 'alba') : ($st === 'draft' ? __('Edit translation (not published)', 'alba') : __('Translate (side by side)', 'alba')));
					echo '<a class="button button-small' . ($st === '' ? ' button-primary' : '') . '" href="' . esc_url($c->editor->url($post->ID, $code)) . '">' . esc_html($lbl) . '</a><br>';
				}
				echo '<a href="' . esc_url($this->new_url($post->ID, $code)) . '" onclick="return confirm(\'' . esc_js(__('Create a separate post as a copy instead? An existing editor translation will be replaced.', 'alba')) . '\');" style="font-size:12px">' . esc_html__('Create as separate post', 'alba') . '</a>';
				$cands = $this->candidates($post, $code);
				if ($cands) {
					echo '<br><select name="mml_link[' . esc_attr($code) . ']" style="width:100%;margin-top:4px"><option value="">' . esc_html__('… or link to an existing one', 'alba') . '</option>';
					foreach ($cands as $p) {
						echo '<option value="' . (int) $p->ID . '">' . esc_html($p->post_title ?: __('(no title)', 'alba')) . '</option>';
					}
					echo '</select>';
				}
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '<p class="description">' . esc_html__('When creating, content, featured image and meta fields are copied; the new content starts as a draft.', 'alba') . '</p>';
	}

	public function save($post_id, $post) {
		$c = $this->core;
		if (!$c->is_translatable($post->post_type)) {
			return;
		}
		if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id)) {
			return;
		}
		if (!isset($_POST['mml_nonce']) || !wp_verify_nonce(wp_unslash($_POST['mml_nonce']), 'mml_save_' . $post_id)) {
			return;
		}
		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		$lang = isset($_POST['mml_lang']) ? sanitize_key(wp_unslash($_POST['mml_lang'])) : '';
		if (!isset($c->langs[$lang])) {
			$lang = $c->default;
		}
		$changed = ($c->post_lang($post_id) !== $lang) || !empty($_POST['mml_link']);
		update_post_meta($post_id, '_mml_lang', $lang);
		$group = $c->group($post_id, true);

		if (!empty($_POST['mml_link']) && is_array($_POST['mml_link'])) {
			foreach (wp_unslash($_POST['mml_link']) as $l => $tid) {
				$l = sanitize_key($l);
				$tid = (int) $tid;
				if (!$tid || !isset($c->langs[$l]) || $l === $lang || !current_user_can('edit_post', $tid)) {
					continue;
				}
				update_post_meta($tid, '_mml_lang', $l);
				update_post_meta($tid, '_mml_group', $group);
				delete_post_meta($post_id, '_mml_on_' . $l);
			}
		}
		$c->flush();
		if ($c->editor) {
			$c->editor->mark_synced($post_id);   // Übersetzung gespeichert = gilt als aktuell
		}
		if ($changed) {
			do_action('mml_post_links_saved', $post_id);   // Sprache/Verknüpfung geändert -> Cache leeren
		}
	}

	/* ---------------------------------------------------------------
	 * Übersetzung anlegen (Kopie als Entwurf)
	 * ------------------------------------------------------------- */

	public function create_translation() {
		$c = $this->core;
		$id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
		$lang = isset($_GET['lang']) ? sanitize_key(wp_unslash($_GET['lang'])) : '';
		check_admin_referer('mml_new_' . $id . '_' . $lang);

		$src = $id ? get_post($id) : null;
		if (!$src || !isset($c->langs[$lang]) || !$c->is_translatable($src->post_type) || !current_user_can('edit_post', $id)) {
			wp_die(__('Not allowed.', 'alba'));
		}

		$existing = $c->translation($id, $lang, false);
		if ($existing) {
			wp_safe_redirect(get_edit_post_link($existing, 'raw'));
			exit;
		}

		$new = $c->duplicate_post($id, $lang, false);
		if (is_wp_error($new)) {
			wp_die(esc_html($new->get_error_message()));
		}
		wp_safe_redirect(get_edit_post_link($new, 'raw'));
		exit;
	}
}
