<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Automatic language detection: on the first visit the visitor is redirected based on the browser language.
 *
 * - The cookie "alba_lang" remembers the language; once it is set, there is never a redirect
 *   (so the visitor can switch language at any time with the switcher).
 * - Search engines, social media previews and other bots are never redirected.
 * - Settings (Languages > Settings > Language detection):
 *   alba_autodetect:        off | home (home page only) | all (every page, if a translation exists)
 *   alba_autodetect_method: server (HTTP redirect 302) | js (JavaScript, suitable for page caches)
 * - With ?alba_noredirect=1 in the address there is never a redirect.
 */
class ALBA_Detect {
	const COOKIE = 'alba_lang';
	const BOT = '~bot|crawl|spider|slurp|archiver|bing|yandex|baidu|duckduck|facebookexternalhit|facebot|twitter|linkedin|whatsapp|telegram|slack|discord|pinterest|embed|preview|lighthouse|pagespeed|gtmetrix|headless|phantom|curl|wget|python|java/|go-http|okhttp|monitor|uptime|pingdom|semrush|ahrefs|mj12|screaming|validator~i';

	private $core;

	public function __construct(ALBA_Core $core) {
		$this->core = $core;
		if (is_admin() || self::mode() === 'off' || count($core->langs) < 2) {
			return;
		}
		if (self::method() === 'js') {
			add_action('wp_head', [$this, 'script'], 0);
		} else {
			add_action('template_redirect', [$this, 'run'], 0);
		}
	}

	public static function mode() {
		$m = get_option('alba_autodetect', 'off');
		return in_array($m, ['home', 'all'], true) ? $m : 'off';
	}

	public static function method() {
		return get_option('alba_autodetect_method', 'server') === 'js' ? 'js' : 'server';
	}

	/* ---------------------------------------------------------------
	 * Hilfen
	 * ------------------------------------------------------------- */

	public static function is_bot($ua) {
		$ua = trim((string) $ua);
		return $ua === '' || (bool) preg_match(self::BOT, $ua);
	}

