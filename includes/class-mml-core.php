<?php
if (!defined('ABSPATH')) {
	exit;
}

class MML_Core {
	private static $inst;

	public $langs = [];        // code => ['name' =>, 'locale' =>]
	public $default = '';
	public $lang = '';         // aktuelle Frontend-Sprache
	public $post_types = ['post', 'page'];
	public $frontend;
	public $strings;
	public $terms;
	public $editor;
	public $glossary;
	public $log;
	public $xliff;
	public $areas;
	public $roles;
	public $seo;
	public $workflow;
	public $mt;
	public $bulk;
	public $forms;
	public $media;
	public $wpml;
	private $forcing = 0;
	public $page_cache;
	public $taxonomies = ['category', 'post_tag'];

	public $orig_uri = '';
	private $cache = [];
	private $busy = false;

	public static function instance() {
		if (!self::$inst) {
			self::$inst = new self();
		}
		return self::$inst;
	}

	public static function presets() {
		return [
			'de' => ['Deutsch', 'de_DE'],
			'en' => ['English', 'en_US'],
			'fr' => ['Français', 'fr_FR'],
			'lb' => ['Lëtzebuergesch', 'lb_LU'],
			'es' => ['Español', 'es_ES'],
			'it' => ['Italiano', 'it_IT'],
			'pt' => ['Português', 'pt_PT'],
			'nl' => ['Nederlands', 'nl_NL'],
			'pl' => ['Polski', 'pl_PL'],
			'cs' => ['Čeština', 'cs_CZ'],
			'da' => ['Dansk', 'da_DK'],
			'sv' => ['Svenska', 'sv_SE'],
			'tr' => ['Türkçe', 'tr_TR'],
			'ru' => ['Русский', 'ru_RU'],
			'ja' => ['日本語', 'ja'],
			'zh' => ['中文', 'zh_CN'],
			'ar' => ['العربية', 'ar'],
		];
	}

	/* ---------------------------------------------------------------
	 * Start
	 * ------------------------------------------------------------- */

	public function boot() {
		$this->load_settings();
		$this->lang = $this->default;

		if (!is_admin()) {
			$this->detect_language();
		}

		add_filter('locale', [$this, 'filter_locale']);
		add_filter('home_url', [$this, 'filter_home_url'], 10, 2);
		add_action('pre_get_posts', [$this, 'pre_get_posts']);
		add_filter('option_page_on_front', [$this, 'filter_front_option']);
		add_filter('option_page_for_posts', [$this, 'filter_front_option']);
		add_filter('wp_unique_post_slug', [$this, 'filter_unique_slug'], 10, 6);
	}

	private function load_settings() {
		$langs = get_option('mml_languages');
		if (!is_array($langs) || !$langs) {
			$loc = get_option('WPLANG') ?: 'en_US';
			$code = strtolower(substr($loc, 0, 2));
			$presets = self::presets();
			$name = isset($presets[$code]) ? $presets[$code][0] : $code;
			$langs = [$code => ['name' => $name, 'locale' => $loc]];
		}
		$this->langs = $langs;

		$def = get_option('mml_default');
		$this->default = isset($langs[$def]) ? $def : (string) key($langs);

		$pt = get_option('mml_post_types');
		$this->post_types = (is_array($pt) && $pt) ? array_values($pt) : ['post', 'page'];

		$tx = get_option('mml_taxonomies');
		if (is_array($tx)) {
			$this->taxonomies = array_values($tx);
		}
	}

	/* ---------------------------------------------------------------
	 * Sprache aus der URL erkennen (/en/seite/ -> /seite/)
	 * ------------------------------------------------------------- */

