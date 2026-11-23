<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Links-rechts-Übersetzungseditor (wie bei WPML), ohne zweiten Beitrag.
 *
 * Die Übersetzung hängt als Meta am Originalbeitrag:
 *   _mml_tr_{lang}  Array: title, excerpt, seg (md5(Originaltext) => Übersetzung)
 *   _mml_on_{lang}  '1' = im Frontend sichtbar
 * Der Inhalt wird in Textabschnitte zerlegt (Block-Struktur, Shortcodes, Bilder und Attribute bleiben unberührt und erscheinen nicht im Editor).
 * Unter /en/ werden Titel, Auszug und Textabschnitte des Originals zur Laufzeit ersetzt;
 * fehlende Abschnitte erscheinen im Original. Wird der Originaltext geändert, ist nur dieser Abschnitt wieder offen.
 */
class MML_Editor {
	const TR = '_mml_tr_';
	const ON = '_mml_on_';
	const PAGE = 'alba-translate';
	const SLUG = '_mml_slug_';

	private static $inline = ['a', 'abbr', 'acronym', 'b', 'bdi', 'bdo', 'big', 'br', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'font', 'i', 'img', 'ins', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'time', 'tt', 'u', 'var', 'wbr'];

	private $core;
	private $off = false;
	private $memo = [];
	private $plain = false;

	public function __construct(MML_Core $core) {
		$this->core = $core;
		$core->editor = $this;

		add_filter('post_link', [$this, 'f_link'], 20, 2);
		add_filter('post_type_link', [$this, 'f_link'], 20, 2);
		add_filter('page_link', [$this, 'f_link'], 20, 2);
		if (!is_admin()) {
			add_filter('request', [$this, 'f_request']);
			add_filter('get_post_metadata', [$this, 'f_meta'], 10, 4);
			$this->seo_hooks();
			add_action('template_redirect', [$this, 'canonical'], 1);
		}

		if (is_admin()) {
			add_action('admin_menu', [$this, 'menu'], 20);
			add_action('admin_post_mml_save_tr', [$this, 'save']);
			add_action('admin_enqueue_scripts', [$this, 'assets']);
		} else {
			add_filter('the_title', [$this, 'f_title'], 5, 2);
			add_filter('single_post_title', [$this, 'f_single_title'], 10, 2);
			add_filter('the_content', [$this, 'f_content'], 1);
			add_filter('get_the_excerpt', [$this, 'f_excerpt'], 20, 2);
		}
	}

	/* ---------------------------------------------------------------
	 * Daten
	 * ------------------------------------------------------------- */

	public function url($id, $lang) {
		return admin_url('admin.php?page=' . self::PAGE . '&post=' . (int) $id . '&lang=' . rawurlencode($lang));
	}

	public function data($id, $lang) {
		$d = get_post_meta((int) $id, self::TR . $lang, true);
		$d = is_array($d) ? $d : [];
		return [
			'title'   => isset($d['title']) ? (string) $d['title'] : '',
			'excerpt' => isset($d['excerpt']) ? (string) $d['excerpt'] : '',
			'slug'    => isset($d['slug']) ? (string) $d['slug'] : '',
			'seg'     => (isset($d['seg']) && is_array($d['seg'])) ? $d['seg'] : [],
			'sig'     => isset($d['sig']) ? (string) $d['sig'] : '',
			'hs'      => (isset($d['hs']) && is_array($d['hs'])) ? $d['hs'] : null,
			'th'      => isset($d['th']) ? (string) $d['th'] : '',
			'eh'      => isset($d['eh']) ? (string) $d['eh'] : '',
			'seo_title' => isset($d['seo_title']) ? (string) $d['seo_title'] : '',
			'seo_desc'  => isset($d['seo_desc']) ? (string) $d['seo_desc'] : '',
			'seo_h'   => (isset($d['seo_h']) && is_array($d['seo_h'])) ? $d['seo_h'] : [],
			'time'    => isset($d['time']) ? (int) $d['time'] : 0,
			'imp'     => isset($d['imp']) ? (int) $d['imp'] : 0,   // Zeitpunkt des XLIFF-Imports, bis zum nächsten Speichern im Editor
		];
	}

	/** Fingerabdruck des übersetzbaren Originals (Titel, Auszug, Textabschnitte) */
	public function sig($post) {
		$h = $this->all_hashes($post->post_content);   // inkl. mehrfach vorkommender Texte
		foreach ($this->meta_texts($post->ID) as $val) {
			$h = array_merge($h, $this->all_hashes($val));
		}
		sort($h);
		return md5($post->post_title . "\0" . $post->post_excerpt . "\0" . implode(',', $h));
	}

	/**
	 * Eigener Übersetzungs-Beitrag: Wurde das Original (Standardsprache) seit dem letzten Speichern
	 * der Übersetzung textlich geändert? Fehlt der Vergleichswert, gilt der jetzige Stand als aktuell.
	 */
	public function outdated_real($tid) {
		$c = $this->core;
		$tid = (int) $tid;
		if (!$tid || $c->post_lang($tid) === $c->default) {
			return false;
		}
		$t = $c->translations($tid);
		if (empty($t[$c->default]) || (int) $t[$c->default] === $tid) {
			return false;
		}
		$src = get_post((int) $t[$c->default]);
		if (!$src) {
			return false;
		}
		$cur = $this->sig($src);
		$base = (string) get_post_meta($tid, '_mml_src_sig', true);
		if ($base === '') {
			// Ältere Übersetzung ohne Vergleichswert: nach Änderungsdatum entscheiden
			$tr = get_post($tid);
			if (!$tr) {
				return false;
			}
			return $this->ts($src) > $this->ts($tr) + 60;
		}
		return $base !== $cur;
	}

	/** Übersetzungs-Beitrag gilt jetzt als aktuell gegenüber dem Original */
	public function mark_synced($tid) {
		$c = $this->core;
		$tid = (int) $tid;
		if (!$tid || $c->post_lang($tid) === $c->default) {
			return;
		}
		$t = $c->translations($tid);
		if (empty($t[$c->default]) || (int) $t[$c->default] === $tid) {
			return;
		}
		$src = get_post((int) $t[$c->default]);
		if ($src) {
			update_post_meta($tid, '_mml_src_sig', $this->sig($src));
		}
	}

	/** Wurde das Original seit dem letzten Speichern der Übersetzung geändert? */
	public function outdated($id, $lang) {
		if (!metadata_exists('post', (int) $id, self::TR . $lang)) {
			return false;
		}
		$d = $this->data($id, $lang);
		$post = get_post((int) $id);
		if (!$post) {
			return false;
		}
		if ($d['sig'] === '') {
			// Ältere Speicherung ohne Vergleichswert: nach Änderungsdatum entscheiden
			return $d['time'] > 0 && $this->ts($post) > $d['time'] + 60;
		}
		return $d['sig'] !== $this->sig($post);
	}

	private function ts($post) {
		$t = strtotime($post->post_modified_gmt . ' UTC');
		return $t ? (int) $t : 0;
	}

	public function is_on($id, $lang) {
		return (bool) get_post_meta((int) $id, self::ON . $lang, true);
	}

	/** '' = keine Übersetzung, 'draft' = gespeichert, nicht sichtbar, 'on' = sichtbar */
	public function state($id, $lang) {
		if ($this->is_on($id, $lang)) {
			return 'on';
		}
		return metadata_exists('post', (int) $id, self::TR . $lang) ? 'draft' : '';
	}

	/* ---------------------------------------------------------------
	 * Übersetzte Slugs (/en/about-us/ statt /en/ueber-uns/)
	 * ------------------------------------------------------------- */

	/** Übersetzter Slug eines Beitrags (nur wenn die Editor-Übersetzung sichtbar ist), sonst '' */
	public function slug($id, $lang) {
		if (!$this->is_active($id, $lang)) {
			return '';
		}
		$d = $this->data($id, $lang);
		return $d['slug'];
	}

	/** Pfad (bei Seiten inkl. Elternseiten) eines Beitrags in einer Sprache */
	private function t_path($post, $lang) {
		$ids = [];
		if (is_post_type_hierarchical($post->post_type)) {
			$ids = array_reverse(array_map('intval', get_post_ancestors($post)));
		}
		$ids[] = (int) $post->ID;
		$parts = [];
		foreach ($ids as $i) {
			$p = get_post($i);
			if (!$p) {
				continue;
			}
			$s = $this->plain ? '' : $this->slug($i, $lang);
			$parts[] = $s !== '' ? $s : $p->post_name;
		}
		return implode('/', $parts);
	}

	private function o_path($post) {
		return is_post_type_hierarchical($post->post_type) ? get_page_uri($post) : $post->post_name;
	}

