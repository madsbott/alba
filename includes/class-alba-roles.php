<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Role "Translator": access only to the translation editor, without being allowed to edit originals.
 *
 * - Role alba_translator with the capabilities read and alba_translate (no edit_posts, no media upload).
 * - Whoever may edit an original (edit_post) may also translate it. Translators may translate published, scheduled,
 *   pending and draft content, but not private or password-protected content.
 * - Languages can be restricted per user (user meta alba_langs, empty = all). Administrators are exempt.
 * - Approval ("Show on the frontend"): only with the right to edit the original or if the setting "Translators may publish" is on.
 * - Own "Translations" page (list of open texts) instead of the dashboard.
 */
class ALBA_Roles {
	const ROLE = 'alba_translator';
	const CAP = 'alba_translate';
	const PAGE = 'alba-mine';
	const STATUSES = ['publish', 'draft', 'pending', 'future'];

	private $core;

	public function __construct(ALBA_Core $core) {
		$this->core = $core;
		$core->roles = $this;

		add_action('init', [$this, 'ensure_role']);
		add_filter('login_redirect', [$this, 'login_redirect'], 10, 3);

		if (is_admin()) {
			add_action('admin_menu', [$this, 'menu'], 20);
			add_action('admin_init', [$this, 'register']);
			add_action('load-index.php', [$this, 'dash_redirect']);
			add_action('show_user_profile', [$this, 'profile_fields']);
			add_action('edit_user_profile', [$this, 'profile_fields']);
			add_action('edit_user_profile_update', [$this, 'profile_save']);
		}
	}

	public function ensure_role() {
		$r = get_role(self::ROLE);
		if (!$r) {
			add_role(self::ROLE, __('Translator', 'alba'), ['read' => true, self::CAP => true]);
		} elseif (empty($r->capabilities[self::CAP])) {
			$r->add_cap(self::CAP);
		}
	}

	/* ---------------------------------------------------------------
	 * Rechte
	 * ------------------------------------------------------------- */

	/** May only translate (has the translator capability but no post capabilities) */
	public function is_translator_only() {
		return current_user_can(self::CAP) && !current_user_can('edit_posts');
	}

	public function can_use() {
		return current_user_can(self::CAP) || current_user_can('edit_posts');
	}

	/** Languages (except the default language) the user may translate into */
	public function allowed_langs($uid = 0) {
		$c = $this->core;
		$all = array_keys(array_diff_key($c->langs, [$c->default => 1]));
		$uid = $uid ? (int) $uid : get_current_user_id();
		if (!$uid) {
			return [];
		}
		if (user_can($uid, 'manage_options')) {
			return $all;
		}
		$m = get_user_meta($uid, 'alba_langs', true);
		if (!is_array($m) || !$m) {
			return $all;
		}
		return array_values(array_intersect($all, $m));
	}

	public function lang_allowed($lang) {
		return in_array($lang, $this->allowed_langs(), true);
	}

	/** May the current user edit this post (in this language) in the translation editor? */
	public function can_translate($post_id, $lang = '') {
		$post = get_post((int) $post_id);
		if (!$post || !$this->core->is_translatable($post->post_type)) {
			return false;
		}
		if ($lang !== '' && !$this->lang_allowed($lang)) {
			return false;
		}
		if (current_user_can('edit_post', $post->ID)) {
			return true;
		}
		if (!current_user_can(self::CAP)) {
			return false;
		}
		return in_array($post->post_status, self::STATUSES, true) && $post->post_password === '';
	}

	/** May the user approve the translation on the frontend? */
	public function can_publish($post_id) {
		return current_user_can('edit_post', (int) $post_id) || get_option('alba_translator_publish', '0') === '1';
	}

	/* ---------------------------------------------------------------
	 * Menu, redirects
	 * ------------------------------------------------------------- */

