// Standard tables (owner 2026-10-09): every <table class="stdtable"> gets the same look (CSS .stdtable) and, unless it
// has data-nosort or its own sorting, sorting by a click on a column title (numbers, percentages, dates and text, accents
// ignored). Header row = the first row whose cells are <th>; it stays at the top while the page scrolls (CSS). The
// chosen sorting is remembered in this browser per page and table (or the container of a list that reloads itself).
(function () {
  // loaded on every page by common.php ui_js (2026-11-01) and still by some pages themselves: the first copy runs
  if (window.stdTables) return;
  // bsReorder(parent, rows, before): puts rows (the new order, all children of parent) in that order right before the
  // node before (null = at the end), moving only the rows out of place: the longest run already in order stays where it is
  // (release run 4, PERF-43 / PERF-57: every moved row is built again by the browser, inputs and pictures included, so a
  // sort that moved all 200 rows of Species Cards took 250-400 ms; now a near order costs a fraction)
  // a long table keeps its column widths while its rows move (release run 5, PERF-57: the layout of 200 rows with their
  // pickers was most of a sort; with the widths fixed the browser does not measure every cell again). Fixed at the first
  // reorder from the widths on screen, released when the window changes size (the next reorder fixes them again).
  // A frozen table is released again as soon as a text inside it changes (release run 5, I18N-17: a label made longer
  // after the freeze, another language or the name shown, was cut in its fixed column instead of the table growing and
  // scrolling in its box); rows moved or added by a sort or a filter keep the freeze.
  function release(t) {
    if (t._bsFreezeObs) { t._bsFreezeObs.disconnect(); t._bsFreezeObs = null; }
    var head = t.tHead ? t.tHead.rows[0] : t.rows[0];
    if (head) Array.prototype.forEach.call(head.cells, function (c) { c.style.width = ''; });
    t.style.width = ''; t.style.tableLayout = ''; delete t.dataset.frozen;
  }
  function freeze(table) {
    if (!table || table.rows.length < 100 || table.dataset.frozen) return;
    var head = table.tHead ? table.tHead.rows[0] : table.rows[0];
    if (!head) return;
    var w = Array.prototype.map.call(head.cells, function (c) { return c.getBoundingClientRect().width; });
    Array.prototype.forEach.call(head.cells, function (c, i) { c.style.width = w[i] + 'px'; });
    table.style.width = table.getBoundingClientRect().width + 'px';
    table.style.tableLayout = 'fixed';
    table.dataset.frozen = '1';
    if (window.MutationObserver) {
      table._bsFreezeObs = new MutationObserver(function (list) {
        for (var i = 0; i < list.length; i++) {
          var m = list[i], tg = m.target;
          // rows moved, added or removed: the change is on the table or on one of its row groups
          if (m.type === 'childList' && (tg === table || (tg.parentNode === table && /^(TBODY|THEAD|TFOOT)$/.test(tg.nodeName)))) continue;
          release(table);
          return;
        }
      });
      table._bsFreezeObs.observe(table, {subtree: true, childList: true, characterData: true});
    }
  }
  window.addEventListener('resize', function () {
    document.querySelectorAll('table[data-frozen]').forEach(release);
  });
  window.bsReorder = function (parent, rows, before) {
    freeze(parent.closest ? parent.closest('table') : null);
    var pos = new Map();
    Array.prototype.forEach.call(parent.children, function (c, i) { pos.set(c, i); });
    var seq = rows.map(function (r) { return pos.has(r) ? pos.get(r) : -1; });
    // the longest increasing run of current positions (patience sorting), kept in place
    var tails = [], tailIdx = [], prev = new Array(seq.length);
    seq.forEach(function (v, i) {
      if (v < 0) return;
      var lo = 0, hi = tails.length;
      while (lo < hi) { var mid = (lo + hi) >> 1; if (tails[mid] < v) lo = mid + 1; else hi = mid; }
      tails[lo] = v; tailIdx[lo] = i; prev[i] = lo > 0 ? tailIdx[lo - 1] : -1;
    });
    var keep = new Set();
    for (var k = tailIdx.length ? tailIdx[tailIdx.length - 1] : -1; k >= 0; k = prev[k]) keep.add(k);
    var next = before || null;
    for (var i = rows.length - 1; i >= 0; i--) {
      if (!keep.has(i)) parent.insertBefore(rows[i], next);
      next = rows[i];
    }
  };
  function key(cell) {
    var v = cell.dataset.sort !== undefined ? cell.dataset.sort : cell.textContent.trim();
    var n = v.replace(/[%,\s×]/g, '').replace(/^-$/, '');
    if (n !== '' && !isNaN(n)) return {n: parseFloat(n)};
    return {s: v.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')};
  }
  function setup(t) {
    if (t.dataset.nosort !== undefined || t.dataset.sortReady) return;
    t.dataset.sortReady = '1';
    var rows = Array.prototype.slice.call(t.rows);
    var hi = rows.findIndex(function (r) { return r.cells.length && r.cells[0].tagName === 'TH'; });
    if (hi < 0) return;
    var head = rows[hi];
    var holder = t.closest('[id]'), id = t.id || (holder ? holder.id : '');
    var view = (location.search.match(/[?&]view=([^&]*)/) || [])[1] || location.pathname;
    var mem = id ? 'stdsort:' + decodeURIComponent(view) + ':' + id : '';
    function load() { try { return JSON.parse(localStorage.getItem(mem) || 'null'); } catch (e) { return null; } }
    Array.prototype.forEach.call(head.cells, function (th, ci) {
      if (th.dataset.nosort !== undefined || !th.textContent.trim()) return;
      th.classList.add('sortable');
      // first click (2026-10-23 test run): numbers, percentages and dates the largest first, text A to Z; then reversed
      var asc = null;
      th.addEventListener('click', function (e) {
        if (e.target.closest('select,input,button,a')) return;
        if (asc === null) {
          var first = Array.prototype.slice.call(t.rows, hi + 1).find(function (r) { return !r.classList.contains('nosortrow') && r.cells[ci]; });
          var k = first ? key(first.cells[ci]) : {s: ''};
          asc = k.n === undefined && !/^(\d{4}-\d\d-\d\d|\d\d?:\d\d)/.test(k.s);
        } else {
          asc = !asc;
        }
        if (e.restore !== undefined) asc = e.restore;
        if (mem) { try { localStorage.setItem(mem, JSON.stringify({ci: ci, asc: asc, h: th.textContent.trim()})); } catch (err) {} }
        Array.prototype.forEach.call(head.cells, function (o) { o.classList.remove('asc', 'desc'); });
        th.classList.add(asc ? 'asc' : 'desc');
        var body = Array.prototype.slice.call(t.rows, hi + 1).filter(function (r) { return !r.classList.contains('nosortrow'); });
        var tail = Array.prototype.slice.call(t.rows, hi + 1).filter(function (r) { return r.classList.contains('nosortrow'); });
        // each row's key read once, one collator, and the rows put back in one move (2026-10-23 test run: a click on the
        // 126-row species table took 250-450 ms to the next paint); the same order as before
        var coll = new Intl.Collator();
        var keyed = body.map(function (r, i) { return {r: r, k: key(r.cells[ci] || r.cells[0]), i: i}; });
        keyed.sort(function (a, b) {
          var x = a.k, y = b.k, c;
          if (x.n !== undefined && y.n !== undefined) c = x.n - y.n;
          else if (x.n !== undefined) c = -1; else if (y.n !== undefined) c = 1;
          else c = coll.compare(x.s, y.s);
          return (asc ? c : -c) || a.i - b.i;
        });
        // only the rows out of place move (bsReorder), the rows that stay out of the sort follow at the end
        var parent = head.parentNode;
        window.bsReorder(parent, keyed.map(function (o) { return o.r; }), null);
        tail.forEach(function (r) { parent.appendChild(r); });
      });
    });
    var saved = mem ? load() : null;
    // the saved column is found by its header text: the same table can come back with other columns (Now Simple / Full)
    var sci = saved ? (saved.h ? Array.prototype.findIndex.call(head.cells, function (o) { return o.textContent.trim() === saved.h; }) : saved.ci) : -1;
    if (sci >= 0 && head.cells[sci]) {
      var ev = new MouseEvent('click'); ev.restore = saved.asc;
      head.cells[sci].dispatchEvent(ev);
    }
  }
  window.stdTables = function (root) { (root || document).querySelectorAll('table.stdtable').forEach(setup); };
  // a table a page fills later (Stations, Status, Data jobs) sorts too
  function watch() {
    stdTables();
    var busy = false;
    new MutationObserver(function () {
      if (busy) return;
      busy = true;
      setTimeout(function () { busy = false; stdTables(); }, 200);
    }).observe(document.body, {childList: true, subtree: true});
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', watch); else watch();
})();
