/**
 * Alba – visibility per language for every block (editor, block widgets, Site Editor).
 * Adds the attribute "albaLangs" (list of language codes) to all blocks and a "Languages" section to the sidebar.
 * Empty list = visible in all languages. Hiding happens on the server on the frontend.
 */
(function (wp) {
	'use strict';
	function albaT(s) { return (window.albaI18n && window.albaI18n[s]) || s; }
	function albaF(s) { var a = Array.prototype.slice.call(arguments, 1), i = 0; return albaT(s).replace(/%(\d+\$)?s/g, function (m, p) { var k = p ? parseInt(p, 10) - 1 : i++; return a[k]; }); }

	var cfg = window.albaVis;
	if (!cfg || !cfg.langs || !wp || !wp.hooks || !wp.compose || !wp.element || !wp.blockEditor || !wp.components) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var CheckboxControl = wp.components.CheckboxControl;

	wp.hooks.addFilter('blocks.registerBlockType', 'alba/visibility-attr', function (settings) {
		settings.attributes = Object.assign({}, settings.attributes, {
			albaLangs: { type: 'array', items: { type: 'string' }, default: [] }
		});
		return settings;
	});

	var withPanel = wp.compose.createHigherOrderComponent(function (BlockEdit) {
		return function (props) {
			if (!props.isSelected) {
				return el(BlockEdit, props);
			}
			var sel = Array.isArray(props.attributes.albaLangs) ? props.attributes.albaLangs : [];
			var boxes = cfg.langs.map(function (l) {
				return el(CheckboxControl, {
					key: l.code,
					label: l.name,
					checked: sel.indexOf(l.code) !== -1,
					onChange: function (on) {
						var next = sel.filter(function (c) { return c !== l.code; });
						if (on) {
							next.push(l.code);
						}
						props.setAttributes({ albaLangs: next });
					}
				});
			});
			return el(Fragment, null,
				el(BlockEdit, props),
				el(InspectorControls, null,
					el(PanelBody, { title: albaT('Languages'), initialOpen: sel.length > 0 },
						el('p', { style: { marginTop: 0, color: '#646970' } }, albaT('Show block only in these languages. Nothing selected: in all languages.')),
						boxes
					)
				)
			);
		};
	}, 'withAlbaLangPanel');

	wp.hooks.addFilter('editor.BlockEdit', 'alba/visibility-ui', withPanel);
})(window.wp);