	/** Filter für post_link / page_link / post_type_link */
	public function f_link($link, $post) {
		$lang = $this->core->lang;
		if ($this->plain || $lang === $this->core->default || !is_string($link) || strpos($link, '%') !== false) {
			return $link;
		}
		$post = is_object($post) ? $post : get_post($post);
		if (!$post || !$this->core->is_translatable($post->post_type)) {
			return $link;
		}
		$old = $this->o_path($post);
		$new = $this->t_path($post, $lang);
		if ($old === '' || $new === $old) {
			return $link;
		}
		$out = preg_replace('~/' . preg_quote($old, '~') . '(/?)(\?[^#]*)?(#.*)?$~', '/' . strtr($new, ['\\' => '\\\\', '$' => '\\$']) . '$1$2$3', $link, 1);
		return is_string($out) ? $out : $link;
	}

	/** Anfrage /en/about-us/ dem Originalbeitrag zuordnen */
	public function f_request($qv) {
		$c = $this->core;
		$lang = $c->lang;
		if ($lang === $c->default || !is_array($qv)) {
			return $qv;
		}
		if (!empty($qv['pagename']) && is_string($qv['pagename'])) {
			$path = $qv['pagename'];
			$type = 'page';
		} elseif (!empty($qv['name']) && is_string($qv['name'])) {
			$path = $qv['name'];
			// Ohne Typangabe kann bei "/%postname%/" auch eine Seite gemeint sein
			$type = (!empty($qv['post_type']) && is_string($qv['post_type'])) ? $qv['post_type'] : '';
		} elseif (!empty($qv['attachment']) && is_string($qv['attachment']) && !empty($GLOBALS['wp']->request)) {
			// "eltern/kind" wird von WordPress zuerst als Anhang gelesen
			$path = (string) $GLOBALS['wp']->request;
			$type = 'page';
		} else {
			return $qv;
		}
		$types = $type !== '' ? [$type] : array_values($c->post_types);
		$types = array_values(array_filter($types, function ($t) use ($c) {
			return $c->is_translatable($t);
		}));
		if (!$types) {
			return $qv;
		}
		$path = trim($path, '/');
		$last = ($p = strrpos($path, '/')) === false ? $path : substr($path, $p + 1);

		$base = ['post_type' => $types, 'post_status' => 'publish', 'fields' => 'ids', 'numberposts' => 20, 'suppress_filters' => true, 'no_found_rows' => true];
		$cand = get_posts($base + ['meta_key' => self::SLUG . $lang, 'meta_value' => $last]);
		if (!$cand && $p !== false && in_array('page', $types, true)) {
			$cand = get_posts(['post_type' => 'page', 'name' => $last] + $base);
		}
		foreach ($cand as $cid) {
			$post = get_post($cid);
			if (!$post || !$this->is_active($cid, $lang) || $this->t_path($post, $lang) !== $path) {
				continue;
			}
			unset($qv['pagename'], $qv['name'], $qv['attachment']);
			if ($post->post_type === 'page') {
				$qv['page_id'] = (int) $cid;
			} else {
				$qv['p'] = (int) $cid;
				$qv['post_type'] = $post->post_type;
			}
			break;
		}
		return $qv;
	}

