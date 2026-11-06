//##############################################################################
// homepage/static/species-tools.js - THE SPECIES LISTS: THE SWITCHES, THE SEARCH AND ITS PERSISTENCE
//
// PURPOSE:
//   The script of the species lists pages (scripts/species_tools.php): the list switches through the shared
//   modal, the search kept per browser.
//
// DEPENDENCIES:
//   - static/species-sort.js, static/std-table.js, static/name-mode.js, static/species-modal.js
//
// USAGE:
//   <script src="static/species-tools.js"> in scripts/species_tools.php
//##############################################################################

const scriptsBase = 'scripts/';
const get = (url) => fetch(url, {cache:'no-store'}).then(r => r.text());
const post = (url) => fetch(url, {method:'POST', cache:'no-store'}).then(r => r.text());

//##############################################################################
// toggles / delete
//##############################################################################
// saved: a green outline; refused (not logged in, invalid): red, the old value back and the reason in the tooltip, like the
// Manual Conf. field (2026-10-23 test run: the picker showed nothing either way)
function setTier(species, sel) {
	post(scriptsBase + 'species_tools.php?settier=1&species=' + encodeURIComponent(species) + '&tier=' + encodeURIComponent(sel.value))
		.then(t => {
			const ok = t.trim() === 'OK';
			if (ok) sel.dataset.was = sel.value; else if (sel.dataset.was) sel.value = sel.dataset.was;
			sel.style.outline = ok ? '2px solid green' : '2px solid red';
			sel.title = ok ? '' : t.trim();
			setTimeout(() => { sel.style.outline = ''; }, ok ? 1500 : 4000);
		})
		.catch(() => { sel.style.outline = '2px solid red'; if (sel.dataset.was) sel.value = sel.dataset.was; });
}
function setConf(species, input) {
	const value = input.value.trim();
	post(scriptsBase + 'species_tools.php?setconf=1&species=' + encodeURIComponent(species) + '&value=' + encodeURIComponent(value))
		.then(t => {
			const ok = t.trim() === 'OK';
			input.style.outline = ok ? '2px solid green' : '2px solid red';
			input.title = ok ? (value === '' ? window.t('str_global_value_in_use') : window.t('str_saved_value', {value: window.fmtNum(parseFloat(value), 2)})) : t.trim();
			if (ok) input.parentElement.dataset.sort = value === '' ? input.dataset.global : parseFloat(value).toFixed(2);
			setTimeout(() => { input.style.outline = ''; }, 1500);
		});
}
/* confirmation modal for the Confirmed / Whitelist / Exclude columns (owner 2026-10-08) */
// askModal() and SPECIES_TOGGLE_TEXT live in static/species-modal.js (shared with the species page)
function toggleSpecies(list, species, action) {
	const parts = species.split('_');   // "Scientific name_Common name"
	const name = parts.length > 1 ? parts.slice(1).join('_') + ' (' + parts[0] + ')' : species;
	const t = (SPECIES_TOGGLE_TEXT[list] || {})[action];
	const go = () => post(scriptsBase + 'species_tools.php?toggle=' + list + '&species=' + encodeURIComponent(species) + '&action=' + action)
		.then(r => { if (r.trim() === 'OK') location.reload(); });
	if (!t) { go(); return; }
	// whitelist and exclude change what is detected: No is the default there
	askModal(t[0].replace('NAME', name), t[1], window.t('str_yes'), list === 'whitelist' || list === 'exclude').then(ok => { if (ok) go(); });
}
// Remove a whole species (owner 2026-10-19: same as Curation's Remove all): clips and lines go to the Removed folder,
// deleted for good only in Species › Purge Files; No is the default
function deleteSpecies(species) {
	let parts = species.split(' + '); let sci_species = parts[0]; let com_species = parts[1];
	get(scriptsBase + 'species_tools.php?getcounts=' + encodeURIComponent(sci_species)).then(t => {
		let info; try { info = JSON.parse(t); } catch { bsNotice(window.t('str_could_not_parse_count_response')); return; }
		askModal(window.t('str_delete_all_detections_of', {n: window.fmtNum(Number(info.count)), name: com_species + ' (' + sci_species + ')'}),
			window.t('str_clips_and_spectrograms_go_to_help'),
			window.t('str_delete_all'), true).then(ok => {
			if (!ok) return;
			post('play.php?deletespecies=' + encodeURIComponent(sci_species)).then(t2 => {
				if (!/^OK \d+/.test(t2)) { bsNotice(t2); return; }
				location.reload();
			});
		});
	});
}


//##############################################################################
// Search with persistence
//##############################################################################
// the rows are filtered by the shared species filter (static/species-sort.js, input.spfilter, as on Validation); this
// block only keeps the text across reloads and shows the count, in its own script and scope so nothing above can stop
// it (2026-11-01: the filter did nothing on the owner's phone while Validation's worked)
(function () {
	var q = document.getElementById('q'), matchCount = document.getElementById('matchCount');
	if (!q) return;
	function count() {
		var rows = document.querySelectorAll('#speciesTable tbody tr'), shown = 0;
		rows.forEach(function (tr) { if (tr.style.display !== 'none') shown++; });
		if (matchCount) matchCount.textContent = rows.length ? shown + ' / ' + rows.length : '';
		try { localStorage.setItem('speciesFilter', q.value); } catch (e) {}
	}
	// after the shared listener (registered first, species-sort.js loads above): the rows are already filtered
	q.addEventListener('input', function () { setTimeout(count, 0); });
	function boot() {
		try { var saved = localStorage.getItem('speciesFilter'); if (saved !== null) q.value = saved; } catch (e) {}
		// a kept filter shows its line open, so a short list never hides why
		if (q.value.trim()) { var bar = q.closest('.sortbar'); if (bar) bar.classList.add('fopen'); }
		q.dispatchEvent(new Event('input'));
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
