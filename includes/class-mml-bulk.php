<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Sammelaktionen für Übersetzungen im Editor:
 *   mt        Leere Felder maschinell vorübersetzen (nie veröffentlicht, nur als Entwurf gespeichert)
 *   publish   Im Frontend anzeigen (nur wenn keine offenen Texte, oder mit „auch unvollständige“)
 *   unpublish Im Frontend ausblenden
 *   stale     Als veraltet markieren (alle Abschnitte werden im Editor zur Prüfung markiert)
 *   assign    Zuweisen mit Frist (oder Zuweisung entfernen)
 *   xliff     Alle als eine XLIFF-Datei exportieren
 *
 * Aufruf aus der Liste „Meine Übersetzungen“ oder per Sammelaktion in den Beitragslisten. Die Ausführung läuft
 * Inhalt für Inhalt per AJAX, damit lange Vorübersetzungen nicht in ein Zeitlimit laufen.
 */
class MML_Bulk {
	const PAGE = 'alba-bulk';
	const MAX = 300;
	const MT_STEP = 12;

	private $core;

	public function __construct(MML_Core $core) {
		$this->core = $core;
		$core->bulk = $this;
		if (!is_admin()) {
			return;
		}
		add_action('admin_menu', [$this, 'menu']);
		add_action('wp_ajax_mml_bulk_item', [$this, 'ajax']);
		add_action('admin_init', [$this, 'list_hooks']);
	}

	public function menu() {
		add_submenu_page('options.php', __('Bulk action', 'alba'), __('Bulk action', 'alba'), 'read', self::PAGE, [$this, 'page']);
	}

	/** Verfügbare Aktionen für den aktuellen Benutzer: Schlüssel => Beschriftung */
	public function ops() {
		$ed = current_user_can('edit_others_posts');
		$out = [];
		if (MML_MT::enabled()) {
			$out['mt'] = __('Pre-translate empty fields automatically', 'alba');
		}
		if (current_user_can('edit_posts') || get_option('mml_translator_publish', '0') === '1') {
			$out['publish'] = __('Show on the frontend (publish)', 'alba');
			$out['unpublish'] = __('Hide on the frontend', 'alba');
		}
		if ($ed) {
			$out['stale'] = __('Mark as outdated', 'alba');
			$out['assign'] = __('Assign / set deadline', 'alba');
			$out['xliff'] = __('Export as XLIFF', 'alba');
		}
		return $out;
	}

	public function url($op, $lang, array $ids, array $extra = []) {
		return wp_nonce_url(add_query_arg(array_merge(['page' => self::PAGE, 'op' => $op, 'lang' => $lang, 'ids' => implode(',', array_map('intval', $ids))], $extra), admin_url('admin.php')), 'mml_bulk_run');
	}

	/* ---------------------------------------------------------------
	 * Sammelaktionen in den Beitragslisten
	 * ------------------------------------------------------------- */

	public function list_hooks() {
		if (!current_user_can('edit_others_posts')) {
			return;
		}
		foreach ((array) $this->core->post_types as $pt) {
			add_filter('bulk_actions-edit-' . $pt, [$this, 'list_actions']);
			add_filter('handle_bulk_actions-edit-' . $pt, [$this, 'list_handle'], 10, 3);
		}
	}

	public function list_actions($a) {
		$c = $this->core;
		$ops = $this->ops();
		foreach ($c->langs as $code => $l) {
			if ($code === $c->default) {
				continue;
			}
			foreach ($ops as $op => $label) {
				if ($op === 'unpublish') {
					continue;
				}
				$a['mml_' . $op . '|' . $code] = __('Translation (', 'alba') . $l['name'] . '): ' . $label;
			}
		}
		return $a;
	}

	public function list_handle($redirect, $action, $ids) {
		if (strpos((string) $action, 'mml_') !== 0 || !strpos($action, '|')) {
			return $redirect;
		}
		list($op, $lang) = explode('|', substr($action, 4), 2);
		if (!isset($this->ops()[$op]) || !isset($this->core->langs[$lang])) {
			return $redirect;
		}
		return $this->url($op, $lang, array_map('intval', (array) $ids));
	}

