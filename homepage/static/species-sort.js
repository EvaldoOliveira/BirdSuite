// Species tables, one behaviour everywhere (owner 2026-10-10: By Hour, By Week, Species Pages, Curation):
//   <span class="hmsort" data-table="TABLE_ID" data-store="KEY" [data-default="prob"]><button data-sort="n|prob|tax|az">…</button>…</span>
//     Count = data-n (most first), Probability = data-prob (location model probability, least expected first; -1 =
//     not in the location model's profile, on top), Default (Curation, data-sort="cur") = rows with data-grp by group first,
//     then the row's (else the table's) data-order (attribute names, "-" = descending; default excl,conf,maxc), Taxonomy = data-tax (eBird/Clements field-guide order, common.php taxon_order()),
//     A–Z = the names shown (follows the Common / Scientific / English picklist). A click on the button already on
//     reverses its order (owner 2026-10-20: every button, as A–Z / Z–A did): key ":r" = reversed; the button shows the
//     direction with the arrows of every column sort (stdtable): ▲ ascending, ▼ descending (Count starts ▼, the rest ▲);
//     remembered per page in this browser, unless the group has data-remember="0" (it then opens on its default)
//   <input class="spfilter" data-table="TABLE_ID">  filters the rows by data-q (names, accents ignored)
//   data-table may name several tables, space-separated (Curation's three tables): each is sorted / filtered on its own;
//   window.speciesSortRefresh() re-applies the current order (after rows moved between tables)
// Rows carry data-n, data-tax and data-q; rows with class "chart" (totals) stay at the bottom.
(function () {
  function plain(s) { return (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }
  function rowsOf(t) { return Array.prototype.slice.call(t.querySelectorAll('tr[data-n]')); }
  function tablesOf(el) { return el.dataset.table.split(/\s+/).map(function (id) { return document.getElementById(id); }).filter(Boolean); }
  function sortTable(group, k) {
    if (k === 'za') k = 'az:r'; // the former Z-A key
    var base = k.split(':')[0], rev = /:r$/.test(k);
    tablesOf(group).forEach(function (t) { sortOne(t, base, rev); });
    group.querySelectorAll('button[data-sort]').forEach(function (b) {
      if (b.dataset.label === undefined) b.dataset.label = b.textContent;
      var on = b.dataset.sort === base;
      b.classList.toggle('on', on);
      // natural direction: Count descending, everything else ascending; reversed flips it
      var desc = (b.dataset.sort === 'n') !== rev;
      b.textContent = b.dataset.label + (on ? (desc ? ' \u25BC' : ' \u25B2') : '');
    });
    group.dataset.current = k;
    if (group.dataset.remember !== '0') { try { localStorage.setItem('spsort:' + group.dataset.store, k); } catch (e) {} }
  }
  function sortOne(t, k, rev) {
    // each row's name read once per sort (2026-10-23 test run: a sort click took 250-450 ms to the next paint)
    var names = new Map();
    var name = function (r) {
      var v = names.get(r);
      if (v === undefined) { var a = r.querySelector('a[data-com]'); v = plain(a ? a.textContent : ''); names.set(r, v); }
      return v;
    };
    var rows = rowsOf(t), order0 = (t.dataset.order || 'excl,conf,maxc').split(','), order = order0;
    rows.sort(function (a, b) {
      if (k === 'tax') return (parseFloat(a.dataset.tax) - parseFloat(b.dataset.tax)) || name(a).localeCompare(name(b));
      if (k === 'az') return name(a).localeCompare(name(b));
      if (k === 'za') return name(b).localeCompare(name(a));
      order = order0;
      if (k === 'cur') {
        // rows in groups (Curation): the group first, then the group's own order (row data-order)
        if (a.dataset.grp !== undefined && b.dataset.grp !== undefined) {
          var gc = parseFloat(a.dataset.grp) - parseFloat(b.dataset.grp);
          if (gc) return gc;
          if (a.dataset.order) order = a.dataset.order.split(',');
        }
        for (var i = 0; i < order.length; i++) {
          var key = order[i].replace(/^-/, ''), s = order[i].charAt(0) === '-' ? -1 : 1;
          var c = (parseFloat(a.dataset[key]) || 0) - (parseFloat(b.dataset[key]) || 0);
          if (c) return s * c;
        }
        return name(a).localeCompare(name(b));
      }
      if (k === 'prob') return (parseFloat(a.dataset.prob) - parseFloat(b.dataset.prob)) || name(a).localeCompare(name(b));
      return (parseFloat(b.dataset.n) - parseFloat(a.dataset.n)) || name(a).localeCompare(name(b));
    });
    if (!rows.length) return;
    if (rev) rows.reverse();
    var parent = rows[0].parentNode, chart = parent.querySelector('tr.chart');
    var frag = document.createDocumentFragment();
    rows.forEach(function (r) { frag.appendChild(r); });
    parent.insertBefore(frag, chart);  // the rows back in one move
    // a column sort of the standard table no longer applies
    t.querySelectorAll('th.asc, th.desc').forEach(function (th) { th.classList.remove('asc', 'desc'); });
  }
  function filterTable(input) {
    var q = plain(input.value).trim();
    tablesOf(input).forEach(function (t) {
      rowsOf(t).forEach(function (r) { r.style.display = !q || plain(r.dataset.q).indexOf(q) >= 0 ? '' : 'none'; });
    });
  }
  window.speciesSortRefresh = function () {
    document.querySelectorAll('.hmsort[data-table]').forEach(function (g) { if (g.dataset.current) sortTable(g, g.dataset.current); });
  };
  function init() {
    document.querySelectorAll('.hmsort[data-table]').forEach(function (g) {
      g.querySelectorAll('button[data-sort]').forEach(function (b) {
        b.addEventListener('click', function () {
          var k = b.dataset.sort, cur = g.dataset.current || '';
          // the button already on reverses its order
          if (cur.split(':')[0] === k) k = /:r$/.test(cur) ? k : k + ':r';
          sortTable(g, k);
        });
      });
      // data-default on the group: the page's own first order (Curation: prob), else Count
      var k = g.dataset.default || 'n';
      if (g.dataset.remember !== '0') { try { k = localStorage.getItem('spsort:' + g.dataset.store) || k; } catch (e) {} }
      sortTable(g, k);
    });
    document.querySelectorAll('input.spfilter[data-table]').forEach(function (i) { i.addEventListener('input', function () { filterTable(i); }); });
    document.querySelectorAll('select.namemode').forEach(function (s) {
      s.addEventListener('change', function () {
        setTimeout(function () {
          document.querySelectorAll('.hmsort[data-table]').forEach(function (g) { if (/^az/.test(g.dataset.current || '')) sortTable(g, g.dataset.current); });
        }, 0);
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
