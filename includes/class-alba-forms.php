<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Formulare pro Sprache: Contact Form 7, WPForms, Gravity Forms, Fluent Forms.
 *
 * Beschriftungen, Platzhalter, Beschreibungen, Auswahlmöglichkeiten, Schaltflächen, Fehlermeldungen,
 * Bestätigungen und Mail-Texte laufen beim Anzeigen und Absenden durch die Zeichenfolgen-Übersetzung
 * (Domain „Formulare“, Sprachen > Zeichenfolgen). Jeder Text wird einmal angelegt und gilt für alle Formulare.
 * Vorgehen: Die Seite einmal in der Standardsprache aufrufen, dann tauchen alle Texte des Formulars in der Liste auf.
 *
 * Absenden per AJAX/REST: Diese Anfragen haben keine Sprach-URL. Die Sprache wird deshalb aus der Herkunftsadresse
 * (Referer) der Seite bestimmt, auf der das Formular stand; Sprache und Locale gelten dann auch für Fehlermeldungen,
 * Bestätigung und E-Mail.
 */
class ALBA_Forms {
	const DOMAIN = 'Forms';
	const OPT = 'alba_forms';

	private $core;

	public function __construct(ALBA_Core $core) {
		$this->core = $core;
		$core->forms = $this;

		$this->adopt_request_lang();

		// Contact Form 7
		add_filter('wpcf7_contact_form_properties', [$this, 'cf7'], 20, 2);
		// WPForms
		add_filter('wpforms_frontend_form_data', [$this, 'wpforms']);
		add_filter('wpforms_process_before_form_data', [$this, 'wpforms']);
		// Gravity Forms
		foreach (['gform_pre_render', 'gform_pre_validation', 'gform_pre_submission_filter'] as $h) {
			add_filter($h, [$this, 'gravity']);
		}
		add_filter('gform_notification', [$this, 'gravity_notification'], 10, 3);
		// Fluent Forms
		add_filter('fluentform/rendering_form', [$this, 'fluent_form']);
		add_filter('fluentform/form_submission_confirmation', [$this, 'fluent_confirmation'], 10, 3);
		add_filter('fluentform/submission_message_parse', [$this, 'fluent_message'], 5, 4);
		add_filter('fluentform/email_subject', [$this, 'fluent_subject'], 5, 4);

		if (is_admin()) {
			add_action('admin_init', [$this, 'register']);
		}
	}

	public function enabled() {
		return get_option(self::OPT, '1') === '1';
	}

	/* ---------------------------------------------------------------
	 * Sprache bei AJAX/REST-Absendungen
	 * ------------------------------------------------------------- */