	/* ---------------------------------------------------------------
	 * Seite
	 * ------------------------------------------------------------- */

	private function ids_from($v) {
		$a = is_array($v) ? $v : explode(',', (string) $v);
		$out = [];
		foreach ($a as $i) {
			$i = (int) $i;
			if ($i > 0) {
				$out[$i] = $i;
			}
		}
		return array_slice(array_values($out), 0, self::MAX);
	}

	public function page() {
		$c = $this->core;
		if (!current_user_can('read')) {
			wp_die(__('Not allowed.', 'alba'));
		}
		check_admin_referer('mml_bulk_run');
		$op = isset($_GET['op']) ? sanitize_key(wp_unslash($_GET['op'])) : '';
		$lang = isset($_GET['lang']) ? sanitize_key(wp_unslash($_GET['lang'])) : '';
		$ops = $this->ops();
		$ids = $this->ids_from(isset($_GET['ids']) ? sanitize_text_field(wp_unslash($_GET['ids'])) : '');
		if (!isset($ops[$op]) || !isset($c->langs[$lang]) || $lang === $c->default || !$c->roles->lang_allowed($lang) || !$ids) {
			wp_die(__('Invalid bulk action. Please select the content and language again.', 'alba'));
		}
		$back = admin_url('admin.php?page=' . ($c->roles->is_translator_only() ? MML_Roles::PAGE : MML_Roles::PAGE));
		$rows = [];
		foreach ($ids as $id) {
			$p = get_post($id);
			if ($p && $c->is_translatable($p->post_type) && $c->post_lang($p->ID) === $c->default) {
				$rows[$id] = $p->post_title !== '' ? $p->post_title : __('(no title)', 'alba');
			}
		}
		echo '<div class="wrap"><h1>Sammelaktion: ' . esc_html($ops[$op]) . ' → ' . esc_html($c->langs[$lang]['name']) . '</h1>';
		if (!$rows) {
			echo '<p>' . esc_html__('No matching content.', 'alba') . '</p><p><a class="button" href="' . esc_url($back) . '">' . esc_html__('Back', 'alba') . '</a></p></div>';
			return;
		}

		if ($op === 'xliff') {
			$u = wp_nonce_url(add_query_arg(['action' => 'mml_xliff_export', 'lang' => $lang, 'fmt' => '1.2', 'tr' => 1, 'ids' => implode(',', array_keys($rows))], admin_url('admin-post.php')), 'mml_xliff_export');
			echo '<p>' . count($rows) . __(' items are exported in one file. Items that already have their own translation post are skipped.</p>', 'alba');
			echo '<p><a class="button button-primary" href="' . esc_url($u) . '">' . esc_html__('Download XLIFF file', 'alba') . '</a> <a class="button" href="' . esc_url($back) . '">' . esc_html__('Back', 'alba') . '</a></p></div>';
			return;
		}

		$extra = [];
		$need_form = ($op === 'assign') || ($op === 'publish' && !isset($_GET['go']));
		if ($need_form && !isset($_GET['go'])) {
			echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
			foreach (['page' => self::PAGE, 'op' => $op, 'lang' => $lang, 'ids' => implode(',', array_keys($rows)), 'go' => '1', '_wpnonce' => wp_create_nonce('mml_bulk_run')] as $k => $v) {
				echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
			}
			if ($op === 'assign') {
				echo '<p>' . esc_html(sprintf(/* translators: %d: number of items */ __('Assign %d items to:', 'alba'), count($rows))) . ' <select name="to"><option value="0">' . esc_html__('– remove assignment –', 'alba') . '</option>';
				foreach ($c->workflow->users() as $u) {
					echo '<option value="' . (int) $u->ID . '">' . esc_html($u->display_name) . '</option>';
				}
				echo '</select> ' . esc_html__('Deadline:', 'alba') . ' <input type="date" name="due"></p>';
			} else {
				echo '<p>' . esc_html(sprintf(/* translators: %d: number of translations */ __('Show %d translations on the frontend.', 'alba'), count($rows))) . '</p><p><label><input type="checkbox" name="force" value="1"> ' . esc_html__('also publish translations with open texts', 'alba') . '</label></p>';
			}
			echo '<p><button class="button button-primary">Starten</button> <a class="button" href="' . esc_url($back) . '">Abbrechen</a></p></form></div>';
			return;
		}
		if ($op === 'assign') {
			$extra = ['to' => isset($_GET['to']) ? (int) $_GET['to'] : 0, 'due' => isset($_GET['due']) ? sanitize_text_field(wp_unslash($_GET['due'])) : ''];
		}
		if ($op === 'publish') {
			$extra = ['force' => !empty($_GET['force']) ? 1 : 0];
		}
		?>
		<p><progress id="mml-bp" max="<?php echo count($rows); ?>" value="0" style="width:420px;max-width:100%"></progress> <span id="mml-bc">0 / <?php echo count($rows); ?></span></p>
		<table class="widefat striped" style="max-width:900px"><thead><tr><th><?php echo esc_html__('Content', 'alba'); ?></th><th><?php echo esc_html__('Result', 'alba'); ?></th></tr></thead><tbody>
		<?php foreach ($rows as $id => $t) : ?>
			<tr data-id="<?php echo (int) $id; ?>"><td><?php echo esc_html($t); ?></td><td class="r"><?php echo esc_html__('waiting …', 'alba'); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<p id="mml-bd" style="display:none"><strong><?php echo esc_html__('Done.', 'alba'); ?></strong> <a class="button" href="<?php echo esc_url($back); ?>"><?php echo esc_html__('Back to the list', 'alba'); ?></a></p>
		<script>
		(function () {
			var rows = Array.prototype.slice.call(document.querySelectorAll('tr[data-id]'));
			var base = {action: 'mml_bulk_item', nonce: <?php echo wp_json_encode(wp_create_nonce('mml_bulk')); ?>, op: <?php echo wp_json_encode($op); ?>, lang: <?php echo wp_json_encode($lang); ?>};
			var extra = <?php echo wp_json_encode((object) $extra); ?>;
			var done = 0, bar = document.getElementById('mml-bp'), cnt = document.getElementById('mml-bc');
			function post(id) {
				var fd = new FormData();
				Object.keys(base).forEach(function (k) { fd.append(k, base[k]); });
				Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); });
				fd.append('id', id);
				return fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, {method: 'POST', credentials: 'same-origin', body: fd}).then(function (r) { return r.json(); });
			}
			function step(i) {
				if (i >= rows.length) { document.getElementById('mml-bd').style.display = ''; return; }
				var tr = rows[i], cell = tr.querySelector('.r');
				cell.textContent = <?php echo wp_json_encode(__('running …', 'alba')); ?>;
				(function run(n) {
					post(tr.getAttribute('data-id')).then(function (j) {
						var d = j && j.data ? j.data : {};
						if (j && j.success && d.more && n < 60) { cell.textContent = (d.msg || <?php echo wp_json_encode(__('running …', 'alba')); ?>) + ' …'; return run(n + 1); }
						cell.textContent = (j && j.success ? '✓ ' : '✗ ') + (typeof j.data === 'string' ? j.data : (d.msg || ''));
						if (!(j && j.success)) { cell.style.color = '#b32d2e'; }
						done++; bar.value = done; cnt.textContent = done + ' / ' + rows.length; step(i + 1);
					}).catch(function () {
						cell.textContent = '✗ ' + <?php echo wp_json_encode(__('Network error', 'alba')); ?>; cell.style.color = '#b32d2e';
						done++; bar.value = done; cnt.textContent = done + ' / ' + rows.length; step(i + 1);
					});
				})(0);
			}
			step(0);
		})();
		</script>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------
	 * Ausführen (ein Inhalt)
	 * ------------------------------------------------------------- */

	public function ajax() {
		check_ajax_referer('mml_bulk', 'nonce');
		$c = $this->core;
		$op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
		$lang = isset($_POST['lang']) ? sanitize_key(wp_unslash($_POST['lang'])) : '';
		$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
		if (!isset($this->ops()[$op]) || !isset($c->langs[$lang]) || $lang === $c->default || !$id) {
			wp_send_json_error(__('Invalid request.', 'alba'));
		}
		$r = $this->run($op, $id, $lang, $_POST);
		if (is_string($r)) {
			wp_send_json_error($r);
		}
		wp_send_json_success($r);
	}

	/** Rückgabe: Fehlertext oder ['msg' => …, 'more' => bool] */
	public function run($op, $id, $lang, array $in = []) {
		$c = $this->core;
		$e = $c->editor;
		$post = get_post((int) $id);
		if (!$post || !$c->is_translatable($post->post_type) || $c->post_lang($post->ID) !== $c->default) {
			return __('Not a translatable original.', 'alba');
		}
		if (!$c->roles->can_translate($post->ID, $lang)) {
			return __('No permission.', 'alba');
		}
		if ($c->translation($post->ID, $lang, false)) {
			return __('There is a separate translation post (please edit it there).', 'alba');
		}
		$has = metadata_exists('post', $post->ID, MML_Editor::TR . $lang);

		switch ($op) {
			case 'mt':
				return $this->op_mt($post, $lang);

			case 'publish':
				if (!$c->roles->can_publish($post->ID)) {
					return __('An editor grants the approval.', 'alba');
				}
				if (!$has) {
					return __('No translation yet.', 'alba');
				}
				if ($e->is_on($post->ID, $lang)) {
					return ['msg' => __('is already published', 'alba'), 'more' => false];
				}
				$oc = $e->open_count($post, $lang);
				if ($oc[0] && empty($in['force'])) {
					return sprintf(/* translators: 1: open texts, 2: total texts */ __('Skipped: %1$d of %2$d texts are still open.', 'alba'), (int) $oc[0], (int) $oc[1]);
				}
				update_post_meta($post->ID, MML_Editor::ON . $lang, '1');
				$this->after($post->ID, $lang, __('Published (bulk action)', 'alba'), true);
				return ['msg' => 'freigegeben', 'more' => false];

			case 'unpublish':
				if (!$c->roles->can_publish($post->ID)) {
					return __('An editor grants the approval.', 'alba');
				}
				if (!$e->is_on($post->ID, $lang)) {
					return ['msg' => __('was not visible', 'alba'), 'more' => false];
				}
				delete_post_meta($post->ID, MML_Editor::ON . $lang);
				$this->after($post->ID, $lang, __('Hidden (bulk action)', 'alba'), false);
				return ['msg' => 'ausgeblendet', 'more' => false];

			case 'stale':
				if (!$has) {
					return __('No translation yet.', 'alba');
				}
				$d = get_post_meta($post->ID, MML_Editor::TR . $lang, true);
				$d = is_array($d) ? $d : [];
				$d['sig'] = '__stale__';
				$d['hs'] = [];
				$d['th'] = '';
				$d['eh'] = '';
				$d['seo_h'] = [];
				update_post_meta($post->ID, MML_Editor::TR . $lang, wp_slash($d));
				if ($c->workflow) {
					$w = $c->workflow->get($post->ID, $lang);
					if ($w['to'] && $w['st'] !== 'review') {
						$w['st'] = 'open';
						$c->workflow->set($post->ID, $lang, $w);
					}
				}
				$this->after($post->ID, $lang, __('Marked as outdated (bulk action)', 'alba'), null);
				return ['msg' => __('marked as outdated', 'alba'), 'more' => false];

			case 'assign':
				if (!current_user_can('edit_others_posts')) {
					return __('No permission.', 'alba');
				}
				$to = isset($in['to']) ? (int) $in['to'] : 0;
				$due = isset($in['due']) ? sanitize_text_field(wp_unslash($in['due'])) : '';
				$r = $c->workflow->assign($post->ID, $lang, $to, $due);
				if ($r !== true) {
					return $r;
				}
				return ['msg' => $to ? 'zugewiesen' : __('Assignment removed', 'alba'), 'more' => false];
		}
		return __('Unknown action.', 'alba');
	}

	private function after($id, $lang, $text, $on) {
		$c = $this->core;
		$c->editor->reset($id);
		do_action('mml_translation_saved', $id, $lang);
		if ($on === true && $c->workflow) {
			$c->workflow->after_save($id, $lang, false, true);
		}
		if ($c->log) {
			$c->log->add($id, $lang, 'save', $text, ['changes' => [], 'removed' => 0, 'on' => $on === null ? $c->editor->is_on($id, $lang) : $on]);
		}
	}

	/* ---------------------------------------------------------------
	 * Maschinelle Vorübersetzung
	 * ------------------------------------------------------------- */

	private function op_mt($post, $lang) {
		$c = $this->core;
		$e = $c->editor;
		if (!MML_MT::enabled() || !$c->mt) {
			return __('Automatic translation is turned off.', 'alba');
		}
		$kinds = [];
		$segs = $e->post_segments($post, $kinds);
		$d = $e->data($post->ID, $lang);
		$todo = [];
		if (trim($post->post_title) !== '' && trim($d['title']) === '') {
			$todo[] = ['title', md5($post->post_title), $post->post_title, true];
		}
		if (trim($post->post_excerpt) !== '' && trim($d['excerpt']) === '') {
			$todo[] = ['excerpt', md5($post->post_excerpt), $post->post_excerpt, true];
		}
		if ($e->seo_plugin()) {
			$ss = $e->seo_source($post->ID);
			if (trim($ss['title']) !== '' && trim($d['seo_title']) === '') {
				$todo[] = ['seo_title', md5($ss['title']), $ss['title'], true];
			}
			if (trim($ss['desc']) !== '' && trim($d['seo_desc']) === '') {
				$todo[] = ['seo_desc', md5($ss['desc']), $ss['desc'], true];
			}
		}
		foreach ($segs as $h => $t) {
			if (trim((string) $t) !== '' && trim(isset($d['seg'][$h]) ? (string) $d['seg'][$h] : '') === '') {
				$todo[] = ['seg', (string) $h, $t, false];
			}
		}
		if (!$todo) {
			return ['msg' => __('nothing open', 'alba'), 'more' => false];
		}
		$from = $c->default;
		$fields = [];
		$seg = [];
		$start = microtime(true);
		$n = 0;
		foreach ($todo as $it) {
			if ($n >= self::MT_STEP || (microtime(true) - $start) > 18) {
				break;
			}
			if (mb_strlen($it[2]) > 6000) {
				$n++;
				continue;
			}
			$r = $c->mt->translate($it[2], $from, $lang);
			if (is_wp_error($r)) {
				if ($n === 0) {
					return $r->get_error_message();
				}
				break;
			}
			$n++;
			if ($it[3]) {
				$r = html_entity_decode((string) $r, ENT_QUOTES | ENT_HTML5, 'UTF-8');
				$fields[$it[0]] = ['src' => $it[1], 'text' => $r];
			} else {
				$seg[$it[1]] = (string) $r;
			}
		}
		$res = $e->import_store($post, $lang, $fields, $seg, false, '', __('Machine pre-translation', 'alba'));
		$left = max(0, count($todo) - $n);
		$done = (int) $res['new'];
		return ['msg' => $done . ($done === 1 ? __(' text', 'alba') : __(' texts', 'alba')) . __(' pre-translated', 'alba') . ($left ? ', ' . $left . __(' open', 'alba') : ''), 'more' => $left > 0 && $done > 0];
	}
}
