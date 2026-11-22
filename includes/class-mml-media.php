<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Medien pro Sprache: Alt-Text, Titel, Beschriftung und Beschreibung je Sprache, optional ein anderes Bild je Sprache.
 *
 * Gespeichert wird am Original-Medium als Meta _mml_media_{sprache}: alt, title, caption, desc, file (ID eines anderen Mediums).
 * Bearbeiten: in der Mediathek (Anhang-Details im Dialog und auf der Bearbeiten-Seite des Mediums).
 * Frontend (nicht in der Standardsprache):
 *  - wp_get_attachment_image (Beitragsbilder, Theme, WPBakery, Salient …): Alt-Text und Bild ersetzt, srcset/sizes passend zum anderen Bild
 *  - wp_get_attachment_image_src / _url: andere Bilddatei
 *  - Bilder im Beitragstext (Klasse wp-image-ID): Alt-Text nur ersetzt, wenn er dem Alt-Text des Mediums entspricht oder leer ist
 *    (ein im Editor übersetzter Alt-Text hat Vorrang); andere Bilddatei samt srcset
 *  - Beschriftung (wp_get_attachment_caption) und Titel (the_title des Mediums)
 */
class MML_Media {
	const META = '_mml_media_';

	private $core;
	private $busy = false;

	public function __construct(MML_Core $core) {
		$this->core = $core;
		$core->media = $this;

		if (is_admin()) {
			add_filter('attachment_fields_to_edit', [$this, 'modal_fields'], 20, 2);
			add_filter('attachment_fields_to_save', [$this, 'modal_save'], 20, 2);
			add_action('add_meta_boxes_attachment', [$this, 'meta_box']);
			add_action('edit_attachment', [$this, 'box_save']);
			add_action('wp_enqueue_media', [$this, 'assets']);
			add_filter('manage_media_columns', [$this, 'column']);
			add_action('manage_media_custom_column', [$this, 'column_cell'], 10, 2);
			add_action('admin_enqueue_scripts', function ($hook) {
				if ($hook === 'post.php' && get_post_type(isset($_GET['post']) ? (int) $_GET['post'] : 0) === 'attachment') {
					wp_enqueue_media();
				}
			});
			return;
		}
		add_filter('wp_get_attachment_image_attributes', [$this, 'image_attributes'], 20, 3);
		add_filter('wp_get_attachment_image_src', [$this, 'image_src'], 20, 4);
		add_filter('wp_get_attachment_url', [$this, 'attachment_url'], 20, 2);
		add_filter('wp_get_attachment_caption', [$this, 'caption'], 20, 2);
		add_filter('the_title', [$this, 'title'], 20, 2);
		add_filter('wp_content_img_tag', [$this, 'content_img'], 20, 3);
	}

	/* ---------------------------------------------------------------
	 * Daten
	 * ------------------------------------------------------------- */

	public function langs() {
		$c = $this->core;
		return array_diff_key($c->langs, [$c->default => 1]);
	}

	public function get($id, $lang) {
		$d = get_post_meta((int) $id, self::META . $lang, true);
		if (!is_array($d)) {
			return [];
		}
		return $d + ['alt' => '', 'title' => '', 'caption' => '', 'desc' => '', 'file' => 0];
	}

	public function store($id, $lang, array $in) {
		$c = $this->core;
		if (!isset($c->langs[$lang]) || $lang === $c->default || get_post_type($id) !== 'attachment') {
			return;
		}
		$file = isset($in['file']) ? (int) $in['file'] : 0;
		if ($file && ($file === (int) $id || get_post_type($file) !== 'attachment' || strpos((string) get_post_mime_type($file), 'image/') !== 0)) {
			$file = 0;
		}
		$d = [
			'alt'     => isset($in['alt']) ? sanitize_text_field(wp_unslash($in['alt'])) : '',
			'title'   => isset($in['title']) ? sanitize_text_field(wp_unslash($in['title'])) : '',
			'caption' => isset($in['caption']) ? wp_kses_post(wp_unslash($in['caption'])) : '',
			'desc'    => isset($in['desc']) ? wp_kses_post(wp_unslash($in['desc'])) : '',
			'file'    => $file,
		];
		if ($d['alt'] === '' && $d['title'] === '' && $d['caption'] === '' && $d['desc'] === '' && !$d['file']) {
			delete_post_meta($id, self::META . $lang);
		} else {
			update_post_meta($id, self::META . $lang, $d);
		}
		$c->flush();
		do_action('mml_media_saved', (int) $id, $lang);
	}

