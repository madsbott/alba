(function () {
	var rows = document.getElementById('alba-gl-rows');
	var tpl = document.getElementById('alba-gl-tpl');
	if (!rows || !tpl) {
		return;
	}
	var next = rows.querySelectorAll('.alba-gl-row').length + 100;

	rows.addEventListener('change', function (e) {
		if (e.target.classList.contains('alba-gl-mode')) {
			e.target.closest('.alba-gl-row').classList.toggle('is-keep', e.target.value === 'keep');
		}
	});
	rows.addEventListener('click', function (e) {
		if (e.target.classList.contains('alba-gl-del')) {
			e.target.closest('.alba-gl-row').remove();
		}
	});
	document.getElementById('alba-gl-add').addEventListener('click', function () {
		var html = tpl.innerHTML.replace(/__i__/g, String(next++));
		var d = document.createElement('div');
		d.innerHTML = html;
		var row = d.firstElementChild;
		rows.appendChild(row);
		row.querySelector('input[type=text]').focus();
	});
})();