	private function adopt_request_lang() {
		$c = $this->core;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw request path, only parsed, never output.
		$uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
		$rest = strpos($uri, '/wp-json/') !== false || isset($_GET['rest_route']);
		if ((!wp_doing_ajax() && !$rest) || (isset($_SERVER['REQUEST_METHOD']) && sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) !== 'POST') || $c->lang !== $c->default) {
			return;
		}
		$lang = $this->referer_lang();
		if ($lang === '' || $lang === $c->default || !$this->enabled()) {
			return;
		}
		$c->lang = $lang;
		add_filter('locale', function ($l) use ($c) {
			return !empty($c->langs[$c->lang]['locale']) ? $c->langs[$c->lang]['locale'] : $l;
		}, 20);
	}

	/** Sprache der Seite, von der die Anfrage kommt ('' wenn unbekannt) */
	public function referer_lang() {
		$c = $this->core;
		$ref = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '';
		if ($ref === '') {
			return '';
		}
		$home = (string) get_option('home');
		$rh = wp_parse_url($ref, PHP_URL_HOST);
		if (!$rh || strcasecmp($rh, (string) wp_parse_url($home, PHP_URL_HOST)) !== 0) {
			return '';
		}
		$path = (string) wp_parse_url($ref, PHP_URL_PATH);
		$base = rtrim((string) wp_parse_url($home, PHP_URL_PATH), '/');
		if ($base !== '' && strpos($path, $base . '/') === 0) {
			$path = substr($path, strlen($base));
		}
		$seg = explode('/', ltrim($path, '/'))[0];
		if ($seg !== '' && isset($c->langs[$seg])) {
			return $seg;
		}
		$q = (string) wp_parse_url($ref, PHP_URL_QUERY);
		if ($q !== '') {
			parse_str($q, $a);
			if (!empty($a['lang']) && is_string($a['lang']) && isset($c->langs[$a['lang']])) {
				return $a['lang'];
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------
	 * Übersetzungs-Helfer
	 * ------------------------------------------------------------- */

	private function active() {
		return $this->enabled() && $this->core->strings && !(is_admin() && !wp_doing_ajax());
	}

	private function has_text($s) {
		$s = preg_replace('/\[[^\]]*\]|\{[^}]*\}|%%?[a-z_]+%%?/i', '', (string) $s);
		return (bool) preg_match('/\p{L}{2}/u', $s);
	}

	/** Einzelner Text (Beschriftung, Platzhalter …): Leerraum am Rand bleibt */
	public function t($s) {
		if (!is_string($s) || !$this->has_text($s)) {
			return $s;
		}
		if (!preg_match('/^(\s*)(.*?)(\s*)$/su', $s, $m) || $m[2] === '') {
			return $s;
		}
		return $m[1] . $this->core->strings->translate($m[2], self::DOMAIN) . $m[3];
	}

	/** Text mit HTML und mehreren Zeilen: jede Textzeile zwischen den Tags einzeln */
	public function html($s) {
		if (!is_string($s) || $s === '' || !$this->has_text($s)) {
			return $s;
		}
		$parts = preg_split('/(<(?![\[{])[^>]*>)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
		foreach ($parts as $i => $p) {
			if ($p === '' || ($p[0] === '<' && substr($p, -1) === '>' && !preg_match('/^<[\[{]/', $p))) {
				continue;
			}
			$lines = preg_split('/(\R)/', $p, -1, PREG_SPLIT_DELIM_CAPTURE);
			foreach ($lines as $j => $ln) {
				if ($ln !== '' && !preg_match('/^\R$/', $ln)) {
					$lines[$j] = $this->t($ln);
				}
			}
			$parts[$i] = implode('', $lines);
		}
		return implode('', $parts);
	}

	/** Bestimmte Schlüssel in einem verschachtelten Array übersetzen */
	private function walk($v, array $keys, array $html_keys = []) {
		if (!is_array($v)) {
			return $v;
		}
		foreach ($v as $k => $x) {
			if (is_array($x)) {
				$v[$k] = $this->walk($x, $keys, $html_keys);
			} elseif (is_string($x) && is_string($k)) {
				if (in_array($k, $html_keys, true)) {
					$v[$k] = $this->html($x);
				} elseif (in_array($k, $keys, true)) {
					$v[$k] = $this->t($x);
				}
			}
		}
		return $v;
	}

	/* ---------------------------------------------------------------
	 * Contact Form 7
	 * ------------------------------------------------------------- */

	public function cf7($props, $form = null) {
		if (!$this->active() || !is_array($props)) {
			return $props;
		}
		if (!empty($props['form']) && is_string($props['form'])) {
			$props['form'] = $this->cf7_template($props['form']);
		}
		if (!empty($props['messages']) && is_array($props['messages'])) {
			foreach ($props['messages'] as $k => $m) {
				$props['messages'][$k] = $this->html($m);
			}
		}
		foreach (['mail', 'mail_2'] as $k) {
			if (!empty($props[$k]) && is_array($props[$k])) {
				if (isset($props[$k]['subject'])) {
					$props[$k]['subject'] = $this->t($props[$k]['subject']);
				}
				if (isset($props[$k]['body'])) {
					$props[$k]['body'] = $this->html($props[$k]['body']);
				}
			}
		}
		return $props;
	}

	/** Formular-Vorlage: Text zwischen den Tags und die Texte in den Tags (Platzhalter, Optionen, Schaltflächen) */
	private function cf7_template($tpl) {
		$parts = preg_split('/(\[[^\[\]]*\])/', $tpl, -1, PREG_SPLIT_DELIM_CAPTURE);
		foreach ($parts as $i => $p) {
			if ($p === '') {
				continue;
			}
			if ($p[0] === '[' && substr($p, -1) === ']') {
				$parts[$i] = $this->cf7_tag($p);
			} else {
				$parts[$i] = $this->html($p);
			}
		}
		return implode('', $parts);
	}

	private function cf7_tag($tag) {
		if (!preg_match('/^\[\s*([a-zA-Z_][\w-]*)\*?(\s.*)?\]$/s', $tag, $m)) {
			return $tag;
		}
		$type = strtolower($m[1]);
		if ($type[0] === '/') {
			return $tag;
		}
		$me = $this;
		if ($type === 'submit') {
			return preg_replace_callback('/"([^"]*)"/', function ($x) use ($me) {
				return '"' . $me->t($x[1]) . '"';
			}, $tag);
		}
		if (in_array($type, ['select', 'radio', 'checkbox'], true)) {
			return preg_replace_callback('/"([^"]*)"/', function ($x) use ($me) {
				$v = $x[1];
				$pos = strpos($v, '|');
				if ($pos !== false) {
					return '"' . $me->t(substr($v, 0, $pos)) . substr($v, $pos) . '"';
				}
				return '"' . $me->t($v) . '"';
			}, $tag);
		}
		if (in_array($type, ['text', 'email', 'url', 'tel', 'textarea', 'number', 'range', 'date', 'password'], true)) {
			return preg_replace_callback('/\b(placeholder|watermark)(\s+)"([^"]*)"/', function ($x) use ($me) {
				return $x[1] . $x[2] . '"' . $me->t($x[3]) . '"';
			}, $tag);
		}
		return $tag;
	}

	/* ---------------------------------------------------------------
	 * WPForms
	 * ------------------------------------------------------------- */

	public function wpforms($fd) {
		if (!$this->active() || !is_array($fd)) {
			return $fd;
		}
		$keys = ['label', 'description', 'placeholder', 'first_placeholder', 'middle_placeholder', 'last_placeholder', 'address1_placeholder', 'address2_placeholder', 'city_placeholder', 'state_placeholder', 'postal_placeholder', 'confirmation_placeholder', 'required-field-message', 'required_field_message', 'next', 'prev', 'title', 'name_pagebreak'];
		if (!empty($fd['fields']) && is_array($fd['fields'])) {
			foreach ($fd['fields'] as $id => $f) {
				if (!is_array($f)) {
					continue;
				}
				$type = isset($f['type']) ? $f['type'] : '';
				foreach ($keys as $k) {
					if (isset($f[$k]) && is_string($f[$k]) && !($k === 'title' && $type !== 'pagebreak')) {
						$f[$k] = $this->t($f[$k]);
					}
				}
				if (isset($f['code']) && is_string($f['code']) && $type === 'html') {
					$f['code'] = $this->html($f['code']);
				}
				if (isset($f['content']) && is_string($f['content'])) {
					$f['content'] = $this->html($f['content']);
				}
				if (!empty($f['choices']) && is_array($f['choices'])) {
					foreach ($f['choices'] as $n => $ch) {
						if (is_array($ch) && isset($ch['label'])) {
							$f['choices'][$n]['label'] = $this->t($ch['label']);
						}
					}
				}
				$fd['fields'][$id] = $f;
			}
		}
		if (!empty($fd['settings']) && is_array($fd['settings'])) {
			$s = &$fd['settings'];
			foreach (['submit_text', 'submit_text_processing', 'form_desc'] as $k) {
				if (isset($s[$k]) && is_string($s[$k])) {
					$s[$k] = $this->t($s[$k]);
				}
			}
			if (!empty($s['notifications']) && is_array($s['notifications'])) {
				foreach ($s['notifications'] as $n => $nf) {
					if (!is_array($nf)) {
						continue;
					}
					foreach (['subject', 'sender_name'] as $k) {
						if (isset($nf[$k])) {
							$s['notifications'][$n][$k] = $this->t($nf[$k]);
						}
					}
					if (isset($nf['message'])) {
						$s['notifications'][$n]['message'] = $this->html($nf['message']);
					}
				}
			}
			if (!empty($s['confirmations']) && is_array($s['confirmations'])) {
				foreach ($s['confirmations'] as $n => $cf) {
					if (is_array($cf) && isset($cf['message'])) {
						$s['confirmations'][$n]['message'] = $this->html($cf['message']);
					}
				}
			}
			unset($s);
		}
		return $fd;
	}

	/* ---------------------------------------------------------------
	 * Gravity Forms
	 * ------------------------------------------------------------- */

	private function g_get($o, $k) {
		return is_object($o) ? (isset($o->$k) ? $o->$k : null) : (isset($o[$k]) ? $o[$k] : null);
	}

	private function g_set(&$o, $k, $v) {
		if (is_object($o)) {
			$o->$k = $v;
		} else {
			$o[$k] = $v;
		}
	}

	public function gravity($form) {
		if (!$this->active() || !is_array($form)) {
			return $form;
		}
		foreach (['title', 'description'] as $k) {
			if (isset($form[$k]) && is_string($form[$k])) {
				$form[$k] = $this->t($form[$k]);
			}
		}
		if (!empty($form['button']['text'])) {
			$form['button']['text'] = $this->t($form['button']['text']);
		}
		if (!empty($form['pagination']) && is_array($form['pagination'])) {
			$form['pagination'] = $this->walk($form['pagination'], ['text', 'title', 'previousButton', 'nextButton'], []);
			if (!empty($form['pagination']['pages']) && is_array($form['pagination']['pages'])) {
				foreach ($form['pagination']['pages'] as $n => $pg) {
					if (is_string($pg)) {
						$form['pagination']['pages'][$n] = $this->t($pg);
					}
				}
			}
		}
		if (!empty($form['confirmations']) && is_array($form['confirmations'])) {
			foreach ($form['confirmations'] as $id => $cf) {
				if (is_array($cf) && isset($cf['message'])) {
					$form['confirmations'][$id]['message'] = $this->html($cf['message']);
				}
			}
		}
		if (!empty($form['fields']) && is_array($form['fields'])) {
			foreach ($form['fields'] as $n => $f) {
				foreach (['label', 'description', 'placeholder', 'errorMessage', 'checkboxLabel', 'customRequiredIndicator'] as $k) {
					$v = $this->g_get($f, $k);
					if (is_string($v) && $v !== '') {
						$this->g_set($f, $k, $this->t($v));
					}
				}
				$content = $this->g_get($f, 'content');
				if (is_string($content) && $content !== '') {
					$this->g_set($f, 'content', $this->html($content));
				}
				foreach (['choices', 'inputs'] as $k) {
					$list = $this->g_get($f, $k);
					if (is_array($list)) {
						foreach ($list as $i => $it) {
							if (!is_array($it)) {
								continue;
							}
							foreach (['text', 'label', 'placeholder'] as $kk) {
								if (isset($it[$kk]) && is_string($it[$kk])) {
									$list[$i][$kk] = $this->t($it[$kk]);
								}
							}
						}
						$this->g_set($f, $k, $list);
					}
				}
				foreach (['nextButton', 'previousButton'] as $k) {
					$b = $this->g_get($f, $k);
					if (is_array($b) && !empty($b['text'])) {
						$b['text'] = $this->t($b['text']);
						$this->g_set($f, $k, $b);
					}
				}
				$form['fields'][$n] = $f;
			}
		}
		return $form;
	}

	public function gravity_notification($n, $form = null, $entry = null) {
		if (!$this->active() || !is_array($n)) {
			return $n;
		}
		foreach (['subject', 'fromName'] as $k) {
			if (isset($n[$k]) && is_string($n[$k])) {
				$n[$k] = $this->t($n[$k]);
			}
		}
		if (isset($n['message']) && is_string($n['message'])) {
			$n['message'] = $this->html($n['message']);
		}
		return $n;
	}

	/* ---------------------------------------------------------------
	 * Fluent Forms
	 * ------------------------------------------------------------- */

	public function fluent_form($form) {
		if (!$this->active() || !is_object($form) || empty($form->fields) || !is_array($form->fields)) {
			return $form;
		}
		$form->fields = $this->walk($form->fields, ['label', 'placeholder', 'help_message', 'message', 'btn_text', 'text', 'title', 'description', 'tc_agree_text', 'section_title', 'prev_btn', 'next_btn'], ['html_codes']);
		return $form;
	}

	public function fluent_confirmation($c, $data = null, $form = null) {
		if (!$this->active() || !is_array($c)) {
			return $c;
		}
		foreach (['messageToShow', 'redirectMessage'] as $k) {
			if (isset($c[$k]) && is_string($c[$k])) {
				$c[$k] = $this->html($c[$k]);
			}
		}
		return $c;
	}

	public function fluent_message($body, $entry = null, $data = null, $form = null) {
		return $this->active() && is_string($body) ? $this->html($body) : $body;
	}

	public function fluent_subject($s, $n = null, $data = null, $form = null) {
		return $this->active() && is_string($s) ? $this->t($s) : $s;
	}

	/* ---------------------------------------------------------------
	 * Einstellungen
	 * ------------------------------------------------------------- */

	public function register() {
		register_setting('alba', self::OPT, ['sanitize_callback' => function ($v) {
			return $v === '1' ? '1' : '0';
		}]);
	}

	/** Erkannte Formular-Plugins */
	public function detected() {
		$out = [];
		if (defined('WPCF7_VERSION') || class_exists('WPCF7_ContactForm')) {
			$out[] = 'Contact Form 7';
		}
		if (function_exists('wpforms')) {
			$out[] = 'WPForms';
		}
		if (class_exists('GFForms')) {
			$out[] = 'Gravity Forms';
		}
		if (defined('FLUENTFORM') || function_exists('wpFluentForm')) {
			$out[] = 'Fluent Forms';
		}
		return $out;
	}

	public function card() {
		$det = $this->detected();
		?>
		<section class="alba-card" data-tab="areas">
			<header class="alba-card-h"><span class="dashicons dashicons-feedback"></span><div><h2><?php echo esc_html__('Forms', 'alba'); ?></h2><p>Contact Form 7, WPForms, Gravity Forms, Fluent Forms.</p></div></header>
			<div class="alba-card-b">
				<div class="alba-fields">
					<div class="alba-field"><div class="alba-label"><?php echo esc_html__('Translation', 'alba'); ?></div><div class="alba-ctl">
						<label><input type="checkbox" name="alba_forms" value="1" <?php checked($this->enabled()); ?>> <?php echo esc_html__('Translate form texts per language', 'alba'); ?></label>
						<p class="alba-hint"><?php echo esc_html__('Labels, placeholders, select fields, buttons, error messages, confirmation and email texts (subject and content). Detected:', 'alba'); ?> <strong><?php echo $det ? esc_html(implode(', ', $det)) : esc_html__('no supported form plugin', 'alba'); ?></strong>.</p>
						<p class="alba-hint"><?php echo esc_html__('How it works: open the page with the form once in the default language, then all texts appear in', 'alba'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=alba-strings')); ?>"><?php echo esc_html__('Strings', 'alba'); ?></a> <?php echo esc_html__('under the domain "', 'alba'); ?><?php echo esc_html(self::DOMAIN); ?><?php echo esc_html__('". Enter the translations there. Emails to you stay in the language in which the form was filled in.', 'alba'); ?></p>
					</div></div>
				</div>
			</div>
		</section>
		<?php
	}
}
