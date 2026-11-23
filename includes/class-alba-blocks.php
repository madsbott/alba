<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Block themes: navigation (core/navigation and its links) per language.
 *
 * - Links to posts/pages/terms point to the translation; without a translation the link is hidden
 * - Link label: if it matches the title of the target, the translated title is used, otherwise it goes
 *   through string translation as a string (domain "Navigation") (manual, .po)
 * - The link to the home page gets the language prefix
 * - The "Page list" block (core/page-list) shows only pages of the current language
 * - Optional: the language switcher is appended to the first or to all navigations
 * A single navigation is used for all languages; in the editor/REST everything stays unchanged.
 */
class ALBA_Blocks {
	private $core;
	private $nav_count = 0;

	public function __construct(ALBA_Core $core) {
		$this->core = $core;
		if (is_admin()) {
			return;
		}
		add_filter('render_block_data', [$this, 'block_data'], 10, 3);
		add_filter('render_block_core/navigation-link', [$this, 'maybe_hide'], 10, 3);
		add_filter('render_block_core/navigation-submenu', [$this, 'maybe_hide'], 10, 3);
		add_filter('render_block_core/navigation', [$this, 'append_switcher'], 10, 3);
		add_filter('get_pages', [$this, 'filter_pages'], 10, 2);
	}

	private function skip() {
		return wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI);
	}

	private function same_text($a, $b) {
		return html_entity_decode(trim((string) $a), ENT_QUOTES, 'UTF-8') === html_entity_decode(trim((string) $b), ENT_QUOTES, 'UTF-8');
	}

	/* ---------------------------------------------------------------
	 * Navigations-Links
	 * ------------------------------------------------------------- */

	public function block_data($block, $source_block = null, $parent_block = null) {
		$name = isset($block['blockName']) ? $block['blockName'] : '';
		if (!in_array($name, ['core/navigation-link', 'core/navigation-submenu', 'core/home-link'], true) || $this->skip()) {
			return $block;
		}
		$c = $this->core;
		$lang = $c->lang;
		$a = (isset($block['attrs']) && is_array($block['attrs'])) ? $block['attrs'] : [];
		$label = isset($a['label']) ? (string) $a['label'] : '';
		$id = isset($a['id']) ? (int) $a['id'] : 0;
		$kind = isset($a['kind']) ? (string) $a['kind'] : '';

		$orig_title = '';
		$new_title = '';

		if ($name !== 'core/home-link' && $id && $kind === 'post-type') {
			$pt = get_post_type($id);
			if ($pt) {
				$orig_title = $c->raw_title($id);
				if ($c->is_translatable($pt) && $c->post_lang($id) !== $lang) {
					$tid = $c->translation($id, $lang);
					if (!$tid) {
						$a['albaHide'] = true;
						$block['attrs'] = $a;
						return $block;
					}
					$a['id'] = $tid;
					$a['url'] = get_permalink($tid);
					$new_title = get_the_title($tid);
				}
			}
		} elseif ($name !== 'core/home-link' && $id && $kind === 'taxonomy' && $c->terms) {
			$t = get_term($id);
			if ($t && !is_wp_error($t)) {
				$orig_title = $t->name;
				if (in_array($t->taxonomy, $c->taxonomies, true) && $c->terms->lang($id) !== $lang) {
					$tid = $c->terms->translation($id, $lang);
					$link = $tid ? $c->terms->link($tid, $lang) : '';
					if ($link === '') {
						$a['albaHide'] = true;
						$block['attrs'] = $a;
						return $block;
					}
					$a['id'] = $tid;
					$a['url'] = $link;
					$tt = get_term($tid);
					$new_title = ($tt && !is_wp_error($tt)) ? $tt->name : '';
				}
			}
		} elseif ($name !== 'core/home-link' && !empty($a['url'])) {
			$home = untrailingslashit(set_url_scheme(get_option('home')));
			if (untrailingslashit((string) $a['url']) === $home) {
				$a['url'] = $c->home_in($lang);
			}
		}

		if ($label !== '') {
			$is_auto = ($orig_title !== '' && $this->same_text($label, $orig_title));
			if ($is_auto) {
				if ($new_title !== '') {
					$a['label'] = $new_title;
				}
			} elseif ($c->strings) {
				$a['label'] = $c->strings->translate($label, __('Navigation', 'alba'));
			}
		}

		$block['attrs'] = $a;
		return $block;
	}

	public function maybe_hide($content, $block = null, $instance = null) {
		if (is_array($block) && !empty($block['attrs']['albaHide'])) {
			return '';
		}
		return $content;
	}

	/* ---------------------------------------------------------------
	 * Seitenliste (core/page-list, wp_list_pages)
	 * ------------------------------------------------------------- */

	public function filter_pages($pages, $args = []) {
		if (!is_array($pages) || $this->skip() || !$this->core->is_translatable('page')) {
			return $pages;
		}
		$c = $this->core;
		$out = [];
		foreach ($pages as $p) {
			if (!is_object($p) || !isset($p->post_type) || $p->post_type !== 'page' || $c->post_lang((int) $p->ID) === $c->lang) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------
	 * Language switcher in the navigation
	 * ------------------------------------------------------------- */

	public function append_switcher($content, $block = null, $instance = null) {
		$mode = get_option('alba_nav_switcher', '');
		if ($mode === '' || $this->skip() || trim((string) $content) === '') {
			return $content;
		}
		$this->nav_count++;
		if ($mode === 'first' && $this->nav_count > 1) {
			return $content;
		}
		$items = $this->items();
		if ($items === '') {
			return $content;
		}
		$pos = strrpos($content, '</ul>');
		if ($pos === false) {
			return $content;
		}
		return substr($content, 0, $pos) . $items . substr($content, $pos);
	}

	private function items() {
		$f = $this->core->frontend;
		if (!$f) {
			return '';
		}
		$display = get_option('alba_display', 'name');
		$hide = (int) get_option('alba_hide_missing', 0);
		if (get_option('alba_menu_style', 'list') === 'dropdown') {
			$dd = $f->dropdown_item(['display' => $display, 'hide_missing' => $hide], 'block');
			if ($dd !== '') {
				return $dd;
			}
		}
		$html = '';
		foreach ($f->links() as $l) {
			if ($hide && $l['missing']) {
				continue;
			}
			$label = ($display === 'code') ? strtoupper($l['code']) : $l['name'];
			$cls = 'wp-block-navigation-item wp-block-navigation-link alba-lang alba-lang-' . $l['code']
				. ($l['current'] ? ' alba-current current-menu-item' : '')
				. ($l['missing'] ? ' alba-missing' : '');
			$html .= '<li class="' . esc_attr($cls) . '"><a class="wp-block-navigation-item__content" href="' . esc_url($l['url']) . '" hreflang="' . esc_attr($l['code']) . '" lang="' . esc_attr($l['code']) . '"'
				. ($l['current'] ? ' aria-current="true"' : '') . '><span class="wp-block-navigation-item__label">' . esc_html($label) . '</span></a></li>';
		}
		return $html;
	}
}
