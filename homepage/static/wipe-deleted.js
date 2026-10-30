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
    try { rows = JSON.parse(this.responseText); } catch (e) { alert(this.status === 401 ? t('Log in first.') : this.responseText); return; }
    if (!rows.length) { alert(t('Nothing to purge.')); return; }
    var total = rows.reduce(function (s, r) { return s + r.n; }, 0);
    var lines = rows.slice(0, 25).map(function (r) { return '- ' + r.Com_Name + ' (' + r.Sci_Name + '): ' + r.n; });
    if (rows.length > 25) lines.push(t('… and {n} more species', {n: rows.length - 25}));
    askModal(total === 1 ? t('Purge {n} deleted detection?', {n: total}) : t('Purge {n} deleted detections?', {n: total}),
      t('All files will be removed for good ({n} files):', {n: total * 2}) + '\n\n' + lines.join('\n') + '\n\n' + t('This cannot be undone.'),
      t('Purge'), true).then(function (ok) {
        if (!ok) return;
        var y = new XMLHttpRequest();
        y.onload = function () {
          var m = this.responseText.match(/^OK (\d+)/);
          if (!m) { alert(t('Not purged: {msg}', {msg: this.responseText})); return; }
          if (btn) { btn.textContent = t('Purged {n}', {n: m[1]}); btn.disabled = true; }
          setTimeout(function () { location.reload(); }, 800);
        };
        y.open('GET', base + '&confirm=1', true);
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
    if (!m) { alert(this.status === 401 ? t('Log in first.') : t('Not restored: {msg}', {msg: this.responseText})); return; }
    if (btn) { btn.textContent = t('Restored {n}', {n: m[1]}); btn.disabled = true; }
    setTimeout(function () { location.reload(); }, 800);
  };
  x.open('GET', 'play.php?restore=1&sci=' + encodeURIComponent(sci) + (loc ? '&loc=' + encodeURIComponent(loc) : ''), true);
  x.send();
}
