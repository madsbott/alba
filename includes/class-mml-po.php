<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * .po/.pot-Export und -Import für die Zeichenfolgen (Sprachen > Import / Export).
 *
 * Export: pro Sprache eine .po-Datei (oder eine .pot-Vorlage ohne Übersetzungen).
 * Jeder Eintrag trägt einen Kommentar "#. mml-id: …", damit der Import auch nach der Bearbeitung
 * in Poedit & Co. exakt zuordnet. Ohne diesen Kommentar wird über Kontext + Originaltext gematcht.
 */
class MML_Po {
	const MAX_BYTES = 3145728; // 3 MB

	private $core;

	public function __construct(MML_Core $core) {
		$this->core = $core;
		if (!is_admin()) {
			return;
		}
		add_action('admin_menu', [$this, 'menu'], 12);
		add_action('admin_post_mml_po_export', [$this, 'export']);
		add_action('admin_post_mml_po_import', [$this, 'import']);
		add_action('admin_notices', [$this, 'notices']);
	}

	/* ---------------------------------------------------------------
	 * Oberfläche
	 * ------------------------------------------------------------- */

	public function menu() {
		add_submenu_page('alba', __('Import / Export (.po)', 'alba'), __('Import / Export', 'alba'), 'manage_options', 'alba-po', [$this, 'page']);
	}

	public function page() {
		if (!current_user_can('manage_options')) {
			return;
		}
		echo '<div class="wrap"><h1>Import / Export (.po)</h1>';
		echo '<p>' . esc_html__('Download strings as a .po file, have them translated externally and import them again.', 'alba') . '</p>';
		$this->tools();
		echo '</div>';
	}

	public function tools() {
		if (!current_user_can('manage_options')) {
			return;
		}
		$c = $this->core;
		$all = get_option(MML_Strings::OPT, []);
		$domains = [];
		foreach ((array) $all as $s) {
			if (isset($s['domain'])) {
				$domains[$s['domain']] = true;
			}
		}
		ksort($domains);
		?>
		<h2><?php echo esc_html__('Export', 'alba'); ?></h2>
		<form method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="mml_po_export">
			<?php wp_nonce_field('mml_po_export'); ?>
			<select name="lang">
				<option value=""><?php echo esc_html__('Template (.pot, without translations)', 'alba'); ?></option>
				<?php foreach ($c->langs as $code => $l) : ?>
					<option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($l['name'] . ' (.po)'); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="domain">
				<option value=""><?php echo esc_html__('All domains', 'alba'); ?></option>
				<?php foreach (array_keys($domains) as $d) : ?>
					<option value="<?php echo esc_attr($d); ?>"><?php echo esc_html($d); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button(__('Download', 'alba'), 'secondary', 'submit', false); ?>
		</form>

		<h2><?php echo esc_html__('Import', 'alba'); ?></h2>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="mml_po_import">
			<?php wp_nonce_field('mml_po_import'); ?>
			<p>
				<label><?php echo esc_html__('Target language', 'alba'); ?>

					<select name="lang">
						<?php foreach ($c->langs as $code => $l) : ?>
							<option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($l['name']); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<input type="file" name="po_file" accept=".po,.pot" required>
			</p>
			<p><label><input type="checkbox" name="keep" value="1"> <?php echo esc_html__('Do not overwrite existing translations', 'alba'); ?></label></p>
			<?php submit_button(__('Import file', 'alba'), 'secondary', 'submit', false); ?>
			<p class="description"><?php echo esc_html__('Expects UTF-8, maximum 3 MB. "Fuzzy" entries, empty translations and plural forms are skipped. Only entries that already exist under "Strings" are imported.', 'alba'); ?></p>
		</form>
		<?php
	}

