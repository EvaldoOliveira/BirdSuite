// System › Disk Space, Disk size needed (US-133, owner 2026-11-01): the measured column and the two simulated columns
// run the same calculation as scripts/disk_forecast.py simulate(), on state/disk-forecast.json (window.DISK_FC).
// Inputs: limit of clips per species (cap), species identified (species), clips kept a day per species (rate),
// detections a day (det_day), average clip in KB (clip_kb), database size today in GB (db_gb), horizon in years (years).
(function () {
  var f = window.DISK_FC;
  if (!f) return;
  var KEYS = ['cap', 'species', 'rate', 'det_day', 'clip_kb', 'db_gb', 'threshold', 'years'];
  function simulate(v) {
    var seen = f.species_seen || 1, rate0 = f.measured.rate || 1, factor = v.rate / rate0;
    var clips = 0, capped = 0;
    f.sp.forEach(function (s) {
      // a simulated limit replaces every species' own; the measured one keeps them
      var c = v.cap !== f.measured.cap ? v.cap : s[2];
      var p = s[1] * factor * 365 * v.years;
      if (c > 0 && p >= c) { p = c; capped++; }
      clips += Math.max(p, s[0]);
    });
    clips *= v.species / seen;
    var clipsGb = clips * v.clip_kb / 1e6;
    var dbGb = v.db_gb + v.det_day * 365 * v.years * f.det_bytes / 1e9;
    var swingGb = f.swing_gb * (f.measured.det_day ? v.det_day / f.measured.det_day : 1);
    var needed = f.rest_gb + dbGb + clipsGb + swingGb;
    var thr = v.threshold !== undefined ? v.threshold : f.threshold;
    var limit = thr > 0 && thr < 100 ? thr / 100 : 1;
    var rec = f.sizes_gb[f.sizes_gb.length - 1];
    for (var i = 0; i < f.sizes_gb.length; i++) if (needed <= f.sizes_gb[i] * f.usable * limit) { rec = f.sizes_gb[i]; break; }
    // on this disk the purge ceiling holds (owner 2026-11-01): above it the purge removes the oldest clips, so what
    // the disk carries at the peak is the need, cut to the ceiling as far as clips can be removed
    var ceiling = f.disk_gb * limit, trim = Math.min(Math.max(0, needed - ceiling), clipsGb);
    var used = needed - trim;
    return {ceiling: ceiling, trim: trim, used: used, stationRate: v.rate * v.species, clips: clips, capped: capped, clipsGb: clipsGb, dbGb: dbGb,
      swingGb: swingGb, needed: needed, free: f.disk_gb - used, freePct: 100 * (f.disk_gb - used) / f.disk_gb, rec: rec};
  }
  var n = function (v, d) { return window.fmtNum ? window.fmtNum(v, d || 0) : v.toFixed(d || 0); };
  var gb = function (v) { return n(v, 1) + ' GB'; };
  function show(col, r) {
    var set = function (k, txt) { var el = document.querySelector('[data-res="' + k + '"][data-col="' + col + '"]'); if (el) el.textContent = txt; };
    set('stationRate', n(r.stationRate));
    set('ceiling', gb(r.ceiling));
    set('fits', r.trim <= 0 ? t('str_yes_lc') : t('str_no_the_purge_removes_of_the_help', {gb: gb(r.trim)}));
    set('used', gb(r.used));
    set('clips', n(r.clips));
    set('capped', n(r.capped));
    set('clipsGb', gb(r.clipsGb));
    set('dbGb', gb(r.dbGb));
    set('rest', gb(f.rest_gb));
    set('swing', gb(r.swingGb));
    set('needed', gb(r.needed));
    set('free', r.free >= 0 ? gb(r.free) + ' (' + n(r.freePct) + '%)' : t('str_does_not_fit_short', {gb: gb(-r.free)}));
    set('rec', n(r.rec) + ' GB');
  }
  function read(col) {
    var v = {};
    KEYS.forEach(function (k) {
      var el = document.querySelector('input[data-sim="' + k + '"][data-col="' + col + '"]');
      var x = el ? parseFloat(String(el.value).replace(',', '.')) : NaN;
      v[k] = isFinite(x) && x >= 0 ? x : f.measured[k];
    });
    return v;
  }
  show('m', simulate(f.measured));
  ['a', 'b'].forEach(function (col) {
    var run = function () { show(col, simulate(read(col))); };
    document.querySelectorAll('input[data-col="' + col + '"]').forEach(function (el) { el.addEventListener('input', run); });
    run();
  });
})();