	private function detect_language() {
		$uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
		$this->orig_uri = $uri;

		$path = (string) parse_url($uri, PHP_URL_PATH);
		$qs = parse_url($uri, PHP_URL_QUERY);
		$home_path = rtrim((string) parse_url(get_option('home'), PHP_URL_PATH), '/');

		if ($home_path !== '') {
			if (strpos($path, $home_path) !== 0) {
				return;
			}
			$rel = substr($path, strlen($home_path));
		} else {
			$rel = $path;
		}

		if (preg_match('#^/([^/]+)(/.*)?$#', $rel, $m)) {
			$code = strtolower(urldecode($m[1]));
			if ($code !== $this->default && isset($this->langs[$code])) {
				$this->lang = $code;
				$rest = isset($m[2]) ? $m[2] : '/';
				$_SERVER['REQUEST_URI'] = $home_path . $rest . (($qs !== null && $qs !== '') ? '?' . $qs : '');
				// Nach dem Routing die Original-URL zurücksetzen, damit redirect_canonical sauber arbeitet
				add_action('parse_request', function () {
					$_SERVER['REQUEST_URI'] = $this->orig_uri;
				});
				return;
			}
		}

		// Fallback für "einfache" Permalinks: ?lang=en
		if (isset($_GET['lang'])) {
			$code = strtolower(sanitize_key(wp_unslash($_GET['lang'])));
			if (isset($this->langs[$code])) {
				$this->lang = $code;
			}
		}
	}

	public function filter_locale($locale) {
		if (!is_admin() && isset($this->langs[$this->lang]['locale']) && $this->langs[$this->lang]['locale'] !== '') {
			return $this->langs[$this->lang]['locale'];
		}
		return $locale;
	}

	/* ---------------------------------------------------------------
	 * URLs
	 * ------------------------------------------------------------- */

	public function prefix_url($url, $lang) {
		if ($lang === $this->default || !isset($this->langs[$lang])) {
			return $url;
		}
		if (!get_option('permalink_structure')) {
			return add_query_arg('lang', $lang, $url);
		}

		$home = preg_replace('#^https?:#i', '', untrailingslashit(get_option('home')));
		$u = preg_replace('#^https?:#i', '', $url);
		if (strpos($u, $home) !== 0) {
			return $url;
		}
		$rest = substr($u, strlen($home));
		if ($rest !== '' && !in_array($rest[0], ['/', '?', '#'], true)) {
			return $url;
		}
		if (preg_match('~^/' . preg_quote($lang, '~') . '(/|\?|#|$)~', $rest)) {
			return $url;
		}
		if ($rest === '' || $rest[0] !== '/') {
			$rest = '/' . $rest;
		}
		$scheme = '';
		if (stripos($url, 'https:') === 0) {
			$scheme = 'https:';
		} elseif (stripos($url, 'http:') === 0) {
			$scheme = 'http:';
		}
		return $scheme . $home . '/' . $lang . $rest;
	}

	public function home_in($lang) {
		$base = trailingslashit(set_url_scheme(get_option('home')));
		return $this->prefix_url($base, $lang);
	}

	public function filter_home_url($url, $path = '') {
		if ((is_admin() && !$this->forcing) || $this->lang === $this->default) {
			return $url;
		}
		$p = ltrim((string) $path, '/');
		if ($p !== '' && preg_match('#^(wp-|xmlrpc|robots\.txt|favicon|sitemap)#', $p)) {
			return $url;
		}
		return $this->prefix_url($url, $this->lang);
	}

	public function with_lang($lang, $cb) {
		$old = $this->lang;
		$this->lang = $lang;
		$this->forcing++;
		try {
			return $cb();
		} finally {
			$this->forcing--;
			$this->lang = $old;
		}
	}

	/* ---------------------------------------------------------------
	 * Übersetzungs-Verknüpfung (Meta: _mml_lang, _mml_group)
	 * ------------------------------------------------------------- */

	public function is_translatable($type) {
		return in_array($type, $this->post_types, true);
	}

	public function post_lang($id) {
		$l = get_post_meta($id, '_mml_lang', true);
		return ($l && isset($this->langs[$l])) ? $l : $this->default;
	}

	public function group($id, $create = false) {
		$g = get_post_meta($id, '_mml_group', true);
		if (!$g && $create) {
			$g = wp_generate_uuid4();
			update_post_meta($id, '_mml_group', $g);
		}
		return $g;
	}

