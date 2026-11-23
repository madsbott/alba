<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Sitemaps and language references.
 *
 * - XML sitemap per language at /alba-sitemap.xml (index), /alba-sitemap-{language}-{n}.xml (content) and
 *   /alba-sitemap-{language}-terms.xml (home page and term archives). Every entry lists all language versions
 *   as xhtml:link (hreflang) and x-default.
 * - The sitemap is added to robots.txt and hooked into the index of Yoast SEO or Rank Math.
 * - og:locale matching the language (Yoast, Rank Math; without an SEO plugin the plugin outputs og:locale itself).
 * - canonical: if a page in a foreign language has a canonical without a language prefix, it is corrected to the language URL.
 */
class ALBA_Seo {
	const PER_PAGE = 1000;
	const OPT_SITEMAP = 'alba_sitemap';
	const OPT_OG = 'alba_ogloc';

	private $core;

	public function __construct(ALBA_Core $core) {
		$this->core = $core;
		$core->seo = $this;

		add_action('init', [$this, 'serve'], 0);
		add_filter('robots_txt', [$this, 'robots'], 20, 2);
		add_filter('wpseo_sitemap_index', [$this, 'append_index']);
		add_filter('rank_math/sitemap/index', [$this, 'append_index']);

		foreach (['save_post', 'deleted_post', 'alba_translation_saved', 'created_term', 'edited_term', 'delete_term', 'update_option_alba_languages', 'update_option_alba_post_types', 'update_option_alba_taxonomies'] as $h) {
			add_action($h, [$this, 'bump']);
		}

		if (!is_admin()) {
			add_filter('wpseo_locale', [$this, 'og_locale_filter']);
			add_filter('wpseo_og_locale', [$this, 'og_locale_filter']);
			add_filter('rank_math/opengraph/facebook/og_locale', [$this, 'og_locale_filter']);
			add_action('wp_head', [$this, 'og_locale_head'], 5);
			add_filter('wpseo_canonical', [$this, 'fix_canonical']);
			add_filter('rank_math/frontend/canonical', [$this, 'fix_canonical']);
			add_filter('get_canonical_url', [$this, 'fix_canonical']);
		}
		if (is_admin()) {
			add_action('admin_init', [$this, 'register']);
			add_action('admin_post_alba_sitemap_rebuild', [$this, 'rebuild']);
		}
	}

	public function enabled() {
		return get_option(self::OPT_SITEMAP, '1') === '1';
	}

	public function bump() {
		update_option('alba_sm_v', (string) microtime(true), false);
	}

	private function ver() {
		$v = get_option('alba_sm_v', '');
		if ($v === '') {
			$v = '1';
			add_option('alba_sm_v', $v, '', false);
		}
		return $v;
	}

	/* ---------------------------------------------------------------
	 * Adressen
	 * ------------------------------------------------------------- */

	public function index_url() {
		return home_url('/alba-sitemap.xml');
	}

	private function file_url($lang, $part) {
		return trailingslashit(set_url_scheme(get_option('home'))) . 'alba-sitemap-' . $lang . '-' . $part . '.xml';
	}

	/** All files: list of [lang, part] */
	public function files() {
		$key = 'alba_sm_files_' . md5($this->ver());
		$hit = get_transient($key);
		if (is_array($hit)) {
			return $hit;
		}
		$c = $this->core;
		$out = [];
		foreach ($c->langs as $code => $l) {
			$q = new WP_Query($this->query_args($code, 1, 1));
			$n = max(1, (int) ceil($q->found_posts / self::PER_PAGE));
			$out[] = [$code, 'terms'];
			for ($i = 1; $i <= $n; $i++) {
				$out[] = [$code, (string) $i];
			}
		}
		set_transient($key, $out, 12 * HOUR_IN_SECONDS);
		return $out;
	}

	/* ---------------------------------------------------------------
	 * Ausliefern
	 * ------------------------------------------------------------- */

