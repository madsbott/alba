<?php
if (!defined('ABSPATH')) {
	exit;
}

class MML_Frontend {
	private $core;

	public function __construct(MML_Core $core) {
		$this->core = $core;
		$core->frontend = $this;

		if (is_admin()) {
			return;
		}

		add_shortcode('mml_switcher', [$this, 'shortcode']);
		add_action('wp_head', [$this, 'hreflang'], 1);
		add_action('wp_head', [$this, 'css'], 99);
		add_filter('wp_get_nav_menu_items', [$this, 'translate_menu']);
		add_filter('wp_nav_menu_items', [$this, 'menu_switcher'], 10, 2);
		add_filter('body_class', [$this, 'body_class']);
	}

	public function body_class($classes) {
		$classes[] = 'mml-lang-' . $this->core->lang;
		return $classes;
	}

	/* ---------------------------------------------------------------
	 * Sprachumschalter
	 * ------------------------------------------------------------- */

	public function links() {
		$c = $this->core;
		$id = 0;
		if (is_singular()) {
			$id = (int) get_queried_object_id();
		} elseif (is_home() && !is_front_page()) {
			$id = (int) get_option('page_for_posts');
		}
		if ($id && !$c->is_translatable(get_post_type($id))) {
			$id = 0;
		}

		$term = 0;
		if (!$id && $c->terms && (is_category() || is_tag() || is_tax())) {
			$qo = get_queried_object();
			if ($qo instanceof WP_Term && in_array($qo->taxonomy, $c->taxonomies, true)) {
				$term = (int) $qo->term_id;
			}
		}

		$out = [];
		foreach ($c->langs as $code => $l) {
			$missing = false;
			if ($term) {
				$tid = $c->terms->translation($term, $code);
				$url = $tid ? $c->terms->link($tid, $code) : '';
				if ($url === '') {
					$missing = true;
					$url = $c->home_in($code);
				}
			} elseif ($id) {
				$tid = $c->translation($id, $code);
				if ($tid) {
					$url = $c->with_lang($code, function () use ($tid) {
						return get_permalink($tid);
					});
				} else {
					$missing = true;
					$url = $c->home_in($code);
				}
			} else {
				$url = $c->home_in($code);
			}
			$out[] = [
				'code'    => $code,
				'name'    => $l['name'],
				'url'     => $url,
				'current' => ($code === $c->lang),
				'missing' => $missing,
			];
		}
		return $out;
	}

	private function defaults($args) {
		return wp_parse_args($args, [
			'style'        => 'list',   // list | dropdown (Auswahlfeld) | menu (Dropdown-Menü im Theme-Design)
			'display'      => 'name',   // name | code
			'hide_missing' => 0,
			'hide_current' => 0,
		]);
	}

	private function label($l, $a) {
		return $a['display'] === 'code' ? strtoupper($l['code']) : $l['name'];
	}

	public function items($args = []) {
		$a = $this->defaults($args);
		$html = '';
		foreach ($this->links() as $l) {
			if (($a['hide_missing'] && $l['missing']) || ($a['hide_current'] && $l['current'])) {
				continue;
			}
			$cls = 'mml-lang mml-lang-' . $l['code'] . ($l['current'] ? ' mml-current' : '') . ($l['missing'] ? ' mml-missing' : '');
			$html .= '<li class="' . esc_attr($cls) . '"><a href="' . esc_url($l['url']) . '" hreflang="' . esc_attr($l['code']) . '" lang="' . esc_attr($l['code']) . '"'
				. ($l['current'] ? ' aria-current="true"' : '') . '>' . esc_html($this->label($l, $a)) . '</a></li>';
		}
		return $html;
	}

