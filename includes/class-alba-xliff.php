<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * XLIFF export and import for external translators.
 *
 * Export: one .xlf file per target language (XLIFF 1.2 or 2.0) with title, slug, SEO fields, excerpt and all
 * text sections of the content that is translated with the side-by-side editor. Inline tags (bold, links, line breaks)
 * are protected placeholders in the text and come back from the original on import, never from the file.
 * Import: first a preview (new / changed / skipped), then adoption. Approval on the frontend stays unchanged.
 */
class ALBA_Xliff {
	const PAGE = 'alba-xliff';
	const MAX_BYTES = 5242880;
	const LIMIT = 2000;

	private $core;

	public function __construct(ALBA_Core $core) {
		$this->core = $core;
		$core->xliff = $this;
		add_action('admin_menu', [$this, 'menu']);
		add_action('admin_post_alba_xliff_export', [$this, 'export']);
		add_action('admin_post_alba_xliff_upload', [$this, 'upload']);
		add_action('admin_post_alba_xliff_apply', [$this, 'apply']);
	}

	public function menu() {
		add_submenu_page(ALBA_Dashboard::SLUG, __('Languages – XLIFF', 'alba'), 'XLIFF', 'edit_others_posts', self::PAGE, [$this, 'page']);
	}

	/** Download address for a single content item (link in the editor) */
	public function export_url($post_id, $lang) {
		return wp_nonce_url(add_query_arg(['action' => 'alba_xliff_export', 'post' => (int) $post_id, 'lang' => $lang, 'fmt' => '1.2', 'tr' => 1], admin_url('admin-post.php')), 'alba_xliff_export');
	}

	/* ---------------------------------------------------------------
	 * Hilfsfunktionen
	 * ------------------------------------------------------------- */

	/** Language identifier for the file, e.g. de-DE */
	private function tag($code) {
		$l = isset($this->core->langs[$code]) ? $this->core->langs[$code] : [];
		return !empty($l['locale']) ? str_replace('_', '-', (string) $l['locale']) : (string) $code;
	}

	private function esc($s) {
		$s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $s);
		return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/** Determine the language from the identifier in the file (de-DE, en_US, en); '' if none matches */
	private function detect($tag) {
		$t = strtolower(str_replace('_', '-', trim((string) $tag)));
		if ($t === '') {
			return '';
		}
		$c = $this->core;
		foreach ($c->langs as $code => $l) {
			if ($code === $c->default) {
				continue;
			}
			$loc = strtolower(str_replace('_', '-', isset($l['locale']) ? (string) $l['locale'] : ''));
			if ($code === $t || $loc === $t) {
				return $code;
			}
		}
		$p = explode('-', $t);
		$p = $p[0];
		foreach ($c->langs as $code => $l) {
			if ($code === $c->default) {
				continue;
			}
			$loc = strtolower(str_replace('_', '-', isset($l['locale']) ? (string) $l['locale'] : ''));
			$lp = explode('-', $loc);
			if ($code === $p || $lp[0] === $p) {
				return $code;
			}
		}
		return '';
	}