	private function request_name() {
		if (!isset($_SERVER['REQUEST_URI']) || (isset($_SERVER['REQUEST_METHOD']) && !in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true))) {
			return '';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw request path, only parsed, never output.
		$path = (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
		$base = rtrim((string) wp_parse_url(get_option('home'), PHP_URL_PATH), '/');
		if ($base !== '' && strpos($path, $base . '/') === 0) {
			$path = substr($path, strlen($base));
		}
		$path = ltrim($path, '/');
		return strpos($path, 'alba-sitemap') === 0 ? $path : '';
	}

	public function serve() {
		$name = $this->request_name();
		if ($name === '' || is_admin()) {
			return;
		}
		if (!$this->enabled()) {
			return;
		}
		$c = $this->core;
		if ($name === 'alba-sitemap.xml') {
			$xml = $this->cached('index', function () {
				return $this->build_index();
			});
		} elseif (preg_match('#^alba-sitemap-(.+)-(terms|\d+)\.xml$#', $name, $m) && isset($c->langs[$m[1]])) {
			$lang = $m[1];
			$part = $m[2];
			$xml = $this->cached($lang . '-' . $part, function () use ($lang, $part) {
				return $part === 'terms' ? $this->build_terms($lang) : $this->build_posts($lang, max(1, (int) $part));
			});
		} else {
			return;
		}
		status_header(200);
		header('Content-Type: application/xml; charset=UTF-8');
		header('X-Robots-Tag: noindex, follow');
		header('Cache-Control: public, max-age=300');
		if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'HEAD') {
			exit;
		}
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private function cached($name, $fn) {
		$key = 'alba_sm_' . md5($this->ver() . '|' . $name);
		$hit = get_transient($key);
		if (is_string($hit) && $hit !== '') {
			return $hit;
		}
		$xml = $fn();
		set_transient($key, $xml, 6 * HOUR_IN_SECONDS);
		return $xml;
	}

	private function x($s) {
		return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	private function head() {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	}

	private function build_index() {
		$x = $this->head() . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ($this->files() as $f) {
			$x .= "\t<sitemap><loc>" . $this->x($this->file_url($f[0], $f[1])) . "</loc></sitemap>\n";
		}
		return $x . '</sitemapindex>' . "\n";
	}

	private function urlset($entries) {
		$x = $this->head() . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
		foreach ($entries as $e) {
			$x .= "\t<url>\n\t\t<loc>" . $this->x($e['loc']) . "</loc>\n";
			if (!empty($e['mod'])) {
				$x .= "\t\t<lastmod>" . $this->x($e['mod']) . "</lastmod>\n";
			}
			if (count($e['alt']) > 1) {
				foreach ($e['alt'] as $code => $u) {
					$x .= "\t\t" . '<xhtml:link rel="alternate" hreflang="' . $this->x($code) . '" href="' . $this->x($u) . '"/>' . "\n";
				}
				$d = $this->core->default;
				$x .= "\t\t" . '<xhtml:link rel="alternate" hreflang="x-default" href="' . $this->x(isset($e['alt'][$d]) ? $e['alt'][$d] : reset($e['alt'])) . '"/>' . "\n";
			}
			$x .= "\t</url>\n";
		}
		return $x . '</urlset>' . "\n";
	}

	/* ---------------------------------------------------------------
	 * Posts
	 * ------------------------------------------------------------- */

	private function types() {
		$out = [];
		foreach ((array) $this->core->post_types as $t) {
			$o = get_post_type_object($t);
			if ($o && $o->public && $t !== 'attachment') {
				$out[] = $t;
			}
		}
		return $out ?: ['page'];
	}

	private function query_args($lang, $page, $per) {
		return [
			'post_type'              => $this->types(),
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => $per,
			'paged'                  => $page,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'meta_query'             => [$this->core->lang_meta_query($lang, true)],
			'suppress_filters'       => true,
			'ignore_sticky_posts'    => true,
			'alba_skip'               => 1,
			'update_post_term_cache' => false,
		];
	}

	/** Is the page set to noindex by Yoast or Rank Math? */
	private function noindex($id) {
		if (get_post_meta($id, '_yoast_wpseo_meta-robots-noindex', true) === '1') {
			return true;
		}
		$rm = get_post_meta($id, 'rank_math_robots', true);
		return is_array($rm) && in_array('noindex', $rm, true);
	}

	private function build_posts($lang, $page) {
		$c = $this->core;
		$q = new WP_Query($this->query_args($lang, $page, self::PER_PAGE));
		$entries = [];
		foreach ($q->posts as $p) {
			if ($this->noindex($p->ID)) {
				continue;
			}
			$tid = $c->translation($p->ID, $lang);
			if (!$tid) {
				continue;
			}
			$alt = [];
			foreach ($c->langs as $code => $l) {
				$t2 = $c->translation($p->ID, $code);
				if ($t2) {
					$alt[$code] = $c->with_lang($code, function () use ($t2) {
						return get_permalink($t2);
					});
				}
			}
			if (empty($alt[$lang])) {
				continue;
			}
			$mod = get_post($tid);
			$entries[] = [
				'loc' => $alt[$lang],
				'mod' => ($mod && $mod->post_modified_gmt && $mod->post_modified_gmt !== '0000-00-00 00:00:00') ? gmdate('c', strtotime($mod->post_modified_gmt . ' UTC')) : '',
				'alt' => $alt,
			];
		}
		return $this->urlset($entries);
	}

	/* ---------------------------------------------------------------
	 * Home page and terms
	 * ------------------------------------------------------------- */

	private function build_terms($lang) {
		$c = $this->core;
		$alt = [];
		foreach ($c->home_languages() as $code) {
			$alt[$code] = $c->home_in($code);
		}
		$entries = isset($alt[$lang]) ? [['loc' => $alt[$lang], 'mod' => '', 'alt' => $alt]] : [];

		if ($c->terms) {
			$left = 2000;
			foreach ((array) $c->taxonomies as $tx) {
				$o = get_taxonomy($tx);
				if (!$o || !$o->public || $left <= 0) {
					continue;
				}
				$terms = get_terms(['taxonomy' => $tx, 'hide_empty' => true, 'number' => 0, 'alba_skip' => 1]);
				if (is_wp_error($terms)) {
					continue;
				}
				foreach ($terms as $t) {
					if ($left <= 0 || $c->terms->lang($t->term_id) !== $lang) {
						continue;
					}
					$a = [];
					foreach ($c->terms->translations($t->term_id) as $code => $tid) {
						$u = $c->terms->link($tid, $code);
						if ($u !== '') {
							$a[$code] = $u;
						}
					}
					if (empty($a[$lang])) {
						continue;
					}
					$entries[] = ['loc' => $a[$lang], 'mod' => '', 'alt' => $a];
					$left--;
				}
			}
		}
		return $this->urlset($entries);
	}

	/* ---------------------------------------------------------------
	 * robots.txt, Yoast, Rank Math
	 * ------------------------------------------------------------- */

	public function robots($output, $public) {
		if (!$public || !$this->enabled()) {
			return $output;
		}
		return rtrim($output) . "\n\nSitemap: " . $this->index_url() . "\n";
	}

	/** Hooks our files into the sitemap index of Yoast or Rank Math */
	public function append_index($xml) {
		if (!is_string($xml) || !$this->enabled()) {
			return $xml;
		}
		$add = '';
		foreach ($this->files() as $f) {
			$add .= '<sitemap><loc>' . $this->x($this->file_url($f[0], $f[1])) . '</loc></sitemap>' . "\n";
		}
		return $xml . $add;
	}

	/* ---------------------------------------------------------------
	 * og:locale and canonical
	 * ------------------------------------------------------------- */

	private function locale() {
		$c = $this->core;
		return isset($c->langs[$c->lang]['locale']) ? (string) $c->langs[$c->lang]['locale'] : '';
	}

	public function og_locale_filter($v) {
		if (get_option(self::OPT_OG, '1') !== '1') {
			return $v;
		}
		$l = $this->locale();
		return $l !== '' ? $l : $v;
	}

	public function og_locale_head() {
		if (get_option(self::OPT_OG, '1') !== '1' || ($this->core->editor && $this->core->editor->seo_plugin()) || defined('AIOSEO_VERSION') || defined('SEOPRESS_VERSION') || defined('THE_SEO_FRAMEWORK_VERSION')) {
			return;
		}
		$l = $this->locale();
		if ($l === '') {
			return;
		}
		echo '<meta property="og:locale" content="' . esc_attr($l) . '" />' . "\n";
		if ($this->core->frontend) {
			foreach ($this->core->frontend->alternate_links() as $code => $u) {
				if ($code !== $this->core->lang && !empty($this->core->langs[$code]['locale'])) {
					echo '<meta property="og:locale:alternate" content="' . esc_attr($this->core->langs[$code]['locale']) . '" />' . "\n";
				}
			}
		}
	}

	/** Canonical without language prefix in a foreign language -> language URL */
	public function fix_canonical($url) {
		$c = $this->core;
		if (!is_string($url) || $url === '' || $c->lang === $c->default || !is_singular() || is_admin()) {
			return $url;
		}
		$id = (int) get_queried_object_id();
		if (!$id || !$c->is_translatable(get_post_type($id))) {
			return $url;
		}
		$pre = untrailingslashit($c->home_in($c->lang));
		if (strpos(untrailingslashit($url), $pre) === 0) {
			return $url;
		}
		$home = untrailingslashit(set_url_scheme(get_option('home')));
		if (strpos($url, $home) !== 0 && strpos(set_url_scheme($url), $home) !== 0) {
			return $url;
		}
		$p = get_permalink($id);
		return $p ? $p : $url;
	}

	/* ---------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------- */

	public function register() {
		foreach ([self::OPT_SITEMAP, self::OPT_OG] as $o) {
			register_setting('alba', $o, ['sanitize_callback' => function ($v) {
				return $v === '1' ? '1' : '0';
			}]);
		}
	}

	public function rebuild() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Not allowed.', 'alba'));
		}
		check_admin_referer('alba_sitemap_rebuild');
		$this->bump();
		wp_safe_redirect(add_query_arg('alba_sm', '1', admin_url('admin.php?page=alba-settings#seo')));
		exit;
	}