	/** Titel im Original (ohne Ersetzung durch eine Editor-Übersetzung) */
	public function raw_title($id) {
		if (!$this->editor) {
			return get_the_title($id);
		}
		return $this->editor->raw(function () use ($id) {
			return get_the_title($id);
		});
	}

	public function flush() {
		$this->cache = [];
	}

	/** Alle Übersetzungen eines Beitrags: lang => post_id (alle Status) */
	public function translations($id) {
		$id = (int) $id;
		if (isset($this->cache[$id])) {
			return $this->cache[$id];
		}
		$out = [$this->post_lang($id) => $id];
		$g = get_post_meta($id, '_mml_group', true);
		if ($g) {
			$this->busy = true;
			try {
				$ids = get_posts([
					'post_type'        => get_post_type($id) ?: 'any',
					'post_status'      => ['publish', 'draft', 'pending', 'private', 'future'],
					'meta_key'         => '_mml_group',
					'meta_value'       => $g,
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'no_found_rows'    => true,
					'suppress_filters' => true,
					'mml_skip'         => 1,
				]);
			} finally {
				$this->busy = false;
			}
			foreach ($ids as $i) {
				$l = $this->post_lang($i);
				if (!isset($out[$l])) {
					$out[$l] = (int) $i;
				}
			}
		}
		$this->cache[$id] = $out;
		return $out;
	}

	public function translation($id, $lang, $published_only = true) {
		$t = $this->translations($id);
		if (empty($t[$lang])) {
			// Links-rechts-Übersetzung am Original (ohne eigenen Beitrag)
			if ($published_only && $this->editor && $this->editor->is_active($id, $lang)) {
				return (int) $id;
			}
			return 0;
		}
		$tid = $t[$lang];
		if ($published_only && get_post_status($tid) !== 'publish') {
			return 0;
		}
		return $tid;
	}

	/* ---------------------------------------------------------------
	 * Abfragen nach Sprache filtern
	 * ------------------------------------------------------------- */

	public function lang_meta_query($lang, $overlay = false) {
		if ($lang === $this->default) {
			return [
				'relation' => 'OR',
				['key' => '_mml_lang', 'value' => $lang],
				['key' => '_mml_lang', 'compare' => 'NOT EXISTS'],
			];
		}
		if ($overlay) {
			// auch Originale mit freigegebener Editor-Übersetzung in dieser Sprache
			return [
				'relation' => 'OR',
				['key' => '_mml_lang', 'value' => $lang],
				['key' => '_mml_on_' . $lang, 'value' => '1'],
			];
		}
		return [['key' => '_mml_lang', 'value' => $lang]];
	}

	public function pre_get_posts($q) {
		if ($q->get('mml_skip')) {
			return;
		}

		if (is_admin()) {
			// Nur die Beitragsliste, wenn per Dropdown gefiltert wird
			global $pagenow;
			if (!$q->is_main_query() || $pagenow !== 'edit.php') {
				return;
			}
			$lang = isset($_GET['mml_lang']) ? sanitize_key(wp_unslash($_GET['mml_lang'])) : '';
			if (!$lang || !isset($this->langs[$lang])) {
				return;
			}
			$pt = $q->get('post_type') ?: 'post';
			if (!is_string($pt) || !$this->is_translatable($pt)) {
				return;
			}
		} else {
			if (wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI)) {
				return;
			}
			if ($q->get('p') || $q->get('page_id') || $q->get('attachment_id') || $q->get('post__in')) {
				return;
			}
			$pt = $q->get('post_type');
			if ($pt && $pt !== 'any') {
				if (!array_intersect((array) $pt, $this->post_types)) {
					return;
				}
			}
			$lang = $this->lang;
		}