	/**
	 * Dropdown-Menü: aktuelle Sprache als Menüpunkt, die übrigen als Untermenü.
	 * Nutzt die Menü-Klassen des Themes bzw. der Block-Navigation, damit das Design des Themes greift.
	 * $ctx: 'classic' (wp_nav_menu, Shortcode) oder 'block' (Navigations-Block)
	 */
	public function dropdown_item($args = [], $ctx = 'classic', $items_html = '') {
		$a = $this->defaults($args);
		$block = ($ctx === 'block');
		$salient = !$block && $this->is_salient();
		$arrow = $block ? '' : $this->arrow($items_html);
		$cur = null;
		$others = [];
		foreach ($this->links() as $l) {
			if ($l['current']) {
				$cur = $l;
				continue;
			}
			if ($a['hide_missing'] && $l['missing']) {
				continue;
			}
			$others[] = $l;
		}
		if (!$cur || !$others) {
			return '';
		}

		$link = function ($l, $parent) use ($a, $block, $salient, $arrow) {
			$lab = esc_html($this->label($l, $a));
			if ($salient) {
				$lab = '<span class="menu-title-text">' . $lab . '</span>';
			}
			if ($parent && $arrow !== '') {
				$lab .= $arrow;
			}
			$attrs = ' href="' . esc_url($l['url']) . '" hreflang="' . esc_attr($l['code']) . '" lang="' . esc_attr($l['code']) . '"' . ($parent ? ' aria-haspopup="true"' : '');
			if ($block) {
				return '<a class="wp-block-navigation-item__content"' . $attrs . '><span class="wp-block-navigation-item__label">' . $lab . '</span></a>';
			}
			return '<a' . $attrs . '>' . $lab . '</a>';
		};

		$sub = '';
		foreach ($others as $l) {
			$cls = ($block ? 'wp-block-navigation-item wp-block-navigation-link' : 'menu-item menu-item-type-custom menu-item-object-custom')
				. ' mml-lang mml-lang-' . $l['code'] . ($l['missing'] ? ' mml-missing' : '');
			$sub .= '<li class="' . esc_attr($cls) . '">' . $link($l, false) . '</li>';
		}

		$pcls = ($block ? 'wp-block-navigation-item has-child wp-block-navigation-submenu' : 'menu-item menu-item-type-custom menu-item-object-custom menu-item-has-children')
			. ' mml-dd mml-lang mml-lang-' . $cur['code'] . ' mml-current';
		$icon = $block
			? '<span class="wp-block-navigation__submenu-icon"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true" focusable="false"><path d="M1.50002 4L6.00002 8L10.5 4" stroke="currentColor" stroke-width="1.5"></path></svg></span>'
			: '';
		return '<li class="' . esc_attr($pcls) . '">' . $link($cur, true) . $icon
			. '<ul class="' . ($block ? 'wp-block-navigation__submenu-container wp-block-navigation-submenu' : 'sub-menu') . '">' . $sub . '</ul></li>';
	}

	private function is_salient() {
		$t = wp_get_theme();
		return in_array('salient', [strtolower((string) $t->get_stylesheet()), strtolower((string) $t->get_template())], true);
	}

	/**
	 * Zeigt das Theme (Salient: „Header Dropdown Arrows“) bei Menüpunkten mit Untermenü einen Pfeil?
	 * Gibt [bool, Begründung] zurück (die Begründung erscheint in den Einstellungen).
	 */
	public function arrow_report($items_html = '') {
		$items_html = (string) $items_html;
		if (strpos($items_html, 'sf-sub-indicator') !== false) {
			return [true, __('Other menu items with a submenu already show an arrow.', 'alba')];
		}
		if ($items_html !== '' && !$this->is_salient() && preg_match('/menu-item-has-children/', $items_html)) {
			return [false, __('Other menu items with a submenu show no arrow.', 'alba')];
		}
		if ($this->is_salient()) {
			$o = function_exists('get_nectar_theme_options') ? get_nectar_theme_options() : get_option('salient_redux', []);
			if (is_array($o) && isset($o['header-dropdown-arrows']) && is_scalar($o['header-dropdown-arrows'])) {
				$val = strtolower((string) $o['header-dropdown-arrows']);
				if (in_array($val, ['', '0', 'off', 'hide', 'none', 'false', 'no', 'disabled'], true)) {
					return [false, __('The Salient option "header-dropdown-arrows" is switched off.', 'alba')];
				}
				/* translators: %s: value of a Salient option */
				return [true, sprintf(__('The Salient option "header-dropdown-arrows" is not switched off (value: %s).', 'alba'), $val)];
			}
			if (is_array($o)) {
				foreach ($o as $k => $v) {
					if (!is_string($k) || !preg_match('/arrow/i', $k) || preg_match('/slider|carousel|lightbox|back-?to|woo|portfolio|blog|related|next|prev|scroll/i', $k)) {
						continue;
					}
					if (in_array(strtolower(is_scalar($v) ? (string) $v : ''), ['1', 'on', 'true', 'yes'], true)) {
						/* translators: %s: name of a theme option */
						return [true, sprintf(__('Salient option "%s" is on.', 'alba'), $k)];
					}
				}
			}
			$seen = [];
			if (is_array($o)) {
				foreach ($o as $k => $v) {
					if (is_string($k) && preg_match('/arrow|dropdown|indicator/i', $k) && is_scalar($v) && count($seen) < 8) {
						$seen[] = $k . '=' . substr((string) $v, 0, 20);
					}
				}
			}
			/* translators: %s: list of Salient option names and values */
			$msg = __('No active arrow option found in the Salient settings. Choose "Always show" if the arrow is wanted anyway. Related options found: %s', 'alba');
			return [false, sprintf($msg, $seen ? implode(', ', $seen) : '–')];
		}
		if (apply_filters('mml_theme_dropdown_arrow', false)) {
			return [true, __('Enabled by the filter mml_theme_dropdown_arrow.', 'alba')];
		}
		return [false, __('The theme could not be asked about arrows. Choose "Always show" if the arrow is wanted.', 'alba')];
	}

