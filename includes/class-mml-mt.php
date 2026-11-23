<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Automatische Übersetzungsvorschläge im Übersetzungseditor (ohne DeepL-Schlüssel).
 *
 * Anbieter (Sprachen > Einstellungen > Übersetzungshilfe):
 *  - MyMemory (kostenlos, ohne Schlüssel; mit E-Mail-Adresse höheres Tageslimit)
 *  - LibreTranslate (eigener Server oder öffentlicher Dienst; je nach Dienst mit API-Schlüssel)
 * Vorschläge werden nie automatisch gespeichert: Im Editor muss jeder Text mit "Übernehmen" bestätigt
 * und anschließend gespeichert werden. Glossar-Begriffe werden geschützt und danach eingesetzt.
 */
class MML_MT {
	const CACHE_DAYS = 30;

	private $core;

	public function __construct(MML_Core $core) {
		$this->core = $core;
		$core->mt = $this;
		add_action('wp_ajax_mml_mt', [$this, 'ajax']);
	}

	public static function provider() {
		$p = get_option('mml_mt_provider', 'off');
		return in_array($p, ['mymemory', 'libre'], true) ? $p : 'off';
	}

	public static function enabled() {
		return self::provider() !== 'off';
	}

	public static function provider_name() {
		return self::provider() === 'libre' ? 'LibreTranslate' : 'MyMemory';
	}

	/* ---------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------- */

