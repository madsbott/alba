/**
 * Alba – "Language switcher" block (editor part, no build step).
 * Rendered on the frontend on the server (PHP); only preview and settings here.
 */
(function (wp) {
	'use strict';
	function albaT(s) { return (window.albaI18n && window.albaI18n[s]) || s; }
	function albaF(s) { var a = Array.prototype.slice.call(arguments, 1), i = 0; return albaT(s).replace(/%(\d+\$)?s/g, function (m, p) { var k = p ? parseInt(p, 10) - 1 : i++; return a[k]; }); }

	if (!wp || !wp.blocks || !wp.element || !wp.blockEditor || !wp.components) {
		return;
	}

	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;

	var data = window.albaBlock || { langs: [], current: '' };

	function labelOf(l, display) {
		return display === 'code' ? String(l.code).toUpperCase() : l.name;
	}

	function Edit(props) {
		var a = props.attributes;
		var set = props.setAttributes;
		var blockProps = useBlockProps({ className: 'alba-switcher-block' });

		var langs = data.langs.filter(function (l) {
			return !(a.hideCurrent && l.code === data.current);
		});

		var preview;
		if (a.variant === 'dropdown') {
			preview = el('select', {
				className: 'alba-switcher alba-dropdown',
				value: data.current,
				onChange: function () {},
				'aria-label': albaT('Language')
			}, langs.map(function (l) {
				return el('option', { key: l.code, value: l.code }, labelOf(l, a.display));
			}));
		} else if (a.variant === 'menu') {
			var curLang = langs.filter(function (l) { return l.code === data.current; })[0] || data.langs[0];
			var others = data.langs.filter(function (l) { return l.code !== curLang.code; });
			preview = el('ul', { className: 'alba-switcher alba-menu', style: { listStyle: 'none', margin: 0, padding: 0 } },
				el('li', { className: 'alba-lang alba-current' },
					el('a', { href: '#', onClick: function (e) { e.preventDefault(); } }, labelOf(curLang, a.display) + ' \u25BE'),
					el('ul', { className: 'sub-menu', style: { display: 'block', position: 'static', listStyle: 'none', margin: '2px 0 0', padding: '2px 0', boxShadow: 'none', border: '1px solid #ddd', background: '#fff', color: '#1d2327' } },
						others.map(function (l) {
							return el('li', { key: l.code }, el('a', { href: '#', style: { display: 'block', padding: '2px 10px' }, onClick: function (e) { e.preventDefault(); } }, labelOf(l, a.display)));
						})
					)
				)
			);
		} else {
			preview = el('ul', {
				className: 'alba-switcher',
				style: { listStyle: 'none', display: 'flex', gap: '.75rem', flexWrap: 'wrap', margin: 0, padding: 0 }
			}, langs.map(function (l) {
				var cur = l.code === data.current;
				return el('li', { key: l.code, className: 'alba-lang' + (cur ? ' alba-current' : '') },
					el('a', {
						href: '#',
						style: { fontWeight: cur ? '700' : 'inherit' },
						onClick: function (e) { e.preventDefault(); }
					}, labelOf(l, a.display))
				);
			}));
		}

		return el('div', blockProps,
			el(InspectorControls, null,
				el(PanelBody, { title: albaT('Language switcher'), initialOpen: true },
					el(SelectControl, {
						label: albaT('Appearance'),
						value: a.variant,
						options: [
							{ label: albaT('List of links'), value: 'list' },
							{ label: albaT('Dropdown (select field)'), value: 'dropdown' },
							{ label: albaT('Dropdown menu (submenu)'), value: 'menu' }
						],
						onChange: function (v) { set({ variant: v }); }
					}),
					el(SelectControl, {
						label: albaT('Label'),
						value: a.display,
						options: [
							{ label: albaT('Language name (full)'), value: 'name' },
							{ label: albaT('Abbreviation (DE)'), value: 'code' }
						],
						onChange: function (v) { set({ display: v }); }
					}),
					el(ToggleControl, {
						label: albaT('Hide current language'),
						checked: !!a.hideCurrent,
						onChange: function (v) { set({ hideCurrent: !!v }); }
					}),
					el(ToggleControl, {
						label: albaT('Hide languages without translation'),
						help: albaT('Only affects the frontend, not this preview.'),
						checked: !!a.hideMissing,
						onChange: function (v) { set({ hideMissing: !!v }); }
					})
				)
			),
			el('div', { style: { pointerEvents: 'none' } }, preview)
		);
	}

	var def = {
		apiVersion: 2,
		title: albaT('Language switcher'),
		description: albaT('Links or a dropdown for switching the language.'),
		category: 'widgets',
		icon: 'translation',
		keywords: ['language', 'translate', 'translation', 'switcher', 'sprache', 'übersetzung'],
		attributes: {
			variant: { type: 'string', default: 'list' },
			display: { type: 'string', default: 'name' },
			hideMissing: { type: 'boolean', default: false },
			hideCurrent: { type: 'boolean', default: false }
		},
		supports: {
			html: false,
			color: { text: true, background: true, link: true },
			typography: { fontSize: true },
			spacing: { margin: true, padding: true }
		},
		edit: Edit,
		save: function () { return null; }
	};
	registerBlockType('alba/switcher', def);
	// Old block name (until 0.10.0): existing blocks in content stay valid, no longer visible in the inserter.
	var old = Object.assign({}, def, { supports: Object.assign({}, def.supports, { inserter: false }) });
	registerBlockType('mads-multilang/switcher', old);
})(window.wp);