	private function theme_shows_arrows($items_html) {
		$r = $this->arrow_report($items_html);
		return $r[0];
	}

	/** Pfeil-Markup für den Dropdown-Menüpunkt (Einstellung: automatisch / immer / nie) */
	private function arrow($items_html = '') {
		$mode = get_option('mml_dd_arrow', 'auto');
		if ($mode === 'off' || ($mode === 'auto' && !$this->theme_shows_arrows($items_html))) {
			return '';
		}
		if ($this->is_salient()) {
			return '<span class="sf-sub-indicator"><i class="icon-angle-down" aria-hidden="true"></i></span>';
		}
		return '<span class="mml-arrow" aria-hidden="true"><svg viewBox="0 0 12 12" width="10" height="10" fill="none"><path d="M1.5 4L6 8l4.5-4" stroke="currentColor" stroke-width="1.5"/></svg></span>';
	}

	public function render($args = []) {
		$a = $this->defaults($args);
		if ($a['style'] === 'menu') {
			$item = $this->dropdown_item($a, 'classic');
			return $item === '' ? '' : '<ul class="mml-switcher mml-menu menu">' . $item . '</ul>';
		}
		if ($a['style'] === 'dropdown') {
			$html = '<select class="mml-switcher mml-dropdown" aria-label="' . esc_attr__('Language', 'alba') . '" onchange="if(this.value)location.href=this.value">';
			foreach ($this->links() as $l) {
				if (($a['hide_missing'] && $l['missing']) || ($a['hide_current'] && $l['current'])) {
					continue;
				}
				$html .= '<option value="' . esc_url($l['url']) . '"' . selected($l['current'], true, false) . '>' . esc_html($this->label($l, $a)) . '</option>';
			}
			return $html . '</select>';
		}
		return '<ul class="mml-switcher">' . $this->items($a) . '</ul>';
	}

	public function shortcode($atts) {
		$atts = shortcode_atts([
			'style'        => 'list',
			'display'      => 'name',
			'hide_missing' => 0,
			'hide_current' => 0,
		], $atts, 'mml_switcher');
		$atts['hide_missing'] = (int) $atts['hide_missing'];
		$atts['hide_current'] = (int) $atts['hide_current'];
		return $this->render($atts);
	}

	/** Umschalter automatisch an ein Menü anhängen (Einstellung) */
	public function menu_switcher($items, $args) {
		$loc = get_option('mml_menu_location', '');
		if ($loc && isset($args->theme_location) && $args->theme_location === $loc) {
			$args = [
				'display'      => get_option('mml_display', 'name'),
				'hide_missing' => (int) get_option('mml_hide_missing', 0),
			];
			$dd = get_option('mml_menu_style', 'list') === 'dropdown' ? $this->dropdown_item($args, 'classic', $items) : '';
			$items .= $dd !== '' ? $dd : $this->items($args);
		}
		return $items;
	}

	public function css() {
		echo '<style id="mml-css">.mml-switcher:not(select){list-style:none;margin:0;padding:0;display:flex;gap:.75rem;flex-wrap:wrap}.mml-current a{font-weight:700}.mml-missing a{opacity:.6}select.mml-switcher{font:inherit;color:inherit;background:transparent;border:1px solid currentColor;border:1px solid color-mix(in srgb,currentColor 35%,transparent);border-radius:4px;padding:.25em .5em;cursor:pointer}.mml-menu{align-items:flex-start}.mml-menu li{position:relative;list-style:none}.mml-menu .sub-menu{display:none;position:absolute;left:0;top:100%;min-width:100%;z-index:9999;list-style:none;margin:0;padding:.25rem 0;background:var(--mml-sub-bg,#fff);color:var(--mml-sub-color,#1d2327);box-shadow:0 4px 16px rgba(0,0,0,.12);border-radius:4px}.mml-menu li:hover>.sub-menu,.mml-menu li:focus-within>.sub-menu{display:block}.mml-menu .sub-menu a{display:block;padding:.4rem .9rem;white-space:nowrap;color:inherit;text-decoration:none}.mml-menu .sub-menu a:hover{background:rgba(0,0,0,.06)}.mml-menu>li>a{color:inherit;text-decoration:none}.mml-arrow{display:inline-block;margin-left:.35em;vertical-align:middle;line-height:0}.mml-dd>a .sf-sub-indicator~.sf-sub-indicator{display:none}</style>' . "\n";
	}

