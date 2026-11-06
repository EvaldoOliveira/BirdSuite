//##############################################################################
// homepage/static/name-mode.js - NAME PICKLIST OF THE SPECIES TABLES
//
// PURPOSE:
//   Name picklist of the species tables (Dashboard, Seasonality, detection lists — owner 2026-10-09): Common /
//   Scientific / English. A <select class="namemode"> in a header switches every <a data-com data-sci data-en>
//   of the page (and every <option data-en> of the species lists, owner 2026-11-04: an option keeps its common
//   name as its first text and its scientific name as its value, so the 9,800 options of a list page carry no
//   copy of them - run 5, the page had grown 40 %); the choice is remembered in this browser. applyNameMode()
//   again after a list is loaded. A page whose picklist has data-remember="0" (Species Pages, owner
//   2026-10-20) opens on its data-default (else Common) and a change there is not remembered.
//
// DEPENDENCIES:
//   - (none beyond the system)
//
// USAGE:
//   <script src="static/name-mode.js">, loaded by scripts/curation.php, scripts/detections_dashboard.php,
//   scripts/ebird_page.php, scripts/models.php, scripts/overview.php, scripts/seasonality.php,
//   scripts/species_list.php; named in homepage/static/curation.js, homepage/static/overview.js,
//   homepage/static/species-tools.js
//##############################################################################

(function () {
	var fixed = document.querySelector('select.namemode[data-remember="0"]'), pageMode = null;
	function mode() {
		if (fixed) return pageMode || fixed.dataset.default || 'com';
		try { return localStorage.getItem('name_mode') || 'com'; } catch (e) { return 'com'; }
	}
	function apply(m) {
		document.querySelectorAll('a[data-com], option[data-en]').forEach(function (a) {
			var com = a.dataset.com !== undefined ? a.dataset.com : (a._com !== undefined ? a._com : (a._com = a.textContent));
			var sci = a.dataset.sci || a.value || '';
			a.textContent = m === 'sci' ? sci : (m === 'en' ? (a.dataset.en || com) : com);
			if (a.tagName === 'A') a.style.fontStyle = m === 'sci' ? 'italic' : '';
		});
		document.querySelectorAll('select.namemode').forEach(function (s) {
			s.value = m;
			if (!s.dataset.bound) {
				s.dataset.bound = '1';
				s.addEventListener('change', function () {
					if (fixed) pageMode = s.value; else { try { localStorage.setItem('name_mode', s.value); } catch (e) {} }
					apply(s.value);
				});
			}
		});
	}
	window.applyNameMode = function () { apply(mode()); };
	apply(mode());
})();