	/** Alte Adresse mit Original-Slug (/en/ueber-uns/) -> 301 auf die übersetzte Adresse */
	public function canonical() {
		$c = $this->core;
		if ($c->lang === $c->default || !is_singular() || is_preview() || (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET')) {
			return;
		}
		$id = (int) get_queried_object_id();
		if (!$id || $this->slug($id, $c->lang) === '' && !$this->has_parent_slug($id, $c->lang)) {
			return;
		}
		$new = get_permalink($id);
		$this->plain = true;
		$old = get_permalink($id);
		$this->plain = false;
		$req = wp_parse_url($c->orig_uri, PHP_URL_PATH);
		$np = wp_parse_url($new, PHP_URL_PATH);
		$op = wp_parse_url($old, PHP_URL_PATH);
		if (!$req || !$np || !$op || untrailingslashit($np) === untrailingslashit($op) || untrailingslashit($req) !== untrailingslashit($op)) {
			return;
		}
		$qs = wp_parse_url($c->orig_uri, PHP_URL_QUERY);
		wp_safe_redirect($new . ($qs ? '?' . $qs : ''), 301);
		exit;
	}

	private function has_parent_slug($id, $lang) {
		foreach (get_post_ancestors($id) as $a) {
			if ($this->slug($a, $lang) !== '') {
				return true;
			}
		}
		return false;
	}

	/** Slug eindeutig machen (je Sprache, Typ und Elternseite; nicht gleich dem Original-Slug eines anderen Beitrags) */
	private function unique_slug($slug, $post, $lang) {
		global $wpdb;
		if ($slug === '' || $slug === $post->post_name) {
			return '';
		}
		$base = $slug;
		$n = 1;
		while (true) {
			$mine = $wpdb->get_var($wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s
				 WHERE p.post_type = %s AND p.post_parent = %d AND p.ID <> %d AND p.post_status NOT IN ('trash','auto-draft') LIMIT 1",
				self::SLUG . $lang, $slug, $post->post_type, $post->post_parent, $post->ID
			));
			$orig = $wpdb->get_var($wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND post_parent = %d AND ID <> %d AND post_status NOT IN ('trash','auto-draft') LIMIT 1",
				$slug, $post->post_type, $post->post_parent, $post->ID
			));
			if (!$mine && !$orig) {
				return $slug;
			}
			$n++;
			$slug = $base . '-' . $n;
		}
	}

	/** Gilt für diesen Beitrag in dieser Sprache eine Editor-Übersetzung (und gibt es keinen eigenen Übersetzungs-Beitrag)? */
	public function is_active($id, $lang) {
		$id = (int) $id;
		$c = $this->core;
		if (!$id || $lang === '' || !isset($c->langs[$lang]) || $c->post_lang($id) === $lang) {
			return false;
		}
		if (!$this->is_on($id, $lang)) {
			return false;
		}
		$t = $c->translations($id);
		return empty($t[$lang]);
	}

	/** Führt $cb ohne Titel-Ersetzung aus (liefert den Original-Titel) */
	public function raw($cb) {
		$old = $this->off;
		$this->off = true;
		try {
			return call_user_func($cb);
		} finally {
			$this->off = $old;
		}
	}

	private function active($id) {
		if ($this->off) {
			return null;
		}
		$id = (int) $id;
		$lang = $this->core->lang;
		$k = $id . '|' . $lang;
		if (!array_key_exists($k, $this->memo)) {
			$this->memo[$k] = $this->is_active($id, $lang) ? $this->data($id, $lang) : null;
		}
		return $this->memo[$k];
	}

	/* ---------------------------------------------------------------
	 * Text in Abschnitte zerlegen
	 * ------------------------------------------------------------- */

	private function tokens($html) {
		preg_match_all('~<(script|style)\b[^>]*>.*?</\1\s*>|<!--.*?-->|<[^>]+>|[^<]+|<~is', (string) $html, $m);
		$out = [];
		foreach ($m[0] as $tok) {
			if ($tok === '' || $tok[0] === '<' || strpos($tok, '[') === false) {
				$out[] = $tok;
				continue;
			}
			// Shortcodes (z. B. WPBakery) als eigene Token abtrennen
			foreach (preg_split('~(\[/?[a-zA-Z][\w-]*(?:\s[^\[\]]*)?/?\])~', $tok, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $piece) {
				$out[] = $piece;
			}
		}
		return $out;
	}

	private function is_shortcode($tok) {
		if ($tok === '' || $tok[0] !== '[' || !preg_match('~^\[(/?)([a-zA-Z][\w-]*)(\s[^\[\]]*)?/?\]$~', $tok, $m)) {
			return false;
		}
		return strpbrk($m[2], '_-0123456789') !== false
			|| (isset($m[3]) && strpos($m[3], '=') !== false)
			|| shortcode_exists($m[2]);
	}

	private function is_inline($tok) {
		if ($tok === '' || $tok === '<' || $tok[0] !== '<') {
			return !$this->is_shortcode($tok);
		}
		if (strpos($tok, '<!--') === 0) {
			return false;
		}
		if (preg_match('~^<\s*/?\s*([a-z][a-z0-9]*)~i', $tok, $m)) {
			return in_array(strtolower($m[1]), self::$inline, true);
		}
		return false;
	}

	private function flush($run, $fn) {
		if ($run === '') {
			return '';
		}
		$text = html_entity_decode(wp_strip_all_tags($run), ENT_QUOTES, 'UTF-8');
		if (preg_match('/\p{L}/u', $text) === 0 || preg_match('/^\s*\[[^\]]*\]\s*$/', $text)) {
			return $run;
		}
		if (!preg_match('/^(\s*)(.*?)(\s*)$/s', $run, $m)) {
			return $run;
		}
		return $m[1] . call_user_func($fn, $m[2]) . $m[3];
	}

	/** Attribute mit sichtbarem Text, die in Shortcodes (z. B. WPBakery) mit übersetzt werden */
	private function text_attrs() {
		return array_values(array_unique((array) apply_filters('mml_shortcode_text_attrs', array_merge(
			['title', 'text', 'heading', 'subheading', 'subtitle', 'sub_title', 'label', 'caption', 'description', 'button_text', 'btn_title', 'btn_text', 'header', 'link_text', 'tagline', 'quote', 'message', 'alt', 'h2', 'h4'],
			$this->extra_names()
		))));
	}

	private function sc_attrs($tok, $fn) {
		if ($tok === '' || $tok[0] !== '[' || (isset($tok[1]) && $tok[1] === '/') || strpos($tok, '=') === false || !$this->is_shortcode($tok)) {
			return $tok;
		}
		$names = implode('|', array_map(function ($n) {
			return preg_quote($n, '~');
		}, $this->text_attrs()));
		if ($names === '') {
			return $tok;
		}
		return preg_replace_callback('~(?<![\w-])(((?:' . $names . '))\s*=\s*)(["\'])(.*?)\3~s', function ($m) use ($fn) {
			if (preg_match('/\p{L}/u', $m[4]) === 0 || $this->not_text($m[4])) {
				return $m[0];
			}
			return $m[1] . $m[3] . call_user_func($fn, $m[4], true, $m[3], 'sc:' . $m[2]) . $m[3];
		}, $tok);
	}

	/* ---------------------------------------------------------------
	 * Weitere Texte: HTML-Attribute, Block-Attribute, benutzerdefinierte Felder
	 * ------------------------------------------------------------- */

	private function opt($name, $default = 1) {
		return (int) get_option($name, $default) === 1;
	}

	private function list_opt($name) {
		$raw = (string) get_option($name, '');
		$out = [];
		foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $x) {
			$x = preg_replace('/[^A-Za-z0-9_\-*.:]/', '', $x);
			if ($x !== '') {
				$out[] = $x;
			}
		}
		return array_values(array_unique($out));
	}

	/** Eigene Feldnamen (Einstellungen), gelten für Element-/Block-Attribute */
	private function extra_names() {
		return $this->list_opt('mml_extra_attrs');
	}

	private function html_attr_names() {
		return $this->opt('mml_t_attrs') ? array_values((array) apply_filters('mml_html_text_attrs', ['alt', 'title', 'aria-label', 'placeholder'])) : [];
	}

	private function block_keys() {
		return array_values(array_unique((array) apply_filters('mml_block_text_attrs', array_merge(
			['label', 'title', 'text', 'alt', 'caption', 'heading', 'subheading', 'subtitle', 'description', 'buttonText', 'placeholder', 'content', 'linkText', 'tagline', 'quote', 'message'],
			$this->extra_names()
		))));
	}

	/** Werte, die kein Fließtext sind (Adressen, E-Mails, Feldschlüssel, Farben) */
	private function not_text($v) {
		return (bool) preg_match('~^(?:https?://|//|/|mailto:|tel:|#[0-9a-f]{3,8}$|field_[0-9a-f]+$|[\w.+-]+@[\w-]+\.[\w.]+$)~i', trim($v));
	}

	/** Attribute eines Tokens (HTML-Tag, Shortcode oder Block-Kommentar) */
	private function tok_attrs($tok, $fn) {
		if ($tok === '' || $tok[0] !== '<' && $tok[0] !== '[') {
			return $tok;
		}
		if ($tok[0] === '[') {
			return $this->sc_attrs($tok, $fn);
		}
		if (strpos($tok, '<!--') === 0) {
			return $this->block_json($tok, $fn);
		}
		if (preg_match('~^<(script|style)\b~i', $tok)) {
			return $tok;
		}
		$names = $this->html_attr_names();
		if (!$names || strpos($tok, '=') === false) {
			return $tok;
		}
		$re = $this->attr_re($names);
		return preg_replace_callback($re, function ($m) use ($fn) {
			if (preg_match('/\p{L}/u', $m[4]) === 0 || $this->not_text($m[4])) {
				return $m[0];
			}
			return $m[1] . $m[3] . call_user_func($fn, $m[4], true, $m[3], 'attr:' . strtolower($m[2])) . $m[3];
		}, $tok);
	}

	private function attr_re($names) {
		$n = implode('|', array_map(function ($x) {
			return preg_quote($x, '~');
		}, $names));
		return '~(?<![\w-])(((?:' . $n . '))\s*=\s*)(["\'])(.*?)\3~is';
	}

	/** Textabschnitt mit Tags: Attributwerte (alt, title …) werden eigene Abschnitte, der Fließtext bleibt wie bisher */
	private function flush_run($run, $fn) {
		$names = $this->html_attr_names();
		if ($run === '' || !$names || strpos($run, '<') === false || strpos($run, '=') === false) {
			return $this->flush($run, $fn);
		}
		$re = $this->attr_re($names);
		$vals = [];
		$masked = preg_replace_callback('~<[^>]+>~', function ($t) use ($re, &$vals) {
			return preg_replace_callback($re, function ($m) use (&$vals) {
				if (preg_match('/\p{L}/u', $m[4]) === 0 || $this->not_text($m[4])) {
					return $m[0];
				}
				$vals[] = [strtolower($m[2]), $m[4], $m[3]];
				return $m[1] . $m[3] . $m[3];
			}, $t[0]);
		}, $run);
		if (!$vals) {
			return $this->flush($run, $fn);
		}
		$tr = [];
		foreach ($vals as $v) {
			$tr[] = call_user_func($fn, $v[1], true, $v[2], 'attr:' . $v[0]);
		}
		$res = $this->flush($masked, $fn);
		$i = 0;
		if ($res === $masked) {
			// Fließtext unverändert: Originalstück mit übersetzten Attributen
			return preg_replace_callback('~<[^>]+>~', function ($t) use ($re, &$i, $tr) {
				return preg_replace_callback($re, function ($m) use (&$i, $tr) {
					if (preg_match('/\p{L}/u', $m[4]) === 0 || $this->not_text($m[4])) {
						return $m[0];
					}
					$v = isset($tr[$i]) ? $tr[$i] : $m[4];
					$i++;
					return $m[1] . $m[3] . $v . $m[3];
				}, $t[0]);
			}, $run);
		}
		// Fließtext übersetzt: leere Attribut-Platzhalter wieder füllen (nur wenn die Anzahl passt)
		$slot = '~(?<![\w-])(((?:' . implode('|', array_map(function ($x) {
			return preg_quote($x, '~');
		}, $names)) . '))\s*=\s*)(["\'])\3~i';
		if (preg_match_all($slot, $res) !== count($vals)) {
			return $res;
		}
		return preg_replace_callback($slot, function ($m) use (&$i, $tr, $vals) {
			$v = isset($tr[$i]) ? $tr[$i] : $vals[$i][1];
			$i++;
			return $m[1] . $m[3] . $v . $m[3];
		}, $res);
	}

	private function block_key_ok($k, $v, $in_data) {
		if ($k === '' || $k[0] === '_' || $this->not_text($v)) {
			return false;
		}
		if (in_array($k, $this->block_keys(), true)) {
			return true;
		}
		return $in_data && preg_match('/\s/u', trim($v)) === 1;
	}

	/** Text in den JSON-Attributen eines Block-Kommentars (Core-/ACF-/eigene Blöcke) */
	private function block_json($tok, $fn) {
		if (!$this->opt('mml_t_blocks') || !preg_match('~^(<!--\s+wp:([a-z0-9_-]+(?:/[a-z0-9_-]+)?)\s+)(\{.*\})(\s+/?-->)$~s', $tok, $m)) {
			return $tok;
		}
		$attrs = json_decode($m[3], true);
		if (!is_array($attrs)) {
			return $tok;
		}
		$acf = strpos($m[2], 'acf/') === 0;
		$changed = false;
		$walk = function (&$node, $key, $in_data) use (&$walk, $fn, &$changed, $acf) {
			foreach ($node as $k => &$v) {
				$kk = is_string($k) ? $k : $key;
				if (is_array($v)) {
					$walk($v, $kk, $in_data || ($acf && $k === 'data'));
					continue;
				}
				if (!is_string($v) || preg_match('/\p{L}/u', $v) === 0 || !$this->block_key_ok($kk, $v, $in_data)) {
					continue;
				}
				$new = call_user_func($fn, $v, 'json', '', 'json:' . $kk);
				if (is_string($new) && $new !== $v) {
					$v = $new;
					$changed = true;
				}
			}
			unset($v);
		};
		$walk($attrs, '', false);
		if (!$changed) {
			return $tok;
		}
		$json = function_exists('serialize_block_attributes') ? serialize_block_attributes($attrs) : wp_json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return $m[1] . $json . $m[4];
	}

	/** Feldnamen (Meta-Schlüssel) mit übersetzbarem Text: eigene Liste plus ACF-Textfelder des Beitrags */
	public function meta_keys($post_id = 0) {
		$keys = $this->list_opt('mml_meta_keys');
		$post_id = (int) $post_id;
		if ($post_id && $this->opt('mml_t_acf') && function_exists('acf_get_field')) {
			foreach ((array) get_post_meta($post_id) as $k => $v) {
				if ($k !== '' && $k[0] !== '_' && isset($v[0]) && is_string($v[0]) && $this->acf_text($post_id, $k)) {
					$keys[] = $k;
				}
			}
		}
		return array_values(array_unique($keys));
	}

	private function acf_text($post_id, $key) {
		static $memo = [];
		$mk = $post_id . '|' . $key;
		if (!isset($memo[$mk])) {
			$ref = get_post_meta($post_id, '_' . $key, true);
			$f = (is_string($ref) && strpos($ref, 'field_') === 0 && function_exists('acf_get_field')) ? acf_get_field($ref) : null;
			$memo[$mk] = is_array($f) && isset($f['type']) && in_array($f['type'], ['text', 'textarea', 'wysiwyg'], true);
		}
		return $memo[$mk];
	}

	private function key_match($key, $list) {
		foreach ($list as $pat) {
			if ($pat === $key || (strpos($pat, '*') !== false && fnmatch($pat, $key))) {
				return true;
			}
		}
		return false;
	}

	/** key => Text der übersetzbaren Felder dieses Beitrags (im Originalzustand) */
	public function meta_texts($post_id) {
		$out = [];
		$post_id = (int) $post_id;
		$manual = $this->list_opt('mml_meta_keys');
		if (!$manual && !($this->opt('mml_t_acf') && function_exists('acf_get_field'))) {
			return $out;
		}
		$all = (array) get_post_meta($post_id);
		foreach ($all as $k => $v) {
			if ($k === '' || $k[0] === '_' || !isset($v[0]) || !is_string($v[0]) || trim($v[0]) === '') {
				continue;
			}
			if ($this->key_match($k, $manual) || ($this->opt('mml_t_acf') && function_exists('acf_get_field') && $this->acf_text($post_id, $k))) {
				$val = maybe_unserialize($v[0]);
				if (is_string($val) && preg_match('/\p{L}/u', $val) && !$this->not_text($val)) {
					$out[$k] = $val;
				}
			}
		}
		return $out;
	}

	/** Alle übersetzbaren Abschnitte eines Beitrags: Inhalt plus benutzerdefinierte Felder (hash => Text) */
	public function post_segments($post, &$kinds = null) {
		$segs = $this->segments($post->post_content, $kinds);
		foreach ($this->meta_texts($post->ID) as $key => $val) {
			$k2 = [];
			foreach ($this->segments($val, $k2) as $h => $t) {
				if (!isset($segs[$h])) {
					$segs[$h] = $t;
					$kinds[$h] = 'meta:' . $key;
				}
			}
		}
		return $segs;
	}

	/** Frontend: Werte benutzerdefinierter Felder (get_post_meta / ACF) übersetzen */
	public function f_meta($value, $id, $key, $single) {
		static $busy = false;
		if ($busy || $value !== null || !is_string($key) || $key === '' || $key[0] === '_' || $this->core->lang === $this->core->default) {
			return $value;
		}
		$id = (int) $id;
		$d = $this->active($id);
		if (!$d || !$d['seg']) {
			return $value;
		}
		$manual = $this->list_opt('mml_meta_keys');
		if (!$this->key_match($key, $manual) && !($this->opt('mml_t_acf') && function_exists('acf_get_field') && $this->acf_text($id, $key))) {
			return $value;
		}
		$busy = true;
		$raw = get_post_meta($id, $key, $single);
		$busy = false;
		if ($single) {
			return is_string($raw) && $raw !== '' ? $this->apply($raw, $d['seg']) : $value;
		}
		if (!is_array($raw)) {
			return $value;
		}
		return array_map(function ($x) use ($d) {
			return is_string($x) && $x !== '' ? $this->apply($x, $d['seg']) : $x;
		}, $raw);
	}

	/* ---------------------------------------------------------------
	 * SEO-Felder (Yoast SEO, Rank Math): Titel und Beschreibung je Sprache
	 * ------------------------------------------------------------- */

	/** Aktives SEO-Plugin: 'Rank Math', 'Yoast SEO' oder '' */
	public function seo_plugin() {
		if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
			return 'Rank Math';
		}
		if (defined('WPSEO_VERSION')) {
			return 'Yoast SEO';
		}
		return '';
	}

	/** Meta-Schlüssel je Plugin: Titel, Beschreibung, Social-Titel, Social-Beschreibung */
	private function seo_keys() {
		if ($this->seo_plugin() === 'Rank Math') {
			return ['title' => 'rank_math_title', 'desc' => 'rank_math_description', 'og_title' => 'rank_math_facebook_title', 'og_desc' => 'rank_math_facebook_description'];
		}
		return ['title' => '_yoast_wpseo_title', 'desc' => '_yoast_wpseo_metadesc', 'og_title' => '_yoast_wpseo_opengraph-title', 'og_desc' => '_yoast_wpseo_opengraph-description'];
	}

	/** Original-SEO-Werte (roh, evtl. mit Variablen wie %%sitename%%) */
	public function seo_source($id) {
		$k = $this->seo_keys();
		return [
			'title' => trim((string) get_post_meta((int) $id, $k['title'], true)),
			'desc'  => trim((string) get_post_meta((int) $id, $k['desc'], true)),
		];
	}

	private function seo_hooks() {
		$map = [
			'wpseo_title'                                              => 'title',
			'wpseo_metadesc'                                           => 'desc',
			'wpseo_opengraph_title'                                    => 'og_title',
			'wpseo_opengraph_desc'                                     => 'og_desc',
			'wpseo_twitter_title'                                      => 'og_title',
			'wpseo_twitter_description'                                => 'og_desc',
			'rank_math/frontend/title'                                 => 'title',
			'rank_math/frontend/description'                           => 'desc',
			'rank_math/opengraph/facebook/og_title'                    => 'og_title',
			'rank_math/opengraph/facebook/og_description'              => 'og_desc',
			'rank_math/opengraph/twitter/twitter_title'                => 'og_title',
			'rank_math/opengraph/twitter/twitter_description'          => 'og_desc',
		];
		foreach ($map as $hook => $kind) {
			add_filter($hook, function ($v) use ($kind) {
				return $this->f_seo($v, $kind);
			}, 20);
		}
	}

	private function seo_vars($text, $id) {
		$post = get_post($id);
		if ($post && function_exists('wpseo_replace_vars')) {
			return wpseo_replace_vars($text, $post);
		}
		if ($post && class_exists('\RankMath\Helper') && method_exists('\RankMath\Helper', 'replace_vars')) {
			return \RankMath\Helper::replace_vars($text, $post);
		}
		return $text;
	}

	public function f_seo($value, $kind) {
		if ($this->core->lang === $this->core->default || !is_singular() || !is_string($value)) {
			return $value;
		}
		$id = (int) get_queried_object_id();
		$d = $id ? $this->active($id) : null;
		if (!$d) {
			return $value;
		}
		$is_title = ($kind === 'title' || $kind === 'og_title');
		$t = $is_title ? $d['seo_title'] : $d['seo_desc'];
		if ($t !== '') {
			// Social-Titel/-Beschreibung nur ersetzen, wenn dafür nichts Eigenes eingetragen ist
			if ($kind === 'og_title' || $kind === 'og_desc') {
				$k = $this->seo_keys();
				if (trim((string) get_post_meta($id, $k[$kind], true)) !== '') {
					return $value;
				}
			}
			return $this->seo_vars($t, $id);
		}
		// Ohne eigene SEO-Übersetzung: übersetzten Seitentitel in den automatisch erzeugten Titel einsetzen
		if ($is_title && $d['title'] !== '') {
			$post = get_post($id);
			if ($post && $post->post_title !== '' && strpos($value, $post->post_title) !== false) {
				return str_replace($post->post_title, $d['title'], $value);
			}
		}
		return $value;
	}

	/** Änderungen gegenüber dem vorherigen Stand ins Protokoll schreiben */
	private function log_save($post, $lang, $old, $data, $segs, $kinds, $was_on, $is_on, $existed) {
		$changes = [];
		$fields = ['title' => __('Title', 'alba'), 'excerpt' => __('Excerpt', 'alba'), 'slug' => __('URL slug', 'alba'), 'seo_title' => __('SEO title', 'alba'), 'seo_desc' => __('SEO description', 'alba')];
		$f_names = [];
		foreach ($fields as $k => $label) {
			if ((string) $old[$k] !== (string) $data[$k]) {
				$changes[] = ['f' => $label, 'old' => (string) $old[$k], 'new' => (string) $data[$k]];
				$f_names[] = $label;
			}
		}
		$new_n = 0;
		$edit_n = 0;
		$clear_n = 0;
		$n = 0;
		foreach ($segs as $h => $src) {
			$n++;
			$o = isset($old['seg'][$h]) ? (string) $old['seg'][$h] : '';
			$nw = isset($data['seg'][$h]) ? (string) $data['seg'][$h] : '';
			if ($o === $nw) {
				continue;
			}
			if ($o === '') {
				$new_n++;
			} elseif ($nw === '') {
				$clear_n++;
			} else {
				$edit_n++;
			}
			$changes[] = ['f' => $this->kind_label(isset($kinds[$h]) ? $kinds[$h] : '', $n), 'src' => $src, 'old' => $o, 'new' => $nw];
		}
		$removed = 0;
		foreach ((array) $old['seg'] as $h => $t) {
			if (!isset($segs[(string) $h]) && (string) $t !== '') {
				$removed++;
			}
		}
		$parts = [];
		if ($new_n) {
			$parts[] = $new_n . ($new_n === 1 ? __(' text newly translated', 'alba') : __(' texts newly translated', 'alba'));
		}
		if ($edit_n) {
			$parts[] = $edit_n . ($edit_n === 1 ? __(' text changed', 'alba') : __(' texts changed', 'alba'));
		}
		if ($clear_n) {
			$parts[] = $clear_n . ($clear_n === 1 ? __(' text cleared', 'alba') : __(' texts cleared', 'alba'));
		}
		if ($f_names) {
			$parts[] = implode(', ', $f_names);
		}
		if (!$existed) {
			array_unshift($parts, __('Translation started', 'alba'));
		}
		if ($was_on !== $is_on) {
			$parts[] = $is_on ? __('published on the frontend', 'alba') : __('hidden on the frontend', 'alba');
		}
		if (!$parts) {
			$parts[] = __('saved without text changes (confirmed)', 'alba');
		}
		$this->core->log->add($post->ID, $lang, 'save', implode(' · ', $parts), ['changes' => $changes, 'removed' => $removed, 'on' => $is_on]);
	}

	/** [offen, gesamt]: Texte dieses Beitrags (Titel, Auszug, SEO, Abschnitte), die in der Sprache noch nicht übersetzt sind */
	public function open_count($post, $lang) {
		$d = $this->data($post->ID, $lang);
		$open = 0;
		$total = 0;
		$check = function ($src, $val) use (&$open, &$total) {
			if (trim((string) $src) === '') {
				return;
			}
			$total++;
			if (trim((string) $val) === '') {
				$open++;
			}
		};
		$check($post->post_title, $d['title']);
		$check($post->post_excerpt, $d['excerpt']);
		if ($this->seo_plugin()) {
			$ss = $this->seo_source($post->ID);
			$check($ss['title'], $d['seo_title']);
			$check($ss['desc'], $d['seo_desc']);
		}
		foreach ($this->post_segments($post) as $h => $t) {
			$check($t, isset($d['seg'][$h]) ? $d['seg'][$h] : '');
		}
		return [$open, $total];
	}

	/**
	 * Übernimmt Texte aus einem XLIFF-Import in die Editor-Übersetzung. Die Freigabe (im Frontend anzeigen) bleibt unverändert,
	 * der Import wird im Editor bis zum nächsten Speichern vermerkt.
	 * $fields: key (title|excerpt|seo_title|seo_desc|slug) => ['src' => md5 des Originals, 'text' => Übersetzung]
	 * $seg: hash => Übersetzung. Rückgabe: Zähler new/chg/same/kept.
	 */
	public function import_store($post, $lang, array $fields, array $seg, $overwrite, $file = '', $label = '') {
		if ($label === '') {
			$label = __('XLIFF import', 'alba');
		}
		$c = $this->core;
		$id = (int) $post->ID;
		$res = ['new' => 0, 'chg' => 0, 'same' => 0, 'kept' => 0];
		if (!isset($c->langs[$lang]) || $c->post_lang($id) === $lang) {
			return $res;
		}
		$kinds = [];
		$segs = $this->post_segments($post, $kinds);
		$old = $this->data($id, $lang);
		$existed = metadata_exists('post', $id, self::TR . $lang);
		$known = ($old['sig'] !== '' && is_array($old['hs']));
		$raw = current_user_can('unfiltered_html');
		$seo_on = $this->seo_plugin() !== '';
		$ss = $seo_on ? $this->seo_source($id) : ['title' => '', 'desc' => ''];
		$src = [
			'title'     => md5($post->post_title),
			'excerpt'   => md5($post->post_excerpt),
			'seo_title' => md5($ss['title']),
			'seo_desc'  => md5($ss['desc']),
			'slug'      => md5($post->post_name),
		];
		$data = $old;
		$changes = [];
		$names = ['title' => __('Title', 'alba'), 'excerpt' => __('Excerpt', 'alba'), 'slug' => __('URL slug', 'alba'), 'seo_title' => __('SEO title', 'alba'), 'seo_desc' => __('SEO description', 'alba')];
		$touched_fields = [];
		$touched_hash = [];

		$put = function ($cur, $val, $label, $source) use (&$res, &$changes, $overwrite) {
			if ($val === '') {
				return false;
			}
			if ((string) $cur === $val) {
				$res['same']++;
				return 'same';
			}
			if ((string) $cur !== '' && !$overwrite) {
				$res['kept']++;
				return false;
			}
			$res[(string) $cur === '' ? 'new' : 'chg']++;
			$ch = ['f' => $label, 'old' => (string) $cur, 'new' => $val];
			if ($source !== '') {
				$ch['src'] = $source;
			}
			$changes[] = $ch;
			return true;
		};

		foreach ($fields as $k => $f) {
			if (!isset($src[$k]) || !is_array($f) || !isset($f['src'], $f['text']) || $f['src'] !== $src[$k]) {
				continue;
			}
			if (($k === 'seo_title' || $k === 'seo_desc') && !$seo_on) {
				continue;
			}
			$t = (string) $f['text'];
			if ($k === 'excerpt' || $k === 'seo_desc') {
				$val = trim(sanitize_textarea_field($t));
			} elseif ($k === 'slug') {
				$val = $this->unique_slug(sanitize_title($t), $post, $lang);
			} else {
				$val = trim(sanitize_text_field($t));
			}
			$r = $put($old[$k], $val, $names[$k], '');
			if ($r === true) {
				$data[$k] = $val;
			}
			if ($r) {
				$touched_fields[$k] = true;
			}
		}
		$pos = array_flip(array_map('strval', array_keys($segs)));
		foreach ($seg as $h => $t) {
			$h = (string) $h;
			if (!isset($segs[$h]) || !is_string($t)) {
				continue;
			}
			$t = trim($t);
			$val = $raw ? $t : wp_kses_post($t);
			$cur = isset($old['seg'][$h]) ? $old['seg'][$h] : '';
			$r = $put($cur, $val, $this->kind_label(isset($kinds[$h]) ? $kinds[$h] : '', $pos[$h] + 1), $segs[$h]);
			if ($r === true) {
				$data['seg'][$h] = $val;
			}
			if ($r) {
				$touched_hash[] = $h;
			}
		}

		if (!$res['new'] && !$res['chg'] && !$res['same']) {
			return $res;
		}
		if ($res['new'] || $res['chg'] || !$existed) {
			// Vergleichswerte: neue Übersetzung = aktueller Stand, bestehende nur für die übernommenen Felder fortschreiben
			if (!$existed) {
				$data['sig'] = $this->sig($post);
				$data['hs'] = array_map('strval', array_keys($segs));
				$data['th'] = md5($post->post_title);
				$data['eh'] = md5($post->post_excerpt);
				$data['seo_h'] = $seo_on ? ['t' => md5($ss['title']), 'd' => md5($ss['desc'])] : [];
			} elseif ($known) {
				$data['hs'] = array_values(array_unique(array_merge(array_map('strval', $old['hs']), $touched_hash)));
				if (isset($touched_fields['title'])) {
					$data['th'] = md5($post->post_title);
				}
				if (isset($touched_fields['excerpt'])) {
					$data['eh'] = md5($post->post_excerpt);
				}
				$sh = is_array($old['seo_h']) ? $old['seo_h'] : [];
				if (isset($touched_fields['seo_title'])) {
					$sh['t'] = md5($ss['title']);
				}
				if (isset($touched_fields['seo_desc'])) {
					$sh['d'] = md5($ss['desc']);
				}
				$data['seo_h'] = $sh;
			}
			$data['time'] = time();
			$data['imp'] = time();
			update_post_meta($id, self::TR . $lang, wp_slash($data));
			if (isset($touched_fields['slug']) && $data['slug'] !== '') {
				update_post_meta($id, self::SLUG . $lang, wp_slash($data['slug']));
			}
			$this->memo = [];
			$c->flush();
			clean_post_cache($id);
			do_action('mml_translation_saved', $id, $lang);

			if ($c->log) {
				$parts = [];
				if ($res['new']) {
					$parts[] = $res['new'] . ($res['new'] === 1 ? __(' text newly translated', 'alba') : __(' texts newly translated', 'alba'));
				}
				if ($res['chg']) {
					$parts[] = $res['chg'] . ($res['chg'] === 1 ? __(' text changed', 'alba') : __(' texts changed', 'alba'));
				}
				$c->log->add($id, $lang, 'import', $label . ($file !== '' ? ' (' . $file . ')' : '') . ': ' . implode(' · ', $parts), ['changes' => $changes]);
			}
		}
		return $res;
	}

	/** Zwischenspeicher nach einer Änderung von außen (Sammelaktion) leeren */
	public function reset($id) {
		$this->memo = [];
		$this->core->flush();
		clean_post_cache((int) $id);
	}

	/** Beschriftung einer Zeile im Editor */
	public function kind_label($kind, $n) {
		$map = ['attr:alt' => __('Image alt text', 'alba'), 'attr:title' => __('Title attribute', 'alba'), 'attr:aria-label' => __('ARIA label', 'alba'), 'attr:placeholder' => __('Placeholder', 'alba')];
		if ($kind === '' || $kind === null) {
			return __('Section ', 'alba') . $n;
		}
		if (isset($map[$kind])) {
			return $map[$kind] . ' (' . $n . ')';
		}
		$p = strpos($kind, ':');
		$name = $p === false ? $kind : substr($kind, $p + 1);
		$type = $p === false ? '' : substr($kind, 0, $p);
		$label = ['sc' => __('Element text', 'alba'), 'json' => __('Block field', 'alba'), 'meta' => __('Custom field', 'alba'), 'attr' => __('Attribute', 'alba')];
		return sprintf(/* translators: 1: content type, 2: title, 3: language */ __('%1$s "%2$s" (%3$s)', 'alba'), isset($label[$type]) ? $label[$type] : __('Text', 'alba'), $name, $n);
	}

	/** Ruft $fn für jeden Textabschnitt auf und setzt den Rückgabewert ein */
	public function transform($html, $fn) {
		$out = '';
		$run = '';
		foreach ($this->tokens($html) as $tok) {
			if ($this->is_inline($tok)) {
				$run .= $tok;
				continue;
			}
			$out .= $this->flush_run($run, $fn) . $this->tok_attrs($tok, $fn);
			$run = '';
		}
		return $out . $this->flush_run($run, $fn);
	}

	/** Alle Text-Hashes in Reihenfolge, auch mehrfach vorkommende */
	public function all_hashes($html) {
		$list = [];
		$this->transform($html, function ($core) use (&$list) {
			$list[] = md5($core);
			return $core;
		});
		return $list;
	}

	/** hash => Originaltext (jeder Text nur einmal) */
	public function segments($html, &$kinds = null) {
		$list = [];
		$kinds = [];
		$this->transform($html, function ($core, $attr = false, $q = '"', $kind = '') use (&$list, &$kinds) {
			$h = md5($core);
			if (!isset($list[$h])) {
				$list[$h] = $core;
				$kinds[$h] = $kind;
			}
			return $core;
		});
		return $list;
	}

	public function apply($html, array $map) {
		return $this->transform($html, function ($core, $attr = false, $q = '"', $kind = '') use ($map) {
			$h = md5($core);
			if (!isset($map[$h]) || $map[$h] === '') {
				return $core;
			}
			if ($attr === true) {
				// in Shortcode-Attributen: ohne Tags und ohne das Anführungszeichen
				return str_replace($q, $q === '"' ? '”' : '’', wp_strip_all_tags($map[$h]));
			}
			return $map[$h];
		});
	}

	/* ---------------------------------------------------------------
	 * Frontend: Ersetzen
	 * ------------------------------------------------------------- */

	public function f_title($title, $id = 0) {
		$d = $this->active($id);
		return ($d && $d['title'] !== '') ? $d['title'] : $title;
	}

	public function f_single_title($title, $post = null) {
		if (!is_object($post) || empty($post->ID)) {
			return $title;
		}
		$d = $this->active($post->ID);
		return ($d && $d['title'] !== '') ? $d['title'] : $title;
	}

	public function f_content($content) {
		$id = (int) get_the_ID();
		if (!$id || !is_string($content) || $content === '') {
			return $content;
		}
		$d = $this->active($id);
		if (!$d || !$d['seg']) {
			return $content;
		}
		return $this->apply($content, $d['seg']);
	}

	public function f_excerpt($excerpt, $post = null) {
		if (!is_object($post) || empty($post->ID)) {
			return $excerpt;
		}
		$d = $this->active($post->ID);
		return ($d && $d['excerpt'] !== '') ? $d['excerpt'] : $excerpt;
	}

	/* ---------------------------------------------------------------
	 * Backend: Editor-Seite
	 * ------------------------------------------------------------- */

	public function menu() {
		add_submenu_page('options.php', __('Translate', 'alba'), __('Translate', 'alba'), 'read', self::PAGE, [$this, 'page']);
	}

	public function assets() {
		if (!isset($_GET['page']) || $_GET['page'] !== self::PAGE) {
			return;
		}
		wp_enqueue_script('mml-translate', MML_URL . 'assets/translate.js', ['mml-i18n'], MML_VERSION, true);
		if (MML_MT::enabled()) {
			$pid = isset($_GET['post']) ? (int) $_GET['post'] : 0;
			wp_localize_script('mml-translate', 'mmlMT', [
				'url'      => admin_url('admin-ajax.php'),
				'nonce'    => wp_create_nonce('mml_mt'),
				'from'     => $pid ? $this->core->post_lang($pid) : $this->core->default,
				'to'       => isset($_GET['lang']) ? sanitize_key(wp_unslash($_GET['lang'])) : '',
				'provider' => MML_MT::provider_name(),
			]);
		}
	}

	/** Glossar-Treffer, Kopiertext mit angewandtem Glossar und Vorschlag aus dem Übersetzungsspeicher */
	private function gl_extra($text, $hash, $lang, $tm) {
		$g = $this->core->glossary;
		if (!$g) {
			return [];
		}
		return [
			'copy'  => $g->apply($text, $lang),
			'terms' => $g->terms_in($text, $lang),
			'tm'    => ($tm && $hash !== '') ? $g->suggest($hash, $lang) : '',
		];
	}

	private function row($label, $field, $hash, $src_html, $src_raw, $val, $rows, $changed = false, $x = []) {
		$copy = isset($x['copy']) ? $x['copy'] : $src_raw;
		$terms = isset($x['terms']) ? $x['terms'] : [];
		$tm = isset($x['tm']) ? $x['tm'] : '';
		$is_slug = !empty($x['slug']);
		$mt = !$is_slug && MML_MT::enabled();
		?>
		<div class="mml-row<?php echo $changed ? ' mml-changed' : ''; ?>" data-field="<?php echo esc_attr($field); ?>"<?php echo $hash !== '' ? ' data-hash="' . esc_attr($hash) . '"' : ''; ?>>
			<div class="mml-label"><?php echo esc_html($label); ?><?php echo !empty($x['max']) ? ' <span class="mml-cnt" data-max="' . (int) $x['max'] . '"></span>' : ''; ?><?php echo $changed ? ' <span class="mml-badge">' . esc_html__('Original changed', 'alba') . '</span>' : ''; ?></div>
			<div class="mml-src"><?php echo $src_html; // bereits bereinigt ?></div>
			<div class="mml-tgt">
				<textarea rows="<?php echo (int) $rows; ?>" data-src="<?php echo esc_attr($copy); ?>" data-raw="<?php echo esc_attr($src_raw); ?>"><?php echo esc_textarea($val); ?></textarea>
				<?php if ($is_slug) : ?>
					<button type="button" class="button-link mml-slug-gen"><?php echo esc_html__('Generate from translated title', 'alba'); ?></button>
				<?php else : ?>
					<button type="button" class="button-link mml-copy"><?php echo esc_html__('Copy original', 'alba'); ?></button>
				<?php endif; ?>
				<?php if ($mt) : ?>
					<button type="button" class="button-link mml-mt-get"><?php echo esc_html__('Suggest automatically', 'alba'); ?></button>
				<?php endif; ?>
				<?php if ($tm !== '') : ?>
					<div class="mml-tm" data-tm="<?php echo esc_attr($tm); ?>"><span class="mml-tm-l"><?php echo esc_html__('From translation memory:', 'alba'); ?></span> <em><?php echo esc_html(wp_html_excerpt(wp_strip_all_tags($tm), 120, '…')); ?></em> <button type="button" class="button-link mml-tm-use"><?php echo esc_html__('Apply', 'alba'); ?></button></div>
				<?php endif; ?>
				<?php if ($terms) : ?>
					<div class="mml-terms"><span class="mml-tm-l"><?php echo esc_html__('Glossary:', 'alba'); ?></span>
						<?php foreach ($terms as $t) : ?>
							<span class="mml-term" data-target="<?php echo esc_attr($t['target']); ?>" data-cs="<?php echo $t['cs'] ? 1 : 0; ?>"><?php echo esc_html($t['keep'] ? $t['term'] . __(' – unchanged', 'alba') : $t['term'] . ' → ' . $t['target']); ?></span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function page() {
		$c = $this->core;
		$id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
		$lang = isset($_GET['lang']) ? sanitize_key(wp_unslash($_GET['lang'])) : '';
		$post = $id ? get_post($id) : null;
		if (!$post || !isset($c->langs[$lang]) || !$c->is_translatable($post->post_type) || !$c->roles->can_translate($id, $lang)) {
			wp_die(__('Not allowed.', 'alba'));
		}
		$from = $c->post_lang($id);
		if ($from === $lang) {
			wp_die(__('Source and target language are the same.', 'alba'));
		}

		echo '<div class="wrap mml-tr"><h1>' . esc_html__('Translate:', 'alba') . ' ' . esc_html($post->post_title !== '' ? $post->post_title : __('(no title)', 'alba')) . '</h1>';

		$real = $c->translation($id, $lang, false);
		if ($real) {
			echo '<div class="notice notice-info inline"><p>' . esc_html(sprintf(/* translators: %s: language name */ __('There is already a separate post for %s.', 'alba'), $c->langs[$lang]['name'])) . (current_user_can('edit_post', $real) ? ' <a href="' . esc_url(get_edit_post_link($real)) . '">' . esc_html__('Edit post', 'alba') . '</a>' : '') . '</p></div></div>';
			return;
		}

		if (!empty($_GET['saved'])) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Translation saved.', 'alba') . '</p></div>';
		}

		$d = $this->data($id, $lang);
		if ($d['imp']) {
			echo '<div class="notice notice-info inline"><p><strong>' . esc_html(sprintf(/* translators: %s: date and time */ __('By import (XLIFF or machine pre-translation) on %s.', 'alba'), wp_date('d.m.Y H:i', $d['imp']))) . '</strong> ' . esc_html__('Please check the texts and save to confirm. The import did not change publication on the frontend.', 'alba') . '</p></div>';
		}
		$kinds = [];
		$segs = $this->post_segments($post, $kinds);
		$tm_n = 0;
		if ($c->glossary) {
			foreach ($segs as $h => $text) {
				if ((!isset($d['seg'][$h]) || (string) $d['seg'][$h] === '') && $c->glossary->suggest((string) $h, $lang) !== '') {
					$tm_n++;
				}
			}
		}
		$on = $this->is_on($id, $lang);
		$known = ($d['sig'] !== '' && is_array($d['hs']));
		$is_old = $this->outdated($id, $lang);
		$hs = $known ? array_map('strval', $d['hs']) : [];
		$seg_new = 0;
		foreach ($segs as $h => $text) {
			$empty = !isset($d['seg'][$h]) || (string) $d['seg'][$h] === '';
			if ($known ? !in_array((string) $h, $hs, true) : ($is_old && $empty)) {
				$seg_new++;
			}
		}
		$t_chg = $known && $d['th'] !== md5($post->post_title);
		$e_chg = $known && trim($post->post_excerpt) !== '' && $d['eh'] !== md5($post->post_excerpt);
		?>
		<style>
			.mml-tr .mml-bar{position:sticky;top:32px;z-index:10;background:#fff;border:1px solid #c3c4c7;padding:10px 14px;margin:12px 0;display:flex;gap:16px;align-items:center;flex-wrap:wrap}
			.mml-tr .mml-bar .mml-grow{flex:1}
			.mml-tr .mml-heads,.mml-tr .mml-row{display:grid;grid-template-columns:1fr 1fr;gap:0 16px;max-width:1400px}
			.mml-tr .mml-heads{font-weight:600;margin-bottom:6px}
			.mml-tr .mml-row{background:#fff;border:1px solid #c3c4c7;border-left:4px solid #dba617;padding:10px 12px;margin-bottom:8px}
			.mml-tr .mml-row.mml-done{border-left-color:#68b17a}
			.mml-tr .mml-row.mml-changed{border-left-color:#d63638}
			.mml-tr .mml-badge{background:#d63638;color:#fff;border-radius:3px;padding:1px 6px;margin-left:6px;text-transform:none;letter-spacing:0}
			.mml-tr .mml-label{grid-column:1/-1;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#787c82;margin-bottom:4px}
			.mml-tr .mml-src{background:#f6f7f7;padding:8px 10px;overflow-wrap:anywhere}
			.mml-tr .mml-tgt textarea{width:100%;min-height:40px;resize:vertical;font-family:inherit;font-size:14px}
			.mml-tr .mml-copy{font-size:12px}
			.mml-tr .mml-tm,.mml-tr .mml-terms{font-size:12px;color:#50575e;margin-top:6px;display:flex;flex-wrap:wrap;gap:4px 6px;align-items:center}
			.mml-tr .mml-tm.mml-hide{display:none}
			.mml-tr .mml-mt{margin-top:6px;padding:6px 10px;border-left:3px solid #72aee6;background:#f0f6fc;font-size:12px}
			.mml-tr .mml-mt .mml-mt-t{display:block;margin:2px 0 4px;color:#1d2327;font-size:13px;white-space:pre-wrap;overflow-wrap:anywhere}
			.mml-tr .mml-mt.mml-err{border-left-color:#d63638;background:#fcf0f1}
			.mml-tr .mml-mt-get{font-size:12px;margin-left:10px}
			.mml-tr .mml-tm-l{color:#787c82}
			.mml-tr .mml-term{background:#f0f6fc;border:1px solid #72aee6;color:#0a4b78;border-radius:999px;padding:0 9px;line-height:20px}
			.mml-tr .mml-term.mml-ok{background:#edfaef;border-color:#68b17a;color:#1e4620}
			.mml-tr .mml-term.mml-miss{background:#fcf0f1;border-color:#d63638;color:#8a2424}
			@media(max-width:900px){.mml-tr .mml-heads,.mml-tr .mml-row{grid-template-columns:1fr}.mml-tr .mml-bar{top:46px}}
		</style>
		<p>
			<?php echo esc_html($c->langs[$from]['name'] . ' → ' . $c->langs[$lang]['name']); ?> ·
			<?php if (current_user_can('edit_post', $id)) : ?><a href="<?php echo esc_url(get_edit_post_link($id)); ?>"><?php echo esc_html__('Edit original', 'alba'); ?></a><?php endif; ?>
			<?php if ($c->xliff && current_user_can('edit_others_posts')) : ?>· <a href="<?php echo esc_url($c->xliff->export_url($id, $lang)); ?>"><?php echo esc_html__('Export as XLIFF', 'alba'); ?></a><?php endif; ?>
			<?php if ($c->log && current_user_can('edit_others_posts') && ($last = $c->log->last($id, $lang))) : $lu = get_userdata((int) $last->user_id); ?>
				<?php echo esc_html__('· Last saved by', 'alba'); ?> <strong><?php echo esc_html($lu ? $lu->display_name : 'unbekannt'); ?></strong> am <?php echo esc_html(wp_date('d.m.Y H:i', strtotime($last->time . ' UTC'))); ?> (<a href="<?php echo esc_url(admin_url('admin.php?page=' . MML_Log::PAGE . '&post=' . (int) $id . '&lang=' . rawurlencode($lang))); ?>"><?php echo esc_html__('Log', 'alba'); ?></a>)
			<?php endif; ?>
			<?php if ($on && $post->post_status === 'publish') : ?>
				· <a href="<?php echo esc_url($c->with_lang($lang, function () use ($id) { return get_permalink($id); })); ?>" target="_blank" rel="noopener"><?php echo esc_html__('View translation', 'alba'); ?></a>
			<?php endif; ?>
		</p>
		<?php if ($is_old) : ?>
			<div class="notice notice-warning inline"><p><strong><?php echo esc_html__('The original has been changed since the last translation.', 'alba'); ?></strong> <?php echo $known ? '' : __('This translation was saved with an older version; all sections that are still empty are marked. ', 'alba'); ?><?php echo ($known && !$seg_new && !$t_chg && !$e_chg) ? __('Texts that are already translated have been added, removed or repeated (e.g. another paragraph with identical wording). Please check the page and save to confirm. ', 'alba') : ''; ?><?php echo esc_html__('Affected fields are marked red', 'alba'); ?><?php echo $seg_new ? ' (' . (int) $seg_new . __(' new or changed sections)', 'alba') : ''; ?><?php echo esc_html__('. After saving, the translation counts as up to date again.', 'alba'); ?></p></div>
		<?php endif; ?>
		<p class="description"><?php echo esc_html__('Empty fields appear in the original on the frontend. If you edit the original text, only the changed section is open again. Images, layout and links of the block structure remain unchanged.', 'alba'); ?></p>

		<?php if ($c->workflow) { $c->workflow->box($post, $lang); } ?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="mml-tr-form">
			<input type="hidden" name="action" value="mml_save_tr">
			<input type="hidden" name="post" value="<?php echo (int) $id; ?>">
			<input type="hidden" name="lang" value="<?php echo esc_attr($lang); ?>">
			<input type="hidden" name="mml_data" id="mml-data" value="">
			<?php wp_nonce_field('mml_tr_' . $id . '_' . $lang); ?>

			<div class="mml-bar">
				<strong id="mml-count">&nbsp;</strong>
				<span class="mml-grow"></span>
				<?php if (MML_MT::enabled()) : ?><button type="button" class="button" id="mml-mt-all"><?php echo esc_html__('Fetch suggestions for empty fields', 'alba'); ?></button><?php endif; ?>
				<?php if ($tm_n) : ?><button type="button" class="button" id="mml-tm-all"><?php echo (int) $tm_n; ?> <?php echo esc_html__('Insert suggestions from translation memory', 'alba'); ?></button><?php endif; ?>
				<button type="button" class="button" id="mml-fill"><?php echo esc_html__('Fill empty fields with the original', 'alba'); ?></button>
				<?php $can_pub = $c->roles->can_publish($id); ?>
				<label><input type="checkbox" id="mml-on" <?php checked($on); ?> <?php disabled(!$can_pub); ?>> <?php echo esc_html__('Show on the frontend', 'alba'); ?></label><?php if (!$can_pub) : ?> <span class="description"><?php echo esc_html__('An editor grants the approval.', 'alba'); ?></span><?php endif; ?>
				<?php if (!$can_pub) : ?><button type="submit" name="mml_review" value="1" class="button"><?php echo esc_html__('Save & submit for review', 'alba'); ?></button><?php endif; ?>
				<button type="submit" class="button button-primary"><?php echo esc_html__('Save', 'alba'); ?></button>
			</div>

			<div class="mml-heads"><div><?php echo esc_html($c->langs[$from]['name']); ?> <?php echo esc_html__('(original)', 'alba'); ?></div><div><?php echo esc_html($c->langs[$lang]['name']); ?></div></div>
			<?php
			$this->row(__('Title', 'alba'), 'title', '', esc_html($post->post_title), $post->post_title, $d['title'], 1, $t_chg, $this->gl_extra($post->post_title, '', $lang, false));
			if ($post->post_name !== '' && !in_array($post->post_status, ['auto-draft'], true)) {
				$this->row(__('URL slug (address)', 'alba'), 'slug', '', esc_html('/' . $post->post_name . '/'), $post->post_name, $d['slug'], 1, false, ['slug' => true]);
			}
			if ($seo = $this->seo_plugin()) {
				$ss = $this->seo_source($id);
				$sh = $d['seo_h'];
				$auto = '<em>' . esc_html__('(automatic, from page title and site name)', 'alba') . '</em>';
				$this->row(__('SEO title (', 'alba') . $seo . ')', 'seo_title', '', $ss['title'] !== '' ? esc_html($ss['title']) : $auto, $ss['title'] !== '' ? $ss['title'] : $post->post_title, $d['seo_title'], 1, !empty($sh['t']) && $sh['t'] !== md5($ss['title']), $this->gl_extra($ss['title'] !== '' ? $ss['title'] : $post->post_title, '', $lang, false) + ['max' => 60]);
				$this->row(__('SEO description (', 'alba') . $seo . ')', 'seo_desc', '', $ss['desc'] !== '' ? esc_html($ss['desc']) : '<em>' . esc_html__('(not set)', 'alba') . '</em>', $ss['desc'], $d['seo_desc'], 3, !empty($sh['d']) && $sh['d'] !== md5($ss['desc']), $this->gl_extra($ss['desc'], '', $lang, false) + ['max' => 160]);
			}
			if (trim($post->post_excerpt) !== '') {
				$this->row(__('Excerpt', 'alba'), 'excerpt', '', esc_html($post->post_excerpt), $post->post_excerpt, $d['excerpt'], 2, $e_chg, $this->gl_extra($post->post_excerpt, '', $lang, false));
			}
			$n = 0;
			foreach ($segs as $h => $text) {
				$n++;
				$val = isset($d['seg'][$h]) ? (string) $d['seg'][$h] : '';
				$chg = $known ? !in_array((string) $h, $hs, true) : ($is_old && $val === '');
				$this->row($this->kind_label(isset($kinds[$h]) ? $kinds[$h] : '', $n), 'seg', (string) $h, wp_kses_post($text), $text, $val, 2, $chg, $this->gl_extra($text, (string) $h, $lang, true));
			}
			if (!$segs) {
				echo '<p>' . esc_html__('The content contains no translatable text.', 'alba') . '</p>';
			}
			?>
			<p><button type="submit" class="button button-primary"><?php echo esc_html__('Save', 'alba'); ?></button></p>
		</form>
		</div>
		<?php
	}

	public function save() {
		$c = $this->core;
		$id = isset($_POST['post']) ? (int) $_POST['post'] : 0;
		$lang = isset($_POST['lang']) ? sanitize_key(wp_unslash($_POST['lang'])) : '';
		check_admin_referer('mml_tr_' . $id . '_' . $lang);

		$post = $id ? get_post($id) : null;
		if (!$post || !isset($c->langs[$lang]) || !$c->is_translatable($post->post_type) || !$c->roles->can_translate($id, $lang)) {
			wp_die(__('Not allowed.', 'alba'));
		}
		if ($c->post_lang($id) === $lang || $c->translation($id, $lang, false)) {
			wp_die(__('There is already a separate post for this language.', 'alba'));
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload; every field is sanitised when it is saved.
		$in = json_decode(wp_unslash(isset($_POST['mml_data']) ? (string) $_POST['mml_data'] : ''), true);
		if (!is_array($in)) {
			wp_die(__('Invalid data.', 'alba'));
		}

		$kinds = [];
		$segs = $this->post_segments($post, $kinds);
		$raw = current_user_can('unfiltered_html');
		$old = $this->data($id, $lang);
		$was_on = $this->is_on($id, $lang);
		$existed = metadata_exists('post', $id, self::TR . $lang);
		$map = [];
		if (!empty($in['seg']) && is_array($in['seg'])) {
			foreach ($in['seg'] as $h => $t) {
				$h = (string) $h;
				if (!is_string($t) || !isset($segs[$h])) {
					continue;
				}
				$t = trim($t);
				if ($t === '') {
					continue;
				}
				$map[$h] = $raw ? $t : wp_kses_post($t);
			}
		}

		$slug = $this->unique_slug(isset($in['slug']) && is_string($in['slug']) ? sanitize_title($in['slug']) : '', $post, $lang);

		$ss = $this->seo_plugin() ? $this->seo_source($id) : ['title' => '', 'desc' => ''];
		$old_seo = $this->data($id, $lang);
		$seo_on = $this->seo_plugin() !== '';

		$data = [
			'seo_title' => $seo_on ? (isset($in['seo_title']) && is_string($in['seo_title']) ? sanitize_text_field($in['seo_title']) : '') : $old_seo['seo_title'],
			'seo_desc'  => $seo_on ? (isset($in['seo_desc']) && is_string($in['seo_desc']) ? sanitize_textarea_field($in['seo_desc']) : '') : $old_seo['seo_desc'],
			'seo_h'   => $seo_on ? ['t' => md5($ss['title']), 'd' => md5($ss['desc'])] : $old_seo['seo_h'],
			'slug'    => $slug,
			'title'   => isset($in['title']) && is_string($in['title']) ? sanitize_text_field($in['title']) : '',
			'excerpt' => isset($in['excerpt']) && is_string($in['excerpt']) ? sanitize_textarea_field($in['excerpt']) : '',
			'seg'     => $map,
			'time'    => time(),
			'sig'     => $this->sig($post),
			'hs'      => array_map('strval', array_keys($segs)),
			'th'      => md5($post->post_title),
			'eh'      => md5($post->post_excerpt),
		];
		update_post_meta($id, self::TR . $lang, wp_slash($data));
		if ($slug !== '') {
			update_post_meta($id, self::SLUG . $lang, wp_slash($slug));
		} else {
			delete_post_meta($id, self::SLUG . $lang);
		}
		$on_in = $c->roles->can_publish($id) ? !empty($in['on']) : $was_on;
		if ($on_in) {
			update_post_meta($id, self::ON . $lang, '1');
		} else {
			delete_post_meta($id, self::ON . $lang);
		}

		$this->memo = [];
		$c->flush();
		clean_post_cache($id);
		do_action('mml_translation_saved', $id, $lang);

		if ($c->workflow) {
			$c->workflow->after_save($id, $lang, !empty($_POST['mml_review']) && !$c->roles->can_publish($id), $on_in);
		}

		if ($c->log) {
			$this->log_save($post, $lang, $old, $data, $segs, $kinds, $was_on, $on_in, $existed);
		}

		wp_safe_redirect(add_query_arg('saved', '1', $this->url($id, $lang)));
		exit;
	}
}