	/* ---------------------------------------------------------------
	 * hreflang
	 * ------------------------------------------------------------- */

	/** Alternative Sprachfassungen der aktuellen Seite: Sprache => URL (leer, wenn es keine gibt) */
	public function alternate_links() {
		$c = $this->core;
		$links = [];

		if (is_singular()) {
			$id = (int) get_queried_object_id();
			if (!$id || !$c->is_translatable(get_post_type($id))) {
				return [];
			}
			foreach ($c->translations($id) as $code => $tid) {
				if (get_post_status($tid) !== 'publish') {
					continue;
				}
				$links[$code] = $c->with_lang($code, function () use ($tid) {
					return get_permalink($tid);
				});
			}
			foreach ($c->langs as $code => $l) {
				if (!isset($links[$code]) && $c->translation($id, $code) === $id) {
					$links[$code] = $c->with_lang($code, function () use ($id) {
						return get_permalink($id);
					});
				}
			}
			if (count($links) < 2) {
				return [];
			}
		} elseif ($c->terms && (is_category() || is_tag() || is_tax()) && ($qo = get_queried_object()) instanceof WP_Term && in_array($qo->taxonomy, $c->taxonomies, true)) {
			foreach ($c->terms->translations($qo->term_id) as $code => $tid) {
				$u = $c->terms->link($tid, $code);
				if ($u !== '') {
					$links[$code] = $u;
				}
			}
			if (count($links) < 2) {
				return [];
			}
		} elseif (is_front_page() || is_home()) {
			foreach ($c->langs as $code => $l) {
				$links[$code] = $c->home_in($code);
			}
		} else {
			return [];
		}
		return $links;
	}

	public function hreflang() {
		$links = $this->alternate_links();
		if (!$links) {
			return;
		}
		foreach ($links as $code => $url) {
			echo '<link rel="alternate" hreflang="' . esc_attr($code) . '" href="' . esc_url($url) . '" />' . "\n";
		}
		$c = $this->core;
		$x = isset($links[$c->default]) ? $links[$c->default] : reset($links);
		echo '<link rel="alternate" hreflang="x-default" href="' . esc_url($x) . '" />' . "\n";
	}

	/* ---------------------------------------------------------------
	 * Menüs: Einträge auf Übersetzungen umbiegen
	 * ------------------------------------------------------------- */

	public function translate_menu($items) {
		if (is_admin() || !is_array($items)) {
			return $items;
		}
		$c = $this->core;
		$lang = $c->lang;
		$home = untrailingslashit(set_url_scheme(get_option('home')));

		foreach ($items as $k => $it) {
			if ($it->type === 'post_type' && $c->is_translatable($it->object)) {
				$oid = (int) $it->object_id;
				if ($c->post_lang($oid) === $lang) {
					continue;
				}
				$tid = $c->translation($oid, $lang);
				if (!$tid) {
					unset($items[$k]);   // keine Übersetzung -> Eintrag ausblenden
					continue;
				}
				if ($it->title === get_the_title($oid)) {
					$it->title = get_the_title($tid);
				}
				$it->object_id = (string) $tid;
				$it->url = get_permalink($tid);
			} elseif ($it->type === 'taxonomy' && $c->terms && in_array($it->object, $c->taxonomies, true)) {
				$oid = (int) $it->object_id;
				if ($c->terms->lang($oid) === $lang) {
					continue;
				}
				$tid = $c->terms->translation($oid, $lang);
				$link = $tid ? $c->terms->link($tid, $lang) : '';
				if ($link === '') {
					unset($items[$k]);
					continue;
				}
				$orig = get_term($oid);
				$trm = get_term($tid);
				if ($orig && $trm && !is_wp_error($orig) && !is_wp_error($trm) && $it->title === $orig->name) {
					$it->title = $trm->name;
				}
				$it->object_id = (string) $tid;
				$it->url = $link;
			} elseif ($it->type === 'custom' && untrailingslashit($it->url) === $home) {
				$it->url = $c->home_in($lang);
			}
		}
		return array_values($items);
	}
}
