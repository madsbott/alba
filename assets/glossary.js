(function () {
	var rows = document.getElementById('mml-gl-rows');
	var tpl = document.getElementById('mml-gl-tpl');
	if (!rows || !tpl) {
		return;
	}
	var next = rows.querySelectorAll('.mml-gl-row').length + 100;

	rows.addEventListener('change', function (e) {
		if (e.target.classList.contains('mml-gl-mode')) {
			e.target.closest('.mml-gl-row').classList.toggle('is-keep', e.target.value === 'keep');
		}
	});
	rows.addEventListener('click', function (e) {
		if (e.target.classList.contains('mml-gl-del')) {
			e.target.closest('.mml-gl-row').remove();
		}
	});
	document.getElementById('mml-gl-add').addEventListener('click', function () {
		var html = tpl.innerHTML.replace(/__i__/g, String(next++));
		var d = document.createElement('div');
		d.innerHTML = html;
		var row = d.firstElementChild;
		rows.appendChild(row);
		row.querySelector('input[type=text]').focus();
	});
})();