	/** Is a redirect allowed here at all? */
	private function eligible() {
		if (wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)) {
			return false;
		}
		if (is_feed() || is_robots() || is_trackback() || is_preview() || is_customize_preview() || is_404() || is_search() || is_embed()) {
			return false;
		}
		if (isset($_GET['alba_noredirect']) || isset($_GET['lang'])) {
			return false;
		}
		if (self::mode() === 'home' && (!is_front_page() || is_paged())) {
			return false;
		}
		return true;
	}

	/** Best language according to Accept-Language: returns the language code or '' */
	public function pick($header) {
		$prefs = [];
		foreach (explode(',', (string) $header) as $i => $part) {
			$bits = explode(';', trim($part));
			$tag = strtolower(str_replace('_', '-', trim($bits[0])));
			if ($tag === '' || $tag === '*') {
				continue;
			}
			$q = 1.0;
			if (isset($bits[1]) && preg_match('/q\s*=\s*([0-9.]+)/i', $bits[1], $m)) {
				$q = (float) $m[1];
			}
			if ($q > 0) {
				$prefs[] = [$q, $i, $tag];
			}
		}
		usort($prefs, function ($a, $b) {
			return $a[0] === $b[0] ? $a[1] - $b[1] : ($a[0] < $b[0] ? 1 : -1);
		});
		foreach ($prefs as $p) {
			$code = $this->match($p[2]);
			if ($code !== '') {
				return $code;
			}
		}
		return '';
	}

	/** Map a browser language (e.g. "pt-br") to a configured language */
	private function match($tag) {
		foreach ($this->core->langs as $code => $l) {
			$loc = isset($l['locale']) ? strtolower(str_replace('_', '-', (string) $l['locale'])) : '';
			if ($loc !== '' && $loc === $tag) {
				return (string) $code;
			}
		}
		$primary = explode('-', $tag)[0];
		foreach ($this->core->langs as $code => $l) {
			if (strtolower((string) $code) === $primary) {
				return (string) $code;
			}
		}
		foreach ($this->core->langs as $code => $l) {
			$loc = isset($l['locale']) ? strtolower((string) $l['locale']) : '';
			if ($loc !== '' && explode('_', $loc)[0] === $primary) {
				return (string) $code;
			}
		}
		return '';
	}

	/** Target URLs of the current view per language (existing translations only) */
	private function targets() {
		$out = [];
		if (!$this->core->frontend) {
			return $out;
		}
		foreach ($this->core->frontend->links() as $l) {
			if (!$l['missing']) {
				$out[$l['code']] = $l['url'];
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------
	 * Variante 1: serverseitig (302)
	 * ------------------------------------------------------------- */

	private function set_cookie($lang) {
		if (headers_sent()) {
			return;
		}
		setcookie(self::COOKIE, $lang, ['expires' => time() + YEAR_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'samesite' => 'Lax']);
		$_COOKIE[self::COOKIE] = $lang;
	}

	public function run() {
		$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
		$ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
		if (!in_array($method, ['GET', 'HEAD'], true) || self::is_bot($ua)) {
			return;
		}
		$c = $this->core;
		$cur = $c->lang;

		// Cookie present: never redirect, only remember the most recently visited language
		if (isset($_COOKIE[self::COOKIE])) {
			$saved = sanitize_key(wp_unslash($_COOKIE[self::COOKIE]));
			if ($saved !== $cur && isset($c->langs[$cur]) && !is_404()) {
				$this->set_cookie($cur);
			}
			return;
		}

		$header = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE'])) : '';
		$go = $this->eligible();
		$want = ($go && $header !== '') ? $this->pick($header) : '';

		if ($want !== '' && $want !== $cur) {
			$t = $this->targets();
			if (isset($t[$want])) {
				$this->set_cookie($want);
				nocache_headers();
				header('Vary: Accept-Language', false);
				wp_safe_redirect($t[$want], 302, 'Alba');
				exit;
			}
		}
		if (!is_404() && !is_feed() && !is_robots()) {
			$this->set_cookie($cur);
		}
	}

	/* ---------------------------------------------------------------
	 * Variant 2: JavaScript (also works with cached pages)
	 * ------------------------------------------------------------- */

	public function script() {
		if (is_feed() || is_robots() || is_embed() || is_404() || is_customize_preview()) {
			return;
		}
		$langs = [];
		foreach ($this->core->langs as $code => $l) {
			$langs[] = [(string) $code, isset($l['locale']) ? strtolower(str_replace('_', '-', (string) $l['locale'])) : ''];
		}
		$cfg = [
			'cur'   => $this->core->lang,
			'go'    => $this->eligible(),
			'langs' => $langs,
			'urls'  => (object) $this->targets(),
			'bot'   => trim(self::BOT, '~i'),
		];
		?>
<script>(function(c){try{
function set(l){document.cookie='<?php echo esc_js(self::COOKIE); ?>='+l+'; path=/; max-age=31536000; SameSite=Lax';}
var m=document.cookie.match(/(?:^|; )<?php echo esc_js(self::COOKIE); ?>=([^;]*)/);
if(m){if(m[1]!==c.cur){set(c.cur);}return;}
if(!c.go||new RegExp(c.bot,'i').test(navigator.userAgent||'')||!navigator.userAgent){set(c.cur);return;}
var prefs=navigator.languages&&navigator.languages.length?navigator.languages:[navigator.language||''],want='',i,j,t,p;
function find(t){var k;for(k=0;k<c.langs.length;k++){if(c.langs[k][1]&&c.langs[k][1]===t){return c.langs[k][0];}}
p=t.split('-')[0];for(k=0;k<c.langs.length;k++){if(c.langs[k][0].toLowerCase()===p){return c.langs[k][0];}}
for(k=0;k<c.langs.length;k++){if(c.langs[k][1]&&c.langs[k][1].split('-')[0]===p){return c.langs[k][0];}}return '';}
for(i=0;i<prefs.length&&!want;i++){t=String(prefs[i]).toLowerCase().replace('_','-');if(t){want=find(t);}}
if(want&&want!==c.cur&&c.urls[want]){set(want);location.replace(c.urls[want]);return;}
set(c.cur);
}catch(e){}})(<?php echo wp_json_encode($cfg); ?>);</script>
		<?php
	}
}
