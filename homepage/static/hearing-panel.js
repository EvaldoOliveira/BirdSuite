// The hearing window (owner 2026-11-04): the settings of the frequency lowering, the station's. The hearing aid is
// switched per device beside Live Audio in the header; while it is on, a gear at its left opens this window. Two scales: Real on the left with the band of the songs (its top = the highest song that matters, its bottom =
// a filter, the sounds below it are cut), You hear on the right, the same band lowered: drag it down or up and it
// shrinks or stretches with it (every frequency is multiplied by one ratio). Saved on every release (FREQSHIFT_HI,
// FREQSHIFT_LO, FREQSHIFT_BASE); the page says what to do
// next through onSaved (the header sends the change to the pages).
// Usage: bsHearing.open({parent: element, place: function (box) {...}, onSaved: function (state, say) {...}}),
// bsHearing.close(), bsHearing.isOpen(). The values come from scripts/hearing.php on every opening.
(function () {
  if (window.bsHearing) return;
  var NS = 'http://www.w3.org/2000/svg', FMAX = 12000, H = 280, TOP = 10, XL = 70, XR = 210, W = 40;
  var box = null, opts = null, pending = null, opener = null;
  var fmt = function (n, d) { return window.fmtNum ? window.fmtNum(n, d) : Number(n).toFixed(d || 0); };
  function style() {
    if (document.getElementById('bsHearingStyle')) return;
    var s = document.createElement('style');
    s.id = 'bsHearingStyle';
    s.textContent = '.hearcfg{position:fixed;z-index:1005;left:16px;top:70px;width:min(420px,calc(100vw - 32px));max-height:calc(100vh - 100px);overflow:auto;'
      + 'padding:10px 14px;border-radius:12px;background:var(--bg,#fff);color:var(--text,#000);box-shadow:0 4px 18px rgba(0,0,0,.35);text-align:left;font-size:14px}'
      + '.hearcfg .hearclose{position:absolute;top:4px;right:6px;border:0;background:transparent;font-size:22px;cursor:pointer;color:inherit;'
      + 'width:32px;height:32px;padding:0;line-height:1;display:flex;align-items:center;justify-content:center}'
      + '.hearcfg .hearscales{margin:6px auto 0;max-width:300px;touch-action:none}.hearcfg .hearscales svg{width:100%;height:auto;display:block;user-select:none}'
      + '.hearcfg .hearratio{text-align:center;font-weight:700;margin:4px 0;white-space:pre-line}.hearcfg .small{font-size:12px}.hearcfg select{width:auto}'
      + '.hearcfg .hearsetdef{width:auto}.hearcfg:focus{outline:none !important}';
    document.head.appendChild(s);
  }
  function close() {
    if (!box) return;
    clearTimeout(pending);
    // the focus goes back to what opened the window when it was inside it (release run 5, NEW-A11Y-R5-10)
    var back = opener, had = box.contains(document.activeElement);
    opener = null;
    box.remove(); box = null; opts = null;
    if (back && had && back.isConnected && typeof back.focus === 'function') back.focus();
    document.removeEventListener('keydown', esc, true);
    window.removeEventListener('resize', place);
  }
  // Esc closes this window only (the review player behind it stays open)
  function esc(e) { if (e.key === 'Escape' && box) { e.preventDefault(); e.stopPropagation(); close(); } }
  function place() { if (box && opts && opts.place) opts.place(box); }
  function post(body, done) {
    var x = new XMLHttpRequest();
    x.timeout = 15000;
    x.onload = function () { done(this.status === 200 && this.responseText.trim() === 'OK' ? '' : (this.status === 401 ? t('str_log_in_first') : this.responseText.trim() || String(this.status))); };
    x.onerror = x.ontimeout = function () { done(t('str_not_changed_network_error')); };
    x.open('POST', 'scripts/settings_set.php', true);
    x.setRequestHeader('Content-Type', 'application/json');
    x.send(JSON.stringify(body));
  }
  function build(st) {
    var hi = st.hi, r = st.r, lo = Math.min(st.base, hi - 500), def = st.def;
    box.innerHTML = '<button type="button" class="hearclose" aria-label="' + t('str_close') + '">&times;</button>'
      + '<b>&#129467; ' + t('str_hear_high_songs_lower') + '</b>'
      + '<div class="hearscales"><svg viewBox="0 0 300 300" role="img" aria-label="' + t('str_hear_high_songs_lower') + '"></svg></div>'
      + '<div class="hearratio"></div>'
      + '<div style="text-align:center"><button type="button" class="setdefault hearsetdef" style="display:none">&#8634; ' + t('str_defaults') + '</button></div>'
      + '<p class="small">' + t('str_hear_bands_help') + '</p>'
      + '<span class="hearmsg small"></span>';
    var svg = box.querySelector('svg'), msg = box.querySelector('.hearmsg'), defb = box.querySelector('.hearsetdef');
    var say = function (s) { if (msg) msg.textContent = s; };
    box.querySelector('.hearclose').onclick = close;
    var y = function (f) { return TOP + H - Math.max(0, Math.min(FMAX, f)) / FMAX * H; };
    var f = function (py) { return Math.round(Math.max(0, Math.min(FMAX, (TOP + H - py) / H * FMAX)) / 50) * 50; };
    var el = function (n, a) { var e = document.createElementNS(NS, n); for (var k in a) e.setAttribute(k, a[k]); return e; };
    var label = function (x, txt) { var e = el('text', {x: x + W / 2, y: TOP + H + 18, 'font-size': 12, 'text-anchor': 'middle', fill: 'currentColor', 'font-weight': 700}); e.textContent = txt; svg.appendChild(e); };
    function draw() {
      while (svg.firstChild) svg.removeChild(svg.firstChild);
      [XL, XR].forEach(function (x, i) {
        svg.appendChild(el('line', {x1: x + W / 2, y1: y(FMAX), x2: x + W / 2, y2: y(0), stroke: '#888', 'stroke-width': 1}));
        for (var k = 0; k <= 12; k += 2) {
          svg.appendChild(el('line', {x1: x + W / 2 - 4, y1: y(k * 1000), x2: x + W / 2 + 4, y2: y(k * 1000), stroke: '#888'}));
          var tx = el('text', {x: i ? x + W + 6 : x - 6, y: y(k * 1000) + 4, 'font-size': 11, 'text-anchor': i ? 'start' : 'end', fill: 'currentColor'}); tx.textContent = k + ' kHz'; svg.appendChild(tx);
        }
      });
      svg.appendChild(el('polygon', {points: [XL + W, y(hi), XR, y(hi * r), XR, y(lo * r), XL + W, y(lo)].join(' '), fill: 'rgba(43,94,34,.15)', stroke: 'none'}));
      svg.appendChild(el('rect', {x: XL, y: y(hi), width: W, height: y(lo) - y(hi), rx: 6, fill: '#2b5e22'}));
      svg.appendChild(el('rect', {x: XL - 4, y: y(hi) - 5, width: W + 8, height: 10, rx: 4, fill: '#173b12', 'data-h': 'hi', style: 'cursor:ns-resize'}));
      svg.appendChild(el('rect', {x: XL - 4, y: y(lo) - 5, width: W + 8, height: 10, rx: 4, fill: '#173b12', 'data-h': 'lo', style: 'cursor:ns-resize'}));
      svg.appendChild(el('rect', {x: XR, y: y(hi * r), width: W, height: y(lo * r) - y(hi * r), rx: 6, fill: '#d97a00', 'data-h': 'heard', style: 'cursor:grab'}));
      label(XL, t('str_real')); label(XR, t('str_you_hear'));
      svg.setAttribute('viewBox', '0 0 300 ' + (TOP + H + 26));
      defb.style.display = (hi !== def.hi || lo !== def.base || Math.abs(r - def.r) > 0.005) ? '' : 'none';
      // one line each (owner 2026-11-05): the ratio and semitones, the band, the cut
      box.querySelector('.hearratio').textContent = '×' + fmt(r, 2) + ' · ' + fmt(1200 * Math.log(r) / Math.LN2 / 100, 1) + ' ' + t('str_semitones') + '\n'
        + fmt(hi, 0) + ' Hz → ' + fmt(Math.round(hi * r), 0) + ' Hz\n' + (lo > 0 ? t('str_cut_below_hz', {hz: fmt(lo, 0)}) : t('str_nothing_cut'));
    }
    function save() {
      var me = box;
      say(t('str_saving'));
      post({FREQSHIFT_HI: String(hi), FREQSHIFT_LO: String(Math.round(hi * r)), FREQSHIFT_BASE: String(lo)}, function (err) {
        if (box !== me) return;
        if (err) { say(err); return; }
        say(t('str_applied'));
        if (opts && opts.onSaved) opts.onSaved({hi: hi, r: r, base: lo}, say);
      });
    }
    function svgY(e) { var b = svg.getBoundingClientRect(); return (e.clientY - b.top) / b.height * (TOP + H + 26); }
    var drag = null, grab = 0;
    svg.addEventListener('pointerdown', function (e) {
      var h = e.target.getAttribute && e.target.getAttribute('data-h'); if (!h) return;
      drag = h; grab = f(svgY(e)) - hi * r; svg.setPointerCapture(e.pointerId); e.preventDefault();
    });
    svg.addEventListener('pointermove', function (e) {
      if (!drag) return;
      var v = f(svgY(e));
      if (drag === 'hi') hi = Math.max(lo + 500, Math.min(FMAX, v));
      else if (drag === 'lo') lo = v < 100 ? 0 : Math.min(hi - 500, v);  // near the bottom = no cut
      else r = Math.min(1, Math.max(0.2, (v - grab) / hi));
      draw();
    });
    var release = function () {
      if (!drag) return;
      drag = null;
      // one save after the last move: each save restarts the live audio, a burst of restarts left it silent a while
      clearTimeout(pending);
      say(t('str_saving'));
      pending = setTimeout(save, 1200);
    };
    svg.addEventListener('pointerup', release);
    svg.addEventListener('pointercancel', release);
    defb.onclick = function () { hi = def.hi; r = def.r; lo = Math.min(def.base, hi - 500); draw(); save(); };
    draw();
    // the keyboard starts inside the window, on its close button (release run 5, NEW-A11Y-R5-10)
    box.querySelector('.hearclose').focus();
  }
  function open(o) {
    close();
    style();
    opts = o || {};
    opener = document.activeElement;
    box = document.createElement('div');
    box.className = 'hearcfg';
    box.tabIndex = -1;
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-label', t('str_settings_of_the_frequency_lowering'));
    box.innerHTML = '<p>' + t('str_loading') + '</p>';
    (opts.parent || document.body).appendChild(box);
    place();
    box.focus({preventScroll: true});
    document.addEventListener('keydown', esc, true);
    window.addEventListener('resize', place);
    var me = box, x = new XMLHttpRequest();
    x.timeout = 15000;
    x.onload = function () {
      if (box !== me) return;
      var st = null;
      try { st = this.status === 200 ? JSON.parse(this.responseText) : null; } catch (e) {}
      if (!st || !st.def) { box.innerHTML = '<p>' + t('str_not_loaded') + '</p>'; return; }
      build(st);
      place();
    };
    x.onerror = x.ontimeout = function () { if (box === me) box.innerHTML = '<p>' + t('str_not_loaded') + '</p>'; };
    x.open('GET', 'scripts/hearing.php', true);
    x.send();
  }
  window.bsHearing = {open: open, close: close, isOpen: function () { return !!box; }, toggle: function (o) { if (box) close(); else open(o); }};
})();
