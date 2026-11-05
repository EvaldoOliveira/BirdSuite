// Delete the removed detections for good (Species › Purge Files and the species page, owner 2026-10-09): asks first, showing how
// many files of each species will be removed for good, with No as the default. Needs static/species-modal.js.
// sci = a scientific name, or '' for every species; loc = a location id ('none' = outside every location), or nothing
// for all of them (Purge Files shows one block per location, owner 2026-10-21).
// US-122: window.t comes from ui_js() in the page head; a page opened on its own without it shows the English text
if (typeof window.t !== 'function') window.t = function (s, v) { if (v) for (var k in v) s = s.split('{' + k + '}').join(v[k]); return s; };
function wipeDeleted(sci, btn, loc) {
  var base = (location.pathname.indexOf('/scripts/') >= 0 ? '../' : '') + 'play.php?wipe=1&sci=' + encodeURIComponent(sci)
    + (loc ? '&loc=' + encodeURIComponent(loc) : '');
  var x = new XMLHttpRequest();
  x.onload = function () {
    var rows = [];
    try { rows = JSON.parse(this.responseText); } catch (e) { bsNotice(this.status === 401 ? t('str_log_in_first') : this.responseText); return; }
    if (!rows.length) { bsNotice(t('str_nothing_to_purge_dot')); return; }
    var total = rows.reduce(function (s, r) { return s + r.n; }, 0);
    // the name is presentation only (play.php: com_name(Sci_Name) AS Com_Name), the scientific name when it is missing
    var lines = rows.slice(0, 25).map(function (r) { return '- ' + (r.Com_Name || r.Sci_Name) + ' (' + r.Sci_Name + '): ' + r.n; });
    if (rows.length > 25) lines.push(t('str_and_more_species', {n: rows.length - 25}));
    askModal(total === 1 ? t('str_purge_deleted_detection', {n: total}) : t('str_purge_deleted_detections', {n: total}),
      t('str_all_files_will_be_removed_for_help', {n: total * 2}) + '\n\n' + lines.join('\n') + '\n\n' + t('str_this_cannot_be_undone'),
      t('str_purge'), true).then(function (ok) {
        if (!ok) return;
        var y = new XMLHttpRequest();
        y.onload = function () {
          var m = this.responseText.match(/^OK (\d+)/);
          if (!m) { bsNotice(t('str_not_purged', {msg: this.responseText})); return; }
          if (btn) { btn.textContent = t('str_purged', {n: m[1]}); btn.disabled = true; }
          setTimeout(function () { location.reload(); }, 800);
        };
        y.open('POST', base + '&confirm=1', true);
        y.send();
      });
  };
  x.open('GET', base, true);
  x.send();
}

// Restore removed detections (one species, or '' for all; loc as for wipeDeleted): files back to By_Date, lines back to the detections
function restoreRemoved(sci, btn, loc) {
  var x = new XMLHttpRequest();
  x.onload = function () {
    var m = this.responseText.match(/^OK (\d+)/);
    if (!m) { bsNotice(this.status === 401 ? t('str_log_in_first') : t('str_not_restored', {msg: this.responseText})); return; }
    if (btn) { btn.textContent = t('str_restored', {n: m[1]}); btn.disabled = true; }
    setTimeout(function () { location.reload(); }, 800);
  };
  x.open('POST', 'play.php?restore=1&sci=' + encodeURIComponent(sci) + (loc ? '&loc=' + encodeURIComponent(loc) : ''), true);
  x.send();
}
