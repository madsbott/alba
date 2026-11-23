<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Glossar und Übersetzungsspeicher.
 *
 * Glossar (Option alba_glossary): Begriffe der Standardsprache, die entweder unverändert bleiben (Firmen-/Produktnamen)
 * oder in jeder Sprache einheitlich übersetzt werden. Im Übersetzungseditor werden Treffer angezeigt, "Original übernehmen"
 * wendet das Glossar an, und fehlende Begriffe in der Übersetzung werden markiert.
 *
 * Übersetzungsspeicher (Option alba_tm, nicht autoloaded): merkt sich jeden gespeicherten Abschnitt (md5 des Originaltexts =>
 * Übersetzung) je Sprache. Taucht derselbe Text in einem anderen Beitrag auf, wird die bekannte Übersetzung vorgeschlagen.
 */
class ALBA_Glossary {
	const OPT = 'alba_glossary';
	const TM = 'alba_tm';
	const TM_MAX = 3000;
	const PAGE = 'alba-glossary';

	private $core;
	private $tm = null;
	private $entries = null;

	public function __construct(ALBA_Core $core) {
		$this->core = $core;
		$core->glossary = $this;

		add_action('admin_menu', [$this, 'menu']);
		add_action('admin_post_alba_save_glossary', [$this, 'handle_save']);
		add_action('admin_post_alba_tm', [$this, 'handle_tm']);
		add_action('alba_translation_saved', [$this, 'remember'], 5, 2);
		add_action('admin_enqueue_scripts', [$this, 'assets']);
	}

	/* ---------------------------------------------------------------
	 * Daten
	 * ------------------------------------------------------------- */

	public function entries() {
		if ($this->entries === null) {
			$e = get_option(self::OPT, []);
			$this->entries = is_array($e) ? array_values($e) : [];
		}
		return $this->entries;
	}

	private function regex($term, $cs) {
		return '~(?<![\p{L}\p{N}_])' . preg_quote($term, '~') . '(?![\p{L}\p{N}_])~u' . ($cs ? '' : 'i');
	}

	/** Wirksame Einträge für eine Zielsprache: [term, target, keep, cs], längste Begriffe zuerst */
	private function active($lang) {
		$out = [];
		foreach ($this->entries() as $e) {
			$term = isset($e['term']) ? (string) $e['term'] : '';
			if ($term === '') {
				continue;
			}
			$keep = !empty($e['keep']);
			$target = $keep ? $term : (isset($e['tr'][$lang]) ? (string) $e['tr'][$lang] : '');
			if ($target === '') {
				continue;
			}
			$out[] = ['term' => $term, 'target' => $target, 'keep' => $keep, 'cs' => !empty($e['cs'])];
		}
		usort($out, function ($a, $b) {
			return mb_strlen($b['term']) - mb_strlen($a['term']);
		});
		return $out;
	}

	/** Im Text vorkommende Glossarbegriffe */
	public function terms_in($text, $lang) {
		$plain = wp_strip_all_tags((string) $text);
		$found = [];
		foreach ($this->active($lang) as $e) {
			if (@preg_match($this->regex($e['term'], $e['cs']), $plain) === 1) {
				$found[] = $e;
			}
		}
		return $found;
	}

	/** Ersetzt Glossarbegriffe durch Platzhalter (für maschinelle Übersetzung): [Text, Map] */
	public function protect($html, $lang) {
		$map = [];
		$list = $this->active($lang);
		if (!$list) {
			return [(string) $html, $map];
		}
		$parts = preg_split('/(<[^>]*>)/', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE);
		foreach ($parts as $i => $p) {
			if ($p === '' || $p[0] === '<') {
				continue;
			}
			foreach ($list as $e) {
				$r = @preg_replace_callback($this->regex($e['term'], $e['cs']), function () use ($e, &$map) {
					$n = count($map);
					$map[$n] = $e['target'];
					return 'ZZ' . $n . 'ZZ';
				}, $p);
				if (is_string($r)) {
					$p = $r;
				}
			}
			$parts[$i] = $p;
		}
		return [implode('', $parts), $map];
	}

	public function restore($text, $map) {
		return (string) preg_replace_callback('/ZZ\s?(\d+)\s?ZZ/i', function ($m) use ($map) {
			return isset($map[(int) $m[1]]) ? htmlspecialchars($map[(int) $m[1]], ENT_NOQUOTES, 'UTF-8') : $m[0];
		}, $text);
	}

