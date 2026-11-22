/**
 * Alba – Block "Sprachumschalter" (Editor-Teil, ohne Build-Schritt).
 * Gerendert wird im Frontend serverseitig (PHP); hier nur Vorschau und Einstellungen.
 */
(function (wp) {
	'use strict';
	function mmlT(s) { return (window.mmlI18n && window.mmlI18n[s]) || s; }
	function mmlF(s) { var a = Array.prototype.slice.call(arguments, 1), i = 0; return mmlT(s).replace(/%(\d+\$)?s/g, function (m, p) { var k = p ? parseInt(p, 10) - 1 : i++; return a[k]; }); }

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

	var data = window.mmlBlock || { langs: [], current: '' };

	function labelOf(l, display) {
		return display === 'code' ? String(l.code).toUpperCase() : l.name;
	}

	function Edit(props) {
		var a = props.attributes;
		var set = props.setAttributes;
		var blockProps = useBlockProps({ className: 'mml-switcher-block' });

		var langs = data.langs.filter(function (l) {
			return !(a.hideCurrent && l.code === data.current);
		});

		var preview;
		if (a.variant === 'dropdown') {
			preview = el('select', {
				className: 'mml-switcher mml-dropdown',
				value: data.current,
				onChange: function () {},
				'aria-label': mmlT('Language')
			}, langs.map(function (l) {
				return el('option', { key: l.code, value: l.code }, labelOf(l, a.display));
			}));
		} else if (a.variant === 'menu') {
			var curLang = langs.filter(function (l) { return l.code === data.current; })[0] || data.langs[0];
			var others = data.langs.filter(function (l) { return l.code !== curLang.code; });
			preview = el('ul', { className: 'mml-switcher mml-menu', style: { listStyle: 'none', margin: 0, padding: 0 } },
				el('li', { className: 'mml-lang mml-current' },
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
				className: 'mml-switcher',
				style: { listStyle: 'none', display: 'flex', gap: '.75rem', flexWrap: 'wrap', margin: 0, padding: 0 }
			}, langs.map(function (l) {
				var cur = l.code === data.current;
				return el('li', { key: l.code, className: 'mml-lang' + (cur ? ' mml-current' : '') },
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
				el(PanelBody, { title: mmlT('Language switcher'), initialOpen: true },
					el(SelectControl, {
						label: mmlT('Appearance'),
						value: a.variant,
						options: [
							{ label: mmlT('List of links'), value: 'list' },
							{ label: mmlT('Dropdown (select field)'), value: 'dropdown' },
							{ label: mmlT('Dropdown menu (submenu)'), value: 'menu' }
						],
						onChange: function (v) { set({ variant: v }); }
					}),
					el(SelectControl, {
						label: mmlT('Label'),
						value: a.display,
						options: [
							{ label: mmlT('Language name (full)'), value: 'name' },
							{ label: mmlT('Abbreviation (DE)'), value: 'code' }
						],
						onChange: function (v) { set({ display: v }); }
					}),
					el(ToggleControl, {
						label: mmlT('Hide current language'),
						checked: !!a.hideCurrent,
						onChange: function (v) { set({ hideCurrent: !!v }); }
					}),
					el(ToggleControl, {
						label: mmlT('Hide languages without translation'),
						help: mmlT('Only affects the frontend, not this preview.'),
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
		title: mmlT('Language switcher'),
		description: mmlT('Links or a dropdown for switching the language.'),
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
	// Alter Blockname (bis 0.10.0): bestehende Blöcke in Inhalten bleiben gültig, im Inserter nicht mehr sichtbar.
	var old = Object.assign({}, def, { supports: Object.assign({}, def.supports, { inserter: false }) });
	registerBlockType('mads-multilang/switcher', old);
})(window.wp);
