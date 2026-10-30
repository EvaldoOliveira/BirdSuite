// Precision of the reviews (owner 2026-10-22, stratified 2026-10-27): the shared method of the species page (Precision)
// and of Validation (Calibrate thresholds, owner 2026-10-28), so both suggest the same threshold.
// rows = [confidence, probability of occurrence, right 1 / false 0] per reviewed detection; count(lo, hi) = the
// detections of the species in [lo, hi), the weight of each band.
(function () {
  var MIN_N = 20, COVER = 0.8;
  function wilson(k, n) { if (!n) return 0; var z = 1.96, p = k / n, d = 1 + z * z / n; return Math.max(0, (p + z * z / (2 * n) - z * Math.sqrt(p * (1 - p) / n + z * z / (4 * n * n))) / d); }
  // the confidence thresholds of the table: 10 % to 95 % by 5 %
  function confSteps() { var cs = []; for (var v = 0.10; v < 0.96; v += 0.05) cs.push(Math.round(v * 100) / 100); return cs; }
  // detections per 5 % confidence band (21 bands) as a count(lo, hi)
  function countConf(nConf) { return function (lo, hi) { var s = 0; nConf.forEach(function (n, b) { var v = b / 20 + 1e-9; if (v >= lo && v < hi) s += n; }); return s; }; }
  // the precision above each threshold as a stratified estimate: every band between two thresholds counts with its
  // share of the detections, not of the reviews; lb = its 95 % lower bound (Guaranteed); share = Covered; lost = the
  // real detections the threshold would leave out. best = the lowest threshold with at least 20 reviews above it, 80 %
  // covered and the precision at the target
  function curve(rows, idx, steps, count, target) {
    var edges = (steps[0] > 0 ? [0] : []).concat(steps), strata = [];
    edges.forEach(function (lo, i) {
      var hi = i + 1 < edges.length ? edges[i + 1] : 1.01, y = 0, f = 0;
      rows.forEach(function (r) { var v = r[idx]; if (v !== null && v >= lo && v < hi) { if (r[2]) y++; else f++; } });
      strata.push({lo: lo, N: count(lo, hi), y: y, f: f});
    });
    var best = null, bestLb = null, lines = [], any = rows.some(function (r) { return r[idx] !== null; });
    steps.forEach(function (th) {
      var y = 0, f = 0, all = 0, cov = 0, sum = 0, v = 0, lost = 0;
      strata.forEach(function (s) {
        var n = s.y + s.f;
        if (s.lo >= th - 1e-9) { y += s.y; f += s.f; all += s.N; if (n && s.N) { cov += s.N; sum += s.N * s.y / n; } }
        else if (n) lost += s.N * s.y / n;
      });
      strata.forEach(function (s) {
        var n = s.y + s.f, pt, w;
        if (s.lo >= th - 1e-9 && n && s.N) { pt = (s.y + 1) / (n + 2); w = s.N / cov; v += w * w * pt * (1 - pt) / n; }
      });
      var n = y + f, prec = null, lb = null, share = all ? cov / all : 0;
      if (cov) { prec = sum / cov; lb = Math.max(0, prec - 1.96 * Math.sqrt(v)); }
      else if (n) { prec = y / n; lb = wilson(y, n); share = 1; }  // no detection left in the database: the reviews alone
      var ok = n >= MIN_N && share >= COVER && prec !== null && prec >= target;
      if (ok && best === null) { best = th; bestLb = lb; }
      lines.push({th: th, y: y, f: f, all: all, prec: prec, lb: lb, share: share, lost: lost});
    });
    return {best: best, lb: bestLb, lines: lines, none: !any};
  }
  // Wood & Kahl (2024): logistic regression of right / false on the confidence; a small ridge keeps it finite when the
  // answers separate
  function logistic(rows) {
    var d = rows.filter(function (r) { return r[0] !== null; }), right = d.filter(function (r) { return r[2]; }).length, a = 0, b = 0;
    if (d.length < MIN_N || !right || right === d.length) return null;
    for (var it = 0; it < 50; it++) {
      var g0 = 0, g1 = -0.01 * b, h00 = 0, h01 = 0, h11 = 0.01;
      d.forEach(function (r) { var x = r[0], p = 1 / (1 + Math.exp(-(a + b * x))), w = p * (1 - p); g0 += r[2] - p; g1 += (r[2] - p) * x; h00 += w; h01 += w * x; h11 += w * x * x; });
      var det = h00 * h11 - h01 * h01, da, db;
      if (Math.abs(det) < 1e-12) break;
      da = (h11 * g0 - h01 * g1) / det; db = (h00 * g1 - h01 * g0) / det;
      a += da; b += db;
      if (Math.abs(da) + Math.abs(db) < 1e-8) break;
    }
    return {a: a, b: b};
  }
  // the confidence where one detection is right with the target chance: {t} or {why: 'few' | 'flat' | 'none'}
  function logisticThreshold(rows, target) {
    var m = logistic(rows), t;
    if (!m) return {why: 'few'};
    if (m.b <= 0) return {why: 'flat'};
    t = Math.ceil(100 * (Math.log(target / (1 - target)) - m.a) / m.b) / 100;
    if (t > 0.99) return {why: 'none'};
    return {t: Math.max(0.01, t)};
  }
  window.bsPrecision = {MIN_N: MIN_N, COVER: COVER, wilson: wilson, confSteps: confSteps, countConf: countConf, curve: curve, logistic: logistic, logisticThreshold: logisticThreshold};
})();
