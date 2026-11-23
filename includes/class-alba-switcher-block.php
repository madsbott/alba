<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * "Language switcher" block (alba/switcher): rendered on the server, preview in the editor via JS.
 * Attribute: variant (list|dropdown|menu), display (name|code), hideMissing, hideCurrent.
 */
class ALBA_Switcher_Block {
	private $core;

	public function __construct(ALBA_Core $core) {
		$this->core = $core;
		add_action('init', [$this, 'register']);
	}

	public function register() {
		if (!function_exists('register_block_type')) {
			return;
		}
		$langs = [];
		foreach ($this->core->langs as $code => $l) {
			$langs[] = ['code' => $code, 'name' => $l['name']];
		}

		wp_register_script(
			'alba-switcher-block',
			ALBA_URL . 'assets/switcher-block.js',
			['alba-i18n', 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components'],
			ALBA_VERSION,
			true
		);
		wp_add_inline_script('alba-switcher-block', 'window.albaBlock = ' . wp_json_encode([
			'langs'   => $langs,
			'current' => $this->core->default,
		]) . ';', 'before');

		$def = [
			'api_version'     => 2,
			'title'           => __('Language switcher', 'alba'),
			'description'     => __('Links or dropdown for switching the language.', 'alba'),
			'category'        => 'widgets',
			'icon'            => 'translation',
			'attributes'      => [
				'variant'     => ['type' => 'string', 'default' => 'list'],
				'display'     => ['type' => 'string', 'default' => 'name'],
				'hideMissing' => ['type' => 'boolean', 'default' => false],
				'hideCurrent' => ['type' => 'boolean', 'default' => false],
			],
			'supports'        => [
				'html'       => false,
				'color'      => ['text' => true, 'background' => true, 'link' => true],
				'typography' => ['fontSize' => true],
				'spacing'    => ['margin' => true, 'padding' => true],
			],
			'editor_script'   => 'alba-switcher-block',
			'render_callback' => [$this, 'render'],
		];
		register_block_type('alba/switcher', $def);
		// Old block name (until 0.10.0): existing blocks in content stay valid.
		$def['supports']['inserter'] = false;
		register_block_type('mads-multilang/switcher', $def);
	}

	public function render($attrs, $content = '', $block = null) {
		$f = $this->core->frontend;
		if (!$f) {
			return '';
		}
		$attrs = is_array($attrs) ? $attrs : [];
		$html = $f->render([
			'style'        => (isset($attrs['variant']) && in_array($attrs['variant'], ['dropdown', 'menu'], true)) ? $attrs['variant'] : 'list',
			'display'      => (isset($attrs['display']) && $attrs['display'] === 'code') ? 'code' : 'name',
			'hide_missing' => !empty($attrs['hideMissing']) ? 1 : 0,
			'hide_current' => !empty($attrs['hideCurrent']) ? 1 : 0,
		]);
		return '<div ' . get_block_wrapper_attributes(['class' => 'alba-switcher-block']) . '>' . $html . '</div>';
	}
}