	/** Wendet das Glossar auf (HTML-)Text an; Tags und Attribute bleiben unberührt */
	public function apply($html, $lang) {
		$list = array_filter($this->active($lang), function ($e) {
			return !$e['keep'] && $e['target'] !== $e['term'];
		});
		if (!$list) {
			return (string) $html;
		}
		$parts = preg_split('/(<[^>]*>)/', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE);
		foreach ($parts as $i => $p) {
			if ($p === '' || $p[0] === '<') {
				continue;
			}
			foreach ($list as $e) {
				$r = @preg_replace_callback($this->regex($e['term'], $e['cs']), function () use ($e) {
					return $e['target'];
				}, $p);
				if (is_string($r)) {
					$p = $r;
				}
			}
			$parts[$i] = $p;
		}
		return implode('', $parts);
	}

	/* ---------------------------------------------------------------
	 * Übersetzungsspeicher
	 * ------------------------------------------------------------- */

	private function tm() {
		if ($this->tm === null) {
			$t = get_option(self::TM, null);
			if ($t === null) {
				$t = $this->scan();
				update_option(self::TM, $t, false);
			}
			$this->tm = is_array($t) ? $t : [];
		}
		return $this->tm;
	}

	/** Baut den Speicher aus allen vorhandenen Editor-Übersetzungen auf */
	private function scan() {
		global $wpdb;
		$tm = [];
		$rows = $wpdb->get_results("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_alba\\_tr\\_%' ORDER BY meta_id DESC LIMIT 4000");
		foreach ((array) $rows as $r) {
			$lang = substr($r->meta_key, strlen('_alba_tr_'));
			$d = maybe_unserialize($r->meta_value);
			if (!isset($this->core->langs[$lang]) || !is_array($d) || empty($d['seg']) || !is_array($d['seg'])) {
				continue;
			}
			foreach ($d['seg'] as $h => $t) {
				if (is_string($t) && trim($t) !== '' && !isset($tm[$lang][$h])) {
					$tm[$lang][(string) $h] = $t;
				}
			}
		}
		foreach ($tm as $l => $m) {
			$tm[$l] = array_slice($m, 0, self::TM_MAX, true);
		}
		return $tm;
	}

	public function suggest($hash, $lang) {
		$tm = $this->tm();
		return isset($tm[$lang][$hash]) ? (string) $tm[$lang][$hash] : '';
	}

	public function remember($post_id, $lang = '') {
		if (!$lang || !isset($this->core->langs[$lang]) || !$this->core->editor) {
			return;
		}
		$d = $this->core->editor->data($post_id, $lang);
		if (empty($d['seg']) || !is_array($d['seg'])) {
			return;
		}
		$tm = $this->tm();
		$cur = isset($tm[$lang]) ? $tm[$lang] : [];
		foreach ($d['seg'] as $h => $t) {
			if (is_string($t) && trim($t) !== '') {
				unset($cur[$h]);
				$cur[(string) $h] = $t;
			}
		}
		if (count($cur) > self::TM_MAX) {
			$cur = array_slice($cur, -self::TM_MAX, null, true);
		}
		$tm[$lang] = $cur;
		$this->tm = $tm;
		update_option(self::TM, $tm, false);
	}

	/* ---------------------------------------------------------------
	 * Admin-Seite
	 * ------------------------------------------------------------- */

	public function menu() {
		add_submenu_page('alba', __('Languages – Glossary', 'alba'), __('Glossary &amp; Memory', 'alba'), 'manage_options', self::PAGE, [$this, 'page']);
	}

	public function assets() {
		if (!isset($_GET['page']) || $_GET['page'] !== self::PAGE) {
			return;
		}
		wp_enqueue_script('alba-glossary', ALBA_URL . 'assets/glossary.js', [], ALBA_VERSION, true);
	}

