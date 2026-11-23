<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Übersetzung von Taxonomie-Begriffen (Kategorien, Schlagwörter, eigene Taxonomien).
 * Sprache und Gruppe liegen als Term-Meta (_mml_lang, _mml_group).
 */
class MML_Terms {
	private $core;
	private $cache = [];

	public function __construct(MML_Core $core) {
		$this->core = $core;
		$core->terms = $this;

		add_action('rest_api_init', [$this, 'rest_init']);

		if (is_admin()) {
			add_action('admin_enqueue_scripts', [$this, 'enqueue']);
			add_action('admin_init', [$this, 'admin_init']);
			add_action('admin_post_mml_new_term', [$this, 'handle_new']);
			return;
		}
		add_filter('get_terms_args', [$this, 'filter_args'], 10, 2);
	}

	/* ---------------------------------------------------------------
	 * Grundlagen
	 * ------------------------------------------------------------- */

	public function lang($term_id) {
		$l = get_term_meta($term_id, '_mml_lang', true);
		return ($l && isset($this->core->langs[$l])) ? $l : $this->core->default;
	}

	public function group($term_id, $create = false) {
		$g = get_term_meta($term_id, '_mml_group', true);
		if (!$g && $create) {
			$g = wp_generate_uuid4();
			update_term_meta($term_id, '_mml_group', $g);
		}
		return $g;
	}

	public function flush() {
		$this->cache = [];
	}

	/** lang => term_id */
	public function translations($term_id) {
		$term_id = (int) $term_id;
		if (isset($this->cache[$term_id])) {
			return $this->cache[$term_id];
		}
		$out = [$this->lang($term_id) => $term_id];
		$g = get_term_meta($term_id, '_mml_group', true);
		$t = get_term($term_id);
		if ($g && $t && !is_wp_error($t)) {
			$ids = get_terms([
				'taxonomy'   => $t->taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_key'   => '_mml_group',
				'meta_value' => $g,
				'mml_skip'   => 1,
			]);
			if (!is_wp_error($ids)) {
				foreach ($ids as $i) {
					$l = $this->lang((int) $i);
					if (!isset($out[$l])) {
						$out[$l] = (int) $i;
					}
				}
			}
		}
		$this->cache[$term_id] = $out;
		return $out;
	}

	public function translation($term_id, $lang) {
		$t = $this->translations($term_id);
		return isset($t[$lang]) ? (int) $t[$lang] : 0;
	}

	/** Archiv-URL eines Begriffs in der angegebenen Sprache ('' bei Fehler) */
	public function link($term_id, $lang = null) {
		$lang = $lang ?: $this->core->lang;
		return (string) $this->core->with_lang($lang, function () use ($term_id) {
			$t = get_term($term_id);
			if (!$t || is_wp_error($t)) {
				return '';
			}
			$u = get_term_link($t);
			return is_wp_error($u) ? '' : $u;
		});
	}

	/* ---------------------------------------------------------------
	 * Frontend: Begriffslisten nach Sprache filtern
	 * ------------------------------------------------------------- */

