/**
 * Alba – Medien pro Sprache: Bildauswahl und Übersetzungsvorschlag in der Mediathek.
 */
(function ($) {
	'use strict';
	function albaT(s) { return (window.albaI18n && window.albaI18n[s]) || s; }
	function albaF(s) { var a = Array.prototype.slice.call(arguments, 1), i = 0; return albaT(s).replace(/%(\d+\$)?s/g, function (m, p) { var k = p ? parseInt(p, 10) - 1 : i++; return a[k]; }); }
	var cfg = window.albaMedia || {};

	$(document).on('click', '.alba-media-pick', function (e) {
		e.preventDefault();
		var box = $(this).closest('.alba-media-file');
		if (!window.wp || !wp.media) { return; }
		var frame = wp.media({ title: cfg.choose || albaT('Choose image'), library: { type: 'image' }, multiple: false, button: { text: cfg.choose || albaT('Choose image') } });
		frame.on('select', function () {
			var a = frame.state().get('selection').first().toJSON();
			var url = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
			box.find('input[data-k="file"]').val(a.id).trigger('change');
			box.find('.alba-media-thumb').html('<img src="' + url + '" style="width:60px;height:60px;object-fit:cover;vertical-align:middle">');
			box.find('.alba-media-clear').show();
		});
		frame.open();
	});

	$(document).on('click', '.alba-media-clear', function (e) {
		e.preventDefault();
		var box = $(this).closest('.alba-media-file');
		box.find('input[data-k="file"]').val('').trigger('change');
		box.find('.alba-media-thumb').empty();
		$(this).hide();
	});

	$(document).on('click', '.alba-media-mt', function (e) {
		e.preventDefault();
		var btn = $(this), wrap = btn.closest('.alba-media'), lang = wrap.data('lang'), msg = wrap.find('.alba-media-msg');
		var fields = [], texts = [];
		wrap.find('[data-k]').each(function () {
			var f = $(this), k = f.data('k');
			if (k === 'file') { return; }
			var orig = String(f.attr('data-orig') || '');
			if (orig.trim() !== '' && !String(f.val()).trim()) { fields.push(f); texts.push(orig); }
		});
		if (!texts.length) { msg.text(albaT('Nothing to translate.')); return; }
		btn.prop('disabled', true); msg.text('…');
		$.post(cfg.ajax, { action: 'alba_mt', nonce: cfg.nonce, from: cfg.from, to: lang, texts: JSON.stringify(texts) }).done(function (r) {
			if (r && r.success && r.data && r.data.texts) {
				r.data.texts.forEach(function (t, i) {
					var d = document.createElement('div'); d.innerHTML = t;
					fields[i].val(d.textContent || t).trigger('change');
				});
				msg.text(albaT('Suggestions inserted, please check and save.'));
			} else {
				msg.text(r && r.data ? String(r.data) : albaT('Error.'));
			}
		}).fail(function () { msg.text(albaT('Network error.')); }).always(function () { btn.prop('disabled', false); });
	});
})(jQuery);