	public function page() {
		if (!current_user_can('manage_options')) {
			return;
		}
		$c = $this->core;
		$targets = array_diff_key($c->langs, [$c->default => 1]);
		$entries = $this->entries();
		if (!$entries) {
			$entries = [['term' => '', 'keep' => 1, 'cs' => 0, 'tr' => []]];
		}
		$tm = $this->tm();
		?>
		<div class="wrap alba-gl">
			<h1><?php echo esc_html__('Glossary & Translation memory', 'alba'); ?></h1>
			<?php if (!empty($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Saved.', 'alba'); ?></p></div><?php endif; ?>
			<?php if (!empty($_GET['tm_done'])) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Translation memory updated.', 'alba'); ?></p></div><?php endif; ?>
			<style>
				.alba-gl{max-width:960px}
				.alba-gl .alba-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;margin:16px 0;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.04)}
				.alba-gl .alba-card-h{padding:16px 22px;background:#f6f7f7;border-bottom:1px solid #dcdcde}
				.alba-gl .alba-card-h h2{margin:0 0 2px;padding:0;font-size:16px}
				.alba-gl .alba-card-h p{margin:0;color:#646970}
				.alba-gl .alba-card-b{padding:16px 22px}
				.alba-gl .alba-gl-row{display:grid;grid-template-columns:1fr 200px auto;gap:10px 14px;align-items:start;padding:14px;border:1px solid #dcdcde;border-radius:6px;margin-bottom:10px;background:#fcfcfc}
				.alba-gl .alba-gl-row input[type=text]{width:100%}
				.alba-gl .alba-gl-tr{grid-column:1/-1;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px 14px;padding-top:4px}
				.alba-gl .alba-gl-tr label{display:block;font-size:12px;color:#646970;margin-bottom:2px}
				.alba-gl .alba-gl-row.is-keep .alba-gl-tr{display:none}
				.alba-gl .alba-gl-opts{grid-column:1/-1;font-size:13px;color:#50575e}
				.alba-gl .alba-save{margin-top:14px;display:flex;gap:12px;align-items:center}
				.alba-gl table.alba-stat{border-collapse:collapse}
				.alba-gl table.alba-stat td,.alba-gl table.alba-stat th{padding:6px 18px 6px 0;text-align:left}
			</style>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="alba-gl-form">
				<input type="hidden" name="action" value="alba_save_glossary">
				<?php wp_nonce_field('alba_glossary'); ?>
				<section class="alba-card">
					<header class="alba-card-h"><h2><?php echo esc_html__('Glossary', 'alba'); ?></h2><p><?php echo esc_html__('Terms in', 'alba'); ?> <?php echo esc_html($c->langs[$c->default]['name']); ?> <?php echo esc_html__('(default language) that should be treated consistently, e.g. company and product names.', 'alba'); ?></p></header>
					<div class="alba-card-b">
						<div id="alba-gl-rows">
						<?php foreach ($entries as $i => $e) : $keep = !isset($e['keep']) || !empty($e['keep']); ?>
							<div class="alba-gl-row<?php echo $keep ? ' is-keep' : ''; ?>">
								<div><input type="text" name="gl[<?php echo (int) $i; ?>][term]" value="<?php echo esc_attr(isset($e['term']) ? $e['term'] : ''); ?>" placeholder="<?php echo esc_attr__('Term, e.g. Acme Studio', 'alba'); ?>"></div>
								<div>
									<select name="gl[<?php echo (int) $i; ?>][mode]" class="alba-gl-mode">
										<option value="keep" <?php selected($keep); ?>><?php echo esc_html__('Keep unchanged', 'alba'); ?></option>
										<option value="tr" <?php selected(!$keep); ?>><?php echo esc_html__('Translate consistently', 'alba'); ?></option>
									</select>
								</div>
								<div><button type="button" class="button-link-delete alba-gl-del"><?php echo esc_html__('Remove', 'alba'); ?></button></div>
								<div class="alba-gl-tr">
									<?php foreach ($targets as $code => $l) : ?>
										<div><label><?php echo esc_html($l['name']); ?></label><input type="text" name="gl[<?php echo (int) $i; ?>][tr][<?php echo esc_attr($code); ?>]" value="<?php echo esc_attr(isset($e['tr'][$code]) ? $e['tr'][$code] : ''); ?>"></div>
									<?php endforeach; ?>
								</div>
								<div class="alba-gl-opts"><label><input type="checkbox" name="gl[<?php echo (int) $i; ?>][cs]" value="1" <?php checked(!empty($e['cs'])); ?>> <?php echo esc_html__('Case sensitive', 'alba'); ?></label></div>
							</div>
						<?php endforeach; ?>
						</div>
						<p><button type="button" class="button" id="alba-gl-add"><?php echo esc_html__('Add term', 'alba'); ?></button></p>
						<p class="description"><?php echo esc_html__('In the translation editor, matches are shown below the field. "Copy original" inserts the terms correctly right away, and if a term is missing in your translation it is marked red. Only whole words are matched.', 'alba'); ?></p>
						<div class="alba-save"><button type="submit" class="button button-primary"><?php echo esc_html__('Save glossary', 'alba'); ?></button></div>
					</div>
				</section>
			</form>
			<template id="alba-gl-tpl">
				<div class="alba-gl-row is-keep">
					<div><input type="text" name="gl[__i__][term]" value="" placeholder="<?php echo esc_attr__('Term', 'alba'); ?>"></div>
					<div><select name="gl[__i__][mode]" class="alba-gl-mode"><option value="keep"><?php echo esc_html__('Keep unchanged', 'alba'); ?></option><option value="tr"><?php echo esc_html__('Translate consistently', 'alba'); ?></option></select></div>
					<div><button type="button" class="button-link-delete alba-gl-del"><?php echo esc_html__('Remove', 'alba'); ?></button></div>
					<div class="alba-gl-tr">
						<?php foreach ($targets as $code => $l) : ?>
							<div><label><?php echo esc_html($l['name']); ?></label><input type="text" name="gl[__i__][tr][<?php echo esc_attr($code); ?>]" value=""></div>
						<?php endforeach; ?>
					</div>
					<div class="alba-gl-opts"><label><input type="checkbox" name="gl[__i__][cs]" value="1"> <?php echo esc_html__('Case sensitive', 'alba'); ?></label></div>
				</div>
			</template>

			<section class="alba-card">
				<header class="alba-card-h"><h2><?php echo esc_html__('Translation memory', 'alba'); ?></h2><p><?php echo esc_html__('Every saved section is remembered. If the same text appears elsewhere, the editor suggests the known translation.', 'alba'); ?></p></header>
				<div class="alba-card-b">
					<table class="alba-stat">
						<tr><th><?php echo esc_html__('Language', 'alba'); ?></th><th><?php echo esc_html__('Saved sections', 'alba'); ?></th></tr>
						<?php foreach ($targets as $code => $l) : ?>
							<tr><td><?php echo esc_html($l['name']); ?></td><td><?php echo (int) count(isset($tm[$code]) ? $tm[$code] : []); ?></td></tr>
						<?php endforeach; ?>
					</table>
					<p>
						<a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=alba_tm&do=rebuild'), 'alba_tm')); ?>"><?php echo esc_html__('Rebuild from existing translations', 'alba'); ?></a>
						<a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=alba_tm&do=clear'), 'alba_tm')); ?>" onclick="return confirm('<?php echo esc_js(__('Really clear the translation memory? The translations on the pages are kept.', 'alba')); ?>');"><?php echo esc_html__('Clear', 'alba'); ?></a>
					</p>
					<p class="description"><?php echo esc_html__('Per language, the last', 'alba'); ?> <?php echo (int) self::TM_MAX; ?> <?php echo esc_html__('sections are stored. Suggestions are only made for identical original text.', 'alba'); ?></p>
				</div>
			</section>
		</div>
		<?php
	}

	public function handle_save() {
		check_admin_referer('alba_glossary');
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each term is sanitised in the loop below.
		$in = isset($_POST['gl']) && is_array($_POST['gl']) ? wp_unslash($_POST['gl']) : [];
		$out = [];
		$seen = [];
		foreach ($in as $e) {
			if (!is_array($e)) {
				continue;
			}
			$term = isset($e['term']) ? trim(sanitize_text_field($e['term'])) : '';
			$key = mb_strtolower($term);
			if ($term === '' || mb_strlen($term) > 200 || isset($seen[$key])) {
				continue;
			}
			$seen[$key] = 1;
			$keep = !isset($e['mode']) || $e['mode'] !== 'tr';
			$tr = [];
			if (!$keep && !empty($e['tr']) && is_array($e['tr'])) {
				foreach ($e['tr'] as $code => $t) {
					$code = sanitize_key($code);
					$t = trim(sanitize_text_field((string) $t));
					if (isset($this->core->langs[$code]) && $t !== '') {
						$tr[$code] = $t;
					}
				}
			}
			$out[] = ['term' => $term, 'keep' => $keep ? 1 : 0, 'cs' => !empty($e['cs']) ? 1 : 0, 'tr' => $tr];
		}
		update_option(self::OPT, $out, false);
		wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE . '&saved=1'));
		exit;
	}

	public function handle_tm() {
		check_admin_referer('alba_tm');
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		$do = isset($_GET['do']) ? sanitize_key($_GET['do']) : '';
		if ($do === 'clear') {
			update_option(self::TM, [], false);
		} elseif ($do === 'rebuild') {
			update_option(self::TM, $this->scan(), false);
		}
		wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE . '&tm_done=1'));
		exit;
	}
}