	public function menu() {
		if ($this->is_translator_only()) {
			add_menu_page(__('Translations', 'alba'), __('Translations', 'alba'), self::CAP, self::PAGE, [$this, 'page'], 'dashicons-translation', 58);
		} elseif (current_user_can('edit_posts')) {
			add_submenu_page(ALBA_Dashboard::SLUG, __('My translations', 'alba'), __('My translations', 'alba'), 'edit_posts', self::PAGE, [$this, 'page']);
		}
	}

	public function login_redirect($redirect, $requested, $user) {
		if ($user instanceof WP_User && user_can($user, self::CAP) && !user_can($user, 'edit_posts')) {
			if ($requested === '' || untrailingslashit($requested) === untrailingslashit(admin_url())) {
				return admin_url('admin.php?page=' . self::PAGE);
			}
		}
		return $redirect;
	}

	public function dash_redirect() {
		if ($this->is_translator_only()) {
			wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE));
			exit;
		}
	}

	/* ---------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------- */

	public function register() {
		register_setting('alba', 'alba_translator_publish', ['sanitize_callback' => function ($v) {
			return $v === '1' ? '1' : '0';
		}]);
	}

	public function card() {
		$n = count(get_users(['role' => self::ROLE, 'fields' => 'ID']));
		?>
		<section class="alba-card" data-tab="lang">
			<header class="alba-card-h"><span class="dashicons dashicons-businessperson"></span><div><h2><?php echo esc_html__('Translator', 'alba'); ?></h2><p><?php echo esc_html__('Users who may only translate.', 'alba'); ?></p></div></header>
			<div class="alba-card-b">
				<p><?php echo esc_html__('The role', 'alba'); ?> <strong><?php echo esc_html__('Translator', 'alba'); ?></strong> <?php echo esc_html__('has access to the "Translations" page and the translation editor, but nothing else: no posts, pages, media or settings. A translator can only read originals, not change them. They do not see private and password-protected content. Languages can be restricted per user (in the user profile).', 'alba'); ?></p>
				<p>
					<a class="button" href="<?php echo esc_url(admin_url('user-new.php')); ?>"><?php echo esc_html__('Create user (choose role "Translator")', 'alba'); ?></a>
					<a class="button" href="<?php echo esc_url(admin_url('users.php?role=' . self::ROLE)); ?>"><?php echo (int) $n; ?> <?php echo esc_html__('Show translators', 'alba'); ?></a>
				</p>
				<div class="alba-fields">
					<div class="alba-field"><div class="alba-label"><?php echo esc_html__('Approval', 'alba'); ?></div><div class="alba-ctl">
						<label><input type="checkbox" name="alba_translator_publish" value="1" <?php checked(get_option('alba_translator_publish', '0'), '1'); ?>> <?php echo esc_html__('Translators may publish translations on the frontend', 'alba'); ?></label>
						<p class="alba-hint"><?php echo esc_html__('Off (default): translators only save. Editors and administrators decide whether a translation appears on the frontend ("Show on the frontend" in the editor). Translations that are already published stay published when saved.', 'alba'); ?></p>
					</div></div>
				</div>
			</div>
		</section>
		<?php
	}

	/* ---------------------------------------------------------------
	 * User profile: restrict languages
	 * ------------------------------------------------------------- */

	public function profile_fields($user) {
		$c = $this->core;
		$targets = array_diff_key($c->langs, [$c->default => 1]);
		$is_admin_view = current_user_can('promote_users');
		if (!$targets || (!$is_admin_view && (int) $user->ID !== get_current_user_id())) {
			return;
		}
		if (!$is_admin_view && !user_can($user, self::CAP)) {
			return;
		}
		$m = get_user_meta($user->ID, 'alba_langs', true);
		$sel = is_array($m) ? $m : [];
		?>
		<h2><?php echo esc_html__('Translate', 'alba'); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php echo esc_html__('Languages', 'alba'); ?></th>
				<td>
					<?php if ($is_admin_view) : ?>
						<?php foreach ($targets as $code => $l) : ?>
							<label style="margin-right:14px"><input type="checkbox" name="alba_langs[]" value="<?php echo esc_attr($code); ?>" <?php checked(in_array($code, $sel, true)); ?>> <?php echo esc_html($l['name']); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php echo esc_html__('The user may translate into these languages. Nothing selected: all. Does not apply to administrators.', 'alba'); ?></p>
					<?php else : ?>
						<?php
						$names = [];
						foreach ($this->allowed_langs($user->ID) as $code) {
							$names[] = $c->langs[$code]['name'];
						}
						echo esc_html($names ? implode(', ', $names) : __('none', 'alba'));
						?>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	public function profile_save($uid) {
		if (!current_user_can('promote_users') || !current_user_can('edit_user', $uid)) {
			return;
		}
		$c = $this->core;
		$targets = array_keys(array_diff_key($c->langs, [$c->default => 1]));
		$sel = (isset($_POST['alba_langs']) && is_array($_POST['alba_langs'])) ? array_map('sanitize_key', wp_unslash($_POST['alba_langs'])) : [];
		$sel = array_values(array_intersect($targets, $sel));
		if ($sel && count($sel) < count($targets)) {
			update_user_meta($uid, 'alba_langs', $sel);
		} else {
			delete_user_meta($uid, 'alba_langs');
		}
	}

	/* ---------------------------------------------------------------
	 * "Translations" page
	 * ------------------------------------------------------------- */

	public function page() {
		if (!$this->can_use()) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		$c = $this->core;
		$e = $c->editor;
		$wf = $c->workflow;
		$langs = $this->allowed_langs();
		echo '<div class="wrap"><h1>' . esc_html__('Translations', 'alba') . '</h1>';
		if (!$langs || !$e) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__('No languages have been assigned to you for translating.', 'alba') . '</p></div></div>';
			return;
		}

		$flang = isset($_GET['lang']) ? sanitize_key(wp_unslash($_GET['lang'])) : '';
		$flang = in_array($flang, $langs, true) ? $flang : '';
		$search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
		$types = (array) $c->post_types;
		$ftype = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : '';
		if ($ftype !== '' && in_array($ftype, $types, true)) {
			$types = [$ftype];
		}
		$who = isset($_GET['who']) ? sanitize_key(wp_unslash($_GET['who'])) : 'all';
		$who = in_array($who, ['all', 'me', 'none'], true) ? $who : 'all';
		$fst = isset($_GET['st']) ? sanitize_key(wp_unslash($_GET['st'])) : '';
		$fst = in_array($fst, ['review', 'open', 'late'], true) ? $fst : '';
		$jobs = ($who !== 'all' || $fst !== '');
		$only = !$jobs && !(isset($_GET['open']) && $_GET['open'] === '0');
		$use = $flang !== '' ? [$flang] : $langs;
		$me = get_current_user_id();
		$today = wp_date('Y-m-d');

		$args = [
			'post_type'        => $types,
			'post_status'      => self::STATUSES,
			'posts_per_page'   => 300,
			'orderby'          => 'modified',
			'order'            => 'DESC',
			's'                => $search,
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'alba_skip'         => 1,
		];
		if ($jobs && $wf) {
			$ids = $wf->job_post_ids();
			$args['post__in'] = $ids ?: [0];
		}
		$posts = get_posts($args);

		$rows = [];
		foreach ($posts as $p) {
			if ($c->post_lang($p->ID) !== $c->default) {
				continue;
			}
			$cells = [];
			$any_open = false;
			foreach ($use as $l) {
				if (!$this->can_translate($p->ID, $l) || $c->translation($p->ID, $l, false)) {
					continue;
				}
				$w = $wf ? $wf->get($p->ID, $l) : ['to' => 0, 'due' => '', 'st' => ''];
				if ($who === 'me' && (int) $w['to'] !== $me) {
					continue;
				}
				if ($who === 'none' && $w['to']) {
					continue;
				}
				if ($fst === 'review' && $w['st'] !== 'review') {
					continue;
				}
				if ($fst === 'open' && $w['st'] !== 'open') {
					continue;
				}
				if ($fst === 'late' && !($w['st'] === 'open' && $w['due'] !== '' && $w['due'] < $today)) {
					continue;
				}
				$oc = $e->open_count($p, $l);
				$cells[$l] = ['open' => (int) $oc[0], 'total' => (int) $oc[1], 'state' => $e->state($p->ID, $l), 'w' => $w];
				if ($oc[0] > 0) {
					$any_open = true;
				}
			}
			if (!$cells || ($only && !$any_open)) {
				continue;
			}
			$rows[] = ['post' => $p, 'cells' => $cells];
		}

		$per = 25;
		$total = count($rows);
		$pages = max(1, (int) ceil($total / $per));
		$paged = isset($_GET['paged']) ? max(1, min($pages, (int) $_GET['paged'])) : 1;
		$slice = array_slice($rows, ($paged - 1) * $per, $per);
		$ops = $c->bulk ? $c->bulk->ops() : [];
		$uname = function ($uid) {
			$u = $uid ? get_userdata((int) $uid) : null;
			return $u ? $u->display_name : '';
		};
		?>
		<form method="get" style="margin:12px 0">
			<input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
			<select name="lang">
				<option value=""><?php echo esc_html__('All my languages', 'alba'); ?></option>
				<?php foreach ($langs as $l) : ?><option value="<?php echo esc_attr($l); ?>" <?php selected($flang, $l); ?>><?php echo esc_html($c->langs[$l]['name']); ?></option><?php endforeach; ?>
			</select>
			<select name="type">
				<option value=""><?php echo esc_html__('All content types', 'alba'); ?></option>
				<?php foreach ((array) $c->post_types as $t) : $o = get_post_type_object($t); if (!$o) { continue; } ?>
					<option value="<?php echo esc_attr($t); ?>" <?php selected($ftype, $t); ?>><?php echo esc_html($o->labels->name); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="who">
				<option value="all" <?php selected($who, 'all'); ?>><?php echo esc_html__('Assigned to: anyone', 'alba'); ?></option>
				<option value="me" <?php selected($who, 'me'); ?>><?php echo esc_html__('Assigned to me', 'alba'); ?></option>
				<option value="none" <?php selected($who, 'none'); ?>><?php echo esc_html__('Not assigned', 'alba'); ?></option>
			</select>
			<select name="st">
				<option value=""><?php echo esc_html__('Status: all', 'alba'); ?></option>
				<option value="open" <?php selected($fst, 'open'); ?>><?php echo esc_html__('In progress', 'alba'); ?></option>
				<option value="review" <?php selected($fst, 'review'); ?>><?php echo esc_html__('For review', 'alba'); ?></option>
				<option value="late" <?php selected($fst, 'late'); ?>><?php echo esc_html__('Overdue', 'alba'); ?></option>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php echo esc_attr__('Search …', 'alba'); ?>">
			<input type="hidden" name="open" value="0">
			<label><input type="checkbox" name="open" value="1" <?php checked(!(isset($_GET['open']) && $_GET['open'] === '0')); ?>> <?php echo esc_html__('only with open texts', 'alba'); ?></label>
			<button class="button"><?php echo esc_html__('Filter', 'alba'); ?></button>
		</form>

		<?php if (!$slice) : ?>
			<p><em><?php echo $only ? esc_html__('Nothing to translate. Everything is done or nothing matches the filter.', 'alba') : esc_html__('No content found.', 'alba'); ?></em></p>
		<?php else : ?>
			<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr(ALBA_Bulk::PAGE); ?>">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('alba_bulk_run')); ?>">
				<?php if ($ops) : ?>
				<p>
					<select name="op"><?php foreach ($ops as $k => $lbl) : ?><option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($lbl); ?></option><?php endforeach; ?></select>
					<?php echo esc_html__('for', 'alba'); ?>

					<select name="lang"><?php foreach ($use as $l) : ?><option value="<?php echo esc_attr($l); ?>"><?php echo esc_html($c->langs[$l]['name']); ?></option><?php endforeach; ?></select>
					<button class="button"><?php echo esc_html__('Apply to selection', 'alba'); ?></button>
					<label style="margin-left:10px"><input type="checkbox" id="alba-all"> <?php echo esc_html__('select all on this page', 'alba'); ?></label>
				</p>
				<?php endif; ?>
			<table class="widefat striped" style="max-width:1100px">
				<thead><tr><?php if ($ops) : ?><td class="check-column"></td><?php endif; ?><th><?php echo esc_html__('Content', 'alba'); ?></th><th><?php echo esc_html__('Type', 'alba'); ?></th><?php foreach ($use as $l) : ?><th><?php echo esc_html($c->langs[$l]['name']); ?></th><?php endforeach; ?></tr></thead>
				<tbody>
				<?php foreach ($slice as $r) :
					$p = $r['post'];
					$o = get_post_type_object($p->post_type);
					?>
					<tr>
						<?php if ($ops) : ?><th class="check-column"><input type="checkbox" class="alba-pick" name="ids[]" value="<?php echo (int) $p->ID; ?>"></th><?php endif; ?>
						<td><strong><?php echo esc_html($p->post_title !== '' ? $p->post_title : __('(no title)', 'alba')); ?></strong><?php echo $p->post_status !== 'publish' ? ' <span class="description">(' . esc_html(get_post_status_object($p->post_status)->label) . ')</span>' : ''; ?></td>
						<td><?php echo esc_html($o ? $o->labels->singular_name : $p->post_type); ?></td>
						<?php foreach ($use as $l) : ?>
							<td>
								<?php if (!isset($r['cells'][$l])) : echo '–'; else :
									$cell = $r['cells'][$l];
									$w = $cell['w'];
									$st = $cell['state'] === 'on' ? __('published', 'alba') : ($cell['state'] === 'draft' ? __('saved', 'alba') : __('new', 'alba'));
									$late = ($w['st'] === 'open' && $w['due'] !== '' && $w['due'] < $today);
									?>
									<a class="button button-small" href="<?php echo esc_url($e->url($p->ID, $l)); ?>"><?php echo esc_html__('Translate', 'alba'); ?></a><br>
									<span class="description"><?php echo $cell['open'] ? sprintf(/* translators: 1: open texts, 2: total texts */ esc_html__('%1$d of %2$d open', 'alba'), (int) $cell['open'], (int) $cell['total']) : esc_html__('complete', 'alba'); ?> · <?php echo esc_html($st); ?></span>
									<?php if ($w['to'] || $w['st'] !== '') : ?><br>
										<span class="description"><?php echo $w['to'] ? esc_html($uname($w['to'])) : ''; ?><?php echo $w['due'] !== '' ? ' · ' . esc_html__('Deadline', 'alba') . ' ' . ($late ? '<strong style="color:#b32d2e">' : '') . esc_html(wp_date('d.m.Y', strtotime($w['due'] . ' 12:00:00'))) . ($late ? ' ' . esc_html__('(overdue)', 'alba') . '</strong>' : '') : ''; ?><?php echo $w['st'] !== '' ? ' · <strong>' . esc_html($wf->status_label($w['st'])) . '</strong>' : ''; ?></span>
									<?php endif; ?>
								<?php endif; ?>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</form>
			<script>
			(function () {
				var all = document.getElementById('alba-all');
				if (all) { all.addEventListener('change', function () { document.querySelectorAll('.alba-pick').forEach(function (b) { b.checked = all.checked; }); }); }
			})();
			</script>
			<?php if ($pages > 1) : ?>
				<p>
					<?php echo wp_kses_post(paginate_links([
						'base'    => add_query_arg('paged', '%#%'),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					])); ?>
				</p>
			<?php endif; ?>
		<?php endif; ?>
		<p class="description"><?php echo esc_html__('The 300 most recently changed items are considered. Use the search to narrow it down further. Assignments (responsibility, status) can be found with the filters regardless of this.', 'alba'); ?></p>
		</div>
		<?php
	}
}