	/** Zusammenfassung für Spalten: Sprachen mit Eintrag */
	public function filled($id) {
		$out = [];
		foreach ($this->langs() as $code => $l) {
			if (metadata_exists('post', $id, self::META . $code)) {
				$out[] = $code;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------
	 * Backend
	 * ------------------------------------------------------------- */

	public function assets() {
		wp_enqueue_script('mml-media', MML_URL . 'assets/media.js', ['jquery', 'mml-i18n'], MML_VERSION, true);
		wp_localize_script('mml-media', 'mmlMedia', [
			'ajax'   => admin_url('admin-ajax.php'),
			'nonce'  => wp_create_nonce('mml_mt'),
			'from'   => $this->core->default,
			'mt'     => MML_MT::enabled(),
			'choose' => __('Choose image', 'alba'),
		]);
	}

	/** Eingabefelder für eine Sprache; $name($feld) liefert den Feldnamen */
	private function fields_html($post, $lang, callable $name) {
		$v = $this->get($post->ID, $lang) ?: ['alt' => '', 'title' => '', 'caption' => '', 'desc' => '', 'file' => 0];
		$orig = [
			'alt'     => (string) get_post_meta($post->ID, '_wp_attachment_image_alt', true),
			'title'   => (string) $post->post_title,
			'caption' => (string) $post->post_excerpt,
			'desc'    => (string) $post->post_content,
		];
		$is_img = strpos((string) $post->post_mime_type, 'image/') === 0;
		$rows = ['alt' => __('Alt text', 'alba'), 'title' => __('Title', 'alba'), 'caption' => __('Caption', 'alba'), 'desc' => __('Description', 'alba')];
		$h = '<div class="mml-media" data-lang="' . esc_attr($lang) . '" style="display:grid;gap:6px;max-width:560px">';
		foreach ($rows as $k => $label) {
			if ($k === 'alt' && !$is_img) {
				continue;
			}
			$long = in_array($k, ['caption', 'desc'], true);
			$h .= '<label style="display:block"><span style="display:block;font-size:12px;color:#646970">' . esc_html($label) . '</span>';
			if ($long) {
				$h .= '<textarea rows="2" class="widefat" data-k="' . esc_attr($k) . '" data-orig="' . esc_attr($orig[$k]) . '" name="' . esc_attr($name($k)) . '" placeholder="' . esc_attr($orig[$k]) . '">' . esc_textarea($v[$k]) . '</textarea>';
			} else {
				$h .= '<input type="text" class="widefat" data-k="' . esc_attr($k) . '" data-orig="' . esc_attr($orig[$k]) . '" name="' . esc_attr($name($k)) . '" value="' . esc_attr($v[$k]) . '" placeholder="' . esc_attr($orig[$k]) . '">';
			}
			$h .= '</label>';
		}
		if ($is_img) {
			$thumb = $v['file'] ? wp_get_attachment_image((int) $v['file'], [60, 60]) : '';
			$h .= '<div class="mml-media-file"><span style="display:block;font-size:12px;color:#646970">' . esc_html__('Different image for this language (optional)', 'alba') . '</span>'
				. '<span class="mml-media-thumb">' . $thumb . '</span> '
				. '<input type="hidden" data-k="file" name="' . esc_attr($name('file')) . '" value="' . (int) $v['file'] . '"> '
				. '<button type="button" class="button mml-media-pick">' . esc_html__('Choose image', 'alba') . '</button> '
				. '<button type="button" class="button-link mml-media-clear"' . ($v['file'] ? '' : ' style="display:none"') . '>entfernen</button></div>';
		}
		if (MML_MT::enabled()) {
			$h .= '<div><button type="button" class="button mml-media-mt">' . esc_html__('Translate texts from the original', 'alba') . '</button> <span class="mml-media-msg description"></span></div>';
		}
		return $h . '</div>';
	}

	public function modal_fields($fields, $post) {
		if (!current_user_can('edit_post', $post->ID)) {
			return $fields;
		}
		foreach ($this->langs() as $code => $l) {
			$fields['mml_media_' . $code] = [
				'label' => $l['name'],
				'input' => 'html',
				'html'  => $this->fields_html($post, $code, function ($k) use ($post, $code) {
					return 'attachments[' . (int) $post->ID . '][mml_' . $code . '_' . $k . ']';
				}),
				'helps' => __('Alba: text and image for this language', 'alba'),
			];
		}
		return $fields;
	}

	public function modal_save($post, $attachment) {
		if (!current_user_can('edit_post', $post['ID'])) {
			return $post;
		}
		foreach ($this->langs() as $code => $l) {
			$keys = ['alt', 'title', 'caption', 'desc', 'file'];
			$has = false;
			$in = [];
			foreach ($keys as $k) {
				if (isset($attachment['mml_' . $code . '_' . $k])) {
					$has = true;
					$in[$k] = wp_slash($attachment['mml_' . $code . '_' . $k]);
				}
			}
			if ($has) {
				$this->store((int) $post['ID'], $code, $in);
			}
		}
		return $post;
	}

	public function meta_box() {
		if (!$this->langs()) {
			return;
		}
		add_meta_box('mml-media', __('Languages (Alba)', 'alba'), [$this, 'box'], 'attachment', 'normal', 'default');
	}

	public function box($post) {
		wp_nonce_field('mml_media_' . $post->ID, 'mml_media_nonce');
		$first = true;
		foreach ($this->langs() as $code => $l) {
			echo '<h4 style="margin:' . ($first ? '0' : '14px') . ' 0 6px">' . esc_html($l['name']) . '</h4>';
			echo $this->fields_html($post, $code, function ($k) use ($code) { // phpcs:ignore WordPress.Security.EscapeOutput
				return 'mml_media[' . $code . '][' . $k . ']';
			});
			$first = false;
		}
		echo '<p class="description">' . esc_html__('Empty fields show the text of the original on the frontend. A different image replaces the file wherever the media item is output via WordPress functions (featured image, image block, theme, WPBakery).', 'alba') . '</p>';
	}

	public function box_save($id) {
		if (empty($_POST['mml_media_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mml_media_nonce'])), 'mml_media_' . $id) || !current_user_can('edit_post', $id)) {
			return;
		}
		$in = isset($_POST['mml_media']) && is_array($_POST['mml_media']) ? wp_unslash($_POST['mml_media']) : [];
		foreach ($this->langs() as $code => $l) {
			if (isset($in[$code]) && is_array($in[$code])) {
				$this->store($id, $code, wp_slash($in[$code]));
			}
		}
	}

	public function column($cols) {
		if ($this->langs()) {
			$cols['mml_media'] = __('Languages', 'alba');
		}
		return $cols;
	}

	public function column_cell($col, $id) {
		if ($col !== 'mml_media') {
			return;
		}
		$f = $this->filled($id);
		echo $f ? esc_html(implode(', ', array_map('strtoupper', $f))) : '–';
	}

	/* ---------------------------------------------------------------
	 * Frontend
	 * ------------------------------------------------------------- */

	private function over($id) {
		$c = $this->core;
		if ($c->lang === $c->default || is_admin() || !$id) {
			return [];
		}
		return $this->get($id, $c->lang);
	}

	private function other_src($file, $size, $icon = false) {
		$this->busy = true;
		$r = wp_get_attachment_image_src($file, $size, $icon);
		$this->busy = false;
		return $r;
	}

	public function image_src($image, $id, $size, $icon) {
		if ($this->busy) {
			return $image;
		}
		$o = $this->over($id);
		if (!$o || empty($o['file'])) {
			return $image;
		}
		$r = $this->other_src((int) $o['file'], $size, $icon);
		return $r ?: $image;
	}

	public function attachment_url($url, $id) {
		if ($this->busy) {
			return $url;
		}
		$o = $this->over($id);
		if (!$o || empty($o['file'])) {
			return $url;
		}
		$this->busy = true;
		$u = wp_get_attachment_url((int) $o['file']);
		$this->busy = false;
		return $u ?: $url;
	}

	public function image_attributes($attr, $attachment, $size) {
		if ($this->busy || !$attachment) {
			return $attr;
		}
		$o = $this->over($attachment->ID);
		if (!$o) {
			return $attr;
		}
		if ($o['alt'] !== '') {
			$attr['alt'] = $o['alt'];
		}
		if ($o['title'] !== '' && isset($attr['title'])) {
			$attr['title'] = $o['title'];
		}
		if (!empty($o['file'])) {
			$f = (int) $o['file'];
			$this->busy = true;
			$srcset = wp_get_attachment_image_srcset($f, $size);
			$sizes = wp_get_attachment_image_sizes($f, $size);
			$this->busy = false;
			if ($srcset) {
				$attr['srcset'] = $srcset;
				if ($sizes) {
					$attr['sizes'] = $sizes;
				}
			} else {
				unset($attr['srcset'], $attr['sizes']);
			}
		}
		return $attr;
	}

	public function caption($caption, $id) {
		$o = $this->over($id);
		return ($o && $o['caption'] !== '') ? $o['caption'] : $caption;
	}

	public function title($title, $id = 0) {
		if (!$id || get_post_type($id) !== 'attachment') {
			return $title;
		}
		$o = $this->over($id);
		return ($o && $o['title'] !== '') ? $o['title'] : $title;
	}

	private function set_attr($html, $name, $value) {
		$val = esc_attr($value);
		if (preg_match('/(\s)' . preg_quote($name, '/') . '=(["\'])(.*?)\2/s', $html, $m)) {
			return str_replace($m[0], $m[1] . $name . '="' . $val . '"', $html);
		}
		return preg_replace('/<img\b/i', '<img ' . $name . '="' . $val . '"', $html, 1);
	}

	/** Bilder im Beitragstext */
	public function content_img($html, $context = '', $aid = 0) {
		$aid = (int) $aid;
		if (!$aid || !is_string($html)) {
			return $html;
		}
		$o = $this->over($aid);
		if (!$o) {
			return $html;
		}
		if ($o['alt'] !== '') {
			$orig = (string) get_post_meta($aid, '_wp_attachment_image_alt', true);
			$cur = preg_match('/\salt=(["\'])(.*?)\1/s', $html, $m) ? html_entity_decode($m[2], ENT_QUOTES, 'UTF-8') : '';
			if ($cur === '' || $cur === $orig) {
				$html = $this->set_attr($html, 'alt', $o['alt']);
			}
		}
		if (!empty($o['file'])) {
			$f = (int) $o['file'];
			$size = preg_match('/\bsize-([\w-]+)/', $html, $m) ? $m[1] : 'full';
			$src = $this->other_src($f, $size);
			if (!$src) {
				return $html;
			}
			$html = $this->set_attr($html, 'src', $src[0]);
			if ($src[1] && $src[2]) {
				$html = $this->set_attr($html, 'width', (string) $src[1]);
				$html = $this->set_attr($html, 'height', (string) $src[2]);
			}
			$this->busy = true;
			$srcset = wp_get_attachment_image_srcset($f, $size);
			$sizes = wp_get_attachment_image_sizes($f, $size);
			$this->busy = false;
			if ($srcset) {
				$html = $this->set_attr($html, 'srcset', $srcset);
				if ($sizes) {
					$html = $this->set_attr($html, 'sizes', $sizes);
				}
			} else {
				$html = preg_replace('/\s(srcset|sizes)=(["\']).*?\2/s', '', $html);
			}
			$html = preg_replace('/\bwp-image-' . $aid . '\b/', 'wp-image-' . $f, $html);
		}
		return $html;
	}
}