	/** Splits HTML into text and tags; tags get numbers and are joined into pairs (opening/closing) */
	private function pieces($html) {
		$parts = preg_split('/(<[^>]*>)/', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
		$void = ['br', 'hr', 'img', 'input', 'wbr', 'source', 'col', 'embed', 'area', 'base', 'link', 'meta', 'param', 'track'];
		$out = [];
		$stack = [];
		$n = 0;
		foreach ($parts as $p) {
			if ($p[0] !== '<' || substr($p, -1) !== '>') {
				$out[] = ['k' => 'text', 't' => $p];
				continue;
			}
			$n++;
			if (preg_match('/^<\s*\/\s*([a-z0-9:_-]+)/i', $p, $m)) {
				$name = strtolower($m[1]);
				$found = null;
				for ($i = count($stack) - 1; $i >= 0; $i--) {
					if ($stack[$i]['name'] === $name) {
						$found = $i;
						break;
					}
				}
				if ($found === null) {
					$out[] = ['k' => 'ph', 't' => $p, 'id' => $n];
				} else {
					$open = $stack[$found];
					array_splice($stack, $found);
					$out[$open['idx']]['paired'] = true;
					$out[] = ['k' => 'close', 't' => $p, 'id' => $n, 'pair' => $open['id']];
				}
			} elseif (preg_match('/^<\s*([a-z0-9:_-]+)/i', $p, $m)) {
				$name = strtolower($m[1]);
				if (substr($p, -2) === '/>' || in_array($name, $void, true)) {
					$out[] = ['k' => 'ph', 't' => $p, 'id' => $n];
				} else {
					$out[] = ['k' => 'open', 't' => $p, 'id' => $n, 'name' => $name];
					$stack[] = ['name' => $name, 'id' => $n, 'idx' => count($out) - 1];
				}
			} else {
				$out[] = ['k' => 'ph', 't' => $p, 'id' => $n];   // Comment or similar
			}
		}
		foreach ($out as $i => $o) {
			if ($o['k'] === 'open' && empty($o['paired'])) {
				$out[$i]['k'] = 'ph';
			}
		}
		return $out;
	}

	/**
	 * Maps the tags of an existing translation to the tag numbers of the original (same tag, same order),
	 * so that on import every number leads back to the right original tag. null if that is not unambiguous.
	 */
	private function align(array $tp, array $sp) {
		$used = [];
		$newid = [];
		foreach ($tp as $i => $o) {
			if ($o['k'] === 'text') {
				continue;
			}
			$hit = null;
			foreach ($sp as $j => $q) {
				if (isset($used[$j]) || $q['k'] !== $o['k'] || $q['t'] !== $o['t']) {
					continue;
				}
				$hit = $j;
				break;
			}
			if ($hit === null) {
				return null;
			}
			$used[$hit] = true;
			$q = $sp[$hit];
			$tp[$i]['id'] = $q['id'];
			if ($o['k'] === 'open') {
				$newid[$o['id']] = $q['id'];
			} elseif ($o['k'] === 'close') {
				if (!isset($newid[$o['pair']]) || $newid[$o['pair']] !== $q['pair']) {
					return null;
				}
				$tp[$i]['pair'] = $q['pair'];
			}
		}
		return $tp;
	}

	/** Text of a section as XLIFF content (with inline elements). $ref = tags of the original (for existing translations) */
	private function inline($s, $html, $fmt, $ref = null) {
		if (!$html) {
			return $this->esc(html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		}
		$pieces = $this->pieces($s);
		if ($ref !== null) {
			$pieces = $this->align($pieces, $ref);
			if ($pieces === null) {
				return null;
			}
		}
		$close_of = [];
		foreach ($pieces as $o) {
			if ($o['k'] === 'close') {
				$close_of[$o['pair']] = $o['t'];
			}
		}
		$x = '';
		foreach ($pieces as $o) {
			switch ($o['k']) {
				case 'text':
					$x .= $this->esc(html_entity_decode($o['t'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
					break;
				case 'open':
					if ($fmt === '2.0') {
						$x .= '<pc id="' . (int) $o['id'] . '" dispStart="' . $this->esc($o['t']) . '" dispEnd="' . $this->esc(isset($close_of[$o['id']]) ? $close_of[$o['id']] : '') . '">';
					} else {
						$x .= '<bpt id="' . (int) $o['id'] . '" rid="' . (int) $o['id'] . '" ctype="x-html-' . $this->esc($o['name']) . '">' . $this->esc($o['t']) . '</bpt>';
					}
					break;
				case 'close':
					if ($fmt === '2.0') {
						$x .= '</pc>';
					} else {
						$x .= '<ept id="' . (int) $o['id'] . '" rid="' . (int) $o['pair'] . '">' . $this->esc($o['t']) . '</ept>';
					}
					break;
				default:
					if ($fmt === '2.0') {
						$x .= '<ph id="' . (int) $o['id'] . '" disp="' . $this->esc($o['t']) . '"/>';
					} else {
						$x .= '<ph id="' . (int) $o['id'] . '">' . $this->esc($o['t']) . '</ph>';
					}
			}
		}
		return $x;
	}

	/** Reads the content of a <target> back into HTML; tags come from the original ($pieces) */
	private function decode(DOMNode $node, array $pieces) {
		$ids = [];
		$pairs = [];
		foreach ($pieces as $o) {
			if ($o['k'] === 'text') {
				continue;
			}
			$ids[$o['id']] = $o['t'];
			if ($o['k'] === 'close') {
				$pairs[$o['pair']]['close'] = $o['t'];
			} elseif ($o['k'] === 'open') {
				$pairs[$o['id']]['open'] = $o['t'];
			}
		}
		return $this->walk($node, $ids, $pairs);
	}

	private function walk(DOMNode $node, array $ids, array $pairs) {
		$s = '';
		foreach ($node->childNodes as $ch) {
			if ($ch->nodeType === XML_TEXT_NODE || $ch->nodeType === XML_CDATA_SECTION_NODE) {
				$s .= htmlspecialchars((string) $ch->nodeValue, ENT_NOQUOTES, 'UTF-8', false);
			} elseif ($ch->nodeType === XML_ELEMENT_NODE) {
				$name = $ch->localName;
				$id = (int) $ch->getAttribute('id');
				if (in_array($name, ['bpt', 'ept', 'ph', 'it', 'x', 'sc', 'ec'], true)) {
					$s .= isset($ids[$id]) ? $ids[$id] : '';
				} elseif ($name === 'g' || $name === 'pc') {
					$s .= (isset($pairs[$id]['open']) ? $pairs[$id]['open'] : '') . $this->walk($ch, $ids, $pairs) . (isset($pairs[$id]['close']) ? $pairs[$id]['close'] : '');
				} elseif ($name === 'sub') {
					continue;
				} else {
					$s .= $this->walk($ch, $ids, $pairs);   // mrk or similar: content only
				}
			}
		}
		return $s;
	}

	private function tags($s) {
		preg_match_all('/<[^>]*>/', (string) $s, $m);
		$t = $m[0];
		sort($t);
		return implode("\n", $t);
	}

	/* ---------------------------------------------------------------
	 * Export
	 * ------------------------------------------------------------- */

	/** Units of a post: id, label, original, existing translation, HTML?, notes */
	private function units($post, $lang, $with_tr) {
		$c = $this->core;
		$e = $c->editor;
		$d = $e->data($post->ID, $lang);
		$u = [];
		$add = function ($key, $md5, $label, $src, $tgt, $html, $note = '') use (&$u, $post, $with_tr, $lang, $c) {
			if (trim((string) $src) === '') {
				return;
			}
			$notes = [];
			if ($note !== '') {
				$notes[] = $note;
			}
			if ($c->glossary) {
				foreach ($c->glossary->terms_in($src, $lang) as $t) {
					$notes[] = !empty($t['keep']) ? __('Glossary: "', 'alba') . $t['term'] . __('" do not translate', 'alba') : __('Glossary: "', 'alba') . $t['term'] . '“ → „' . $t['target'] . '“';
				}
			}
			$u[] = ['id' => 'p' . (int) $post->ID . '_' . $key . '_' . $md5, 'label' => $label, 'src' => (string) $src, 'tgt' => $with_tr ? (string) $tgt : '', 'html' => $html, 'notes' => $notes];
		};
		$add('title', md5($post->post_title), __('Title', 'alba'), $post->post_title, $d['title'], false);
		if ($post->post_name !== '' && $post->post_status !== 'auto-draft') {
			$add('slug', md5($post->post_name), __('URL slug', 'alba'), $post->post_name, $d['slug'], false, __('Only lowercase letters, numbers and hyphens.', 'alba'));
		}
		if ($e->seo_plugin()) {
			$ss = $e->seo_source($post->ID);
			$add('seotitle', md5($ss['title']), __('SEO title', 'alba'), $ss['title'], $d['seo_title'], false, __('Recommended: at most 60 characters.', 'alba'));
			$add('seodesc', md5($ss['desc']), __('SEO description', 'alba'), $ss['desc'], $d['seo_desc'], false, __('Recommended: at most 160 characters.', 'alba'));
		}
		$add('excerpt', md5($post->post_excerpt), __('Excerpt', 'alba'), $post->post_excerpt, $d['excerpt'], false);
		$kinds = [];
		$n = 0;
		foreach ($e->post_segments($post, $kinds) as $h => $t) {
			$n++;
			$add('seg', (string) $h, $e->kind_label(isset($kinds[$h]) ? $kinds[$h] : '', $n), $t, isset($d['seg'][$h]) ? $d['seg'][$h] : '', true);
		}
		return $u;
	}

	/** Content that is exported */
	private function export_posts($lang, array $types, $only_open, $one, array $pick = []) {
		$c = $this->core;
		$posts = [];
		if ($one) {
			$p = get_post($one);
			if ($p && $c->is_translatable($p->post_type) && current_user_can('edit_post', $p->ID)) {
				$posts[] = $p;
			}
		} elseif ($pick) {
			if (!current_user_can('edit_others_posts')) {
				return [];
			}
			foreach ($pick as $pid) {
				$p = get_post($pid);
				if ($p && $c->is_translatable($p->post_type) && current_user_can('edit_post', $p->ID)) {
					$posts[] = $p;
				}
			}
		} else {
			if (!current_user_can('edit_others_posts')) {
				return [];
			}
			foreach ($types as $t) {
				$list = get_posts(['post_type' => $t, 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'posts_per_page' => self::LIMIT, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true, 'suppress_filters' => true, 'alba_skip' => 1]);
				update_meta_cache('post', wp_list_pluck($list, 'ID'));
				foreach ($list as $p) {
					if (current_user_can('edit_post', $p->ID)) {
						$posts[] = $p;
					}
				}
			}
		}
		$out = [];
		foreach ($posts as $p) {
			$id = (int) $p->ID;
			if ($c->post_lang($id) !== $c->default || $c->translation($id, $lang, false)) {
				continue;   // only originals without their own translation post
			}
			if ($only_open && !$one) {
				$oc = $c->editor->open_count($p, $lang);
				if (!$oc[0]) {
					continue;
				}
			}
			$out[] = $p;
		}
		return $out;
	}

	public function build($posts, $lang, $fmt = '1.2', $with_tr = true) {
		$c = $this->core;
		$fmt = $fmt === '2.0' ? '2.0' : '1.2';
		$src = $this->tag($c->default);
		$tgt = $this->tag($lang);
		$x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		if ($fmt === '2.0') {
			$x .= '<xliff version="2.0" xmlns="urn:oasis:names:tc:xliff:document:2.0" srcLang="' . $this->esc($src) . '" trgLang="' . $this->esc($tgt) . '">' . "\n";
		} else {
			$x .= '<xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2">' . "\n";
		}
		foreach ($posts as $post) {
			$obj = get_post_type_object($post->post_type);
			$note = __('Content: ', 'alba') . ($post->post_title !== '' ? $post->post_title : __('(no title)', 'alba')) . ' (' . ($obj ? $obj->labels->singular_name : $post->post_type) . ' #' . (int) $post->ID . ')';
			$note .= __(' · Please leave HTML tags in the text unchanged (they are protected as placeholders).', 'alba');
			$orig = 'post-' . (int) $post->ID;
			if ($fmt === '2.0') {
				$x .= '<file id="f' . (int) $post->ID . '" original="' . $this->esc($orig) . '">' . "\n";
				$x .= '<notes><note category="developer">' . $this->esc($note) . '</note></notes>' . "\n";
			} else {
				$x .= '<file original="' . $this->esc($orig) . '" source-language="' . $this->esc($src) . '" target-language="' . $this->esc($tgt) . '" datatype="html" tool-id="alba">' . "\n";
				$x .= '<header><tool tool-id="alba" tool-name="Alba" tool-version="' . $this->esc(ALBA_VERSION) . '"/><note>' . $this->esc($note) . '</note></header>' . "\n<body>\n";
			}
			foreach ($this->units($post, $lang, $with_tr) as $u) {
				$s = $this->inline($u['src'], $u['html'], $fmt);
				$t = $u['tgt'] !== '' ? $this->inline($u['tgt'], $u['html'], $fmt, $u['html'] ? $this->pieces($u['src']) : null) : '';
				$t = (string) $t;   // null = tags of the translation do not match the original: export without target
				if ($fmt === '2.0') {
					$x .= '<unit id="' . $this->esc($u['id']) . '" name="' . $this->esc($u['label']) . '">';
					if ($u['notes']) {
						$x .= '<notes>';
						foreach ($u['notes'] as $n) {
							$x .= '<note category="developer">' . $this->esc($n) . '</note>';
						}
						$x .= '</notes>';
					}
					$x .= '<segment state="' . ($t !== '' ? 'translated' : 'initial') . '"><source>' . $s . '</source>' . ($t !== '' ? '<target>' . $t . '</target>' : '') . '</segment></unit>' . "\n";
				} else {
					$x .= '<trans-unit id="' . $this->esc($u['id']) . '" resname="' . $this->esc($u['label']) . '"><source xml:lang="' . $this->esc($src) . '">' . $s . '</source>';
					if ($t !== '') {
						$x .= '<target xml:lang="' . $this->esc($tgt) . '" state="translated">' . $t . '</target>';
					}
					foreach ($u['notes'] as $n) {
						$x .= '<note from="developer">' . $this->esc($n) . '</note>';
					}
					$x .= '</trans-unit>' . "\n";
				}
			}
			$x .= $fmt === '2.0' ? "</file>\n" : "</body>\n</file>\n";
		}
		return $x . "</xliff>\n";
	}

	public function export() {
		check_admin_referer('alba_xliff_export');
		$c = $this->core;
		$one = isset($_GET['post']) ? (int) $_GET['post'] : 0;
		$pick = [];
		if (!$one && !empty($_GET['ids'])) {
			foreach (explode(',', sanitize_text_field(wp_unslash($_GET['ids']))) as $i) {
				if ((int) $i > 0) {
					$pick[(int) $i] = (int) $i;
				}
			}
			$pick = array_slice(array_values($pick), 0, 500);
		}
		if (!$one && !current_user_can('edit_others_posts')) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		$lang = isset($_GET['lang']) ? sanitize_key(wp_unslash($_GET['lang'])) : '';
		if (!isset($c->langs[$lang]) || $lang === $c->default) {
			wp_die(esc_html__('Please choose a target language (not the default language).', 'alba'));
		}
		$fmt = (isset($_GET['fmt']) && $_GET['fmt'] === '2.0') ? '2.0' : '1.2';
		$types = [];
		if (!empty($_GET['types']) && is_array($_GET['types'])) {
			$types = array_values(array_intersect(array_map('sanitize_key', wp_unslash($_GET['types'])), (array) $c->post_types));
		}
		if (!$one && !$types && !$pick) {
			$this->back(['alba_xl_err' => __('Please choose at least one content type.', 'alba')]);
		}
		@set_time_limit(120);
		$posts = $this->export_posts($lang, $types, !empty($_GET['open']), $one, $pick);
		if (!$posts) {
			$this->back(['alba_xl_err' => __('No matching content found (everything translated or separate translation posts exist).', 'alba')]);
		}
		$xml = $this->build($posts, $lang, $fmt, !empty($_GET['tr']));
		$name = 'alba-' . $c->default . '-' . $lang . '-' . ($one ? 'post-' . $one : count($posts) . '-items') . '-' . gmdate('Ymd') . '.xlf';
		nocache_headers();
		header('Content-Type: application/x-xliff+xml; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $name . '"');
		header('X-Content-Type-Options: nosniff');
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/* ---------------------------------------------------------------
	 * Import: read the file and create a plan
	 * ------------------------------------------------------------- */

	/** Direct child elements with this local name */
	private function kids(DOMNode $n, $name) {
		$r = [];
		foreach ($n->childNodes as $ch) {
			if ($ch->nodeType === XML_ELEMENT_NODE && $ch->localName === $name) {
				$r[] = $ch;
			}
		}
		return $r;
	}

	/** <target> elements of a unit; [found, [elements]] */
	private function targets(DOMElement $unit, $v2) {
		$out = [];
		if (!$v2) {
			foreach ($this->kids($unit, 'target') as $t) {
				$out[] = $t;
			}
			return $out;
		}
		foreach ($unit->childNodes as $ch) {
			if ($ch->nodeType !== XML_ELEMENT_NODE || !in_array($ch->localName, ['segment', 'ignorable'], true)) {
				continue;
			}
			$t = $this->kids($ch, 'target');
			if ($t) {
				$out[] = $t[0];
			} elseif ($ch->localName === 'ignorable' && ($s = $this->kids($ch, 'source'))) {
				$out[] = $s[0];   // Whitespace between sentences
			}
		}
		return $out;
	}

	/**
	 * Reads the file and checks every entry against the current content. Result: plan (array) or WP_Error.
	 * Nothing is saved yet.
	 */
	public function plan($xml, $choice = '', $name = '') {
		$c = $this->core;
		$e = $c->editor;
		if (!class_exists('DOMDocument')) {
			return new WP_Error('alba', __('The PHP extension "DOM" (libxml) is missing on the server. Please ask your host to enable it.', 'alba'));
		}
		$xml = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $xml);
		if (stripos($xml, '<!ENTITY') !== false) {
			return new WP_Error('alba', __('The file contains entity declarations and is not read for security reasons.', 'alba'));
		}
		$prev = libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		$ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		$root = $ok ? $dom->documentElement : null;
		if (!$root || $root->localName !== 'xliff') {
			return new WP_Error('alba', __('This is not a valid XLIFF file.', 'alba'));
		}
		$version = (string) $root->getAttribute('version');
		$v2 = ($version !== '' && $version[0] === '2');

		$tag = $v2 ? (string) $root->getAttribute('trgLang') : '';
		if (!$v2) {
			$files = $dom->getElementsByTagNameNS('*', 'file');
			foreach ($files as $f) {
				if ($f->getAttribute('target-language') !== '') {
					$tag = (string) $f->getAttribute('target-language');
					break;
				}
			}
		}
		$lang = $choice !== '' ? $choice : $this->detect($tag);
		if (!isset($c->langs[$lang]) || $lang === $c->default) {
			return new WP_Error('alba', $choice !== '' ? __('Invalid target language.', 'alba') : __('The target language', 'alba') . ($tag !== '' ? ' „' . $tag . '“' : '') . __(' in the file does not match any language that has been added. Please choose the target language in the form.', 'alba'));
		}

		$units = $dom->getElementsByTagNameNS('*', $v2 ? 'unit' : 'trans-unit');
		$by = [];
		$unknown = 0;
		foreach ($units as $u) {
			if (preg_match('/^p(\d+)_(title|excerpt|seotitle|seodesc|slug|seg)_([0-9a-f]{32})$/', (string) $u->getAttribute('id'), $m)) {
				$by[(int) $m[1]][] = [$m[2], $m[3], $u];
			} else {
				$unknown++;
			}
		}
		if (!$by) {
			return new WP_Error('alba', __('The file contains no entries that belong to this plugin (export with "Alba" required).', 'alba'));
		}

		$map = ['title' => 'title', 'excerpt' => 'excerpt', 'seotitle' => 'seo_title', 'seodesc' => 'seo_desc', 'slug' => 'slug'];
		$plan = ['lang' => $lang, 'tag' => $tag, 'file' => $name, 'version' => $version, 'time' => time(), 'unknown' => $unknown, 'posts' => [], 'bad' => []];

		foreach ($by as $pid => $items) {
			$post = get_post($pid);
			if (!$post || !$c->is_translatable($post->post_type)) {
				$plan['bad'][] = ['id' => $pid, 'title' => '#' . $pid, 'why' => __('Content no longer exists', 'alba')];
				continue;
			}
			$title = $post->post_title !== '' ? $post->post_title : __('(no title)', 'alba');
			if (!current_user_can('edit_post', $pid)) {
				$plan['bad'][] = ['id' => $pid, 'title' => $title, 'why' => __('no permission', 'alba')];
				continue;
			}
			if ($c->post_lang($pid) !== $c->default || $c->translation($pid, $lang, false)) {
				$plan['bad'][] = ['id' => $pid, 'title' => $title, 'why' => __('has its own translation post in this language or is itself a translation', 'alba')];
				continue;
			}
			$kinds = [];
			$segs = $e->post_segments($post, $kinds);
			$ss = $e->seo_plugin() ? $e->seo_source($pid) : null;
			$cur = ['title' => $post->post_title, 'excerpt' => $post->post_excerpt, 'slug' => $post->post_name];
			if ($ss) {
				$cur['seotitle'] = $ss['title'];
				$cur['seodesc'] = $ss['desc'];
			}
			$d = $e->data($pid, $lang);
			$p = ['title' => $title, 'fields' => [], 'seg' => [], 'new' => 0, 'chg' => 0, 'same' => 0, 'stale' => 0, 'markup' => 0, 'empty' => 0, 'live' => $e->is_on($pid, $lang), 'edit' => $e->url($pid, $lang)];
			foreach ($items as $it) {
				list($key, $md5, $unit) = $it;
				$tg = $this->targets($unit, $v2);
				if ($key === 'seg') {
					if (!isset($segs[$md5])) {
						$p['stale']++;
						continue;
					}
					$text = '';
					if ($tg) {
						$pc = $this->pieces($segs[$md5]);
						foreach ($tg as $t) {
							$text .= $this->decode($t, $pc);
						}
					}
					$text = trim($text);
					if ($text === '') {
						$p['empty']++;
						continue;
					}
					if ($this->tags($text) !== $this->tags($segs[$md5])) {
						$p['markup']++;
						continue;
					}
					if (!current_user_can('unfiltered_html')) {
						$text = wp_kses_post($text);
					}
					$old = isset($d['seg'][$md5]) ? (string) $d['seg'][$md5] : '';
					$p['seg'][$md5] = $text;
				} else {
					if (!isset($cur[$key]) || md5((string) $cur[$key]) !== $md5) {
						$p['stale']++;
						continue;
					}
					$text = '';
					foreach ($tg as $t) {
						$text .= $t->textContent;
					}
					$f = $map[$key];
					$text = $this->clean($f, trim($text));
					if ($text === '') {
						$p['empty']++;
						continue;
					}
					$old = (string) $d[$f];
					$p['fields'][$f] = ['src' => $md5, 'text' => $text];
				}
				if ($old === $text) {
					$p['same']++;
				} elseif ($old === '') {
					$p['new']++;
				} else {
					$p['chg']++;
				}
			}
			$plan['posts'][$pid] = $p;
		}
		return $plan;
	}

	/** Same cleanup as when saving in the editor, so that the comparison in the preview is correct */
	private function clean($field, $text) {
		if ($field === 'excerpt' || $field === 'seo_desc') {
			return trim(sanitize_textarea_field($text));
		}
		if ($field === 'slug') {
			return sanitize_title($text);
		}
		return trim(sanitize_text_field($text));
	}

	/** Applies a plan; $overwrite = replace existing translations */
	public function run_apply(array $plan, $overwrite) {
		$c = $this->core;
		$tot = ['new' => 0, 'chg' => 0, 'same' => 0, 'kept' => 0, 'posts' => 0];
		foreach ($plan['posts'] as $pid => $p) {
			$post = get_post((int) $pid);
			if (!$post || !current_user_can('edit_post', $pid) || !$c->editor) {
				continue;
			}
			$r = $c->editor->import_store($post, $plan['lang'], $p['fields'], $p['seg'], $overwrite, isset($plan['file']) ? (string) $plan['file'] : '');
			foreach (['new', 'chg', 'same', 'kept'] as $k) {
				$tot[$k] += $r[$k];
			}
			if ($r['new'] || $r['chg']) {
				$tot['posts']++;
			}
		}
		return $tot;
	}

	/* ---------------------------------------------------------------
	 * Forms
	 * ------------------------------------------------------------- */

	private function back(array $args) {
		wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=' . self::PAGE)));
		exit;
	}

	private function tkey($token) {
		return 'alba_xl_' . get_current_user_id() . '_' . preg_replace('/[^A-Za-z0-9]/', '', (string) $token);
	}

	public function upload() {
		check_admin_referer('alba_xliff_upload');
		if (!current_user_can('edit_others_posts')) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Upload array; name, size and type are validated below.
		$f = isset($_FILES['xlf']) ? $_FILES['xlf'] : null;
		if (!$f || (int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
			$this->back(['alba_xl_err' => __('Upload failed.', 'alba')]);
		}
		$ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
		if (!in_array($ext, ['xlf', 'xliff', 'xml'], true)) {
			$this->back(['alba_xl_err' => __('Please upload an .xlf or .xliff file.', 'alba')]);
		}
		if ((int) $f['size'] > self::MAX_BYTES) {
			$this->back(['alba_xl_err' => __('The file is larger than 5 MB.', 'alba')]);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the local uploaded temp file, not a URL.
		$xml = (string) file_get_contents($f['tmp_name']);
		if (function_exists('mb_check_encoding') && !mb_check_encoding($xml, 'UTF-8')) {
			$this->back(['alba_xl_err' => __('The file is not UTF-8 encoded.', 'alba')]);
		}
		$choice = isset($_POST['lang']) ? sanitize_key(wp_unslash($_POST['lang'])) : '';
		@set_time_limit(120);
		$plan = $this->plan($xml, $choice, sanitize_file_name((string) $f['name']));
		if (is_wp_error($plan)) {
			$this->back(['alba_xl_err' => $plan->get_error_message()]);
		}
		$token = wp_generate_password(16, false);
		set_transient($this->tkey($token), $plan, HOUR_IN_SECONDS);
		$this->back(['alba_xl' => $token]);
	}

	public function apply() {
		check_admin_referer('alba_xliff_apply');
		if (!current_user_can('edit_others_posts')) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		$token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
		$plan = get_transient($this->tkey($token));
		if (!is_array($plan) || empty($plan['posts'])) {
			$this->back(['alba_xl_err' => __('The preview has expired. Please upload the file again.', 'alba')]);
		}
		@set_time_limit(180);
		$r = $this->run_apply($plan, isset($_POST['mode']) && $_POST['mode'] === 'all');
		delete_transient($this->tkey($token));
		$this->back(['alba_xl_done' => 1, 'n_new' => $r['new'], 'n_chg' => $r['chg'], 'n_same' => $r['same'], 'n_kept' => $r['kept'], 'n_posts' => $r['posts'], 'l' => $plan['lang']]);
	}

	/* ---------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------- */

	public function page() {
		if (!current_user_can('edit_others_posts')) {
			return;
		}
		$c = $this->core;
		echo '<div class="wrap"><h1>' . esc_html__('XLIFF – Export & import for external translators', 'alba') . '</h1>';
		echo '<style>.alba-xl-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:4px 20px 16px;margin:16px 0;max-width:900px}.alba-xl-card h2{margin-top:14px}.alba-xl-card label{display:inline-block;margin:2px 14px 2px 0}</style>';

		if (!empty($_GET['alba_xl_err'])) {
			echo '<div class="notice notice-error"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['alba_xl_err']))) . '</p></div>';
		}
		if (!empty($_GET['alba_xl_done'])) {
			$l = isset($_GET['l']) ? sanitize_key(wp_unslash($_GET['l'])) : '';
			$txt = sprintf(/* translators: 1: new texts, 2: changed texts, 3: items */ __('%1$d new and %2$d changed texts in %3$d items imported', 'alba'), isset($_GET['n_new']) ? absint($_GET['n_new']) : 0, isset($_GET['n_chg']) ? absint($_GET['n_chg']) : 0, isset($_GET['n_posts']) ? absint($_GET['n_posts']) : 0);
			if (!empty($_GET['n_same'])) {
				$txt .= ', ' . (int) $_GET['n_same'] . __(' unchanged', 'alba');
			}
			if (!empty($_GET['n_kept'])) {
				$txt .= ', ' . (int) $_GET['n_kept'] . __(' existing translations kept', 'alba');
			}
			echo '<div class="notice notice-success"><p>' . esc_html($txt) . esc_html__('. The texts are in the side-by-side editor', 'alba') . ($l && isset($c->langs[$l]) ? ' (' . esc_html($c->langs[$l]['name']) . ')' : '') . ' ' . esc_html__('ready for review. The import did not change whether a translation is visible on the frontend.', 'alba') . '</p></div>';
		}

		$token = isset($_GET['alba_xl']) ? sanitize_text_field(wp_unslash($_GET['alba_xl'])) : '';
		$plan = $token !== '' ? get_transient($this->tkey($token)) : false;
		if (is_array($plan)) {
			$this->preview($plan, $token);
			echo '</div>';
			return;
		}
		if ($token !== '') {
			echo '<div class="notice notice-warning"><p>' . esc_html__('The preview has expired. Please upload the file again.', 'alba') . '</p></div>';
		}

		$targets = array_diff_key($c->langs, [$c->default => 1]);
		if (!$targets) {
			echo '<div class="notice notice-info"><p>' . esc_html__('There is only one language. First add a target language under Settings.', 'alba') . '</p></div></div>';
			return;
		}
		?>
		<p><?php echo esc_html__('Process: export as', 'alba'); ?> <code>.xlf</code> <?php echo esc_html__('→ translator edits the file in their translation tool (Trados, memoQ, Phrase, Crowdin, Poedit …) → import here. Imported texts end up in the side-by-side editor and must be checked and saved there by you.', 'alba'); ?></p>

		<div class="alba-xl-card">
			<h2><?php echo esc_html__('1. Export', 'alba'); ?></h2>
			<form method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="alba_xliff_export">
				<?php wp_nonce_field('alba_xliff_export'); ?>
				<p>
					<label><?php echo esc_html__('Target language', 'alba'); ?>

						<select name="lang">
							<?php foreach ($targets as $code => $l) : ?>
								<option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($l['name']); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label><?php echo esc_html__('Format', 'alba'); ?>

						<select name="fmt">
							<option value="1.2"><?php echo esc_html__('XLIFF 1.2 (most widely used)', 'alba'); ?></option>
							<option value="2.0"><?php echo esc_html__('XLIFF 2.0', 'alba'); ?></option>
						</select>
					</label>
				</p>
				<p><strong><?php echo esc_html__('Content types', 'alba'); ?></strong><br>
					<?php foreach ((array) $c->post_types as $t) :
						$obj = get_post_type_object($t);
						if (!$obj) {
							continue;
						}
						?>
						<label><input type="checkbox" name="types[]" value="<?php echo esc_attr($t); ?>" checked> <?php echo esc_html($obj->labels->name); ?></label>
					<?php endforeach; ?>
				</p>
				<p>
					<label><input type="checkbox" name="open" value="1" checked> <?php echo esc_html__('Only content with open texts', 'alba'); ?></label><br>
					<label><input type="checkbox" name="tr" value="1" checked> <?php echo esc_html__('Include existing translations as target (for checking and revising)', 'alba'); ?></label>
				</p>
				<?php submit_button(__('Download XLIFF', 'alba'), 'primary', 'submit', false); ?>
				<p class="description"><?php echo esc_html__('Included are title, URL slug, SEO fields, excerpt and all text sections (including alt texts, block and field texts). Glossary terms appear as a note at the relevant place. You can export single items via the "Export as XLIFF" link in the side-by-side editor.', 'alba'); ?></p>
			</form>
		</div>

		<div class="alba-xl-card">
			<h2><?php echo esc_html__('2. Import', 'alba'); ?></h2>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="alba_xliff_upload">
				<?php wp_nonce_field('alba_xliff_upload'); ?>
				<p>
					<label><?php echo esc_html__('Target language', 'alba'); ?>

						<select name="lang">
							<option value=""><?php echo esc_html__('detect from file', 'alba'); ?></option>
							<?php foreach ($targets as $code => $l) : ?>
								<option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($l['name']); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<input type="file" name="xlf" accept=".xlf,.xliff,.xml" required>
				</p>
				<?php submit_button(__('Check file', 'alba'), 'secondary', 'submit', false); ?>
				<p class="description"><?php echo esc_html__('Expects UTF-8, maximum 5 MB. A preview comes first; nothing is saved until you confirm. Entries whose original text has changed since the export, and texts with changed markup, are skipped.', 'alba'); ?></p>
			</form>
		</div>
		</div>
		<?php
	}

	private function preview(array $plan, $token) {
		$c = $this->core;
		$lang = $plan['lang'];
		$tot = ['new' => 0, 'chg' => 0, 'same' => 0, 'stale' => 0, 'markup' => 0, 'empty' => 0];
		$live = 0;
		foreach ($plan['posts'] as $p) {
			foreach ($tot as $k => $v) {
				$tot[$k] += $p[$k];
			}
			if ($p['live'] && ($p['new'] || $p['chg'])) {
				$live++;
			}
		}
		?>
		<p><strong><?php echo esc_html__('Preview', 'alba'); ?></strong> <?php echo esc_html__('· File', 'alba'); ?> <code><?php echo esc_html($plan['file']); ?></code> <?php echo esc_html__('· XLIFF', 'alba'); ?> <?php echo esc_html($plan['version']); ?> <?php echo esc_html__('· Target language', 'alba'); ?> <strong><?php echo esc_html($c->langs[$lang]['name']); ?></strong></p>
		<table class="widefat striped" style="max-width:900px">
			<thead><tr><th><?php echo esc_html__('Content', 'alba'); ?></th><th><?php echo esc_html__('New', 'alba'); ?></th><th><?php echo esc_html__('Changed', 'alba'); ?></th><th><?php echo esc_html__('Unchanged', 'alba'); ?></th><th><?php echo esc_html__('Skipped', 'alba'); ?></th></tr></thead>
			<tbody>
			<?php foreach ($plan['posts'] as $pid => $p) :
				$skip = [];
				if ($p['stale']) {
					$skip[] = $p['stale'] . __(' original changed', 'alba');
				}
				if ($p['markup']) {
					$skip[] = $p['markup'] . __(' markup differs', 'alba');
				}
				if ($p['empty']) {
					$skip[] = $p['empty'] . __(' not translated', 'alba');
				}
				?>
				<tr>
					<td><a href="<?php echo esc_url($p['edit']); ?>"><?php echo esc_html($p['title']); ?></a><?php echo $p['live'] ? ' <span title="' . esc_attr__('Translation is visible on the frontend', 'alba') . '" style="color:#2271b1">●</span>' : ''; ?></td>
					<td><?php echo (int) $p['new']; ?></td>
					<td><?php echo (int) $p['chg']; ?></td>
					<td><?php echo (int) $p['same']; ?></td>
					<td><?php echo $skip ? esc_html(implode(' · ', $skip)) : '–'; ?></td>
				</tr>
			<?php endforeach; ?>
			<?php foreach ($plan['bad'] as $b) : ?>
				<tr><td><?php echo esc_html($b['title']); ?></td><td colspan="4" style="color:#b32d2e"><?php echo esc_html__('Will not be imported:', 'alba'); ?> <?php echo esc_html($b['why']); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
			<tfoot><tr><th><?php echo esc_html__('Total', 'alba'); ?></th><th><?php echo (int) $tot['new']; ?></th><th><?php echo (int) $tot['chg']; ?></th><th><?php echo (int) $tot['same']; ?></th><th><?php echo (int) ($tot['stale'] + $tot['markup'] + $tot['empty']); ?></th></tr></tfoot>
		</table>
		<?php if ($plan['unknown']) : ?>
			<p class="description"><?php echo (int) $plan['unknown']; ?> <?php echo esc_html__('Entries in the file do not belong to this plugin and are ignored.', 'alba'); ?></p>
		<?php endif; ?>
		<?php if ($tot['stale']) : ?>
			<p class="description"><?php echo esc_html__('"Original changed": The original text was edited since the export. These sections stay open and appear again after a new export.', 'alba'); ?></p>
		<?php endif; ?>
		<?php if ($tot['markup']) : ?>
			<p class="description"><?php echo esc_html__('"Markup differs": The translated text is missing tags or has different ones than the original. Please translate these sections by hand in the editor or re-import them after correcting.', 'alba'); ?></p>
		<?php endif; ?>

		<?php if ($tot['new'] + $tot['chg'] === 0) : ?>
			<p><?php echo esc_html__('There is nothing to import.', 'alba'); ?> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE)); ?>"><?php echo esc_html__('Back', 'alba'); ?></a></p>
			<?php return; ?>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px">
			<input type="hidden" name="action" value="alba_xliff_apply">
			<input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
			<?php wp_nonce_field('alba_xliff_apply'); ?>
			<?php if ($tot['chg']) : ?>
				<p>
					<label><input type="radio" name="mode" value="empty" checked> <?php echo esc_html__('Fill empty fields only,', 'alba'); ?> <?php echo (int) $tot['chg']; ?> <?php echo esc_html__('existing translations remain unchanged', 'alba'); ?></label><br>
					<label><input type="radio" name="mode" value="all"> <?php echo esc_html__('Import everything and', 'alba'); ?> <?php echo (int) $tot['chg']; ?> <?php echo esc_html__('overwrite existing translations', 'alba'); ?></label>
				</p>
			<?php endif; ?>
			<?php if ($live) : ?>
				<p class="description"><?php echo esc_html__('For', 'alba'); ?> <?php echo (int) $live; ?> <?php echo esc_html__('items (●) the translation is already published on the frontend. New and changed texts appear there immediately.', 'alba'); ?></p>
			<?php endif; ?>
			<p>
				<button type="submit" class="button button-primary"><?php echo esc_html__('Run import', 'alba'); ?></button>
				<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE)); ?>"><?php echo esc_html__('Cancel', 'alba'); ?></a>
			</p>
		</form>
		<?php
	}
}
