// A folder tree of the whole station, as the station user sees it (owner 2026-10-29: the row of start buttons and the
// wall of folder buttons were hard to use); its boxes have their height from the start, so the folders filled in after
// the page is drawn never push the page down (release run 4, PERF-56). folderPicker(el, start, pick): the tree in el, opened down to start (else
// the station user's home), pick(path, listing) on every folder chosen; a line of shortcuts (home, network shares, USB
// disks) above it, a folder the station user cannot read greyed with the reason. The listing is the one of
// scripts/external_files.php?ls (path, dirs, audio, results).
(function () {
  var css = '.fp{border:1px solid rgba(0,0,0,.2);border-radius:6px;background:var(--panel,rgba(255,255,255,.55));color:inherit;padding:6px;margin:4px 0}'
    // the texts take the page's own colour, full strength (release run 5: 2.1-2.6:1 in Dark at opacity .8)
    + '.fp .small,.fp .fpsel{color:inherit;opacity:1}'
    + '.fp .fpcuts{display:flex;flex-wrap:wrap;gap:4px;align-items:center;margin-bottom:6px;min-height:24px}'
    + '.fp .fpcuts button{font-size:12px;padding:2px 8px;border-radius:12px}'
    + '.fp .fptree{position:relative;height:min(320px,50vh);overflow:auto;font-size:14px;line-height:1.5;background:var(--panel,#fff);color:inherit;border-radius:4px;padding:4px 0}'
    + '.fp .fprow{display:flex;align-items:center;cursor:pointer;white-space:nowrap;padding:1px 6px 1px 0;min-height:24px}'
    + '.fp .fprow:hover{background:rgba(0,0,0,.06)}.fp .fprow.sel{background:#1f6aa8;color:#fff}'
    + '.fp .fptree:focus{outline:2px solid var(--accent,#1f6aa8);outline-offset:1px}'
    + '.fp .fptog{display:inline-block;width:24px;text-align:center;flex:none;user-select:none}'
    + '.fp .fpsel{margin-top:6px;font-size:13px;word-break:break-all;min-height:1.5em}.fp .fpsel b{font-family:monospace}';
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
  var tr = function (s, v) { return window.t ? window.t(s, v) : s; };
  var cache = {}, roots = null;
  var get = function (url, done) { var x = new XMLHttpRequest(); x.onload = function () { try { done(JSON.parse(this.responseText)); } catch (e) { done(null); } }; x.onerror = function () { done(null); }; x.open('GET', url, true); x.send(); };
  // backup folders (BirdSuite or BirdNET-Pi) are shown with a box instead of the folder icon
  var backup = {};
  var ls = function (p, done) {
    if (cache[p]) { done(cache[p]); return; }
    get('scripts/external_files.php?ls=' + encodeURIComponent(p), function (r) {
      if (r && !r.error) { cache[p] = r; (r.backups || []).forEach(function (d) { backup[join(r.path, d)] = true; }); }
      done(r && !r.error ? r : null);
    });
  };
  var join = function (p, d) { return (p === '/' ? '' : p) + '/' + d; };
  var chain = function (p) { var out = ['/'], acc = ''; p.split('/').filter(Boolean).forEach(function (s) { acc += '/' + s; out.push(acc); }); return out; };

  window.folderPicker = function (el, start, pick) {
    if (typeof el === 'string') el = document.getElementById(el);
    // the old containers (.dirs) have a height and a scroll of their own: the tree scrolls by itself
    el.style.maxHeight = 'none'; el.style.overflow = 'visible'; el.style.display = 'block'; el.style.height = 'auto';
    el.innerHTML = '<div class="fp"><div class="small" style="margin-bottom:2px">' + esc(tr('str_mounted_folders')) + '</div><div class="fpcuts"></div><div class="fptree" role="tree" tabindex="0" aria-label="' + esc(tr('str_selected_folder')) + '"></div><div class="fpsel"></div></div>';
    var cuts = el.querySelector('.fpcuts'), tree = el.querySelector('.fptree'), selEl = el.querySelector('.fpsel');
    var open = {}, sel = '';
    var row = function (p) { return tree.querySelector('.fprow[data-p="' + CSS.escape(p) + '"]'); };
    var draw = function () {
      var html = '', walk = function (p, depth) {
        var r = cache[p], name = p === '/' ? '/' : p.slice(p.lastIndexOf('/') + 1);
        var has = !r || r.dirs.length > 0, isOpen = open[p] && r;
        html += '<div class="fprow' + (p === sel ? ' sel' : '') + '" role="treeitem" aria-selected="' + (p === sel) + '"' + (has ? ' aria-expanded="' + !!isOpen + '"' : '') + ' data-p="' + esc(p) + '" style="padding-left:' + (depth * 16) + 'px">'
          + '<span class="fptog">' + (has ? (isOpen ? '&#9662;' : '&#9656;') : '') + '</span>' + (backup[p] ? '&#128230;&nbsp;<b>' + esc(name) + '</b> <span style="font-size:11px;opacity:.7">' + esc(tr('str_backup')) + '</span>' : '&#128193;&nbsp;' + esc(name)) + '</div>';
        if (isOpen) r.dirs.forEach(function (d) { walk(join(p, d), depth + 1); });
      };
      walk('/', 0);
      tree.innerHTML = html;
      // only the tree scrolls to the folder chosen, never the page
      var s = row(sel);
      if (s && (s.offsetTop < tree.scrollTop || s.offsetTop + s.offsetHeight > tree.scrollTop + tree.clientHeight)) tree.scrollTop = s.offsetTop - tree.clientHeight / 3;
    };
    var choose = function (p, r) {
      sel = p;
      selEl.innerHTML = tr('str_selected_folder') + ' <b>' + esc(p) + '</b>';
      draw();
      pick(p, r);
    };
    // opens every level down to p, then chooses it; a level that cannot be read stops there
    var goTo = function (p) {
      var steps = chain(p), i = 0, next = function () {
        ls(steps[i], function (r) {
          if (!r) { if (i > 0) choose(steps[i - 1], cache[steps[i - 1]]); else draw(); return; }
          open[steps[i]] = true;
          if (++i < steps.length) next(); else choose(r.path, r);
        });
      };
      next();
    };
    tree.onclick = function (e) {
      var rw = e.target.closest('.fprow'); if (!rw) return;
      var p = rw.dataset.p, tog = e.target.closest('.fptog');
      if (tog && open[p]) { open[p] = false; draw(); return; }
      ls(p, function (r) {
        if (!r) { rw.style.opacity = '.5'; rw.title = tr('str_the_station_user_cannot_read_help'); return; }
        open[p] = true;
        if (tog) draw(); else choose(r.path, r);
      });
    };
    // the keyboard (WCAG 2.1.1, release run 4): the tree takes the focus with Tab; Up / Down choose the folder above /
    // below, Right opens the folder chosen (or goes into its first subfolder), Left closes it (or goes to its parent)
    tree.onkeydown = function (e) {
      var rows = Array.prototype.slice.call(tree.querySelectorAll('.fprow')), i = rows.indexOf(row(sel));
      var go = function (p) { if (p) ls(p, function (r) { if (r) choose(r.path, r); }); };
      if (e.key === 'ArrowDown') { if (rows[i + 1]) go(rows[i + 1].dataset.p); }
      else if (e.key === 'ArrowUp') { if (i > 0) go(rows[i - 1].dataset.p); }
      else if (e.key === 'ArrowRight') {
        if (!sel) return;
        if (open[sel] && cache[sel] && cache[sel].dirs.length) go(join(sel, cache[sel].dirs[0]));
        else ls(sel, function (r) { if (r) { open[sel] = true; draw(); } });
      } else if (e.key === 'ArrowLeft') {
        if (!sel) return;
        if (open[sel]) { open[sel] = false; draw(); }
        else if (sel !== '/') go(sel.slice(0, sel.lastIndexOf('/')) || '/');
      } else return;
      e.preventDefault();
    };
    var drawCuts = function () {
      cuts.innerHTML = (roots || []).map(function (x) {
        return x.ok ? '<button type="button" data-p="' + esc(x.path) + '">&#128190; ' + esc(x.name) + '</button>'
          : '<button type="button" disabled style="opacity:.5" title="' + esc(tr('str_the_station_mount_it_help')) + '">&#128190; ' + esc(x.name) + ' &#128274;</button>';
      }).join('');
      cuts.querySelectorAll('button[data-p]').forEach(function (b) { b.onclick = function () { goTo(b.dataset.p); }; });
    };
    // without a start folder: the first shortcut (the station user's home)
    var begin = function () { drawCuts(); goTo(start || (roots[0] && roots[0].ok ? roots[0].path : '/')); };
    if (roots) begin(); else get('scripts/external_files.php?roots=1', function (r) { roots = r || []; begin(); });
    return {goTo: goTo, selected: function () { return sel; }};
  };
})();