	public function card() {
		$c = $this->core;
		$seo = $c->editor ? $c->editor->seo_plugin() : '';
		$on = $this->enabled();
		?>
		<section class="alba-card" data-tab="seo">
			<header class="alba-card-h"><span class="dashicons dashicons-chart-area"></span><div><h2><?php echo esc_html__('Sitemap & language references', 'alba'); ?></h2><p><?php echo esc_html__('XML sitemap per language, hreflang, og:locale, canonical.', 'alba'); ?></p></div></header>
			<div class="alba-card-b">
				<?php if (!empty($_GET['alba_sm'])) : ?><div class="notice notice-success inline"><p><?php echo esc_html__('The sitemap is rebuilt on the next request.', 'alba'); ?></p></div><?php endif; ?>
				<div class="alba-fields">
					<div class="alba-field"><div class="alba-label"><?php echo esc_html__('Sitemap', 'alba'); ?></div><div class="alba-ctl">
						<label><input type="checkbox" name="alba_sitemap" value="1" <?php checked($on); ?>> <?php echo esc_html__('Provide a sitemap for all languages', 'alba'); ?></label>
						<?php if ($on) : ?><p class="alba-hint"><a href="<?php echo esc_url($this->index_url()); ?>" target="_blank" rel="noopener"><?php echo esc_html($this->index_url()); ?></a> · <?php echo count($this->files()); ?> <?php echo esc_html__('files. Each entry lists all language versions (hreflang, x-default).', 'alba'); ?></p><?php endif; ?>
						<p class="alba-hint"><?php echo esc_html__('The entry in', 'alba'); ?> <code>robots.txt</code> <?php echo esc_html__('is made automatically', 'alba'); ?><?php echo $seo ? esc_html(sprintf(/* translators: %s: name of the SEO plugin */ esc_html__(' and the files are added to the sitemap index of %s', 'alba'), $seo)) : ''; ?><?php echo esc_html__('. Pages with "noindex" (Yoast, Rank Math) and password-protected content are not included.', 'alba'); ?><?php echo $seo ? esc_html__(' The sitemap of ', 'alba') . esc_html($seo) . esc_html__(' remains and also contains the default language and separate translation posts; duplicate URLs do no harm.', 'alba') : ''; ?></p>
					</div></div>
					<div class="alba-field"><div class="alba-label">og:locale</div><div class="alba-ctl">
						<label><input type="checkbox" name="alba_ogloc" value="1" <?php checked(get_option(self::OPT_OG, '1'), '1'); ?>> <?php echo esc_html__('Set language for social media previews (', 'alba'); ?><code>og:locale</code>)</label>
						<p class="alba-hint"><?php echo $seo ? esc_html(sprintf(/* translators: %s: name of the SEO plugin */ esc_html__('Passed to %s.', 'alba'), $seo)) : esc_html__('Without an SEO plugin, Alba outputs the meta tag itself.', 'alba'); ?> <?php echo esc_html__('Canonical URLs without a language prefix are corrected automatically in other languages.', 'alba'); ?></p>
					</div></div>
					<div class="alba-field"><div class="alba-label"><?php echo esc_html__('Check', 'alba'); ?></div><div class="alba-ctl">
						<ul class="alba-hint" style="list-style:disc;margin-left:18px">
							<li><?php echo get_option('blog_public') ? esc_html__('Search engines are allowed.', 'alba') : '<strong>' . esc_html__('Warning:', 'alba') . '</strong> ' . esc_html__('"Discourage search engines from indexing this site" is active (Settings > Reading).', 'alba'); ?></li>
							<li><?php echo file_exists(ABSPATH . 'robots.txt') ? '<strong>' . esc_html__('Warning:', 'alba') . '</strong> ' . esc_html__('There is a real robots.txt in the root directory; WordPress cannot add the sitemap entry there. Please add the line', 'alba') . ' <code>Sitemap: ' . esc_html($this->index_url()) . '</code> ' . esc_html__('yourself.', 'alba') : esc_html__('robots.txt is generated by WordPress and contains the sitemap.', 'alba'); ?></li>
							<li><?php echo get_option('permalink_structure') ? esc_html__('Permalinks are active.', 'alba') : '<strong>' . esc_html__('Note:', 'alba') . '</strong> ' . esc_html__('Without pretty permalinks, the language URLs use', 'alba') . ' <code>?lang=</code>.'; ?></li>
						</ul>
						<a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=alba_sitemap_rebuild'), 'alba_sitemap_rebuild')); ?>"><?php echo esc_html__('Rebuild sitemap', 'alba'); ?></a>
					</div></div>
				</div>
			</div>
		</section>
		<?php
	}
}