	public function notices() {
		if (!isset($_GET['page']) || $_GET['page'] !== 'alba-po' || !current_user_can('manage_options')) {
			return;
		}
		if (!empty($_GET['mml_po_err'])) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['mml_po_err']))) . '</p></div>';
		}
		if (isset($_GET['mml_po_ok'])) {
			$txt = (int) $_GET['mml_po_ok'] . __(' translations imported', 'alba');
			$bits = [
				'mml_po_keep'   => __('already existed and were kept', 'alba'),
				'mml_po_miss'   => __('without matching entry', 'alba'),
				'mml_po_ignore' => __('ignored (fuzzy, empty or plural)', 'alba'),
			];
			foreach ($bits as $k => $label) {
				if (!empty($_GET[$k])) {
					$txt .= ', ' . (int) $_GET[$k] . ' ' . $label;
				}
			}
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($txt . '.') . '</p></div>';
		}
	}

	/* ---------------------------------------------------------------
	 * Export
	 * ------------------------------------------------------------- */

	public function export() {
		check_admin_referer('mml_po_export');
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		$lang = isset($_GET['lang']) ? sanitize_key(wp_unslash($_GET['lang'])) : '';
		$dom = isset($_GET['domain']) ? sanitize_text_field(wp_unslash($_GET['domain'])) : '';
		if ($lang !== '' && !isset($this->core->langs[$lang])) {
			wp_die(esc_html__('Unknown language.', 'alba'));
		}

		$po = $this->build($lang, $dom);
		$name = 'alba' . ($lang !== '' ? '-' . $lang . '.po' : '.pot');

		nocache_headers();
		header('Content-Type: text/x-gettext-translation; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $name . '"');
		echo $po; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private function esc($s) {
		return str_replace(['\\', '"', "\r", "\n", "\t"], ['\\\\', '\\"', '\\r', '\\n', '\\t'], (string) $s);
	}

	private function line($s) {
		return trim(preg_replace('/\s+/', ' ', (string) $s));
	}

	private function header_line($k, $v) {
		return '"' . $k . ': ' . $v . '\n"' . "\n";
	}

	public function build($lang, $dom = '') {
		$c = $this->core;
		$all = get_option(MML_Strings::OPT, []);
		if (!is_array($all)) {
			$all = [];
		}
		if ($c->strings) {
			$c->strings->seed($all);
		}
		uasort($all, function ($a, $b) {
			return strcmp($a['domain'] . ' ' . $a['text'], $b['domain'] . ' ' . $b['text']);
		});

		$loc = '';
		if ($lang !== '') {
			$loc = !empty($c->langs[$lang]['locale']) ? $c->langs[$lang]['locale'] : $lang;
		}

		$out = "# Alba - Strings\n";
		$out .= "msgid \"\"\nmsgstr \"\"\n";
		$out .= $this->header_line('Project-Id-Version', 'Alba ' . MML_VERSION);
		$out .= $this->header_line('Language', $loc);
		$out .= $this->header_line('MIME-Version', '1.0');
		$out .= $this->header_line('Content-Type', 'text/plain; charset=UTF-8');
		$out .= $this->header_line('Content-Transfer-Encoding', '8bit');
		$out .= $this->header_line('X-Generator', 'Alba');

		$seen = [];
		foreach ($all as $id => $s) {
			if ($dom !== '' && $s['domain'] !== $dom) {
				continue;
			}
			$text = (string) $s['text'];
			if (trim($text) === '') {
				continue;
			}
			$ctx = isset($s['ctx']) ? (string) $s['ctx'] : '';
			$k = $ctx . "\0" . $text;
			if (isset($seen[$k])) {
				// gleicher Text in zwei Domains: .po verlangt eindeutige Kontext/Text-Paare
				$ctx = $s['domain'] . ':' . (!empty($s['name']) ? $s['name'] : substr($id, 0, 6));
				$k = $ctx . "\0" . $text;
			}
			$seen[$k] = true;

			$out .= "\n#. mml-id: " . $id . "\n#. domain: " . $this->line($s['domain']) . "\n";
			if (!empty($s['name'])) {
				$out .= '#. name: ' . $this->line($s['name']) . "\n";
			}
			if ($ctx !== '') {
				$out .= 'msgctxt "' . $this->esc($ctx) . "\"\n";
			}
			$out .= 'msgid "' . $this->esc($text) . "\"\n";
			$tr = ($lang !== '' && !empty($s['tr'][$lang])) ? $s['tr'][$lang] : '';
			$out .= 'msgstr "' . $this->esc($tr) . "\"\n";
		}
		return $out;
	}

	/* ---------------------------------------------------------------
	 * Import
	 * ------------------------------------------------------------- */

	private function back(array $args) {
		wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=alba-po')));
		exit;
	}

	public function import() {
		check_admin_referer('mml_po_import');
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		$c = $this->core;
		$lang = isset($_POST['lang']) ? sanitize_key(wp_unslash($_POST['lang'])) : '';
		if (!isset($c->langs[$lang])) {
			$this->back(['mml_po_err' => __('Unknown target language.', 'alba')]);
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Upload array; name, size and type are validated below.
		$f = isset($_FILES['po_file']) ? $_FILES['po_file'] : null;
		if (!$f || (int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
			$this->back(['mml_po_err' => __('Upload failed.', 'alba')]);
		}
		$ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
		if (!in_array($ext, ['po', 'pot'], true)) {
			$this->back(['mml_po_err' => __('Please upload a .po or .pot file.', 'alba')]);
		}
		if ((int) $f['size'] > self::MAX_BYTES) {
			$this->back(['mml_po_err' => __('The file is larger than 3 MB.', 'alba')]);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the local uploaded temp file, not a URL.
		$text = (string) file_get_contents($f['tmp_name']);
		if (function_exists('mb_check_encoding') && !mb_check_encoding($text, 'UTF-8')) {
			$this->back(['mml_po_err' => __('The file is not UTF-8 encoded.', 'alba')]);
		}

		$res = $this->apply($this->parse($text), $lang, !empty($_POST['keep']));
		$this->back([
			'mml_po_ok'     => $res['ok'],
			'mml_po_keep'   => $res['keep'],
			'mml_po_miss'   => $res['miss'],
			'mml_po_ignore' => $res['ignore'],
		]);
	}

	/** Übernimmt geparste Einträge in die gespeicherten Zeichenfolgen */
	public function apply(array $entries, $lang, $keep) {
		$all = get_option(MML_Strings::OPT, []);
		if (!is_array($all)) {
			$all = [];
		}
		if ($this->core->strings) {
			$this->core->strings->seed($all);
		}
		$by_text = [];
		foreach ($all as $id => $s) {
			$by_text[(isset($s['ctx']) ? (string) $s['ctx'] : '') . "\0" . $s['text']][] = $id;
		}

		$raw_ok = current_user_can('unfiltered_html');
		$res = ['ok' => 0, 'keep' => 0, 'miss' => 0, 'ignore' => 0];

		foreach ($entries as $e) {
			if ($e['id'] === '' && $e['ctx'] === null) {
				continue; // Kopfzeile
			}
			if ($e['plural'] || $e['fuzzy'] || trim((string) $e['str']) === '') {
				$res['ignore']++;
				continue;
			}
			$targets = [];
			if ($e['mmlid'] !== '' && isset($all[$e['mmlid']])) {
				$targets[] = $e['mmlid'];
			} else {
				$k = (string) $e['ctx'] . "\0" . $e['id'];
				if (isset($by_text[$k])) {
					$targets = $by_text[$k];
				}
			}
			if (!$targets) {
				$res['miss']++;
				continue;
			}
			$val = $raw_ok ? $e['str'] : wp_kses_post($e['str']);
			foreach ($targets as $id) {
				if ($keep && !empty($all[$id]['tr'][$lang])) {
					$res['keep']++;
					continue;
				}
				$all[$id]['tr'][$lang] = $val;
				$res['ok']++;
			}
		}
		update_option(MML_Strings::OPT, $all, false);
		return $res;
	}

	/* ---------------------------------------------------------------
	 * Parser
	 * ------------------------------------------------------------- */

	private function unesc($s) {
		return preg_replace_callback('/\\\\(.)/s', function ($m) {
			switch ($m[1]) {
				case 'n':
					return "\n";
				case 't':
					return "\t";
				case 'r':
					return "\r";
				default:
					return $m[1]; // \" und \\ und alles andere
			}
		}, $s);
	}

	private function blank() {
		return ['ctx' => null, 'id' => null, 'str' => null, 'mmlid' => '', 'fuzzy' => false, 'plural' => false];
	}

	/** Liest .po-Text: gibt Liste von Einträgen (ctx, id, str, mmlid, fuzzy, plural) zurück */
	public function parse($text) {
		$text = preg_replace('/^\xEF\xBB\xBF/', '', (string) $text);
		$entries = [];
		$cur = null;
		$field = null;

		foreach (preg_split('/\r\n|\n|\r/', $text) as $line) {
			$line = trim($line);

			if ($line === '') {
				if ($cur !== null && $cur['id'] !== null) {
					$entries[] = $cur;
				}
				$cur = null;
				$field = null;
				continue;
			}

			if ($line[0] === '#') {
				if (strpos($line, '#~') === 0) {
					continue; // veraltete Einträge
				}
				if ($cur !== null && $cur['str'] !== null) {
					if ($cur['id'] !== null) {
						$entries[] = $cur;
					}
					$cur = null;
				}
				if ($cur === null) {
					$cur = $this->blank();
				}
				$field = null;
				if (strpos($line, '#,') === 0 && stripos($line, 'fuzzy') !== false) {
					$cur['fuzzy'] = true;
				} elseif (preg_match('/^#\.\s*mml-id:\s*([a-f0-9]{32})/i', $line, $m)) {
					$cur['mmlid'] = strtolower($m[1]);
				}
				continue;
			}

			if (preg_match('/^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?)\s*"(.*)"$/s', $line, $m)) {
				$key = $m[1];
				$val = $this->unesc($m[2]);
				if (($key === 'msgctxt' || $key === 'msgid') && $cur !== null && $cur['str'] !== null) {
					if ($cur['id'] !== null) {
						$entries[] = $cur;
					}
					$cur = null;
				}
				if ($cur === null) {
					$cur = $this->blank();
				}
				if ($key === 'msgctxt') {
					$cur['ctx'] = $val;
					$field = 'ctx';
				} elseif ($key === 'msgid') {
					$cur['id'] = $val;
					$field = 'id';
				} elseif ($key === 'msgid_plural') {
					$cur['plural'] = true;
					$field = null;
				} elseif ($key === 'msgstr') {
					$cur['str'] = $val;
					$field = 'str';
				} else { // msgstr[n]
					$cur['plural'] = true;
					if ($cur['str'] === null) {
						$cur['str'] = '';
					}
					$field = null;
				}
				continue;
			}

			if ($line[0] === '"' && substr($line, -1) === '"' && strlen($line) >= 2 && $cur !== null && $field !== null) {
				$cur[$field] .= $this->unesc(substr($line, 1, -1));
			}
		}
		if ($cur !== null && $cur['id'] !== null) {
			$entries[] = $cur;
		}
		return $entries;
	}
}