	public function ajax() {
		check_ajax_referer('mml_mt', 'nonce');
		if (!(current_user_can('edit_posts') || current_user_can(MML_Roles::CAP)) || !self::enabled()) {
			wp_send_json_error(__('Not allowed, or automatic translation is turned off.', 'alba'));
		}
		$c = $this->core;
		$from = isset($_POST['from']) ? sanitize_key(wp_unslash($_POST['from'])) : '';
		$to = isset($_POST['to']) ? sanitize_key(wp_unslash($_POST['to'])) : '';
		if (!isset($c->langs[$from]) || !isset($c->langs[$to]) || $from === $to) {
			wp_send_json_error(__('Invalid languages.', 'alba'));
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON list of texts; plain text is sent to the translation service, never output unescaped.
		$texts = json_decode(wp_unslash(isset($_POST['texts']) ? (string) $_POST['texts'] : ''), true);
		if (!is_array($texts) || !$texts || count($texts) > 10) {
			wp_send_json_error(__('Invalid request.', 'alba'));
		}
		$out = [];
		foreach ($texts as $t) {
			if (!is_string($t) || mb_strlen($t) > 6000) {
				wp_send_json_error(__('A text is too long (max. 6000 characters).', 'alba'));
			}
			$r = $this->translate($t, $from, $to);
			if (is_wp_error($r)) {
				wp_send_json_error($r->get_error_message());
			}
			$out[] = $r;
		}
		wp_send_json_success(['texts' => $out, 'provider' => self::provider_name()]);
	}

	/* ---------------------------------------------------------------
	 * Übersetzen
	 * ------------------------------------------------------------- */

	public function translate($text, $from, $to) {
		if (trim(wp_strip_all_tags($text)) === '') {
			return $text;
		}
		$key = 'mml_mt_' . md5(self::provider() . '|' . get_option('mml_mt_url', '') . '|' . $from . '|' . $to . '|' . $text . '|' . md5(wp_json_encode(get_option(MML_Glossary::OPT, []))));
		$hit = get_transient($key);
		if (is_string($hit) && $hit !== '') {
			return $hit;
		}

		$g = $this->core->glossary;
		$map = [];
		$src = $text;
		if ($g) {
			list($src, $map) = $g->protect($text, $to);
		}

		$res = self::provider() === 'libre' ? $this->libre($src, $from, $to) : $this->mymemory($src, $from, $to);
		if (is_wp_error($res)) {
			return $res;
		}
		if ($g && $map) {
			$res = $g->restore($res, $map);
		}
		set_transient($key, $res, self::CACHE_DAYS * DAY_IN_SECONDS);
		return $res;
	}

	/** Kleinste Einheit je Aufruf: Text zwischen Tags, bei MyMemory max. ~450 Byte */
	private function mymemory($html, $from, $to) {
		$parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
		$calls = 0;
		foreach ($parts as $i => $p) {
			if ($p === '' || $p[0] === '<' || !preg_match('/\p{L}/u', $p)) {
				continue;
			}
			preg_match('/^(\s*)(.*?)(\s*)$/su', $p, $m);
			$core = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$chunks = $this->chunks($core, 450);
			$done = '';
			foreach ($chunks as $ch) {
				if (++$calls > 14) {
					return new WP_Error('mml_mt', __('The text is too long for MyMemory. Please translate it section by section.', 'alba'));
				}
				$r = $this->mymemory_call($ch, $from, $to);
				if (is_wp_error($r)) {
					return $r;
				}
				$done .= ($done === '' ? '' : ' ') . $r;
			}
			$parts[$i] = $m[1] . htmlspecialchars($done, ENT_NOQUOTES, 'UTF-8') . $m[3];
		}
		return implode('', $parts);
	}

	private function chunks($text, $max) {
		if (strlen($text) <= $max) {
			return [$text];
		}
		$out = [];
		$cur = '';
		foreach (preg_split('/(?<=[.!?;:])\s+/u', $text) as $s) {
			if ($cur !== '' && strlen($cur) + strlen($s) + 1 > $max) {
				$out[] = $cur;
				$cur = '';
			}
			while (strlen($s) > $max) {
				$cut = mb_strcut($s, 0, $max, 'UTF-8');
				$sp = strrpos($cut, ' ');
				if ($sp !== false && $sp > 50) {
					$cut = substr($cut, 0, $sp);
				}
				$out[] = trim($cut);
				$s = ltrim(substr($s, strlen($cut)));
			}
			$cur .= ($cur === '' ? '' : ' ') . $s;
		}
		if ($cur !== '') {
			$out[] = $cur;
		}
		return $out;
	}

	private function mymemory_call($q, $from, $to) {
		$args = ['q' => $q, 'langpair' => $from . '|' . $to];
		$mail = trim((string) get_option('mml_mt_email', ''));
		if ($mail !== '' && is_email($mail)) {
			$args['de'] = $mail;
		}
		$r = wp_remote_get(add_query_arg(rawurlencode_deep($args), 'https://api.mymemory.translated.net/get'), ['timeout' => 15, 'headers' => ['User-Agent' => 'Alba/' . MML_VERSION]]);
		if (is_wp_error($r)) {
			return new WP_Error('mml_mt', __('MyMemory is not reachable: ', 'alba') . $r->get_error_message());
		}
		$d = json_decode(wp_remote_retrieve_body($r), true);
		$status = is_array($d) && isset($d['responseStatus']) ? (int) $d['responseStatus'] : 0;
		$txt = is_array($d) && isset($d['responseData']['translatedText']) ? (string) $d['responseData']['translatedText'] : '';
		if ($status === 429 || ($txt !== '' && stripos($txt, 'MYMEMORY WARNING') !== false) || !empty($d['quotaFinished'])) {
			return new WP_Error('mml_mt', __('The daily limit of MyMemory has been reached. With an email address in the settings it increases considerably; otherwise try again tomorrow or use LibreTranslate.', 'alba'));
		}
		if ($status !== 200 || $txt === '') {
			$why = is_array($d) && !empty($d['responseDetails']) ? (string) $d['responseDetails'] : __('No response', 'alba');
			return new WP_Error('mml_mt', 'MyMemory: ' . wp_strip_all_tags($why));
		}
		return html_entity_decode($txt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	private function libre($html, $from, $to) {
		$url = trim((string) get_option('mml_mt_url', ''));
		if ($url === '') {
			return new WP_Error('mml_mt', __('LibreTranslate: Please enter the server URL in the settings.', 'alba'));
		}
		$body = ['q' => $html, 'source' => $from, 'target' => $to, 'format' => 'html'];
		$key = trim((string) get_option('mml_mt_key', ''));
		if ($key !== '') {
			$body['api_key'] = $key;
		}
		$r = wp_remote_post(untrailingslashit($url) . '/translate', [
			'timeout' => 25,
			'headers' => ['Content-Type' => 'application/json', 'User-Agent' => 'Alba/' . MML_VERSION],
			'body'    => wp_json_encode($body),
		]);
		if (is_wp_error($r)) {
			return new WP_Error('mml_mt', __('LibreTranslate is not reachable: ', 'alba') . $r->get_error_message());
		}
		$d = json_decode(wp_remote_retrieve_body($r), true);
		if (!is_array($d) || empty($d['translatedText'])) {
			$why = is_array($d) && !empty($d['error']) ? (string) $d['error'] : 'HTTP ' . wp_remote_retrieve_response_code($r);
			return new WP_Error('mml_mt', 'LibreTranslate: ' . wp_strip_all_tags($why));
		}
		return (string) $d['translatedText'];
	}
}
