<?php
if (!defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table names come from $wpdb or a fixed helper; every value goes through $wpdb->prepare().

/**
 * WPML-Importer: übernimmt bestehende WPML-Daten aus den Tabellen icl_* (auch bei deaktiviertem oder gelöschtem WPML-Plugin,
 * solange die Tabellen noch in der Datenbank sind).
 *
 *  Einstellungen  Sprachen (Name, Locale), Standardsprache, übersetzbare Inhaltstypen und Taxonomien
 *  Beiträge       Sprache und Übersetzungsgruppe je Beitrag (_alba_lang, _alba_group); „Übersetzung muss aktualisiert werden“ aus WPML bleibt als veraltet erhalten
 *  Begriffe       Sprache und Gruppe je Kategorie/Schlagwort/Taxonomie-Begriff
 *  Menüs          Zuordnung der Menüs je Sprache (Einstellungen > Suche, Kommentare & Menüs)
 *  Medien         Alt-Text, Titel, Beschriftung, Beschreibung und andere Bilddatei der übersetzten Medien (am Original-Medium)
 *  Zeichenfolgen  String-Übersetzungen (Zeichenfolgen; wirksam für Theme/Plugin-Texte, Seitentitel, Slogan und Widget-Titel)
 * Es werden keine Beiträge angelegt oder gelöscht; die Importschritte sind wiederholbar. Ein Probelauf zählt nur.
 */
class ALBA_Wpml {
	const PAGE = 'alba-wpml';
	const BATCH = 200;

	private $core;
	private $dry = false;

	public function __construct(ALBA_Core $core) {
		$this->core = $core;
		$core->wpml = $this;
		if (is_admin()) {
			add_action('admin_menu', [$this, 'menu'], 30);
			add_action('wp_ajax_alba_wpml_step', [$this, 'ajax']);
		}
	}

	/* ---------------------------------------------------------------
	 * Tabellen
	 * ------------------------------------------------------------- */

	private function tn($n) {
		global $wpdb;
		return $wpdb->prefix . $n;
	}

	private function has($n) {
		global $wpdb;
		$t = $this->tn($n);
		return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($t))) === $t;
	}

	public function available() {
		return $this->has('icl_translations');
	}

	public function menu() {
		if ($this->available()) {
			add_submenu_page(ALBA_Dashboard::SLUG, __('WPML import', 'alba'), __('WPML import', 'alba'), 'manage_options', self::PAGE, [$this, 'page']);
		}
	}

	private function settings() {
		$s = get_option('icl_sitepress_settings');
		return is_array($s) ? $s : [];
	}

	/* ---------------------------------------------------------------
	 * Seite
	 * ------------------------------------------------------------- */

	public function analyse() {
		global $wpdb;
		$t = $this->tn('icl_translations');
		$out = ['types' => [], 'langs' => [], 'strings' => 0, 'string_tr' => 0, 'warn' => []];
		foreach ((array) $wpdb->get_results("SELECT element_type, COUNT(*) n FROM {$t} GROUP BY element_type", ARRAY_A) as $r) {
			$out['types'][$r['element_type']] = (int) $r['n'];
		}
		foreach ((array) $wpdb->get_results("SELECT language_code, COUNT(*) n FROM {$t} GROUP BY language_code", ARRAY_A) as $r) {
			$out['langs'][$r['language_code']] = (int) $r['n'];
		}
		if ($this->has('icl_strings') && $this->has('icl_string_translations')) {
			$out['strings'] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $this->tn('icl_strings'));
			$out['string_tr'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $this->tn('icl_string_translations') . " WHERE value <> ''");
		}
		$s = $this->settings();
		$neg = isset($s['language_negotiation_type']) ? (int) $s['language_negotiation_type'] : 1;
		if ($neg === 2) {
			$out['warn'][] = __('WPML uses a separate domain per language. Alba uses language directories (/en/). The data can be imported, but the URLs change.', 'alba');
		} elseif ($neg === 3) {
			$out['warn'][] = __('WPML uses the ?lang= parameter. Alba uses language directories (/en/); the URLs change.', 'alba');
		}
		if (!empty($s['urls']['directory_for_default_language'])) {
			$out['warn'][] = __('WPML also puts a directory in front of the default language. In Alba the default language has no prefix; the URLs of the default language change.', 'alba');
		}
		return $out;
	}

	public function page() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		$a = $this->analyse();
		$s = $this->settings();
		$def = isset($s['default_language']) ? $s['default_language'] : '';
		?>
		<div class="wrap">
			<h1><?php echo esc_html__('WPML import', 'alba'); ?></h1>
			<div class="notice notice-warning inline"><p><strong><?php echo esc_html__('Create a database backup before importing.', 'alba'); ?></strong> <?php echo esc_html__('The "Dry run" only counts; nothing is written.', 'alba'); ?></p></div>
			<p><?php echo esc_html__('Found: default language in WPML', 'alba'); ?> <strong><?php echo esc_html($def ?: 'unbekannt'); ?></strong><?php echo esc_html__(', languages:', 'alba'); ?> <?php echo esc_html(implode(', ', array_map(function ($k, $v) { return $k . ' (' . $v . ')'; }, array_keys($a['langs']), $a['langs']))); ?>.</p>
			<table class="widefat striped" style="max-width:520px"><thead><tr><th><?php echo esc_html__('Content type', 'alba'); ?></th><th><?php echo esc_html__('Entries', 'alba'); ?></th></tr></thead><tbody>
				<?php foreach ($a['types'] as $k => $n) : ?><tr><td><?php echo esc_html($k); ?></td><td><?php echo (int) $n; ?></td></tr><?php endforeach; ?>
				<tr><td><?php echo esc_html__('String translations (strings)', 'alba'); ?></td><td><?php echo (int) $a['string_tr']; ?> (<?php echo esc_html__('of', 'alba'); ?> <?php echo (int) $a['strings']; ?> <?php echo esc_html__('strings', 'alba'); ?>)</td></tr>
			</tbody></table>
			<?php foreach ($a['warn'] as $w) : ?><div class="notice notice-info inline"><p><?php echo esc_html($w); ?></p></div><?php endforeach; ?>
			<p style="margin-top:14px">
				<label><input type="checkbox" id="alba-w-dry" checked> <?php echo esc_html__('Dry run (write nothing)', 'alba'); ?></label><br>
				<label><input type="checkbox" id="alba-w-over"> <?php echo esc_html__('overwrite content that is already assigned', 'alba'); ?></label><br>
				<label><input type="checkbox" id="alba-w-lang" checked> <?php echo esc_html__('Import languages, default language, content types and taxonomies', 'alba'); ?></label>
			</p>
			<p><button class="button button-primary" id="alba-w-go"><?php echo esc_html__('Start import', 'alba'); ?></button></p>
			<table class="widefat striped" id="alba-w-tab" style="max-width:760px;display:none"><thead><tr><th><?php echo esc_html__('Step', 'alba'); ?></th><th><?php echo esc_html__('Result', 'alba'); ?></th></tr></thead><tbody></tbody></table>
			<script>
			(function () {
				var steps = [['settings', <?php echo wp_json_encode(__('Settings', 'alba')); ?>], ['posts', <?php echo wp_json_encode(__('Posts and pages', 'alba')); ?>], ['terms', <?php echo wp_json_encode(__('Terms (categories, tags …)', 'alba')); ?>], ['menus', <?php echo wp_json_encode(__('Menus', 'alba')); ?>], ['media', <?php echo wp_json_encode(__('Media', 'alba')); ?>], ['strings', <?php echo wp_json_encode(__('Strings', 'alba')); ?>]];
				var go = document.getElementById('alba-w-go'), tab = document.getElementById('alba-w-tab'), body = tab.querySelector('tbody');
				var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, nonce = <?php echo wp_json_encode(wp_create_nonce('alba_wpml')); ?>;
				function call(step, cursor, opts) {
					var fd = new FormData();
					fd.append('action', 'alba_wpml_step'); fd.append('nonce', nonce); fd.append('step', step); fd.append('cursor', cursor);
					Object.keys(opts).forEach(function (k) { fd.append(k, opts[k] ? '1' : '0'); });
					return fetch(ajax, {method: 'POST', credentials: 'same-origin', body: fd}).then(function (r) { return r.json(); });
				}
				go.addEventListener('click', function () {
					var opts = {dry: document.getElementById('alba-w-dry').checked, over: document.getElementById('alba-w-over').checked, lang: document.getElementById('alba-w-lang').checked};
					if (!opts.dry && !confirm(<?php echo wp_json_encode(__('Really import? Please create a database backup first.', 'alba')); ?>)) { return; }
					go.disabled = true; tab.style.display = ''; body.innerHTML = '';
					var i = 0;
					(function next() {
						if (i >= steps.length) { var tr = document.createElement('tr'); tr.innerHTML = '<td colspan="2"><strong>' + (opts.dry ? <?php echo wp_json_encode(__('Dry run finished. To import, untick "Dry run".', 'alba')); ?> : <?php echo wp_json_encode(__('Import finished. Please check pages and translations and re-save the permalinks (Settings > Permalinks).', 'alba')); ?>) + '</strong></td>'; body.appendChild(tr); go.disabled = false; return; }
						var st = steps[i], tr = document.createElement('tr'), td = document.createElement('td'), res = document.createElement('td');
						td.textContent = st[1]; res.textContent = <?php echo wp_json_encode(__('running …', 'alba')); ?>; tr.appendChild(td); tr.appendChild(res); body.appendChild(tr);
						var sum = {}, lines = [];
						(function loop(cursor) {
							call(st[0], cursor, opts).then(function (j) {
								if (!j || !j.success) { res.textContent = '✗ ' + (j && j.data ? j.data : <?php echo wp_json_encode(__('Error', 'alba')); ?>); res.style.color = '#b32d2e'; go.disabled = false; return; }
								var d = j.data;
								Object.keys(d.n || {}).forEach(function (k) { sum[k] = (sum[k] || 0) + d.n[k]; });
								(d.notes || []).forEach(function (n) { if (lines.indexOf(n) < 0) { lines.push(n); } });
								res.textContent = Object.keys(sum).map(function (k) { return k + ': ' + sum[k]; }).join(' · ') || '…';
								if (d.done) { if (lines.length) { res.textContent += ' — ' + lines.join(' ') ; } res.textContent = '✓ ' + res.textContent; i++; next(); } else { loop(d.cursor); }
							}).catch(function () { res.textContent = '✗ ' + <?php echo wp_json_encode(__('Network error', 'alba')); ?>; res.style.color = '#b32d2e'; go.disabled = false; });
						})(0);
					})();
				});
			})();
			</script>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------- */

	public function ajax() {
		check_ajax_referer('alba_wpml', 'nonce');
		if (!current_user_can('manage_options') || !$this->available()) {
			wp_send_json_error(__('Not allowed or no WPML data found.', 'alba'));
		}
		@set_time_limit(120);
		$step = isset($_POST['step']) ? sanitize_key(wp_unslash($_POST['step'])) : '';
		$cursor = isset($_POST['cursor']) ? (int) $_POST['cursor'] : 0;
		$opts = [
			'dry'  => !empty($_POST['dry']) && $_POST['dry'] === '1',
			'over' => !empty($_POST['over']) && $_POST['over'] === '1',
			'lang' => !empty($_POST['lang']) && $_POST['lang'] === '1',
		];
		$r = $this->run($step, $cursor, $opts);
		if (is_string($r)) {
			wp_send_json_error($r);
		}
		wp_send_json_success($r);
	}

	/** Ein Schritt/Paket. Rückgabe: Fehlertext oder ['done'=>bool, 'cursor'=>int, 'n'=>[…], 'notes'=>[…]] */
	public function run($step, $cursor, array $o) {
		$this->dry = !empty($o['dry']);
		switch ($step) {
			case 'settings':
				return $this->step_settings($o);
			case 'posts':
				return $this->step_posts($cursor, $o);
			case 'terms':
				return $this->step_terms($cursor, $o);
			case 'menus':
				return $this->step_menus($o);
			case 'media':
				return $this->step_media($cursor, $o);
			case 'strings':
				return $this->step_strings($cursor, $o);
		}
		return __('Unknown step.', 'alba');
	}

	private function res($done, $cursor, array $n, array $notes = []) {
		return ['done' => $done, 'cursor' => $cursor, 'n' => $n, 'notes' => $notes];
	}

	/* ---------------------------------------------------------------
	 * Einstellungen
	 * ------------------------------------------------------------- */

	private function step_settings(array $o) {
		global $wpdb;
		$c = $this->core;
		$n = [__('New languages', 'alba') => 0, __('Content types', 'alba') => 0, __('Taxonomies', 'alba') => 0];
		$notes = [];
		if (empty($o['lang'])) {
			return $this->res(true, 0, [__('skipped', 'alba') => 1], [__('Not selected: languages and types are not adjusted.', 'alba')]);
		}
		$langs = get_option('alba_languages');
		$langs = is_array($langs) ? $langs : [];
		if ($this->has('icl_languages')) {
			$rows = (array) $wpdb->get_results('SELECT code, english_name, default_locale FROM ' . $this->tn('icl_languages') . ' WHERE active = 1', ARRAY_A);
			$loc = [];
			if ($this->has('icl_locale_map')) {
				foreach ((array) $wpdb->get_results('SELECT code, locale FROM ' . $this->tn('icl_locale_map'), ARRAY_A) as $r) {
					$loc[$r['code']] = $r['locale'];
				}
			}
			$names = [];
			if ($this->has('icl_languages_translations')) {
				foreach ((array) $wpdb->get_results('SELECT language_code, name FROM ' . $this->tn('icl_languages_translations') . ' WHERE language_code = display_language_code', ARRAY_A) as $r) {
					$names[$r['language_code']] = $r['name'];
				}
			}
			foreach ($rows as $r) {
				$code = sanitize_key($r['code']);
				if ($code === '' || isset($langs[$code])) {
					continue;
				}
				$locale = isset($loc[$r['code']]) && $loc[$r['code']] !== '' ? $loc[$r['code']] : (string) $r['default_locale'];
				$langs[$code] = ['name' => isset($names[$r['code']]) && $names[$r['code']] !== '' ? $names[$r['code']] : (string) $r['english_name'], 'locale' => $locale];
				$n[__('New languages', 'alba')]++;
			}
		}
		$s = $this->settings();
		$def = isset($s['default_language']) ? sanitize_key($s['default_language']) : '';
		if (!$this->dry) {
			if ($langs) {
				update_option('alba_languages', $langs);
			}
			if ($def !== '' && isset($langs[$def])) {
				update_option('alba_default', $def);
			}
		}
		if ($def !== '') {
			$notes[] = __('Default language: ', 'alba') . $def . '.';
		}

		$pt = get_option('alba_post_types');
		$pt = is_array($pt) && $pt ? array_values($pt) : ['post', 'page'];
		$tx = get_option('alba_taxonomies');
		$tx = is_array($tx) ? array_values($tx) : [];
		foreach ((array) $wpdb->get_col('SELECT DISTINCT element_type FROM ' . $this->tn('icl_translations')) as $et) {
			if (strpos($et, 'post_') === 0) {
				$name = substr($et, 5);
				$po = get_post_type_object($name);
				if ($po && $po->public && !in_array($name, ['attachment'], true) && !in_array($name, $pt, true)) {
					$pt[] = $name;
					$n[__('Content types', 'alba')]++;
				}
			} elseif (strpos($et, 'tax_') === 0) {
				$name = substr($et, 4);
				$to = get_taxonomy($name);
				if ($to && $to->public && $name !== 'nav_menu' && !in_array($name, $tx, true)) {
					$tx[] = $name;
					$n[__('Taxonomies', 'alba')]++;
				}
			}
		}
		if (!$this->dry) {
			update_option('alba_post_types', $pt);
			if ($tx) {
				update_option('alba_taxonomies', $tx);
			}
			$c->post_types = $pt;
			$c->taxonomies = $tx ?: $c->taxonomies;
			$c->langs = $langs ?: $c->langs;
			if ($def !== '' && isset($c->langs[$def])) {
				$c->default = $def;
			}
		}
		return $this->res(true, 0, $n, $notes);
	}

	/* ---------------------------------------------------------------
	 * Beiträge
	 * ------------------------------------------------------------- */

	private function needs_update_ids(array $tids) {
		global $wpdb;
		if (!$tids || !$this->has('icl_translation_status')) {
			return [];
		}
		$in = implode(',', array_map('intval', $tids));
		return array_map('intval', (array) $wpdb->get_col('SELECT translation_id FROM ' . $this->tn('icl_translation_status') . " WHERE needs_update = 1 AND translation_id IN ({$in})"));
	}

	private function step_posts($cursor, array $o) {
		global $wpdb;
		$c = $this->core;
		$t = $this->tn('icl_translations');
		$rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE element_type LIKE %s AND element_type <> 'post_attachment' AND translation_id > %d ORDER BY translation_id ASC LIMIT %d", $wpdb->esc_like('post_') . '%', $cursor, self::BATCH), ARRAY_A);
		$n = [__('assigned', 'alba') => 0, __('skipped', 'alba') => 0, __('Language missing', 'alba') => 0, __('Post missing', 'alba') => 0, __('outdated', 'alba') => 0];
		$upd = $this->needs_update_ids(wp_list_pluck($rows, 'translation_id'));
		$last = $cursor;
		foreach ($rows as $r) {
			$last = (int) $r['translation_id'];
			$id = (int) $r['element_id'];
			$lang = sanitize_key((string) $r['language_code']);
			if (!$lang || !isset($c->langs[$lang])) {
				$n[__('Language missing', 'alba')]++;
				continue;
			}
			if (!get_post($id)) {
				$n[__('Post missing', 'alba')]++;
				continue;
			}
			if (!$o['over'] && metadata_exists('post', $id, '_alba_group')) {
				$n[__('skipped', 'alba')]++;
				continue;
			}
			$n[__('assigned', 'alba')]++;
			if (!$this->dry) {
				update_post_meta($id, '_alba_lang', $lang);
				update_post_meta($id, '_alba_group', 'wpml-' . (int) $r['trid']);
			}
			if (in_array((int) $r['translation_id'], $upd, true)) {
				$n[__('outdated', 'alba')]++;
			}
		}
		if (!$this->dry) {
			// Übersetzungen als „aktuell“ markieren, außer WPML hielt sie für aktualisierungsbedürftig
			$c->flush();
			foreach ($rows as $r) {
				$id = (int) $r['element_id'];
				$lang = sanitize_key((string) $r['language_code']);
				if ($lang && $lang !== $c->default && isset($c->langs[$lang]) && get_post($id) && get_post_meta($id, '_alba_group', true) === 'wpml-' . (int) $r['trid'] && !in_array((int) $r['translation_id'], $upd, true) && $c->editor) {
					$c->editor->mark_synced($id);
				}
			}
			$c->flush();
		}
		return $this->res(count($rows) < self::BATCH, $last, $n);
	}

	/* ---------------------------------------------------------------
	 * Begriffe
	 * ------------------------------------------------------------- */

	private function term_id($ttid, $taxonomy) {
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d AND taxonomy = %s", $ttid, $taxonomy));
	}

	private function step_terms($cursor, array $o) {
		global $wpdb;
		$c = $this->core;
		$t = $this->tn('icl_translations');
		$rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE element_type LIKE %s AND element_type <> 'tax_nav_menu' AND translation_id > %d ORDER BY translation_id ASC LIMIT %d", $wpdb->esc_like('tax_') . '%', $cursor, self::BATCH), ARRAY_A);
		$n = [__('assigned', 'alba') => 0, __('skipped', 'alba') => 0, __('Language missing', 'alba') => 0, __('Term missing', 'alba') => 0];
		$last = $cursor;
		foreach ($rows as $r) {
			$last = (int) $r['translation_id'];
			$lang = sanitize_key((string) $r['language_code']);
			if (!$lang || !isset($c->langs[$lang])) {
				$n[__('Language missing', 'alba')]++;
				continue;
			}
			$tid = $this->term_id((int) $r['element_id'], substr($r['element_type'], 4));
			if (!$tid) {
				$n[__('Term missing', 'alba')]++;
				continue;
			}
			if (!$o['over'] && metadata_exists('term', $tid, '_alba_group')) {
				$n[__('skipped', 'alba')]++;
				continue;
			}
			$n[__('assigned', 'alba')]++;
			if (!$this->dry) {
				update_term_meta($tid, '_alba_lang', $lang);
				update_term_meta($tid, '_alba_group', 'wpml-' . (int) $r['trid']);
			}
		}
		if (!$this->dry && $c->terms) {
			$c->terms->flush();
		}
		return $this->res(count($rows) < self::BATCH, $last, $n);
	}

	/* ---------------------------------------------------------------
	 * Menüs
	 * ------------------------------------------------------------- */

	private function step_menus(array $o) {
		global $wpdb;
		$c = $this->core;
		$t = $this->tn('icl_translations');
		$rows = (array) $wpdb->get_results("SELECT * FROM {$t} WHERE element_type = 'tax_nav_menu'", ARRAY_A);
		$groups = [];
		foreach ($rows as $r) {
			$tid = $this->term_id((int) $r['element_id'], 'nav_menu');
			if ($tid) {
				$groups[(int) $r['trid']][sanitize_key((string) $r['language_code'])] = $tid;
			}
		}
		$map = get_option('alba_menu_map', []);
		$map = is_array($map) ? $map : [];
		$map += ['menu' => [], 'nav' => []];
		$n = [__('Menus assigned', 'alba') => 0, __('skipped', 'alba') => 0];
		foreach ($groups as $g) {
			if (empty($g[$c->default])) {
				$n[__('skipped', 'alba')]++;
				continue;
			}
			$src = $g[$c->default];
			foreach ($g as $lang => $tid) {
				if ($lang === $c->default || !isset($c->langs[$lang])) {
					continue;
				}
				if (!$o['over'] && !empty($map['menu'][$src][$lang])) {
					$n[__('skipped', 'alba')]++;
					continue;
				}
				$map['menu'][$src][$lang] = $tid;
				$n[__('Menus assigned', 'alba')]++;
			}
		}
		if (!$this->dry && $n[__('Menus assigned', 'alba')]) {
			update_option('alba_menu_map', $map);
		}
		return $this->res(true, 0, $n);
	}

	/* ---------------------------------------------------------------
	 * Medien
	 * ------------------------------------------------------------- */

	private function step_media($cursor, array $o) {
		global $wpdb;
		$c = $this->core;
		$t = $this->tn('icl_translations');
		$trids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT trid FROM {$t} WHERE element_type = 'post_attachment' AND trid > %d ORDER BY trid ASC LIMIT %d", $cursor, 100)));
		$n = [__('Media imported', 'alba') => 0, __('skipped', 'alba') => 0, __('Without original', 'alba') => 0];
		$last = $cursor;
		foreach ($trids as $trid) {
			$last = $trid;
			$rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE element_type = 'post_attachment' AND trid = %d", $trid), ARRAY_A);
			$src = 0;
			foreach ($rows as $r) {
				if (sanitize_key((string) $r['language_code']) === $c->default) {
					$src = (int) $r['element_id'];
				}
			}
			if (!$src || get_post_type($src) !== 'attachment') {
				$n[__('Without original', 'alba')]++;
				continue;
			}
			foreach ($rows as $r) {
				$lang = sanitize_key((string) $r['language_code']);
				$id = (int) $r['element_id'];
				if ($id === $src || $lang === $c->default || !isset($c->langs[$lang]) || get_post_type($id) !== 'attachment') {
					continue;
				}
				if (!$o['over'] && metadata_exists('post', $src, ALBA_Media::META . $lang)) {
					$n[__('skipped', 'alba')]++;
					continue;
				}
				$p = get_post($id);
				$same_file = get_post_meta($id, '_wp_attached_file', true) === get_post_meta($src, '_wp_attached_file', true);
				$alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
				$sp = get_post($src);
				$in = [
					'alt'     => $alt !== (string) get_post_meta($src, '_wp_attachment_image_alt', true) ? $alt : '',
					'title'   => $p->post_title !== $sp->post_title ? $p->post_title : '',
					'caption' => $p->post_excerpt !== $sp->post_excerpt ? $p->post_excerpt : '',
					'desc'    => $p->post_content !== $sp->post_content ? $p->post_content : '',
					'file'    => $same_file ? 0 : $id,
				];
				if (!array_filter($in)) {
					continue;
				}
				$n[__('Media imported', 'alba')]++;
				if (!$this->dry && $c->media) {
					$c->media->store($src, $lang, wp_slash($in));
				}
			}
		}
		return $this->res(count($trids) < 100, $last, $n);
	}

	/* ---------------------------------------------------------------
	 * Zeichenfolgen
	 * ------------------------------------------------------------- */

	private function step_strings($cursor, array $o) {
		global $wpdb;
		$c = $this->core;
		if (!$this->has('icl_strings') || !$this->has('icl_string_translations')) {
			return $this->res(true, 0, [__('skipped', 'alba') => 1], [__('No string tables found.', 'alba')]);
		}
		$st = $this->tn('icl_strings');
		$tt = $this->tn('icl_string_translations');
		$rows = (array) $wpdb->get_results($wpdb->prepare("SELECT id, context, name, value, gettext_context FROM {$st} WHERE id > %d ORDER BY id ASC LIMIT %d", $cursor, 300), ARRAY_A);
		$n = [__('Translations', 'alba') => 0, __('Without translation', 'alba') => 0, __('Limit reached', 'alba') => 0];
		$notes = [];
		$last = $cursor;
		$all = get_option(ALBA_Strings::OPT, []);
		$all = is_array($all) ? $all : [];
		$dirty = false;
		$ids = wp_list_pluck($rows, 'id');
		$trs = [];
		if ($ids) {
			$in = implode(',', array_map('intval', $ids));
			foreach ((array) $wpdb->get_results("SELECT string_id, language, value FROM {$tt} WHERE value <> '' AND string_id IN ({$in})", ARRAY_A) as $r) {
				$trs[(int) $r['string_id']][sanitize_key((string) $r['language'])] = (string) $r['value'];
			}
		}
		foreach ($rows as $r) {
			$last = (int) $r['id'];
			if (empty($trs[(int) $r['id']])) {
				$n[__('Without translation', 'alba')]++;
				continue;
			}
			$ctx = (string) $r['context'];
			$name = (string) $r['name'];
			$value = (string) $r['value'];
			$gctx = (string) $r['gettext_context'];
			if ($ctx === 'WP' && $name === 'Blog Title') {
				$domain = 'Site';
				$key = 'blogname';
				$nm = 'blogname';
			} elseif ($ctx === 'WP' && $name === 'Tagline') {
				$domain = 'Site';
				$key = 'blogdescription';
				$nm = 'blogdescription';
			} else {
				$domain = $ctx !== '' ? $ctx : 'Strings';
				$gctx = $domain === 'Widgets' ? '' : $gctx;
				$key = $value;
				$nm = '';
			}
			$id = md5($domain . "\0" . ($nm !== '' ? '' : $gctx) . "\0" . $key);
			$count = 0;
			foreach ($trs[(int) $r['id']] as $lang => $val) {
				if (!isset($c->langs[$lang]) || $lang === $c->default) {
					continue;
				}
				if (!isset($all[$id])) {
					if (count($all) >= ALBA_Strings::MAX) {
						$n[__('Limit reached', 'alba')]++;
						continue;
					}
					$all[$id] = ['domain' => $domain, 'ctx' => $nm !== '' ? '' : $gctx, 'name' => $nm, 'text' => $value, 'tr' => []];
				}
				if (!$o['over'] && !empty($all[$id]['tr'][$lang])) {
					continue;
				}
				$all[$id]['tr'][$lang] = $val;
				$count++;
				$dirty = true;
			}
			$n[__('Translations', 'alba')] += $count;
		}
		if ($dirty && !$this->dry) {
			update_option(ALBA_Strings::OPT, $all, false);
		}
		if ($n[__('Limit reached', 'alba')]) {
			$notes[] = __('The limit of ', 'alba') . ALBA_Strings::MAX . __(' strings has been reached, the rest was not imported.', 'alba');
		}
		return $this->res(count($rows) < 300, $last, $n, $notes);
	}
}
