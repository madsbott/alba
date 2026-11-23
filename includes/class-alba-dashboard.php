<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Hauptmenü "Sprachen" im Backend: Übersicht mit Status, Abdeckung der Übersetzungen,
 * Schnellzugriff auf alle Bereiche und Kurzhilfe. Unterseiten (Einstellungen, Zeichenfolgen,
 * Import/Export) registrieren sich selbst unter diesem Menü.
 */
class ALBA_Dashboard {
	const SLUG = 'alba';
	const LIMIT = 2000;
	const OLD_CHECK = 300;   // höchstens so viele Inhalte werden auf "veraltet" geprüft

	private $core;

	public function __construct(ALBA_Core $core) {
		$this->core = $core;
		if (!is_admin()) {
			return;
		}
		add_action('admin_menu', [$this, 'menu'], 9);
		add_action('admin_init', [$this, 'legacy_redirect']);
		add_filter('plugin_action_links_' . plugin_basename(ALBA_FILE), [$this, 'action_links']);
	}

	public function menu() {
		add_menu_page('Alba', __('Languages', 'alba'), 'manage_options', self::SLUG, [$this, 'page'], 'dashicons-translation', 58);
		add_submenu_page(self::SLUG, __('Overview', 'alba'), __('Overview', 'alba'), 'manage_options', self::SLUG, [$this, 'page']);
	}

	public function action_links($links) {
		array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">' . esc_html__('Overview', 'alba') . '</a>');
		return $links;
	}

	/** Alte Adressen unter "Einstellungen" leiten auf das neue Menü um */
	public function legacy_redirect() {
		global $pagenow;
		if ($pagenow !== 'options-general.php' || empty($_GET['page'])) {
			return;
		}
		$map = [
			'alba'         => 'alba-settings',
			'alba-strings' => 'alba-strings',
		];
		$p = sanitize_key(wp_unslash($_GET['page']));
		if (isset($map[$p])) {
			wp_safe_redirect(admin_url('admin.php?page=' . $map[$p]));
			exit;
		}
	}

	/* ---------------------------------------------------------------
	 * Seite
	 * ------------------------------------------------------------- */

	private function url($page) {
		return esc_url(admin_url('admin.php?page=' . $page));
	}

