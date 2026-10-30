// Standard tables (owner 2026-10-09): every <table class="stdtable"> gets the same look (CSS .stdtable) and, unless it
// has data-nosort or its own sorting, sorting by a click on a column title (numbers, percentages, dates and text, accents
// ignored). Header row = the first row whose cells are <th>; it stays at the top while the page scrolls (CSS). The
// chosen sorting is remembered in this browser per page and table (or the container of a list that reloads itself).
(function () {
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
        var parent = head.parentNode, frag = document.createDocumentFragment();
        keyed.forEach(function (o) { frag.appendChild(o.r); });
        tail.forEach(function (r) { frag.appendChild(r); });
        parent.appendChild(frag);
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
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { stdTables(); }); else stdTables();
})();
