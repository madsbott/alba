<?php
if (!defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table names come from $wpdb or a fixed helper; every value goes through $wpdb->prepare().

/**
 * Change log: who translated which text and when.
 * Own table {prefix}alba_log. Logged are saving in the side-by-side editor (with before/after per text)
 * and creating own translation posts. Retention is configurable (default 365 days).
 */
class ALBA_Log {
	const DB = '1';
	const PAGE = 'alba-log';
	const PER_PAGE = 30;

	private $core;

	public function __construct(ALBA_Core $core) {
		$this->core = $core;
		$core->log = $this;

		add_action('admin_init', [$this, 'ensure_table']);
		add_action('admin_menu', [$this, 'menu']);
		add_action('alba_translation_created', [$this, 'on_created'], 10, 4);
		add_action('admin_post_alba_log_csv', [$this, 'csv']);
		add_action('admin_post_alba_log_clear', [$this, 'clear']);
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'alba_log';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta("CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			time datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			lang varchar(20) NOT NULL DEFAULT '',
			action varchar(20) NOT NULL DEFAULT '',
			summary varchar(255) NOT NULL DEFAULT '',
			details longtext NULL,
			PRIMARY KEY  (id),
			KEY time (time),
			KEY post_id (post_id),
			KEY user_id (user_id)
		) " . $wpdb->get_charset_collate() . ';');
		update_option('alba_log_db', self::DB, false);
	}

	public function ensure_table() {
		if (get_option('alba_log_db') !== self::DB) {
			self::install();
		}
	}

	public static function days() {
		$d = (int) get_option('alba_log_days', 365);
		return in_array($d, [0, 30, 90, 180, 365], true) ? $d : 365;
	}

	/* ---------------------------------------------------------------
	 * Schreiben
	 * ------------------------------------------------------------- */

	private function cut($s, $n = 300) {
		$s = (string) $s;
		return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s;
	}

	public function add($post_id, $lang, $action, $summary, array $details = []) {
		global $wpdb;
		$this->ensure_table();
		if (!empty($details['changes'])) {
			$more = max(0, count($details['changes']) - 60);
			$details['changes'] = array_slice($details['changes'], 0, 60);
			foreach ($details['changes'] as &$ch) {
				foreach (['src', 'old', 'new'] as $k) {
					if (isset($ch[$k])) {
						$ch[$k] = $this->cut($ch[$k]);
					}
				}
			}
			unset($ch);
			$details['more'] = $more;
		}
		$wpdb->insert(self::table(), [
			'time'    => gmdate('Y-m-d H:i:s'),
			'user_id' => get_current_user_id(),
			'post_id' => (int) $post_id,
			'lang'    => (string) $lang,
			'action'  => (string) $action,
			'summary' => $this->cut($summary, 250),
			'details' => wp_json_encode($details),
		]);
		$days = self::days();
		if ($days > 0 && wp_rand(1, 25) === 1) {
			$wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE time < %s', gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)));
		}
	}

	public function on_created($new, $src, $lang, $auto) {
		$this->add((int) $src, $lang, 'create', __('Created separate translation post (#', 'alba') . (int) $new . ')', ['new_id' => (int) $new]);
	}

	/** Latest entry for a post and language */
	public function last($post_id, $lang) {
		global $wpdb;
		$this->ensure_table();
		return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . " WHERE post_id = %d AND lang = %s AND action IN ('save','import') ORDER BY id DESC LIMIT 1", (int) $post_id, (string) $lang));
	}

	/* ---------------------------------------------------------------
	 * Anzeige
	 * ------------------------------------------------------------- */

	public function menu() {
		add_submenu_page(ALBA_Dashboard::SLUG, __('Languages – Log', 'alba'), __('Log', 'alba'), 'edit_others_posts', self::PAGE, [$this, 'page']);
	}

	private function filters() {
		return [
			'lang' => isset($_GET['lang']) ? sanitize_key(wp_unslash($_GET['lang'])) : '',
			'user' => isset($_GET['user']) ? (int) $_GET['user'] : 0,
			'post' => isset($_GET['post']) ? (int) $_GET['post'] : 0,
		];
	}

	private function where($f) {
		global $wpdb;
		$w = ['1=1'];
		if ($f['lang'] !== '') {
			$w[] = $wpdb->prepare('lang = %s', $f['lang']);
		}
		if ($f['user']) {
			$w[] = $wpdb->prepare('user_id = %d', $f['user']);
		}
		if ($f['post']) {
			$w[] = $wpdb->prepare('post_id = %d', $f['post']);
		}
		return implode(' AND ', $w);
	}

	private function when($mysql) {
		$ts = strtotime($mysql . ' UTC');
		return wp_date('d.m.Y H:i', $ts) . ' <span style="color:#787c82">(' . esc_html(human_time_diff($ts, time())) . ')</span>';
	}

	private function user_name($id) {
		$u = $id ? get_userdata((int) $id) : false;
		return $u ? $u->display_name : ($id ? __('User #', 'alba') . (int) $id . __(' (deleted)', 'alba') : __('System', 'alba'));
	}

	public function page() {
		if (!current_user_can('edit_others_posts')) {
			return;
		}
		global $wpdb;
		$this->ensure_table();
		$c = $this->core;
		$t = self::table();
		$f = $this->filters();
		$where = $this->where($f);
		$paged = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
		$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE {$where}");
		$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", self::PER_PAGE, ($paged - 1) * self::PER_PAGE));
		$users = $wpdb->get_col("SELECT DISTINCT user_id FROM {$t} ORDER BY user_id");
		$pages = max(1, (int) ceil($total / self::PER_PAGE));
		$base = admin_url('admin.php?page=' . self::PAGE);
		$qs = array_filter(['lang' => $f['lang'], 'user' => $f['user'], 'post' => $f['post']]);
		?>
		<div class="wrap">
			<h1><?php echo esc_html__('Change log', 'alba'); ?></h1>
			<?php if (!empty($_GET['cleared'])) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Log cleared.', 'alba'); ?></p></div><?php endif; ?>
			<p class="description"><?php echo esc_html__('Who saved which translation, and when. Retention:', 'alba'); ?> <?php echo self::days() ? (int) self::days() . esc_html__(' days', 'alba') : 'unbegrenzt'; ?> <?php echo esc_html__('(Languages > Settings > Updates & System).', 'alba'); ?></p>

			<form method="get" style="margin:14px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
				<input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
				<select name="lang">
					<option value=""><?php echo esc_html__('All languages', 'alba'); ?></option>
					<?php foreach ($c->langs as $code => $l) : ?><option value="<?php echo esc_attr($code); ?>" <?php selected($f['lang'], $code); ?>><?php echo esc_html($l['name']); ?></option><?php endforeach; ?>
				</select>
				<select name="user">
					<option value="0"><?php echo esc_html__('All users', 'alba'); ?></option>
					<?php foreach ($users as $uid) : ?><option value="<?php echo (int) $uid; ?>" <?php selected($f['user'], (int) $uid); ?>><?php echo esc_html($this->user_name((int) $uid)); ?></option><?php endforeach; ?>
				</select>
				<?php if ($f['post']) : ?>
					<input type="hidden" name="post" value="<?php echo (int) $f['post']; ?>">
					<span><?php echo esc_html__('Only:', 'alba'); ?> <strong><?php echo esc_html(get_the_title($f['post'])); ?></strong> <a href="<?php echo esc_url(add_query_arg(array_diff_key($qs, ['post' => 1]), $base)); ?>"><?php echo esc_html__('Remove filter', 'alba'); ?></a></span>
				<?php endif; ?>
				<button class="button"><?php echo esc_html__('Filter', 'alba'); ?></button>
				<span style="flex:1"></span>
				<a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(array_merge($qs, ['action' => 'alba_log_csv']), admin_url('admin-post.php')), 'alba_log')); ?>"><?php echo esc_html__('Export as CSV', 'alba'); ?></a>
				<?php if (current_user_can('manage_options')) : ?>
					<a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=alba_log_clear'), 'alba_log')); ?>" onclick="return confirm('<?php echo esc_js(__('Really clear the entire log?', 'alba')); ?>');"><?php echo esc_html__('Clear log', 'alba'); ?></a>
				<?php endif; ?>
			</form>

			<table class="widefat striped" style="max-width:1100px">
				<thead><tr><th style="width:150px"><?php echo esc_html__('When', 'alba'); ?></th><th style="width:140px"><?php echo esc_html__('Who', 'alba'); ?></th><th><?php echo esc_html__('Content', 'alba'); ?></th><th style="width:90px"><?php echo esc_html__('Language', 'alba'); ?></th><th><?php echo esc_html__('What', 'alba'); ?></th></tr></thead>
				<tbody>
				<?php if (!$rows) : ?>
					<tr><td colspan="5"><?php echo esc_html__('No entries yet.', 'alba'); ?></td></tr>
				<?php endif; ?>
				<?php foreach ($rows as $r) :
					$d = json_decode((string) $r->details, true);
					$d = is_array($d) ? $d : [];
					$post = get_post((int) $r->post_id);
					$title = $post ? ($post->post_title !== '' ? $post->post_title : __('(no title)', 'alba')) : __('(deleted #', 'alba') . (int) $r->post_id . ')';
					$link = ($post && $c->editor && in_array($r->action, ['save', 'import'], true)) ? $c->editor->url((int) $r->post_id, $r->lang) : ($post ? get_edit_post_link($post->ID, '') : '');
					$ln = isset($c->langs[$r->lang]) ? $c->langs[$r->lang]['name'] : $r->lang;
					?>
					<tr>
						<td><?php echo $this->when($r->time); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside when(). ?></td>
						<td><?php echo esc_html($this->user_name((int) $r->user_id)); ?></td>
						<td><?php echo $link ? '<a href="' . esc_url($link) . '">' . esc_html($title) . '</a>' : esc_html($title); ?>
							<div style="font-size:12px"><a href="<?php echo esc_url(add_query_arg(array_merge($qs, ['post' => (int) $r->post_id]), $base)); ?>"><?php echo esc_html__('Only this content', 'alba'); ?></a></div></td>
						<td><?php echo esc_html($ln); ?></td>
						<td>
							<?php echo esc_html($r->summary); ?>
							<?php if (!empty($d['changes'])) : ?>
								<details style="margin-top:4px"><summary style="cursor:pointer;color:#2271b1"><?php echo esc_html__('Show changes (', 'alba'); ?><?php echo (int) count($d['changes']) + (int) (isset($d['more']) ? $d['more'] : 0); ?>)</summary>
									<table style="margin-top:6px;border-collapse:collapse;width:100%">
									<?php foreach ($d['changes'] as $ch) : ?>
										<tr style="vertical-align:top;border-top:1px solid #f0f0f1">
											<td style="padding:4px 8px 4px 0;white-space:nowrap;color:#50575e"><?php echo esc_html($ch['f']); ?></td>
											<td style="padding:4px 0">
												<?php if (!empty($ch['src'])) : ?><div style="color:#787c82"><?php echo esc_html__('Original:', 'alba'); ?> <?php echo esc_html(wp_strip_all_tags($ch['src'])); ?></div><?php endif; ?>
												<?php if (isset($ch['old']) && $ch['old'] !== '') : ?><div style="color:#8a2424"><del><?php echo esc_html(wp_strip_all_tags($ch['old'])); ?></del></div><?php endif; ?>
												<div style="color:#1e4620"><?php echo isset($ch['new']) && $ch['new'] !== '' ? esc_html(wp_strip_all_tags($ch['new'])) : '<em>(leer)</em>'; ?></div>
											</td>
										</tr>
									<?php endforeach; ?>
									</table>
									<?php if (!empty($d['more'])) : ?><p class="description"><?php echo esc_html__('… and', 'alba'); ?> <?php echo (int) $d['more']; ?> weitere.</p><?php endif; ?>
								</details>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ($pages > 1) : ?>
				<p class="tablenav-pages" style="margin-top:12px">
					<?php echo (int) $total; ?> <?php echo esc_html__('entries ·', 'alba'); ?>

					<?php for ($i = 1; $i <= $pages; $i++) : ?>
						<?php if ($i === $paged) : ?><strong><?php echo (int) $i; ?></strong><?php else : ?><a href="<?php echo esc_url(add_query_arg(array_merge($qs, ['paged' => $i]), $base)); ?>"><?php echo (int) $i; ?></a><?php endif; ?>
					<?php endfor; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function csv() {
		check_admin_referer('alba_log');
		if (!current_user_can('edit_others_posts')) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		global $wpdb;
		$f = $this->filters();
		$rows = $wpdb->get_results('SELECT * FROM ' . self::table() . ' WHERE ' . $this->where($f) . ' ORDER BY id DESC LIMIT 5000');
		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="alba-log-' . gmdate('Y-m-d') . '.csv"');
		$out = fopen('php://output', 'w');
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streams the CSV download to php://output.
		fwrite($out, "\xEF\xBB\xBF");
		fputcsv($out, [__('Time (UTC)', 'alba'), __('User', 'alba'), __('Content ID', 'alba'), __('Content', 'alba'), __('Language', 'alba'), __('Action', 'alba'), __('Summary', 'alba')], ';');
		foreach ($rows as $r) {
			$cell = function ($s) {
				$s = (string) $s;
				return preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s;   // no formulas in spreadsheet programs
			};
			fputcsv($out, [$r->time, $cell($this->user_name((int) $r->user_id)), $r->post_id, $cell(get_the_title((int) $r->post_id)), $r->lang, $r->action, $cell($r->summary)], ';');
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the php://output stream.
		fclose($out);
		exit;
	}

	public function clear() {
		check_admin_referer('alba_log');
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		global $wpdb;
		$wpdb->query('TRUNCATE TABLE ' . self::table());
		wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE . '&cleared=1'));
		exit;
	}
}
