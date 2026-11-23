<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Arbeitsablauf mit Übersetzern: Aufträge, Fristen, Status „Zur Prüfung“, E-Mail-Benachrichtigungen.
 *
 * Gilt für Übersetzungen im Übersetzungseditor (Editor-Übersetzungen am Original). Je Inhalt und Sprache wird ein Auftrag
 * als Beitrags-Meta _mml_wf_{sprache} gespeichert:
 *   to  = zuständiger Benutzer, by = wer zugewiesen hat, due = Frist (Y-m-d),
 *   st  = '' (kein offener Vorgang), 'open' (in Arbeit) oder 'review' (zur Prüfung eingereicht),
 *   at/rv = Zeitpunkt der Zuweisung / Einreichung, nt = Fingerabdruck des Originals bei der letzten „Original geändert“-Mail,
 *   dn = Datum der letzten Fristmail, note = Hinweis des Prüfenden bei Rückgabe.
 * E-Mails: bei Zuweisung (an den Übersetzer), bei Einreichung (an den Zuweisenden bzw. die eingestellte Adresse),
 * bei Änderung des Originals nach der Übersetzung (an den Zuständigen und die eingestellte Adresse) und täglich
 * zur Frist (am Vortag und wenn überfällig).
 */
class MML_Workflow {
	const META = '_mml_wf_';
	const CRON = 'mml_wf_daily';

	private $core;

	public function __construct(MML_Core $core) {
		$this->core = $core;
		$core->workflow = $this;

		add_action('save_post', [$this, 'original_saved'], 99, 2);
		add_action(self::CRON, [$this, 'daily']);
		add_action('init', function () {
			if (!wp_next_scheduled(self::CRON)) {
				wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON);
			}
		});

