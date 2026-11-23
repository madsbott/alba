<?php
if (!defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table names come from $wpdb or a fixed helper; every value goes through $wpdb->prepare().

/**
 * Bereiche pro Sprache: Suche, Kommentare, Widgets/Blöcke und Menüs (z. B. Footer-Menüs).
 *
 * - Suche: im Frontend nur Inhalte der aktuellen Sprache; Übersetzungen aus dem Links-rechts-Editor werden mitdurchsucht
 *   (Suchindex in _mml_srch_{lang}, nicht das Original).
 * - Kommentare: jeder Kommentar merkt sich die Sprache (Kommentar-Meta _mml_lang). Im Frontend sieht man nur die Kommentare der
 *   eigenen Sprache, auch die Anzahl stimmt. Kommentare ohne Angabe zählen zur Sprache ihres Beitrags.
 * - Widgets: klassische Widgets bekommen „Sprachen“-Auswahl; für Blöcke (auch Block-Widgets) gibt es in der Seitenleiste
 *   ein Feld „Sprachen“ (Attribut mmlLangs).
 * - Menüs: pro Menü (und pro Navigation im Block-Theme) lässt sich je Sprache ein anderes Menü zuordnen. Das gilt für
 *   Theme-Positionen, Navigations-Menü-Widgets und wp_nav_menu() mit festem Menü.
 */
class MML_Areas {
	const SRCH = '_mml_srch_';
	const PAGE = 'alba-settings';

	private $core;
	private $map_cache = null;

	public function __construct(MML_Core $core) {
		$this->core = $core;
		$core->areas = $this;

		// Suchindex: immer, wenn eine Übersetzung gespeichert wird
		add_action('mml_translation_saved', [$this, 'index_post'], 10, 2);
		// Sprache des Kommentars festhalten (Frontend, aber auch Antworten im Backend)
		add_action('comment_post', [$this, 'comment_saved'], 10, 2);

		if (is_admin()) {
			add_action('admin_init', [$this, 'register']);
			add_action('admin_init', [$this, 'backfill']);
			add_action('admin_post_mml_menu_copy', [$this, 'copy_menu']);
			add_filter('manage_edit-comments_columns', [$this, 'comment_col']);
			add_action('manage_comments_custom_column', [$this, 'comment_col_body'], 10, 2);
			add_action('in_widget_form', [$this, 'widget_form'], 10, 3);
			add_filter('widget_update_callback', [$this, 'widget_update'], 10, 4);
			add_action('enqueue_block_editor_assets', [$this, 'editor_assets']);
			return;
		}

		add_filter('posts_search', [$this, 'posts_search'], 20, 2);

		add_action('comment_form', [$this, 'comment_field']);
		add_action('pre_get_comments', [$this, 'comments_query']);
		add_filter('get_comments_number', [$this, 'comments_number'], 20, 2);
		add_filter('comment_post_redirect', [$this, 'comment_redirect'], 20, 2);

		add_filter('widget_display_callback', [$this, 'widget_display'], 10, 3);
		add_filter('render_block', [$this, 'render_block'], 10, 2);

		add_filter('theme_mod_nav_menu_locations', [$this, 'locations']);
		add_filter('wp_nav_menu_args', [$this, 'nav_args'], 1);
		add_filter('render_block_data', [$this, 'nav_ref'], 5);
	}

	/* ---------------------------------------------------------------
	 * Einstellungen
	 * ------------------------------------------------------------- */

	public static function search_on() {
		return get_option('mml_search', '1') !== '0';
	}

	public static function comments_split() {
		return get_option('mml_comments', 'split') !== 'all';
	}

	public function register() {
		register_setting('mml', 'mml_search', ['sanitize_callback' => function ($v) {
			return $v === '0' ? '0' : '1';
		}]);
		register_setting('mml', 'mml_comments', ['sanitize_callback' => function ($v) {
			return $v === 'all' ? 'all' : 'split';
		}]);
		register_setting('mml', 'mml_menu_map', ['sanitize_callback' => [$this, 'sanitize_map']]);
	}

	public function sanitize_map($v) {
		$out = ['menu' => [], 'nav' => []];
		if (!is_array($v)) {
			return $out;
		}
		$langs = array_diff_key($this->core->langs, [$this->core->default => 1]);
		foreach (['menu', 'nav'] as $k) {
			if (empty($v[$k]) || !is_array($v[$k])) {
				continue;
			}
			foreach ($v[$k] as $from => $by) {
				$from = (int) $from;
				if (!$from || !is_array($by) || !$this->menu_exists($k, $from)) {
					continue;
				}
				foreach ($by as $l => $to) {
					$l = sanitize_key($l);
					$to = (int) $to;
					if (!$to || $to === $from || !isset($langs[$l]) || !$this->menu_exists($k, $to)) {
						continue;
					}
					$out[$k][$from][$l] = $to;
				}
			}
		}
		return $out;
	}

	private function menu_exists($kind, $id) {
		if ($kind === 'menu') {
			$m = wp_get_nav_menu_object((int) $id);
			return $m && !is_wp_error($m);
		}
		$p = get_post((int) $id);
		return $p && $p->post_type === 'wp_navigation';
	}

	private function map() {
		if ($this->map_cache === null) {
			$m = get_option('mml_menu_map', []);
			$this->map_cache = [
				'menu' => (is_array($m) && !empty($m['menu']) && is_array($m['menu'])) ? $m['menu'] : [],
				'nav'  => (is_array($m) && !empty($m['nav']) && is_array($m['nav'])) ? $m['nav'] : [],
			];
		}
		return $this->map_cache;
	}

	/** Karten für den Einstellungs-Tab „Suche, Kommentare & Menüs“ */
	public function cards() {
		$c = $this->core;
		$targets = array_diff_key($c->langs, [$c->default => 1]);
		?>
		<section class="mml-card" data-tab="areas">
			<header class="mml-card-h"><span class="dashicons dashicons-search"></span><div><h2><?php echo esc_html__('Search', 'alba'); ?></h2><p><?php echo esc_html__('What visitors find in their language.', 'alba'); ?></p></div></header>
			<div class="mml-card-b"><div class="mml-fields">
				<div class="mml-field"><div class="mml-label"><?php echo esc_html__('Search results', 'alba'); ?></div><div class="mml-ctl">
					<select name="mml_search">
						<option value="1" <?php selected(self::search_on() ? '1' : '0', '1'); ?>><?php echo esc_html__('Current language only, include translation texts in the search', 'alba'); ?></option>
						<option value="0" <?php selected(self::search_on() ? '1' : '0', '0'); ?>><?php echo esc_html__('Current language only, search original texts only', 'alba'); ?></option>
					</select>
					<p class="mml-hint"><?php echo esc_html__('On the frontend, search only ever shows content in the visitor\'s current language. With the first setting, the English search also finds words from translations in the side-by-side editor (title, excerpt, texts), not the German original text. The search form stays in the language because the address carries the language prefix (', 'alba'); ?><code>/en/?s=…</code>).</p>
				</div></div>
			</div></div>
		</section>

		<section class="mml-card" data-tab="areas">
			<header class="mml-card-h"><span class="dashicons dashicons-admin-comments"></span><div><h2><?php echo esc_html__('Comments', 'alba'); ?></h2><p><?php echo esc_html__('Comments separated by language.', 'alba'); ?></p></div></header>
			<div class="mml-card-b"><div class="mml-fields">
				<div class="mml-field"><div class="mml-label"><?php echo esc_html__('Display', 'alba'); ?></div><div class="mml-ctl">
					<select name="mml_comments">
						<option value="split" <?php selected(self::comments_split() ? 'split' : 'all', 'split'); ?>><?php echo esc_html__('Separate per language', 'alba'); ?></option>
						<option value="all" <?php selected(self::comments_split() ? 'split' : 'all', 'all'); ?>><?php echo esc_html__('Show all comments in all languages', 'alba'); ?></option>
					</select>
					<p class="mml-hint"><?php echo esc_html__('New comments remember the language of the page they were written on. Visitors only see and count the comments of their own language (comment list, count, "Recent comments"). Existing comments without a language belong to the language of their post, which for side-by-side translations means the default language. In the backend, the comment list shows a "Language" column.', 'alba'); ?></p>
				</div></div>
			</div></div>
		</section>

		<section class="mml-card" data-tab="areas">
			<header class="mml-card-h"><span class="dashicons dashicons-welcome-widgets-menus"></span><div><h2><?php echo esc_html__('Widgets and blocks', 'alba'); ?></h2><p><?php echo esc_html__('Show content only in certain languages.', 'alba'); ?></p></div></header>
			<div class="mml-card-b">
				<p><strong><?php echo esc_html__('Classic widgets', 'alba'); ?></strong> <?php echo esc_html__('(Appearance → Widgets): Every widget has a "Languages" field at the bottom. Nothing ticked = visible in all languages.', 'alba'); ?></p>
				<p><strong><?php echo esc_html__('Blocks', 'alba'); ?></strong> <?php echo esc_html__('(editor, block widgets, Site Editor): In the block tab of the sidebar, every block has a "Languages" section. Use it to show a footer text or a group for one language only. For other languages, simply create a second block.', 'alba'); ?></p>
				<p class="mml-hint"><?php echo esc_html__('The language switcher itself and all widgets without a selection remain unchanged.', 'alba'); ?></p>
			</div>
		</section>

		<section class="mml-card" data-tab="areas">
			<header class="mml-card-h"><span class="dashicons dashicons-menu"></span><div><h2><?php echo esc_html__('Menus per language', 'alba'); ?></h2><p><?php echo esc_html__('For example, a separate footer menu for each language.', 'alba'); ?></p></div></header>
			<div class="mml-card-b">
				<?php if (!empty($_GET['mml_cn'])) :
					$ck = isset($_GET['mml_ck']) && $_GET['mml_ck'] === 'nav' ? 'nav' : 'menu';
					$ci = isset($_GET['mml_ci']) ? (int) $_GET['mml_ci'] : 0;
					$edit = $ck === 'nav' ? admin_url('site-editor.php?postType=wp_navigation&postId=' . $ci . '&canvas=edit') : admin_url('nav-menus.php?action=edit&menu=' . $ci);
					?>
					<div class="notice notice-success inline"><p>„<?php echo esc_html(sanitize_text_field(wp_unslash($_GET['mml_cn']))); ?><?php echo esc_html__('" was created as a copy and assigned to the language.', 'alba'); ?> <a href="<?php echo esc_url($edit); ?>"><?php echo esc_html__('Edit menu items now', 'alba'); ?></a></p></div>
				<?php endif; ?>
				<p><?php echo esc_html__('Choose, per menu and language, which other menu appears instead. This applies to theme locations (e.g. footer), navigation menu widgets and navigation blocks. "Create copy" creates a menu with the same items and assigns it; after that you only change what differs. Linked pages automatically point to their translation.', 'alba'); ?></p>
				<?php
				$rows = $this->menu_rows();
				if (!$rows) :
					?>
					<p><em><?php echo esc_html__('There are no menus yet. Create one under Appearance → Menus.', 'alba'); ?></em></p>
				<?php elseif (!$targets) : ?>
					<p><em><?php echo esc_html__('First add a second language.', 'alba'); ?></em></p>
				<?php else :
					$map = $this->map();
					?>
					<table class="widefat striped mml-langs">
						<thead><tr><th><?php echo esc_html__('Menu', 'alba'); ?></th><th><?php echo esc_html__('Used in', 'alba'); ?></th><?php foreach ($targets as $code => $l) : ?><th><?php echo esc_html($l['name']); ?></th><?php endforeach; ?></tr></thead>
						<tbody>
						<?php foreach ($rows as $r) : ?>
							<tr>
								<td><strong><?php echo esc_html($r['name']); ?></strong><?php echo $r['kind'] === 'nav' ? ' <span class="description">(Navigation)</span>' : ''; ?></td>
								<td><?php echo $r['where'] !== '' ? esc_html($r['where']) : '–'; ?></td>
								<?php foreach ($targets as $code => $l) :
									$cur = isset($map[$r['kind']][$r['id']][$code]) ? (int) $map[$r['kind']][$r['id']][$code] : 0;
									?>
									<td>
										<select name="mml_menu_map[<?php echo esc_attr($r['kind']); ?>][<?php echo (int) $r['id']; ?>][<?php echo esc_attr($code); ?>]">
											<option value=""><?php echo esc_html__('– like original –', 'alba'); ?></option>
											<?php foreach ($rows as $o) {
												if ($o['kind'] !== $r['kind'] || $o['id'] === $r['id']) {
													continue;
												}
												echo '<option value="' . (int) $o['id'] . '"' . selected($cur, $o['id'], false) . '>' . esc_html($o['name']) . '</option>';
											} ?>
										</select>
										<?php if (!$cur) : ?>
											<br><a href="<?php echo esc_url($this->copy_url($r['kind'], $r['id'], $code)); ?>"><?php echo esc_html__('Create copy', 'alba'); ?></a>
										<?php endif; ?>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="mml-hint"><?php echo esc_html__('Changes to the selection take effect after "Save changes". "Create copy" saves immediately and reloads the page; unsaved changes next to it are lost.', 'alba'); ?></p>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	private function menu_rows() {
		$rows = [];
		$locs = get_registered_nav_menus();
		$assigned = get_nav_menu_locations();
		foreach (wp_get_nav_menus() as $m) {
			$where = [];
			foreach ((array) $assigned as $loc => $mid) {
				if ((int) $mid === (int) $m->term_id && isset($locs[$loc])) {
					$where[] = $locs[$loc];
				}
			}
			$rows[] = ['kind' => 'menu', 'id' => (int) $m->term_id, 'name' => $m->name, 'where' => implode(', ', $where)];
		}
		$navs = get_posts(['post_type' => 'wp_navigation', 'post_status' => ['publish', 'draft'], 'posts_per_page' => 100, 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true, 'suppress_filters' => true, 'mml_skip' => 1]);
		foreach ($navs as $p) {
			$rows[] = ['kind' => 'nav', 'id' => (int) $p->ID, 'name' => $p->post_title !== '' ? $p->post_title : __('Navigation #', 'alba') . $p->ID, 'where' => ''];
		}
		return $rows;
	}

	private function copy_url($kind, $id, $lang) {
		return wp_nonce_url(add_query_arg(['action' => 'mml_menu_copy', 'kind' => $kind, 'id' => (int) $id, 'lang' => $lang], admin_url('admin-post.php')), 'mml_menu_copy');
	}

	public function copy_menu() {
		check_admin_referer('mml_menu_copy');
		if (!current_user_can('edit_theme_options')) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		$c = $this->core;
		$kind = isset($_GET['kind']) && $_GET['kind'] === 'nav' ? 'nav' : 'menu';
		$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
		$lang = isset($_GET['lang']) ? sanitize_key(wp_unslash($_GET['lang'])) : '';
		if (!isset($c->langs[$lang]) || $lang === $c->default || !$this->menu_exists($kind, $id)) {
			wp_die(esc_html__('Invalid input.', 'alba'));
		}
		$suffix = ' (' . strtoupper($lang) . ')';
		if ($kind === 'menu') {
			$src = wp_get_nav_menu_object($id);
			$name = $src->name . $suffix;
			for ($i = 2; wp_get_nav_menu_object($name); $i++) {
				$name = $src->name . $suffix . ' ' . $i;
			}
			$new = wp_update_nav_menu_object(0, ['menu-name' => $name]);
			if (is_wp_error($new)) {
				wp_die(esc_html($new->get_error_message()));
			}
			$ids = [];
			foreach ((array) wp_get_nav_menu_items($id, ['post_status' => 'publish']) as $it) {
				$parent = ($it->menu_item_parent && isset($ids[(int) $it->menu_item_parent])) ? $ids[(int) $it->menu_item_parent] : 0;
				$nid = wp_update_nav_menu_item($new, 0, [
					'menu-item-object-id'   => $it->object_id,
					'menu-item-object'      => $it->object,
					'menu-item-parent-id'   => $parent,
					'menu-item-position'    => $it->menu_order,
					'menu-item-type'        => $it->type,
					'menu-item-title'       => $it->title,
					'menu-item-url'         => $it->url,
					'menu-item-description' => $it->description,
					'menu-item-attr-title'  => $it->attr_title,
					'menu-item-target'      => $it->target,
					'menu-item-classes'     => implode(' ', (array) $it->classes),
					'menu-item-xfn'         => $it->xfn,
					'menu-item-status'      => 'publish',
				]);
				if (!is_wp_error($nid)) {
					$ids[(int) $it->ID] = (int) $nid;
				}
			}
		} else {
			$src = get_post($id);
			$name = ($src->post_title !== '' ? $src->post_title : __('Navigation', 'alba')) . $suffix;
			$new = wp_insert_post(['post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => $name, 'post_content' => $src->post_content], true);
			if (is_wp_error($new)) {
				wp_die(esc_html($new->get_error_message()));
			}
		}
		$m = $this->map();
		$m[$kind][$id][$lang] = (int) $new;
		update_option('mml_menu_map', $m);
		$this->map_cache = null;
		if ($c->page_cache && method_exists($c->page_cache, 'purge_all')) {
			$c->page_cache->purge_all();
		}
		wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'mml_cn' => rawurlencode($name), 'mml_ck' => $kind, 'mml_ci' => (int) $new], admin_url('admin.php')) . '#areas');
		exit;
	}

	/* ---------------------------------------------------------------
	 * Suche
	 * ------------------------------------------------------------- */

	/** Durchsuchbarer Text einer Editor-Übersetzung */
	public function index_post($id, $lang = '') {
		$e = $this->core->editor;
		$id = (int) $id;
		if (!$e || !$id || !isset($this->core->langs[$lang])) {
			return;
		}
		$d = $e->data($id, $lang);
		$bits = [$d['title'], $d['excerpt'], $d['seo_title'], $d['seo_desc']];
		foreach ((array) $d['seg'] as $t) {
			$bits[] = (string) $t;
		}
		$bits = array_filter($bits, function ($b) {
			return trim((string) $b) !== '';
		});
		$txt = trim(html_entity_decode(wp_strip_all_tags(implode("\n", $bits), true), ENT_QUOTES, 'UTF-8'));
		$txt = trim(preg_replace('/%%[a-z0-9_]+%%/i', '', $txt));   // SEO-Variablen (Yoast) nicht mitindizieren
		if ($txt === '') {
			delete_post_meta($id, self::SRCH . $lang);
		} else {
			update_post_meta($id, self::SRCH . $lang, wp_slash($txt));
		}
	}

	/** Einmalig: Index für bereits gespeicherte Übersetzungen aufbauen */
	public function backfill() {
		if (!current_user_can('manage_options') || wp_doing_ajax()) {
			return;
		}
		global $wpdb;
		if (get_option('mml_cmt_v') !== '1') {
			// Kommentare auf Übersetzungs-Beiträgen gehören zur Sprache des Beitrags
			$rows = $wpdb->get_results($wpdb->prepare(
				"SELECT c.comment_ID AS id, pm.meta_value AS lang FROM {$wpdb->comments} c
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = c.comment_post_ID AND pm.meta_key = '_mml_lang' AND pm.meta_value <> ''
				 LEFT JOIN {$wpdb->commentmeta} cm ON cm.comment_id = c.comment_ID AND cm.meta_key = %s
				 WHERE cm.meta_id IS NULL", '_mml_lang'
			));
			foreach ((array) $rows as $r) {
				if (isset($this->core->langs[$r->lang])) {
					add_comment_meta((int) $r->id, '_mml_lang', $r->lang, true);
				}
			}
			update_option('mml_cmt_v', '1', false);
		}
		if (get_option('mml_srch_v') === '1') {
			return;
		}
		$rows = $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like('_mml_tr_') . '%'));
		foreach ((array) $rows as $r) {
			$lang = substr($r->meta_key, strlen('_mml_tr_'));
			$this->index_post((int) $r->post_id, $lang);
		}
		update_option('mml_srch_v', '1', false);
	}

	public function posts_search($search, $q) {
		$c = $this->core;
		if ($search === '' || !self::search_on() || $c->lang === $c->default || $q->get('mml_skip')) {
			return $search;
		}
		if (wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI)) {
			return $search;
		}
		$terms = $q->get('search_terms');
		if (!is_array($terms) || !$terms) {
			return $search;
		}
		global $wpdb;
		$p = $wpdb->posts;
		$lang = $c->lang;
		$n = $q->get('exact') ? '' : '%';
		$own = $wpdb->prepare("EXISTS (SELECT 1 FROM {$wpdb->postmeta} ml WHERE ml.post_id = {$p}.ID AND ml.meta_key = '_mml_lang' AND ml.meta_value = %s)", $lang);
		$parts = [];
		foreach ($terms as $t) {
			$include = ('-' !== substr($t, 0, 1));
			if (!$include) {
				$t = substr($t, 1);
			}
			$like = $n . $wpdb->esc_like($t) . $n;
			$orig = $wpdb->prepare("({$p}.post_title LIKE %s OR {$p}.post_excerpt LIKE %s OR {$p}.post_content LIKE %s)", $like, $like, $like);
			$idx = $wpdb->prepare("EXISTS (SELECT 1 FROM {$wpdb->postmeta} ms WHERE ms.post_id = {$p}.ID AND ms.meta_key = %s AND ms.meta_value LIKE %s)", self::SRCH . $lang, $like);
			$m = "(($own AND $orig) OR $idx)";
			$parts[] = $include ? $m : 'NOT ' . $m;
		}
		$out = ' AND (' . implode(' AND ', $parts) . ')';
		if (!is_user_logged_in()) {
			$out .= " AND ({$p}.post_password = '') ";
		}
		return $out;
	}

	/* ---------------------------------------------------------------
	 * Kommentare
	 * ------------------------------------------------------------- */

	public function comment_field() {
		echo '<input type="hidden" name="mml_lang" value="' . esc_attr($this->core->lang) . '">' . "\n";
	}

	/** Sprache aus einer Adresse (Referer) lesen: /en/seite/ -> en */
	private function lang_from_url($url) {
		$c = $this->core;
		$path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
		$home = rtrim((string) wp_parse_url(get_option('home'), PHP_URL_PATH), '/');
		if ($home !== '' && strpos($path, $home) === 0) {
			$path = substr($path, strlen($home));
		}
		if (preg_match('#^/([^/]+)(/|$)#', $path, $m)) {
			$code = strtolower(urldecode($m[1]));
			if (isset($c->langs[$code])) {
				return $code;
			}
		}
		return $c->default;
	}

	public function comment_saved($comment_id, $approved = null) {
		$c = $this->core;
		$comment = get_comment($comment_id);
		if (!$comment) {
			return;
		}
		$lang = '';
		if (!empty($_POST['mml_lang'])) {
			$l = sanitize_key(wp_unslash($_POST['mml_lang']));
			$lang = isset($c->langs[$l]) ? $l : '';
		}
		if ($lang === '' && $comment->comment_parent) {
			$pl = get_comment_meta((int) $comment->comment_parent, '_mml_lang', true);
			$lang = isset($c->langs[$pl]) ? $pl : '';
		}
		if ($lang === '' && !is_admin()) {
			$ref = wp_get_referer();
			$lang = $ref ? $this->lang_from_url($ref) : '';
		}
		if ($lang === '') {
			$lang = $c->post_lang((int) $comment->comment_post_ID);
		}
		update_comment_meta($comment_id, '_mml_lang', $lang);
	}

	/**
	 * Kommentar-Abfragen im Frontend auf die aktuelle Sprache einschränken. Das geschieht über eine meta_query, damit die Sprache
	 * im Cache-Schlüssel von WordPress steht (wichtig bei persistentem Object-Cache wie Redis).
	 * Kommentare ohne Sprachangabe zählen zur Standardsprache (Kommentare auf Übersetzungs-Beiträgen werden einmalig nachgetragen).
	 */
	public function comments_query($q) {
		$c = $this->core;
		if (is_admin() || !self::comments_split() || (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_cron()) {
			return;
		}
		if (!empty($q->query_vars['mml_all']) || !isset($c->langs[$c->lang])) {
			return;
		}
		if ($c->lang === $c->default) {
			$mine = ['relation' => 'OR', ['key' => '_mml_lang', 'value' => $c->lang], ['key' => '_mml_lang', 'compare' => 'NOT EXISTS']];
		} else {
			$mine = [['key' => '_mml_lang', 'value' => $c->lang]];
		}
		$old = isset($q->query_vars['meta_query']) ? $q->query_vars['meta_query'] : [];
		$q->query_vars['meta_query'] = $old ? ['relation' => 'AND', $old, $mine] : $mine;
	}

	public function comments_number($count, $post_id) {
		static $memo = [];
		$c = $this->core;
		if (is_admin() || !self::comments_split() || !isset($c->langs[$c->lang])) {
			return $count;
		}
		$k = $c->lang . ':' . (int) $post_id;
		if (!isset($memo[$k])) {
			$memo[$k] = (int) get_comments(['post_id' => (int) $post_id, 'status' => 'approve', 'count' => true]);
		}
		return $memo[$k];
	}

	public function comment_redirect($location, $comment) {
		$c = $this->core;
		$lang = get_comment_meta((int) $comment->comment_ID, '_mml_lang', true);
		if ($lang && $lang !== $c->default && isset($c->langs[$lang])) {
			return $c->with_lang($lang, function () use ($comment) {
				return get_comment_link($comment);
			});
		}
		return $location;
	}

	public function comment_col($cols) {
		$out = [];
		foreach ($cols as $k => $v) {
			$out[$k] = $v;
			if ($k === 'comment') {
				$out['mml_lang'] = __('Language', 'alba');
			}
		}
		return $out;
	}

	public function comment_col_body($col, $comment_id) {
		if ($col !== 'mml_lang') {
			return;
		}
		$c = $this->core;
		$l = get_comment_meta((int) $comment_id, '_mml_lang', true);
		if (!$l || !isset($c->langs[$l])) {
			$cm = get_comment($comment_id);
			$l = $cm ? $c->post_lang((int) $cm->comment_post_ID) : $c->default;
		}
		echo esc_html(isset($c->langs[$l]) ? $c->langs[$l]['name'] : $l);
	}

	/* ---------------------------------------------------------------
	 * Widgets und Blöcke
	 * ------------------------------------------------------------- */

	public function widget_form($widget, $return, $instance) {
		$c = $this->core;
		if (count($c->langs) < 2) {
			return;
		}
		$sel = (isset($instance['mml_langs']) && is_array($instance['mml_langs'])) ? $instance['mml_langs'] : [];
		echo '<p class="mml-wl" style="border-top:1px solid #dcdcde;padding-top:8px"><strong>' . esc_html__('Languages', 'alba') . '</strong><br>';
		foreach ($c->langs as $code => $l) {
			echo '<label style="margin-right:10px"><input type="checkbox" name="' . esc_attr($widget->get_field_name('mml_langs')) . '[]" value="' . esc_attr($code) . '"' . checked(in_array($code, $sel, true), true, false) . '> ' . esc_html($l['name']) . '</label>';
		}
		echo '<br><span class="description">' . esc_html__('Nothing selected: visible in all languages.', 'alba') . '</span></p>';
	}

	public function widget_update($instance, $new, $old, $widget) {
		if (!is_array($instance)) {
			return $instance;
		}
		$sel = (isset($new['mml_langs']) && is_array($new['mml_langs'])) ? array_map('sanitize_key', $new['mml_langs']) : [];
		$sel = array_values(array_intersect($sel, array_keys($this->core->langs)));
		if ($sel && count($sel) < count($this->core->langs)) {
			$instance['mml_langs'] = $sel;
		} else {
			unset($instance['mml_langs']);
		}
		return $instance;
	}

	public function widget_display($instance, $widget, $args) {
		if (is_array($instance) && !empty($instance['mml_langs']) && is_array($instance['mml_langs']) && !in_array($this->core->lang, $instance['mml_langs'], true)) {
			return false;
		}
		return $instance;
	}

	public function render_block($content, $block) {
		if (empty($block['attrs']['mmlLangs']) || !is_array($block['attrs']['mmlLangs'])) {
			return $content;
		}
		if (wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI)) {
			return $content;
		}
		return in_array($this->core->lang, $block['attrs']['mmlLangs'], true) ? $content : '';
	}

	public function editor_assets() {
		$c = $this->core;
		if (count($c->langs) < 2) {
			return;
		}
		wp_enqueue_script('mml-visibility', MML_URL . 'assets/visibility.js', ['mml-i18n', 'wp-hooks', 'wp-compose', 'wp-element', 'wp-block-editor', 'wp-components'], MML_VERSION, true);
		$langs = [];
		foreach ($c->langs as $code => $l) {
			$langs[] = ['code' => $code, 'name' => $l['name']];
		}
		wp_add_inline_script('mml-visibility', 'window.mmlVis = ' . wp_json_encode(['langs' => $langs]) . ';', 'before');
	}

	/* ---------------------------------------------------------------
	 * Menüs
	 * ------------------------------------------------------------- */

	private function skip() {
		return is_admin() || is_customize_preview() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI);
	}

	private function mapped($kind, $id) {
		$m = $this->map();
		$lang = $this->core->lang;
		$id = (int) $id;
		if ($lang !== $this->core->default && isset($m[$kind][$id][$lang])) {
			$to = (int) $m[$kind][$id][$lang];
			if ($to && $this->menu_exists($kind, $to)) {
				return $to;
			}
		}
		return $id;
	}

	public function locations($locs) {
		if ($this->skip() || !is_array($locs) || !$locs) {
			return $locs;
		}
		foreach ($locs as $loc => $mid) {
			if ($mid) {
				$locs[$loc] = $this->mapped('menu', $mid);
			}
		}
		return $locs;
	}

	public function nav_args($args) {
		if ($this->skip() || !is_array($args) || empty($args['menu'])) {
			return $args;
		}
		$m = wp_get_nav_menu_object($args['menu']);
		if ($m && !is_wp_error($m)) {
			$to = $this->mapped('menu', $m->term_id);
			if ($to !== (int) $m->term_id) {
				$args['menu'] = $to;
			}
		}
		return $args;
	}

	public function nav_ref($block) {
		if (!is_array($block) || empty($block['blockName']) || $block['blockName'] !== 'core/navigation' || empty($block['attrs']['ref']) || $this->skip()) {
			return $block;
		}
		$block['attrs']['ref'] = $this->mapped('nav', (int) $block['attrs']['ref']);
		return $block;
	}
}