		$new = ['relation' => 'AND', $this->lang_meta_query($lang, !is_admin())];
		$mq = $q->get('meta_query');
		if (!empty($mq)) {
			$new[] = $mq;
		}
		$q->set('meta_query', $new);
	}

	/** Startseite / Beitragsseite in die aktuelle Sprache übersetzen */
	public function filter_front_option($v) {
		if ($this->busy || is_admin() || !$v) {
			return $v;
		}
		$id = (int) $v;
		if ($this->post_lang($id) === $this->lang) {
			return $v;
		}
		$tid = $this->translation($id, $this->lang);
		return $tid ? $tid : $v;
	}

	/** Gleicher Slug in verschiedenen Sprachen erlaubt (kein "-2") */
	public function filter_unique_slug($slug, $post_id, $status, $type, $parent, $original = '') {
		if ($slug === $original || !$this->is_translatable($type)) {
			return $slug;
		}
		if (!preg_match('/^' . preg_quote($original, '/') . '-\d+$/', $slug)) {
			return $slug;
		}
		if (apply_filters('wp_unique_post_slug_is_bad_flat_slug', false, $original, $type)) {
			return $slug;
		}

		$lang = isset($_POST['mml_lang']) ? sanitize_key(wp_unslash($_POST['mml_lang'])) : '';
		if (!isset($this->langs[$lang])) {
			$lang = $this->post_lang($post_id);
		}

		$args = [
			'post_type'        => $type,
			'name'             => $original,
			'post_status'      => ['publish', 'future', 'private', 'pending', 'draft'],
			'post__not_in'     => [(int) $post_id],
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'mml_skip'         => 1,
			'meta_query'       => [$this->lang_meta_query($lang)],
		];
		if (is_post_type_hierarchical($type)) {
			$args['post_parent'] = (int) $parent;
		}
		$clash = get_posts($args);
		return $clash ? $slug : $original;
	}

	/**
	 * Legt die Übersetzung eines Inhalts als Entwurf an (Kopie).
	 * $auto = Slug bleibt leer, fehlende Begriffe werden mit angelegt.
	 * Gibt die ID der Übersetzung oder WP_Error zurück.
	 */
	public function duplicate_post($id, $lang, $auto = false) {
		$src = get_post($id);
		if (!$src || !isset($this->langs[$lang]) || !$this->is_translatable($src->post_type)) {
			return new WP_Error('mml', __('This content cannot be translated.', 'alba'));
		}
		$existing = $this->translation($id, $lang, false);
		if ($existing) {
			return (int) $existing;
		}

		$group = $this->group($id, true);
		if (!metadata_exists('post', $id, '_mml_lang')) {
			update_post_meta($id, '_mml_lang', $this->post_lang($id));
		}

		$parent = 0;
		if ($src->post_parent) {
			$parent = $this->translation($src->post_parent, $lang, false);
		}

		$new = wp_insert_post(wp_slash([
			'post_type'      => $src->post_type,
			'post_status'    => 'draft',
			'post_title'     => $src->post_title,
			'post_name'      => $auto ? '' : $src->post_name,
			'post_content'   => $src->post_content,
			'post_excerpt'   => $src->post_excerpt,
			'post_parent'    => $parent,
			'menu_order'     => $src->menu_order,
			'comment_status' => $src->comment_status,
			'ping_status'    => $src->ping_status,
			'post_author'    => get_current_user_id(),
		]), true);
		if (is_wp_error($new)) {
			return $new;
		}

		update_post_meta($new, '_mml_lang', $lang);
		update_post_meta($new, '_mml_group', $group);
		delete_post_meta($id, '_mml_slug_' . $lang);
		delete_post_meta($id, '_mml_on_' . $lang);   // eigener Beitrag löst die Editor-Übersetzung ab
		$this->flush();
		if ($this->editor) {
			$this->editor->mark_synced($new);
		}

		$skip = ['_edit_lock', '_edit_last', '_wp_old_slug', '_wp_trash_meta_status', '_wp_trash_meta_time', '_encloseme', '_pingme'];
		foreach (get_post_meta($id) as $k => $vals) {
			if (strpos($k, '_mml_') === 0 || in_array($k, $skip, true)) {
				continue;
			}
			foreach ($vals as $v) {
				add_post_meta($new, $k, wp_slash(maybe_unserialize($v)));
			}
		}

		$this->flush();
		if ($this->terms) {
			$this->terms->copy_post_terms($id, $new, $lang, $auto);
		}
		do_action('mml_translation_created', (int) $new, (int) $id, $lang, (bool) $auto);
		return (int) $new;
	}
}