		if (is_admin()) {
			add_action('admin_post_mml_wf_assign', [$this, 'handle_assign']);
			add_action('admin_post_mml_wf_return', [$this, 'handle_return']);
			add_action('admin_init', [$this, 'register']);
		}
	}

	/* ---------------------------------------------------------------
	 * Daten
	 * ------------------------------------------------------------- */

	public function get($id, $lang) {
		$d = get_post_meta((int) $id, self::META . $lang, true);
		$d = is_array($d) ? $d : [];
		return $d + ['to' => 0, 'by' => 0, 'due' => '', 'st' => '', 'at' => 0, 'rv' => 0, 'nt' => '', 'dn' => '', 'note' => ''];
	}

	public function set($id, $lang, array $d) {
		if (!$d['to'] && $d['due'] === '' && $d['st'] === '' && $d['nt'] === '') {
			delete_post_meta((int) $id, self::META . $lang);
			return;
		}
		update_post_meta((int) $id, self::META . $lang, $d);
	}

	/** Alle Beitrags-IDs mit mindestens einem Auftrag */
	public function job_post_ids() {
		global $wpdb;
		$ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like(self::META) . '%'));
		return array_map('intval', (array) $ids);
	}

	public function status_label($st) {
		return $st === 'review' ? __('For review', 'alba') : ($st === 'open' ? __('In progress', 'alba') : '');
	}

	/** Benutzer, denen ein Auftrag zugewiesen werden kann */
	public function users() {
		$users = get_users(['capability__in' => [MML_Roles::CAP, 'edit_posts'], 'orderby' => 'display_name', 'fields' => ['ID', 'display_name']]);
		return is_array($users) ? $users : [];
	}

	private function valid_user($uid) {
		$uid = (int) $uid;
		return $uid > 0 && ($u = get_userdata($uid)) && (user_can($u, MML_Roles::CAP) || user_can($u, 'edit_posts'));
	}

	private function valid_due($d) {
		return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d . ' 12:00:00') !== false;
	}

	/** Auftrag zuweisen ($uid 0 = Zuweisung entfernen). Rückgabe: true oder Fehlertext */
	public function assign($id, $lang, $uid, $due, $by = 0, $notify = true) {
		$c = $this->core;
		$post = get_post((int) $id);
		if (!$post || !isset($c->langs[$lang]) || $lang === $c->default || !$c->is_translatable($post->post_type)) {
			return __('Invalid content or language.', 'alba');
		}
		$uid = (int) $uid;
		if ($uid && !$this->valid_user($uid)) {
			return __('The user may not translate.', 'alba');
		}
		$due = $this->valid_due($due) ? $due : '';
		$w = $this->get($id, $lang);
		$w['to'] = $uid;
		$w['by'] = $uid ? (int) ($by ?: get_current_user_id()) : 0;
		$w['due'] = $uid || $due !== '' ? $due : '';
		$w['at'] = $uid ? time() : 0;
		$w['dn'] = '';
		$w['note'] = '';
		if ($uid) {
			$w['st'] = ($w['st'] === 'review') ? 'review' : 'open';
		} elseif ($w['st'] === 'open') {
			$w['st'] = '';
		}
		$this->set($id, $lang, $w);
		if ($uid && $notify && $uid !== (int) get_current_user_id()) {
			$this->mail_assign($post, $lang, $uid, $w);
		}
		return true;
	}

	/* ---------------------------------------------------------------
	 * Speichern im Editor
	 * ------------------------------------------------------------- */

	/** Nach dem Speichern im Übersetzungseditor: Status fortschreiben */
	public function after_save($id, $lang, $submit, $is_on) {
		$w = $this->get($id, $lang);
		$old = $w;
		$me = get_current_user_id();
		if ($is_on) {
			$w['st'] = '';
			$w['note'] = '';
		} elseif ($submit) {
			$w['st'] = 'review';
			$w['rv'] = time();
			$w['note'] = '';
			if (!$w['to']) {
				$w['to'] = $me;
			}
		} elseif ($w['st'] === 'review' && !current_user_can('edit_others_posts')) {
			$w['st'] = 'open';
		}
		if ($w !== $old) {
			$this->set($id, $lang, $w);
		}
		if ($submit && !$is_on) {
			$this->mail_review(get_post($id), $lang, $w);
		}
	}

	/* ---------------------------------------------------------------
	 * Original geändert -> Hinweis
	 * ------------------------------------------------------------- */

	public function original_saved($id, $post) {
		$c = $this->core;
		if (wp_is_post_revision($id) || wp_is_post_autosave($id) || !$post || $post->post_status === 'auto-draft' || !$c->editor || !$c->is_translatable($post->post_type) || $c->post_lang($id) !== $c->default) {
			return;
		}
		$cur = null;
		foreach ($c->langs as $lang => $l) {
			if ($lang === $c->default || !metadata_exists('post', $id, MML_Editor::TR . $lang) || !$c->editor->outdated($id, $lang)) {
				continue;
			}
			if ($cur === null) {
				$cur = $c->editor->sig($post);
			}
			$w = $this->get($id, $lang);
			if ($w['nt'] === $cur) {
				continue;
			}
			$w['nt'] = $cur;
			if ($w['to'] && $w['st'] !== 'review') {
				$w['st'] = 'open';
			}
			$this->set($id, $lang, $w);
			$this->mail_stale($post, $lang, $w);
		}
	}

	/* ---------------------------------------------------------------
	 * Fristen
	 * ------------------------------------------------------------- */

	public function daily() {
		$c = $this->core;
		$today = wp_date('Y-m-d');
		$tomorrow = wp_date('Y-m-d', time() + DAY_IN_SECONDS);
		foreach ($this->job_post_ids() as $id) {
			$post = get_post($id);
			if (!$post) {
				continue;
			}
			foreach ($c->langs as $lang => $l) {
				$w = $this->get($id, $lang);
				if ($w['due'] === '' || !$w['to'] || $w['st'] !== 'open' || $w['dn'] === $today) {
					continue;
				}
				if ($w['due'] <= $tomorrow) {
					$w['dn'] = $today;
					$this->set($id, $lang, $w);
					$this->mail_due($post, $lang, $w, $w['due'] < $today);
				}
			}
		}
	}

	/* ---------------------------------------------------------------
	 * E-Mails
	 * ------------------------------------------------------------- */

	private function mails_on() {
		return get_option('mml_wf_mail', '1') === '1';
	}

	private function cc() {
		$out = [];
		foreach (preg_split('/[\s,;]+/', (string) get_option('mml_wf_cc', '')) as $m) {
			if ($m !== '' && is_email($m)) {
				$out[] = $m;
			}
		}
		return $out;
	}

	private function user_mail($uid) {
		$u = $uid ? get_userdata((int) $uid) : null;
		return $u ? $u->user_email : '';
	}

	private function send($to, $subject, $body) {
		$to = array_values(array_unique(array_filter((array) $to)));
		if (!$to || !$this->mails_on()) {
			return false;
		}
		return wp_mail($to, '[' . wp_specialchars_decode(get_option('blogname'), ENT_QUOTES) . '] ' . $subject, $body);
	}

	private function line($post, $lang) {
		$c = $this->core;
		$t = $post->post_title !== '' ? $post->post_title : __('(no title)', 'alba');
		return $t . ' (' . $c->langs[$c->default]['name'] . ' → ' . $c->langs[$lang]['name'] . ')';
	}

	private function link($post, $lang) {
		return $this->core->editor ? $this->core->editor->url($post->ID, $lang) : '';
	}

	private function due_text($w) {
		return $w['due'] !== '' ? __('
Deadline: ', 'alba') . wp_date('d.m.Y', strtotime($w['due'] . ' 12:00:00')) : '';
	}

	private function mail_assign($post, $lang, $uid, $w) {
		$to = $this->user_mail($uid);
		$by = get_userdata((int) $w['by']);
		$this->send($to, __('New translation assignment: ', 'alba') . $post->post_title, __('A translation has been assigned to you:

', 'alba') . $this->line($post, $lang) . $this->due_text($w) . ($by ? __('
Assigned by: ', 'alba') . $by->display_name : '') . __('

To translate:
', 'alba') . $this->link($post, $lang) . "\n");
	}

	private function mail_review($post, $lang, $w) {
		if (!$post) {
			return;
		}
		$to = $this->cc();
		$to[] = $this->user_mail($w['by']);
		if (!array_filter($to)) {
			$to[] = get_option('admin_email');
		}
		$me = wp_get_current_user();
		$this->send($to, __('For review: ', 'alba') . $post->post_title, ($me && $me->ID ? $me->display_name : __('A translator', 'alba')) . __(' has submitted a translation for review:

', 'alba') . $this->line($post, $lang) . __('

Review and publish:
', 'alba') . $this->link($post, $lang) . "\n");
	}

	private function mail_stale($post, $lang, $w) {
		$to = $this->cc();
		$to[] = $this->user_mail($w['to']);
		$this->send($to, __('Original changed: ', 'alba') . $post->post_title, __('The original was changed after the translation. The translation is outdated:

', 'alba') . $this->line($post, $lang) . $this->due_text($w) . __('

Revise the translation:
', 'alba') . $this->link($post, $lang) . "\n");
	}

	private function mail_due($post, $lang, $w, $overdue) {
		$to = [$this->user_mail($w['to'])];
		if ($overdue) {
			$to = array_merge($to, $this->cc());
		}
		$this->send($to, ($overdue ? __('Overdue: ', 'alba') : __('Deadline tomorrow: ', 'alba')) . $post->post_title, ($overdue ? __('The deadline for this translation has passed:

', 'alba') : __('Tomorrow is the deadline for this translation:

', 'alba')) . $this->line($post, $lang) . $this->due_text($w) . __('

To translate:
', 'alba') . $this->link($post, $lang) . "\n");
	}

	private function mail_return($post, $lang, $w) {
		$this->send($this->user_mail($w['to']), __('Translation returned: ', 'alba') . $post->post_title, __('Your submitted translation has been returned for revision:

', 'alba') . $this->line($post, $lang) . ($w['note'] !== '' ? __('

Note:
', 'alba') . $w['note'] : '') . __('

To revise:
', 'alba') . $this->link($post, $lang) . "\n");
	}

	/* ---------------------------------------------------------------
	 * Formulare im Editor
	 * ------------------------------------------------------------- */

	private function back($post, $lang, $msg) {
		wp_safe_redirect(add_query_arg('mml_wf', rawurlencode($msg), $this->core->editor->url($post, $lang)));
		exit;
	}

	public function handle_assign() {
		$id = isset($_POST['post']) ? (int) $_POST['post'] : 0;
		$lang = isset($_POST['lang']) ? sanitize_key(wp_unslash($_POST['lang'])) : '';
		check_admin_referer('mml_wf_' . $id . '_' . $lang);
		if (!current_user_can('edit_others_posts') || !$this->core->roles->can_translate($id, $lang)) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		$uid = isset($_POST['to']) ? (int) $_POST['to'] : 0;
		$due = isset($_POST['due']) ? sanitize_text_field(wp_unslash($_POST['due'])) : '';
		$r = $this->assign($id, $lang, $uid, $due);
		$this->back($id, $lang, $r === true ? ($uid ? __('Assignment created.', 'alba') : __('Assignment removed.', 'alba')) : $r);
	}

	public function handle_return() {
		$id = isset($_POST['post']) ? (int) $_POST['post'] : 0;
		$lang = isset($_POST['lang']) ? sanitize_key(wp_unslash($_POST['lang'])) : '';
		check_admin_referer('mml_wf_' . $id . '_' . $lang);
		if (!$this->core->roles->can_translate($id, $lang) || !$this->core->roles->can_publish($id)) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		$w = $this->get($id, $lang);
		$w['st'] = 'open';
		$w['note'] = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';
		$this->set($id, $lang, $w);
		if ($w['to']) {
			$this->mail_return(get_post($id), $lang, $w);
		}
		$this->back($id, $lang, __('Returned for revision.', 'alba'));
	}

	/** Kasten über dem Editor: Zuständig, Frist, Status; Formulare für Redakteure */
	public function box($post, $lang) {
		$id = (int) $post->ID;
		$w = $this->get($id, $lang);
		$u = $w['to'] ? get_userdata((int) $w['to']) : null;
		$can_assign = current_user_can('edit_others_posts');
		$can_pub = $this->core->roles->can_publish($id);
		if (!$can_assign && !$w['to'] && $w['st'] === '' && $w['note'] === '') {
			return;
		}
		$overdue = $w['due'] !== '' && $w['st'] === 'open' && $w['due'] < wp_date('Y-m-d');
		if (!empty($_GET['mml_wf'])) {
			echo '<div class="notice notice-success inline"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['mml_wf']))) . '</p></div>';
		}
		?>
		<div class="mml-wf" style="background:#fff;border:1px solid #dcdcde;border-left:4px solid <?php echo $w['st'] === 'review' ? '#dba617' : '#2271b1'; ?>;padding:10px 14px;margin:12px 0;max-width:1100px">
			<strong><?php echo esc_html__('Assignment', 'alba'); ?></strong> <?php echo esc_html__('· Assigned to:', 'alba'); ?> <strong><?php echo esc_html($u ? $u->display_name : esc_html__('nobody', 'alba')); ?></strong> <?php echo esc_html__('· Deadline:', 'alba'); ?> <strong<?php echo $overdue ? ' style="color:#b32d2e"' : ''; ?>><?php echo $w['due'] !== '' ? esc_html(wp_date('d.m.Y', strtotime($w['due'] . ' 12:00:00'))) . ($overdue ? esc_html__(' (overdue)', 'alba') : '') : esc_html__('none', 'alba'); ?></strong>
			<?php if ($w['st'] !== '') : ?> <?php echo esc_html__('· Status:', 'alba'); ?> <strong><?php echo esc_html($this->status_label($w['st'])); ?></strong><?php endif; ?>
			<?php if ($w['st'] === 'review') : ?>
				<?php echo $can_pub ? '<br><span class="description">' . esc_html__('Review, correct if necessary, then tick "Show on the frontend" and save. This completes the assignment.', 'alba') . '</span>' : '<br><span class="description">' . esc_html__('Submitted. An editor will review the translation.', 'alba') . '</span>'; ?>
			<?php endif; ?>
			<?php if ($w['note'] !== '' && $w['st'] === 'open') : ?><br><?php echo esc_html__('Reviewer\'s note:', 'alba'); ?> <em><?php echo esc_html($w['note']); ?></em><?php endif; ?>

			<?php if ($can_assign) : ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
					<input type="hidden" name="action" value="mml_wf_assign">
					<input type="hidden" name="post" value="<?php echo (int) $id; ?>">
					<input type="hidden" name="lang" value="<?php echo esc_attr($lang); ?>">
					<?php wp_nonce_field('mml_wf_' . $id . '_' . $lang); ?>
					<select name="to">
						<option value="0"><?php echo esc_html__('– nobody –', 'alba'); ?></option>
						<?php foreach ($this->users() as $usr) : ?><option value="<?php echo (int) $usr->ID; ?>" <?php selected((int) $w['to'], (int) $usr->ID); ?>><?php echo esc_html($usr->display_name); ?></option><?php endforeach; ?>
					</select>
					<input type="date" name="due" value="<?php echo esc_attr($w['due']); ?>">
					<button class="button"><?php echo esc_html__('Assign', 'alba'); ?></button>
				</form>
			<?php endif; ?>
			<?php if ($w['st'] === 'review' && $can_pub) : ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
					<input type="hidden" name="action" value="mml_wf_return">
					<input type="hidden" name="post" value="<?php echo (int) $id; ?>">
					<input type="hidden" name="lang" value="<?php echo esc_attr($lang); ?>">
					<?php wp_nonce_field('mml_wf_' . $id . '_' . $lang); ?>
					<input type="text" name="note" class="regular-text" placeholder="<?php echo esc_attr__('Note for the translator (optional)', 'alba'); ?>">
					<button class="button"><?php echo esc_html__('Return for revision', 'alba'); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------
	 * Einstellungen
	 * ------------------------------------------------------------- */

	public function register() {
		register_setting('mml', 'mml_wf_mail', ['sanitize_callback' => function ($v) {
			return $v === '1' ? '1' : '0';
		}]);
		register_setting('mml', 'mml_wf_cc', ['sanitize_callback' => function ($v) {
			$out = [];
			foreach (preg_split('/[\s,;]+/', (string) $v) as $m) {
				if ($m !== '' && is_email($m)) {
					$out[] = sanitize_email($m);
				}
			}
			return implode(', ', array_unique($out));
		}]);
	}

	public function card() {
		?>
		<section class="mml-card" data-tab="lang">
			<header class="mml-card-h"><span class="dashicons dashicons-email-alt"></span><div><h2><?php echo esc_html__('Assignments & notifications', 'alba'); ?></h2><p><?php echo esc_html__('Assign, deadlines, for review, emails.', 'alba'); ?></p></div></header>
			<div class="mml-card-b">
				<p><?php echo esc_html__('You assign translations in the translation editor or via bulk action in the "My translations" list. Translators submit finished texts "for review"; an editor approves or returns them.', 'alba'); ?></p>
				<div class="mml-fields">
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('Emails', 'alba'); ?></div><div class="mml-ctl">
						<label><input type="checkbox" name="mml_wf_mail" value="1" <?php checked(get_option('mml_wf_mail', '1'), '1'); ?>> <?php echo esc_html__('Send notifications by email', 'alba'); ?></label>
						<p class="mml-hint"><?php echo esc_html__('On assignment, submission for review, return, changed original after translation, and at the deadline (the day before and when overdue, once a day).', 'alba'); ?></p>
					</div></div>
					<div class="mml-field"><div class="mml-label"><?php echo esc_html__('Additional address(es)', 'alba'); ?></div><div class="mml-ctl">
						<input type="text" class="regular-text" name="mml_wf_cc" value="<?php echo esc_attr((string) get_option('mml_wf_cc', '')); ?>" placeholder="redaktion@example.com">
						<p class="mml-hint"><?php echo esc_html__('Receives notifications about submitted translations, changed originals and overdue deadlines. Separate several addresses with commas. If empty, submissions go to the person who made the assignment (otherwise to the administrator address).', 'alba'); ?></p>
					</div></div>
				</div>
			</div>
		</section>
		<?php
	}
}