	public function page() {
		if (!current_user_can('manage_options')) {
			return;
		}
		$c = $this->core;
		?>
		<div class="wrap">
			<h1>Alba <span style="font-size:13px;color:#646970;font-weight:400"><?php echo esc_html__('Version', 'alba'); ?> <?php echo esc_html(ALBA_VERSION); ?></span></h1>

			<?php if (!get_option('permalink_structure')) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html__('Pretty permalinks are not active. Language URLs then only work as', 'alba'); ?> <code>?lang=xx</code><?php echo esc_html__('. Recommended: choose a structure under Settings > Permalinks.', 'alba'); ?></p></div>
			<?php endif; ?>
			<?php if (count($c->langs) < 2) : ?>
				<div class="notice notice-info inline"><p><?php echo esc_html__('Only one language has been added.', 'alba'); ?> <a href="<?php echo esc_url($this->url('alba-settings')); ?>"><?php echo esc_html__('Add more languages', 'alba'); ?></a></p></div>
			<?php endif; ?>

			<h2><?php echo esc_html__('Quick access', 'alba'); ?></h2>
			<p>
				<a class="button" href="<?php echo esc_url($this->url('alba-settings')); ?>"><?php echo esc_html__('Settings', 'alba'); ?></a>
				<a class="button" href="<?php echo esc_url($this->url('alba-strings')); ?>"><?php echo esc_html__('Strings', 'alba'); ?></a>
				<a class="button" href="<?php echo esc_url($this->url('alba-po')); ?>"><?php echo esc_html__('Import / Export (.po)', 'alba'); ?></a>
				<a class="button" href="<?php echo esc_url($this->url('alba-glossary')); ?>"><?php echo esc_html__('Glossary & Memory', 'alba'); ?></a>
				<a class="button" href="<?php echo esc_url($this->url('alba-log')); ?>"><?php echo esc_html__('Log', 'alba'); ?></a>
				<a class="button" href="<?php echo esc_url($this->url('alba-xliff')); ?>"><?php echo esc_html__('XLIFF (Export / Import)', 'alba'); ?></a>
				<?php if (function_exists('wp_is_block_theme') && wp_is_block_theme()) : ?>
					<a class="button" href="<?php echo esc_url(admin_url('site-editor.php')); ?>"><?php echo esc_html__('Site Editor (navigation, switcher block)', 'alba'); ?></a>
				<?php endif; ?>
			</p>

			<h2><?php echo esc_html__('Languages', 'alba'); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th><?php echo esc_html__('Code', 'alba'); ?></th><th><?php echo esc_html__('Name', 'alba'); ?></th><th><?php echo esc_html__('Locale', 'alba'); ?></th><th><?php echo esc_html__('Home page', 'alba'); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ($c->langs as $code => $l) : ?>
					<tr>
						<td><code><?php echo esc_html($code); ?></code></td>
						<td><?php echo esc_html($l['name']); ?></td>
						<td><?php echo esc_html($l['locale']); ?></td>
						<td><a href="<?php echo esc_url($c->home_in($code)); ?>" target="_blank" rel="noopener"><?php echo esc_html($c->home_in($code)); ?></a></td>
						<td><?php echo $code === $c->default ? '<strong>Standard</strong>' : ''; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php $this->open_section(); ?>

			<h2><?php echo esc_html__('Translation status', 'alba'); ?></h2>
			<?php $this->coverage_table(); ?>

			<h2><?php echo esc_html__('Strings', 'alba'); ?></h2>
			<?php $this->strings_status(); ?>

			<h2><?php echo esc_html__('Quick help', 'alba'); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<tr><td><strong><?php echo esc_html__('Language switcher', 'alba'); ?></strong></td><td><?php echo esc_html__('Block "Language switcher" (widgets), shortcode', 'alba'); ?> <code>[alba_switcher style="dropdown" display="code"]</code><?php echo esc_html__(', function', 'alba'); ?> <code>alba_switcher()</code><?php echo esc_html__(', or automatically appended to the menu or block navigation (Settings).', 'alba'); ?></td></tr>
					<tr><td><strong><?php echo esc_html__('Posts & pages', 'alba'); ?></strong></td><td><?php echo esc_html__('In the editor, box "Language & Translations": translate in the side-by-side editor (red ⟳ in the language code = original changed), or create a separate post or link one. The lists have the "Languages" column and the language filter.', 'alba'); ?></td></tr>
					<tr><td><strong><?php echo esc_html__('Taxonomies', 'alba'); ?></strong></td><td><?php echo esc_html__('Categories, tags & co.: language per term, translations in the edit form. In the editor, the selection only shows terms in the post\'s language.', 'alba'); ?></td></tr>
					<tr><td><strong><?php echo esc_html__('Theme texts', 'alba'); ?></strong></td><td><code>alba__('Text', 'Domain', 'name')</code>, <code>alba_e()</code>, <code>[alba_string domain="Theme"]Text[/alba_string]</code> <?php echo esc_html__('or gettext scan under Strings.', 'alba'); ?></td></tr>
					<tr><td><strong><?php echo esc_html__('For developers', 'alba'); ?></strong></td><td><code>alba_current_lang()</code>, <code>alba_get_translation($post_id, $lang)</code>, <code>alba_get_term_translation($term_id, $lang)</code>.</td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------
	 * Offene Texte
	 * ------------------------------------------------------------- */

	/** Offene Texte je Sprache (nur Originale in der Standardsprache ohne eigenen Übersetzungs-Beitrag) */
	private function open_texts() {
		$c = $this->core;
		$targets = array_diff_key($c->langs, [$c->default => 1]);
		$res = ['langs' => [], 'top' => [], 'partial' => false];
		foreach ($targets as $code => $l) {
			$res['langs'][$code] = ['open' => 0, 'total' => 0, 'pages' => 0];
		}
		if (!$targets || !$c->editor) {
			return $res;
		}
		$cache = get_option('alba_open_cache', []);
		$cache = is_array($cache) ? $cache : [];
		$seen = [];
		$start = microtime(true);
		$salt = md5(implode('|', [get_option('alba_meta_keys', ''), get_option('alba_extra_attrs', ''), get_option('alba_t_attrs', 1), get_option('alba_t_blocks', 1), get_option('alba_t_acf', 1), (int) floor(time() / 3600)]));
		foreach ($c->post_types as $t) {
			$posts = get_posts(['post_type' => $t, 'post_status' => 'publish', 'posts_per_page' => self::LIMIT, 'orderby' => 'none', 'no_found_rows' => true, 'suppress_filters' => true, 'alba_skip' => 1]);
			update_meta_cache('post', wp_list_pluck($posts, 'ID'));
			foreach ($posts as $post) {
				$id = (int) $post->ID;
				if ($c->post_lang($id) !== $c->default) {
					continue;
				}
				$trs = $c->translations($id);
				foreach ($targets as $code => $l) {
					if (!empty($trs[$code]) && (int) $trs[$code] !== $id) {
						continue;   // eigener Übersetzungs-Beitrag
					}
					$d = $c->editor->data($id, $code);
					$key = $id . '|' . $code;
					$stamp = md5($post->post_modified_gmt . '|' . $d['time'] . '|' . $salt);
					if (isset($cache[$key]) && $cache[$key][0] === $stamp) {
						list(, $open, $total) = $cache[$key];
					} elseif (microtime(true) - $start < 5) {
						list($open, $total) = $c->editor->open_count($post, $code);
						$cache[$key] = [$stamp, $open, $total];
					} else {
						$res['partial'] = true;
						continue;
					}
					$seen[$key] = $cache[$key];
					$res['langs'][$code]['open'] += $open;
					$res['langs'][$code]['total'] += $total;
					if ($open > 0) {
						$res['langs'][$code]['pages']++;
						$res['top'][] = [$open, $id, $code, $post->post_title];
					}
				}
			}
		}
		if (!$res['partial']) {
			$cache = $seen;
		}
		update_option('alba_open_cache', $cache, false);
		usort($res['top'], function ($a, $b) {
			return $b[0] - $a[0];
		});
		$res['top'] = array_slice($res['top'], 0, 8);
		return $res;
	}

	private function open_section() {
		$c = $this->core;
		if (count($c->langs) < 2 || !$c->editor) {
			return;
		}
		$o = $this->open_texts();
		?>
		<h2><?php echo esc_html__('Open texts', 'alba'); ?></h2>
		<table class="widefat striped" style="max-width:900px">
			<thead><tr><th><?php echo esc_html__('Language', 'alba'); ?></th><th><?php echo esc_html__('Open', 'alba'); ?></th><th><?php echo esc_html__('Translated', 'alba'); ?></th><th style="width:30%"><?php echo esc_html__('Progress', 'alba'); ?></th><th><?php echo esc_html__('Affected content', 'alba'); ?></th></tr></thead>
			<tbody>
			<?php foreach ($o['langs'] as $code => $s) :
				$done = max(0, $s['total'] - $s['open']);
				$pct = $s['total'] > 0 ? (int) round($done * 100 / $s['total']) : 100;
				?>
				<tr>
					<td><strong><?php echo esc_html($c->langs[$code]['name']); ?></strong></td>
					<td><?php echo $s['open'] > 0 ? '<strong style="color:#b32d2e">' . (int) $s['open'] . '</strong>' : '<span style="color:#1e8a3c">0</span>'; ?></td>
					<td><?php echo (int) $done; ?> <?php echo esc_html__('of', 'alba'); ?> <?php echo (int) $s['total']; ?></td>
					<td><div style="background:#e0e0e0;border-radius:4px;height:10px;overflow:hidden"><div style="width:<?php echo (int) $pct; ?>%;height:10px;background:<?php echo $pct >= 100 ? '#1e8a3c' : '#2271b1'; ?>"></div></div><span style="font-size:12px;color:#646970"><?php echo (int) $pct; ?> %</span></td>
					<td><?php echo (int) $s['pages']; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ($o['top']) : ?>
			<p style="margin-top:10px"><strong><?php echo esc_html__('Most open texts:', 'alba'); ?></strong>
			<?php
			$bits = [];
			foreach ($o['top'] as $t) {
				$bits[] = '<a href="' . esc_url($c->editor->url($t[1], $t[2])) . '">' . esc_html(($t[3] !== '' ? $t[3] : __('(no title)', 'alba')) . ' (' . strtoupper($t[2]) . ')') . '</a> <span style="color:#b32d2e">' . (int) $t[0] . '</span>';
			}
			echo implode(' · ', $bits); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each part is escaped above.
			?></p>
		<?php endif; ?>
		<p class="description"><?php echo esc_html__('Counted are titles, excerpts, SEO texts and all text sections (including alt texts, block and field texts) of published originals that are not yet translated into this language. Content with its own translation post is not counted.', 'alba'); ?><?php echo $o['partial'] ? ' <strong>' . esc_html__('Partially calculated', 'alba') . '</strong> ' . esc_html__('(very many items), it continues on the next visit.', 'alba') : ''; ?></p>
		<?php
	}

	/* ---------------------------------------------------------------
	 * Abdeckung
	 * ------------------------------------------------------------- */

	/** @return array{0:array,1:array,2:int,3:bool} Anzahl pro Sprache, fehlend pro Sprache, Gesamt, abgeschnitten */
	private function tally(array $items, array $extra = []) {
		$c = $this->core;
		$count = array_fill_keys(array_keys($c->langs), 0);
		$groups = [];
		foreach ($items as $it) {
			if (isset($count[$it[0]])) {
				$count[$it[0]]++;
			}
			$groups[$it[1] !== '' ? $it[1] : 'x' . $it[2]][$it[0]] = true;
		}
		foreach ($extra as $e) {
			$groups[$e[1]][$e[0]] = true;
		}
		$missing = array_fill_keys(array_keys($c->langs), 0);
		foreach ($groups as $have) {
			foreach ($c->langs as $code => $l) {
				if (!isset($have[$code])) {
					$missing[$code]++;
				}
			}
		}
		return [$count, $missing, count($items), count($items) >= self::LIMIT];
	}

	private function coverage_table() {
		$c = $this->core;
		$rows = [];

		foreach ($c->post_types as $t) {
			$obj = get_post_type_object($t);
			if (!$obj) {
				continue;
			}
			$ids = get_posts([
				'post_type'        => $t,
				'post_status'      => ['publish', 'draft', 'pending', 'private', 'future'],
				'posts_per_page'   => self::LIMIT,
				'fields'           => 'ids',
				'orderby'          => 'none',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'alba_skip'         => 1,
			]);
			$items = [];
			$extra = [];
			$zero = array_fill_keys(array_keys($c->langs), 0);
			$ov_on = $zero;
			$ov_draft = $zero;
			$old = $zero;
			$checked = 0;
			update_meta_cache('post', $ids);
			foreach ($ids as $id) {
				$id = (int) $id;
				$g = (string) get_post_meta($id, '_alba_group', true);
				$lang = $c->post_lang($id);
				$items[] = [$lang, $g, $id];
				if (!$c->editor) {
					continue;
				}
				foreach ($c->langs as $code => $l) {
					if ($code === $lang) {
						continue;
					}
					$st = $c->editor->state($id, $code);
					if ($st === '') {
						continue;
					}
					$extra[] = [$code, $g !== '' ? $g : 'x' . $id];
					$st === 'on' ? $ov_on[$code]++ : $ov_draft[$code]++;
					if ($checked < self::OLD_CHECK) {
						$checked++;
						if ($c->editor->outdated($id, $code)) {
							$old[$code]++;
						}
					}
				}
				// eigene Übersetzungs-Beiträge: Original seit dem Speichern geändert?
				if ($lang !== $c->default && $checked < self::OLD_CHECK) {
					$checked++;
					if ($c->editor->outdated_real($id)) {
						$old[$lang]++;
					}
				}
			}
			list($count, $missing, $total, $capped) = $this->tally($items, $extra);
			foreach ($count as $code => $n) {
				$count[$code] = $n + $ov_on[$code];
			}
			$rows[] = [
				'label'   => $obj->labels->name,
				'count'   => $count,
				'missing' => $missing,
				'draft'   => $ov_draft,
				'old'     => $old,
				'total'   => $total,
				'capped'  => $capped,
				'link'    => function ($code) use ($t, $ov_on, $ov_draft) {
					$url = 'edit.php?post_type=' . rawurlencode($t);
					if (($ov_on[$code] + $ov_draft[$code]) === 0) {
						$url .= '&alba_lang=' . rawurlencode($code);
					}
					return admin_url($url);
				},
			];
		}

		foreach ($c->taxonomies as $tx) {
			$obj = taxonomy_exists($tx) ? get_taxonomy($tx) : null;
			if (!$obj) {
				continue;
			}
			$ids = get_terms(['taxonomy' => $tx, 'hide_empty' => false, 'fields' => 'ids', 'number' => self::LIMIT, 'alba_skip' => 1]);
			if (is_wp_error($ids)) {
				continue;
			}
			$items = [];
			foreach ($ids as $id) {
				$items[] = [$c->terms ? $c->terms->lang((int) $id) : $c->default, (string) get_term_meta($id, '_alba_group', true), (int) $id];
			}
			list($count, $missing, $total, $capped) = $this->tally($items);
			$rows[] = [
				'label'   => $obj->labels->name,
				'count'   => $count,
				'missing' => $missing,
				'draft'   => array_fill_keys(array_keys($c->langs), 0),
				'old'     => array_fill_keys(array_keys($c->langs), 0),
				'total'   => $total,
				'capped'  => $capped,
				'link'    => function ($code) use ($tx) {
					return admin_url('edit-tags.php?taxonomy=' . rawurlencode($tx));
				},
			];
		}

		if (!$rows) {
			echo '<p>' . esc_html__('No translatable content types selected.', 'alba') . '</p>';
			return;
		}
		?>
		<table class="widefat striped" style="max-width:900px">
			<thead>
				<tr>
					<th><?php echo esc_html__('Content', 'alba'); ?></th>
					<?php foreach ($c->langs as $code => $l) : ?>
						<th><?php echo esc_html($l['name']); ?></th>
					<?php endforeach; ?>
					<th><?php echo esc_html__('Total', 'alba'); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($rows as $r) : ?>
				<tr>
					<td><strong><?php echo esc_html($r['label']); ?></strong></td>
					<?php foreach ($c->langs as $code => $l) : ?>
						<td>
							<a href="<?php echo esc_url(call_user_func($r['link'], $code)); ?>"><?php echo (int) $r['count'][$code]; ?></a>
							<?php if ($r['old'][$code] > 0) : ?>
								<span style="color:#d63638" title="<?php echo esc_attr__('The original was changed after the last translation', 'alba'); ?>"> · <?php echo (int) $r['old'][$code]; ?> <span class="dashicons dashicons-update" style="font-size:14px;width:14px;height:14px;vertical-align:text-bottom"></span> <?php echo esc_html__('outdated', 'alba'); ?></span>
							<?php endif; ?>
							<?php if ($r['draft'][$code] > 0) : ?>
								<span style="color:#996800" title="<?php echo esc_attr__('Saved in the editor but not yet published on the frontend', 'alba'); ?>"> · <?php echo (int) $r['draft'][$code]; ?> <?php echo esc_html__('not published', 'alba'); ?></span>
							<?php endif; ?>
							<?php if ($r['missing'][$code] > 0 && count($c->langs) > 1) : ?>
								<span style="color:#b32d2e" title="<?php echo esc_attr__('Content for which there is no translation in this language yet', 'alba'); ?>"> · <?php echo (int) $r['missing'][$code]; ?> fehlen</span>
							<?php endif; ?>
						</td>
					<?php endforeach; ?>
					<td><?php echo (int) $r['total']; ?><?php echo $r['capped'] ? '+' : ''; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php echo esc_html__('The number per language covers content in this language and content translated in the side-by-side editor (published).', 'alba'); ?> <strong><?php echo esc_html__('outdated', 'alba'); ?></strong><?php echo esc_html__(': the original was changed after the last translation.', 'alba'); ?> <strong><?php echo esc_html__('not published', 'alba'); ?></strong><?php echo esc_html__(': saved in the editor, not yet visible on the frontend.', 'alba'); ?> <strong><?php echo esc_html__('missing', 'alba'); ?></strong><?php echo esc_html__(': no translation yet. "Total" counts originals and separate translation posts, including drafts and private content. At most', 'alba'); ?> <?php echo (int) self::LIMIT; ?> <?php echo esc_html__('entries per type are counted, and for "outdated" at most', 'alba'); ?> <?php echo (int) self::OLD_CHECK; ?>.</p>
		<?php
	}

	private function strings_status() {
		$c = $this->core;
		$all = get_option(ALBA_Strings::OPT, []);
		$all = is_array($all) ? $all : [];
		$open = array_fill_keys(array_keys($c->langs), 0);
		foreach ($all as $s) {
			foreach ($c->langs as $code => $l) {
				if (empty($s['tr'][$code])) {
					$open[$code]++;
				}
			}
		}
		echo '<p>';
		if ($all) {
			echo esc_html(count($all) . __(' strings captured. Without translation: ', 'alba'));
			$bits = [];
			foreach ($c->langs as $code => $l) {
				if ($code === $c->default) {
					continue;
				}
				$bits[] = $l['name'] . ' ' . $open[$code];
			}
			echo esc_html($bits ? implode(', ', $bits) : '–');
			echo '. <a href="' . esc_url($this->url('alba-strings')) . '">' . esc_html__('Open strings', 'alba') . '</a>';
		} else {
			echo '' . esc_html__('No strings captured yet.', 'alba') . ' <a href="' . esc_url($this->url('alba-strings')) . '">' . esc_html__('Open strings', 'alba') . '</a>';
		}
		echo '</p>';
	}
}
