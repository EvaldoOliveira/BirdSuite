// Consistent buttons (owner 2026-10-21): in a table, the buttons of one column (the first button of each cell, the
// second, ...) take the width of the widest, so a column of "Make current" / "Restore" / "Purge" lines up. Runs on every
// page (printed by common.php ui_js) and again when a table is filled later.
// the Sort icon (common.php sort_toggle): opens / folds the sort buttons of its bar
window.bsSortToggle = function (el) {
  var bar = el.closest('.sortbar');
  if (bar) bar.classList.toggle('sopen');
};
// the Filter icon (owner 2026-10-22): a filter line opens closed (open only with data-fdefault="1", Validation); the last state is kept per page in this
// browser (the icon's own onclick toggles the line, this only remembers it)
(function () {
  function key(i) {
    var v = (location.search.match(/[?&]view=([^&]*)/) || [])[1] || location.pathname;
    return 'filteropen:' + decodeURIComponent(v) + ':' + i;
  }
  function init() {
    document.querySelectorAll('.dashfilter').forEach(function (ic, i) {
      var bar = ic.closest('.curlegend, .sortbar, .bar, .top');
      if (!bar || ic.dataset.fmem) return;
      ic.dataset.fmem = '1';
      var st = null;
      try { st = localStorage.getItem(key(i)); } catch (e) {}
      // closed by default; a bar with data-fdefault="1" opens open (only Validation, owner 2026-10-22)
      // the station's default: data-fphone / data-fdesktop by the screen (owner 2026-10-25, Reports), else data-fdefault
      var def = bar.dataset.fdesktop !== undefined ? (window.innerWidth > 500 ? bar.dataset.fdesktop : bar.dataset.fphone) === '1' : bar.dataset.fdefault === '1';
      bar.classList.toggle('fopen', st === null ? def : st !== '0');
      ic.addEventListener('click', function () {
        setTimeout(function () { try { localStorage.setItem(key(i), bar.classList.contains('fopen') ? '1' : '0'); } catch (e) {} }, 0);
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
(function () {
  var busy = false;
  function equalize() {
    busy = false;
    document.querySelectorAll('table').forEach(function (tb) {
      var groups = {};
      tb.querySelectorAll('tr').forEach(function (tr) {
        Array.prototype.forEach.call(tr.cells, function (td, c) {
          var k = 0;
          Array.prototype.forEach.call(td.children, function (el) {
            if (el.tagName !== 'BUTTON' || el.offsetParent === null) return;
            (groups[c + ':' + k] = groups[c + ':' + k] || []).push(el);
            k++;
          });
        });
      });
      Object.keys(groups).forEach(function (key) {
        var list = groups[key];
        if (list.length < 2) return;
        list.forEach(function (b) { b.style.minWidth = ''; });
        var w = Math.max.apply(null, list.map(function (b) { return b.getBoundingClientRect().width; }));
        list.forEach(function (b) { b.style.minWidth = Math.ceil(w) + 'px'; });
      });
    });
  }
  function later() { if (!busy) { busy = true; setTimeout(equalize, 150); } }
  function start() {
    equalize();
    new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        var m = muts[i];
        if (m.target.nodeName === 'BUTTON' && m.attributeName === 'style') continue; // our own widths
        later();
        return;
      }
    }).observe(document.body, {childList: true, subtree: true});
    window.addEventListener('resize', later);
  }
  if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
})();
// Keyboard and names on every page (2026-10-23 test run, WCAG 2.2 AA), one place for all the pages and the old upstream
// forms: a click handler on something that is not a control (an icon picture, a chip, a span) makes it a button for
// the keyboard too (Tab stop, Enter and Space), unless it holds buttons or links of its own (a table row: those are its
// keyboard way, the click on the rest of the row is a shortcut); a picture without alt text takes its title; a
// <label for="x"> whose field only has name="x" gets its field; a field in a table cell without any label is named by
// its row and its column header. Runs when the page is ready and again when the page adds content.
(function () {
  var NATIVE = /^(A|BUTTON|INPUT|SELECT|TEXTAREA|SUMMARY|OPTION|LABEL|AUDIO|VIDEO|IFRAME|BODY|HTML|FORM)$/;
  var FIELDS = 'input:not([type=hidden]):not([type=submit]):not([type=button]):not([type=reset]):not([type=image]), select, textarea';
  var seq = 0;
  function txt(el) {
    if (!el) return '';
    var c = el.cloneNode(true);
    c.querySelectorAll('select, script, style, button, input').forEach(function (x) { x.remove(); });
    return c.textContent.replace(/\s+/g, ' ').trim().replace(/:$/, '');
  }
  function hasName(f) {
    return (f.labels && f.labels.length) || f.getAttribute('aria-label') || f.getAttribute('aria-labelledby') || f.title
      || (f.tagName !== 'SELECT' && f.placeholder);
  }
  // the header cell above a table cell (colspans counted)
  function headerOf(td) {
    var tr = td.parentNode, table = tr.closest('table');
    if (!table) return '';
    var head = (table.tHead && table.tHead.rows[0]) || table.rows[0];
    if (!head || head === tr) return '';
    var pos = 0, p = 0;
    for (var i = 0; i < td.cellIndex; i++) pos += tr.cells[i].colSpan || 1;
    for (var j = 0; j < head.cells.length; j++) {
      var h = head.cells[j];
      if (pos < p + (h.colSpan || 1)) return h.tagName === 'TH' ? (txt(h) || h.title || '') : '';
      p += h.colSpan || 1;
    }
    return '';
  }
  function scan() {
    document.querySelectorAll('[onclick], [data-kbd]').forEach(function (el) {
      if (NATIVE.test(el.tagName) || el.hasAttribute('tabindex') || el.dataset.kbdOk) return;
      if (el.querySelector('a[href], button, ' + FIELDS)) return;
      el.dataset.kbdOk = '1';
      el.setAttribute('tabindex', '0');
      if (!el.hasAttribute('role')) el.setAttribute('role', 'button');
      if (el.tagName !== 'IMG' && !el.getAttribute('aria-label') && !el.textContent.trim() && el.title) el.setAttribute('aria-label', el.title);
    });
    document.querySelectorAll('img:not([alt])').forEach(function (img) {
      img.setAttribute('alt', img.title || img.getAttribute('aria-label') || '');
    });
    document.querySelectorAll('label[for]').forEach(function (l) {
      var id = l.htmlFor;
      if (!id || document.getElementById(id) || !window.CSS || !CSS.escape) return;
      var f = (l.closest('form') || l.parentNode).querySelector('[name="' + CSS.escape(id) + '"]');
      if (f && !f.id && f.matches(FIELDS)) f.id = id;
    });
    document.querySelectorAll(FIELDS).forEach(function (f) {
      if (hasName(f)) return;
      // a label written just before the field without for="" (old forms)
      var prev = f.previousElementSibling;
      if (prev && prev.tagName === 'LABEL' && !prev.htmlFor && !prev.querySelector(FIELDS)) {
        if (!f.id) f.id = 'bsf' + (++seq);
        prev.htmlFor = f.id;
        return;
      }
      // the hidden file field of an upload widget (plupload) answers its own button
      if (f.type === 'file') { f.setAttribute('aria-label', window.t ? window.t('Choose a file') : 'Choose a file'); return; }
      var td = f.closest('td');
      if (!td) return;
      var tr = td.parentNode, first = tr.cells[0];
      var row = first && first !== td && !first.querySelector(FIELDS) ? txt(first).slice(0, 60) : '';
      var name = [row, headerOf(td)].filter(Boolean).join(' · ');
      if (name) f.setAttribute('aria-label', name);
    });
  }
  // Enter and Space press a wired element, as on a button (capture: before the page's own key shortcuts, which see
  // the key as handled)
  document.addEventListener('keydown', function (e) {
    var el = e.target;
    if (!el || !el.dataset || el.dataset.kbdOk !== '1' || (e.key !== 'Enter' && e.key !== ' ') || e.repeat) return;
    e.preventDefault();
    el.click();
  }, true);
  var timer = null;
  function later() { if (!timer) timer = setTimeout(function () { timer = null; scan(); }, 200); }
  function start() {
    scan();
    new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) if (muts[i].addedNodes.length) { later(); return; }
    }).observe(document.body, {childList: true, subtree: true});
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
// the standard pager on lists drawn whole (2026-10-23 run 3: Defaults, the Status checks, the species page list): a table
// with data-pager="<label with {select}>" gets the same pager as common.php list_pager (count picklist, Newer / Older,
// range) under it, in this browser; rows hidden by the page's own filter (style display none) are not counted, a sort
// keeps the page, group rows (data-pager-group="<class>") show when one of their rows shows
(function () {
  function pkey(tb, i) {
    var v = (location.search.match(/[?&]view=([^&]*)/) || [])[1] || location.pathname;
    return 'pager:' + decodeURIComponent(v) + ':' + (tb.id || i);
  }
  function setup(tb, i) {
    if (tb.dataset.pagerOn) return;
    tb.dataset.pagerOn = '1';
    var grp = tb.dataset.pagerGroup || '', k = pkey(tb, i), size = 25, off = 0;
    try { var s = parseInt(localStorage.getItem(k), 10); if ([10, 25, 50, 100].indexOf(s) >= 0) size = s; } catch (e) {}
    var host = tb.closest('.reviewlist') || tb, pg = document.createElement('div');
    pg.className = 'nowpager';
    host.parentNode.insertBefore(pg, host.nextSibling);
    function rows() {
      return Array.prototype.filter.call(tb.rows, function (tr) { return !tr.querySelector('th'); });
    }
    function apply() {
      var all = rows(), data = all.filter(function (tr) { return !(grp && tr.classList.contains(grp)) && tr.style.display !== 'none'; });
      if (off >= data.length) off = Math.max(0, Math.floor((data.length - 1) / size) * size);
      var shown = data.slice(off, off + size);
      all.forEach(function (tr) { tr.classList.toggle('bs-pghide', !(grp && tr.classList.contains(grp)) && shown.indexOf(tr) < 0); });
      if (grp) {
        var head = null, any = false;
        all.forEach(function (tr) {
          if (tr.classList.contains(grp)) { if (head) head.classList.toggle('bs-pghide', !any); head = tr; any = false; }
          else if (tr.style.display !== 'none' && !tr.classList.contains('bs-pghide')) any = true;
        });
        if (head) head.classList.toggle('bs-pghide', !any);
      }
      var opts = [10, 25, 50, 100].map(function (c) { return '<option' + (c === size ? ' selected' : '') + '>' + c + '</option>'; }).join('');
      var sel = '<select class="nowcount" title="' + t('How many the list shows') + '">' + opts + '</select>';
      pg.style.display = data.length > 10 ? '' : 'none';
      pg.innerHTML = '<span class="nowpcount">' + tb.dataset.pager.replace('{select}', sel) + '</span><span class="nowpnav">'
        + (off > 0 ? '<button type="button" class="openbtn" data-to="' + Math.max(0, off - size) + '">&#9664; ' + t('Newer {n}', {n: size}) + '</button>' : '')
        + '<span class="nowprange">' + (shown.length ? (off + 1) + '-' + (off + shown.length) : '') + '</span>'
        + (off + size < data.length ? '<button type="button" class="openbtn" data-to="' + (off + size) + '">' + t('Older {n}', {n: size}) + ' &#9654;</button>' : '')
        + '</span><span></span>';
    }
    pg.addEventListener('change', function (e) {
      if (!e.target.classList.contains('nowcount')) return;
      size = parseInt(e.target.value, 10); off = 0;
      try { localStorage.setItem(k, String(size)); } catch (x) {}
      apply();
    });
    pg.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-to]');
      if (b) { off = parseInt(b.dataset.to, 10); apply(); }
    });
    var waiting = false;
    new MutationObserver(function () {
      if (waiting) return;
      waiting = true;
      setTimeout(function () { waiting = false; apply(); }, 0);
    }).observe(tb, {subtree: true, childList: true, attributes: true, attributeFilter: ['style']});
    apply();
  }
  function start() { document.querySelectorAll('table[data-pager]').forEach(setup); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();

// A field that differs from its factory value is yellow, on every page (owner 2026-10-24: the standard of the whole app):
// an input, select or textarea whose name is a setting of factory_defaults.conf (the name in capitals, or data-default-key),
// compared with window.BS_DEFAULTS (common.php ui_js) as it is typed; a radio marks its label, a checkbox 1/0
(function () {
  var D = window.BS_DEFAULTS || {};
  function clean(v) { return String(v == null ? '' : v).trim().replace(/^["']|["']$/g, ''); }
  function same(a, b) {
    a = clean(a); b = clean(b);
    return (a !== '' && b !== '' && !isNaN(a) && !isNaN(b)) ? parseFloat(a) === parseFloat(b) : a.toLowerCase() === b.toLowerCase();
  }
  function on(v) { return ['1', 'true', 'on', 'yes'].indexOf(clean(v).toLowerCase()) >= 0; }
  function mark(el) {
    var k = (el.dataset.defaultKey || el.name || '').toUpperCase();
    if (!(k in D)) return;
    if (el.type === 'radio') {
      document.querySelectorAll('input[type=radio][name="' + el.name + '"]').forEach(function (r) {
        var lab = r.closest('label') || r;
        lab.classList.toggle('nondefault', r.checked && !same(r.value, D[k]));
      });
    } else if (el.type === 'checkbox') el.classList.toggle('nondefault', el.checked !== on(D[k]));
    else el.classList.toggle('nondefault', !same(el.value, D[k]));
  }
  function scan(root) {
    (root || document).querySelectorAll('input[name], select[name], textarea[name]').forEach(function (el) {
      if (['hidden', 'submit', 'button', 'password', 'file'].indexOf(el.type) >= 0 || el.dataset.bsDef) return;
      el.dataset.bsDef = '1';
      mark(el);
      el.addEventListener('input', function () { mark(el); });
      el.addEventListener('change', function () { mark(el); });
    });
  }
  function start() {
    scan();
    // fields drawn later (a panel opened, a list loaded) too
    var waiting = false;
    new MutationObserver(function () {
      if (waiting) return;
      waiting = true;
      setTimeout(function () { waiting = false; scan(); }, 200);
    }).observe(document.body, {childList: true, subtree: true});
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();

// a field with data-setting="KEY" is saved as soon as it changes (owner 2026-10-25: the options moved out of Settings ›
// Advanced): a checkbox writes 1 / 0, any other field its value, through scripts/settings_set.php; the answer shows
// right after the field ("saved" or the reason)
(function () {
  function note(el, text, bad) {
    var s = el.parentNode.querySelector('.bssaved[data-for="' + el.dataset.setting + '"]');
    if (!s) { s = document.createElement('span'); s.className = 'bssaved'; s.dataset.for = el.dataset.setting; s.style.cssText = 'margin-left:6px;font-size:.85em'; el.insertAdjacentElement('afterend', s); }
    s.textContent = text; s.style.color = bad ? '#c62828' : '#2e7d32';
  }
  window.bsSet = function (el) {
    var body = {}, k = el.dataset.setting;
    body[k] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value.trim();
    fetch('/scripts/settings_set.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body), credentials: 'same-origin', keepalive: true})
      .then(function (r) { return r.status === 401 ? window.t('Log in first.') : r.text(); })
      .then(function (r) { r = String(r).trim(); note(el, r === 'OK' ? window.t('saved') : r, r !== 'OK'); })
      .catch(function () { note(el, window.t('Not saved: the station did not answer'), true); });
  };
  document.addEventListener('change', function (e) { var el = e.target; if (el && el.dataset && el.dataset.setting) window.bsSet(el); });
})();