	public function filter_args($args, $taxonomies) {
		$c = $this->core;
		if (!empty($args['mml_skip']) || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI)) {
			return $args;
		}
		foreach (['include', 'object_ids', 'slug', 'name', 'term_taxonomy_id'] as $k) {
			if (!empty($args[$k])) {
				return $args;
			}
		}
		$taxonomies = (array) $taxonomies;
		if (!$taxonomies || array_diff($taxonomies, $c->taxonomies)) {
			return $args;
		}
		$mq = $c->lang_meta_query($c->lang);
		$args['meta_query'] = !empty($args['meta_query']) ? ['relation' => 'AND', $mq, $args['meta_query']] : $mq;
		return $args;
	}

	/* ---------------------------------------------------------------
	 * Übersetzung anlegen
	 * ------------------------------------------------------------- */

	/** Legt die Übersetzung eines Begriffs an. Gibt Term-ID oder WP_Error zurück. */
	public function duplicate($term_id, $lang, $auto = false) {
		$c = $this->core;
		$src = get_term($term_id);
		if (!$src || is_wp_error($src) || !in_array($src->taxonomy, $c->taxonomies, true) || !isset($c->langs[$lang])) {
			return new WP_Error('mml', __('This term cannot be translated.', 'alba'));
		}
		$existing = $this->translation($term_id, $lang);
		if ($existing) {
			return $existing;
		}

		$from = $this->lang($term_id);
		$tax = $src->taxonomy;
		$name = $src->name;
		$desc = $src->description;

		$slug = $src->slug . '-' . $lang;
		if ($slug === '' || term_exists($slug, $tax)) {
			$slug = ($slug !== '' ? $slug : $src->slug) . '-' . $lang;
		}

		$this->group($term_id, true);
		if (!metadata_exists('term', $term_id, '_mml_lang')) {
			update_term_meta($term_id, '_mml_lang', $from);
		}

		$parent = 0;
		if ($src->parent && is_taxonomy_hierarchical($tax)) {
			$p = $this->translation($src->parent, $lang);
			if (!$p) {
				$r = $this->duplicate($src->parent, $lang, $auto);
				$p = is_wp_error($r) ? 0 : (int) $r;
			}
			$parent = $p;
		}

		$args = ['slug' => $slug, 'description' => $desc, 'parent' => $parent];
		$r = wp_insert_term($name, $tax, $args);
		if (is_wp_error($r) && $r->get_error_code() === 'term_exists') {
			$r = wp_insert_term($name . ' (' . strtoupper($lang) . ')', $tax, $args);
		}
		if (is_wp_error($r)) {
			return $r;
		}
		$new = (int) $r['term_id'];
		update_term_meta($new, '_mml_lang', $lang);
		update_term_meta($new, '_mml_group', $this->group($term_id));
		$this->flush();
		return $new;
	}

	/** Begriffe eines Beitrags auf die Übersetzung übertragen (fehlende bei $auto anlegen) */
	public function copy_post_terms($src_id, $new_id, $lang, $auto = false) {
		foreach ($this->core->taxonomies as $tx) {
			if (!taxonomy_exists($tx) || !is_object_in_taxonomy(get_post_type($src_id), $tx)) {
				continue;
			}
			$ids = wp_get_object_terms($src_id, $tx, ['fields' => 'ids']);
			if (is_wp_error($ids)) {
				continue;
			}
			$map = [];
			foreach ($ids as $tid) {
				$t = $this->translation((int) $tid, $lang);
				if (!$t && $auto) {
					$r = $this->duplicate((int) $tid, $lang, true);
					$t = is_wp_error($r) ? 0 : (int) $r;
				}
				if ($t) {
					$map[] = (int) $t;
				}
			}
			if ($map) {
				wp_set_object_terms($new_id, $map, $tx);
			}
		}
	}

	/* ---------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------- */

	/* ---------------------------------------------------------------
	 * Editor: Sprachfilter für die Begriffsauswahl
	 * ------------------------------------------------------------- */

	private function with_lang_query($args, $lang) {
		$mq = $this->core->lang_meta_query($lang);
		$args['meta_query'] = !empty($args['meta_query']) ? ['relation' => 'AND', $mq, $args['meta_query']] : $mq;
		return $args;
	}

	public function rest_init() {
		foreach ($this->core->taxonomies as $tx) {
			if (!taxonomy_exists($tx)) {
				continue;
			}
			add_filter("rest_{$tx}_query", [$this, 'rest_query'], 10, 2);
			add_action("rest_insert_{$tx}", [$this, 'rest_insert'], 10, 3);
		}
	}

	/** Block-Editor: ?mml_lang=xx filtert die Begriffsliste (bereits zugewiesene per include bleiben unberührt) */
	public function rest_query($args, $request) {
		$lang = sanitize_key((string) $request->get_param('mml_lang'));
		if ($lang === '' || !isset($this->core->langs[$lang]) || !empty($args['include'])) {
			return $args;
		}
		return $this->with_lang_query($args, $lang);
	}

	/** Block-Editor: neu angelegte Begriffe bekommen die Sprache des Beitrags */
	public function rest_insert($term, $request, $creating) {
		if (!$creating) {
			return;
		}
		$lang = sanitize_key((string) $request->get_param('mml_lang'));
		if ($lang !== '' && isset($this->core->langs[$lang])) {
			update_term_meta($term->term_id, '_mml_lang', $lang);
			$this->flush();
		}
	}

	/** Klassischer Editor: Tag-Vorschläge (Ajax) nach Sprache filtern */
	public function admin_args($args, $taxonomies) {
		if (!wp_doing_ajax() || !isset($_GET['action']) || $_GET['action'] !== 'ajax-tag-search' || empty($_GET['mml_lang']) || !empty($args['mml_skip'])) {
			return $args;
		}
		$lang = sanitize_key(wp_unslash($_GET['mml_lang']));
		$taxonomies = (array) $taxonomies;
		if (!isset($this->core->langs[$lang]) || !$taxonomies || array_diff($taxonomies, $this->core->taxonomies)) {
			return $args;
		}
		return $this->with_lang_query($args, $lang);
	}

	/** Begriffe, die beim Speichern eines Beitrags oder per Schnell-Hinzufügen entstehen, übernehmen die Editor-Sprache */
	public function auto_lang($term_id) {
		if (metadata_exists('term', $term_id, '_mml_lang')) {
			return;
		}
		$lang = '';
		if (!empty($_REQUEST['mml_lang'])) {
			$l = sanitize_key(wp_unslash($_REQUEST['mml_lang']));
			if (isset($this->core->langs[$l])) {
				$lang = $l;
			}
		}
		if ($lang === '' && wp_doing_ajax()) {
			$ref = wp_get_referer();
			if ($ref) {
				parse_str((string) wp_parse_url($ref, PHP_URL_QUERY), $q);
				$pid = isset($q['post']) ? (int) $q['post'] : 0;
				if ($pid) {
					$lang = $this->core->post_lang($pid);
				}
			}
		}
		if ($lang !== '') {
			update_term_meta($term_id, '_mml_lang', $lang);
			$this->flush();
		}
	}

	public function enqueue($hook) {
		if ($hook !== 'post.php' && $hook !== 'post-new.php') {
			return;
		}
		$screen = get_current_screen();
		$c = $this->core;
		if (!$screen || !$c->is_translatable($screen->post_type)) {
			return;
		}
		$taxes = array_values(array_intersect(get_object_taxonomies($screen->post_type), $c->taxonomies));
		if (!$taxes) {
			return;
		}

		$post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
		$req = isset($_GET['mml_lang']) ? sanitize_key(wp_unslash($_GET['mml_lang'])) : '';
		$lang = $post_id ? $c->post_lang($post_id) : (isset($c->langs[$req]) ? $req : $c->default);

		$bases = [];
		$terms = [];
		foreach ($taxes as $tx) {
			$obj = get_taxonomy($tx);
			$bases[] = $obj->rest_base ? $obj->rest_base : $tx;
			if ($obj->hierarchical) {
				$ids = get_terms(['taxonomy' => $tx, 'hide_empty' => false, 'fields' => 'ids', 'mml_skip' => 1]);
				$map = [];
				if (!is_wp_error($ids)) {
					foreach ($ids as $id) {
						$map[(string) $id] = $this->lang((int) $id);
					}
				}
				$terms[$tx] = (object) $map;
			}
		}

		wp_enqueue_script('mml-editor', MML_URL . 'assets/editor.js', ['jquery', 'wp-api-fetch'], MML_VERSION, true);
		wp_add_inline_script('mml-editor', 'window.mmlEditor = ' . wp_json_encode([
			'lang'  => $lang,
			'bases' => $bases,
			'terms' => (object) $terms,
		]) . ';', 'before');
	}

	public function admin_init() {
		add_filter('get_terms_args', [$this, 'admin_args'], 10, 2);
		foreach ($this->core->taxonomies as $tx) {
			if (!taxonomy_exists($tx)) {
				continue;
			}
			add_action("{$tx}_add_form_fields", [$this, 'field_add']);
			add_action("{$tx}_edit_form_fields", [$this, 'field_edit'], 10, 2);
			add_action("created_{$tx}", [$this, 'save_term']);
			add_action("edited_{$tx}", [$this, 'save_term']);
			add_action("created_{$tx}", [$this, 'auto_lang'], 20);
			add_filter("manage_edit-{$tx}_columns", [$this, 'col_head']);
			add_filter("manage_{$tx}_custom_column", [$this, 'col_body'], 10, 3);
		}
	}

	private function new_url($term_id, $lang, $auto) {
		return wp_nonce_url(
			admin_url('admin-post.php?action=mml_new_term&term=' . (int) $term_id . '&lang=' . rawurlencode($lang) . ($auto ? '&auto=1' : '')),
			'mml_term_new_' . (int) $term_id . '_' . $lang . ($auto ? '_auto' : '')
		);
	}

	public function col_head($cols) {
		$cols['mml'] = __('Languages', 'alba');
		return $cols;
	}

	public function col_body($content, $column, $term_id) {
		if ($column !== 'mml') {
			return $content;
		}
		$t = get_term($term_id);
		if (!$t || is_wp_error($t)) {
			return $content;
		}
		$tr = $this->translations($term_id);
		$html = '';
		foreach ($this->core->langs as $code => $l) {
			if (isset($tr[$code])) {
				$html .= '<a class="mml-pill mml-has" href="' . esc_url(get_edit_term_link($tr[$code], $t->taxonomy)) . '" title="' . esc_attr($l['name']) . '">' . esc_html(strtoupper($code)) . '</a>';
			} else {
				$html .= '<a class="mml-pill mml-add" href="' . esc_url($this->new_url($term_id, $code, false)) . '" title="' . esc_attr(__('Create translation: ', 'alba') . $l['name']) . '">+ ' . esc_html(strtoupper($code)) . '</a>';
			}
		}
		return $html;
	}

	private function lang_select($current) {
		$html = '<select name="mml_term_lang" id="mml_term_lang">';
		foreach ($this->core->langs as $code => $l) {
			$html .= '<option value="' . esc_attr($code) . '"' . selected($current, $code, false) . '>' . esc_html($l['name']) . '</option>';
		}
		return $html . '</select>';
	}

	public function field_add($taxonomy) {
		wp_nonce_field('mml_term_new', 'mml_term_nonce');
		echo '<div class="form-field"><label for="mml_term_lang">' . esc_html__('Language', 'alba') . '</label>' . $this->lang_select($this->core->default) . '<p>' . esc_html__('Language of this term. You create translations after saving.', 'alba') . '</p></div>';
	}

	public function field_edit($term, $taxonomy) {
		$c = $this->core;
		wp_nonce_field('mml_term_' . $term->term_id, 'mml_term_nonce');
		$cur = $this->lang($term->term_id);
		$tr = $this->translations($term->term_id);

		echo '<tr class="form-field"><th scope="row"><label for="mml_term_lang">' . esc_html__('Language', 'alba') . '</label></th><td>' . $this->lang_select($cur) . '</td></tr>';
		echo '<tr class="form-field"><th scope="row">' . esc_html__('Translations', 'alba') . '</th><td>';
		foreach ($c->langs as $code => $l) {
			if ($code === $cur) {
				continue;
			}
			echo '<p><strong>' . esc_html($l['name']) . ':</strong> ';
			if (isset($tr[$code])) {
				$t = get_term($tr[$code]);
				echo '<a href="' . esc_url(get_edit_term_link($tr[$code], $taxonomy)) . '">' . esc_html($t && !is_wp_error($t) ? $t->name : '#' . $tr[$code]) . '</a>';
			} else {
				echo '<a class="button button-small" href="' . esc_url($this->new_url($term->term_id, $code, false)) . __('">+ Create translation</a>', 'alba');
			}
			echo '</p>';
		}
		echo '</td></tr>';
	}

	public function save_term($term_id) {
		if (!isset($_POST['mml_term_nonce'])) {
			return;
		}
		$n = sanitize_text_field(wp_unslash($_POST['mml_term_nonce']));
		if (!wp_verify_nonce($n, 'mml_term_new') && !wp_verify_nonce($n, 'mml_term_' . $term_id)) {
			return;
		}
		$t = get_term($term_id);
		if (!$t || is_wp_error($t) || !in_array($t->taxonomy, $this->core->taxonomies, true)) {
			return;
		}
		$tax = get_taxonomy($t->taxonomy);
		if (!$tax || !current_user_can($tax->cap->edit_terms)) {
			return;
		}
		$lang = isset($_POST['mml_term_lang']) ? sanitize_key(wp_unslash($_POST['mml_term_lang'])) : '';
		if (!isset($this->core->langs[$lang])) {
			$lang = $this->core->default;
		}
		update_term_meta($term_id, '_mml_lang', $lang);
		$this->group($term_id, true);
		$this->flush();
	}

	public function handle_new() {
		$id = isset($_GET['term']) ? (int) $_GET['term'] : 0;
		$lang = isset($_GET['lang']) ? sanitize_key(wp_unslash($_GET['lang'])) : '';
		$auto = !empty($_GET['auto']);
		check_admin_referer('mml_term_new_' . $id . '_' . $lang . ($auto ? '_auto' : ''));

		$t = $id ? get_term($id) : null;
		if (!$t || is_wp_error($t) || !in_array($t->taxonomy, $this->core->taxonomies, true) || !isset($this->core->langs[$lang])) {
			wp_die(__('Not allowed.', 'alba'));
		}
		$tax = get_taxonomy($t->taxonomy);
		if (!$tax || !current_user_can($tax->cap->edit_terms)) {
			wp_die(__('Not allowed.', 'alba'));
		}
		@set_time_limit(120);
		$r = $this->duplicate($id, $lang, $auto);
		if (is_wp_error($r)) {
			wp_die(esc_html($r->get_error_message()));
		}
		wp_safe_redirect(get_edit_term_link($r, $t->taxonomy));
		exit;
	}
}
