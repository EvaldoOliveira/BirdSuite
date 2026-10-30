// Location switch (owner 2026-10-21): the location beside the station name in the header opens the list of locations,
// like the station name; a click on one changes the current location. Every change (here, Settings › Locations, the
// Latest page) runs on the station through scripts/location_switch.php: the recording stops, the recordings already made are
// analysed, the location changes, the recording starts again. A window shows the steps and their state while it runs,
// on every page that is opened meanwhile too.
// locationSwitch(id, name) starts a change; inside the page frame it is handed to the top window.

(function () {
  var top = window;
  try { if (window.top !== window && window.top.locationSwitch) top = window.top; } catch (e) {}
  if (top !== window) { window.locationSwitch = function (id, name) { top.locationSwitch(id, name); }; return; }

  var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
  var box = null, timer = null;

  function label(s, st) {
    if (s.key === 'stop') return t('Stop the recording');
    if (s.key === 'analyse') return t('Analyse the recordings already made here') + (s.status === 'running' && s.left ? ' ' + t('({n} left)', {n: s.left}) : '');
    if (s.key === 'change') return t('Change the location to "{name}"', {name: st.name});
    return t('Start the recording again');
  }
  var ICON = {pending: '&#9675;', running: '<span class="lsspin"></span>', done: '&#10003;', skipped: '&#8211;', error: '&#10007;'};

  function show(st) {
    if (!box) {
      box = document.createElement('div');
      box.className = 'lsback';
      box.innerHTML = '<style>'
        + '.lsback { position: fixed; inset: 0; z-index: 5000; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; padding: 16px; }'
        + '.lsbox { background: #fff; color: #222; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,.35); max-width: 460px; width: 100%; padding: 18px 20px; font-size: 15px; letter-spacing: 0; font-weight: normal; text-align: left; }'
        + '.lsbox h3 { margin: 0 0 12px; font-size: 18px; }'
        + '.lsbox ol { list-style: none; margin: 0; padding: 0; }'
        + '.lsbox li { display: flex; gap: 10px; padding: 6px 0; align-items: flex-start; }'
        + '.lsbox li .lsi { width: 20px; text-align: center; flex: none; }'
        + '.lsbox li.pending { color: #999; } .lsbox li.done .lsi { color: #2e7d32; } .lsbox li.error .lsi { color: #c62828; } .lsbox li.skipped { color: #777; }'
        + '.lsbox li small { display: block; color: #777; }'
        + '.lsbox .lserr { color: #c62828; margin-top: 10px; } .lsbox .lsok { color: #2e7d32; margin-top: 10px; }'
        + '.lsbox .lsfoot { text-align: right; margin-top: 14px; } .lsbox .lsfoot button { padding: 6px 16px; }'
        + '.lsspin { display: inline-block; width: 12px; height: 12px; border: 2px solid #1f6feb; border-right-color: transparent; border-radius: 50%; animation: lsspin 1s linear infinite; }'
        + '@keyframes lsspin { to { transform: rotate(360deg); } }'
        + '</style><div class="lsbox"></div>';
      document.body.appendChild(box);
    }
    var end = st.status !== 'running';
    box.querySelector('.lsbox').innerHTML = '<h3>' + esc(t('Changing the location to "{name}"', {name: st.name || ''})) + '</h3><ol>'
      + (st.steps || []).map(function (s) {
        return '<li class="' + s.status + '"><span class="lsi">' + ICON[s.status] + '</span><span>' + esc(label(s, st))
          + (s.note ? '<small>' + esc(s.note) + '</small>' : '') + '</span></li>';
      }).join('') + '</ol>'
      + (st.status === 'error' ? '<div class="lserr">' + esc(st.error || t('Error')) + '</div>' : '')
      + (st.status === 'done' ? '<div class="lsok">' + esc(t('The current location is now "{name}".', {name: st.name})) + '</div>' : '')
      + (end ? '' : '<div style="margin-top:10px;color:#777;font-size:13px">' + esc(t('The steps go on at the station even if this page is closed.')) + '</div>')
      + '<div class="lsfoot"><button type="button"' + (end ? '' : ' disabled') + '>' + esc(t('Close')) + '</button></div>';
    box.querySelector('.lsfoot button').onclick = function () { (window.top || window).location.reload(); };
  }

  function poll() {
    var x = new XMLHttpRequest();
    x.onload = function () {
      var st = null; try { st = JSON.parse(this.responseText); } catch (e) {}
      if (!st || !st.status) { timer = setTimeout(poll, 1500); return; }
      show(st);
      if (st.status === 'running') timer = setTimeout(poll, 1500);
      // done: the whole station page reloads by itself, header and frame, in the new location's theme (owner 2026-10-21)
      else if (st.status === 'done' && box) setTimeout(function () { (window.top || window).location.reload(); }, 1500);
    };
    x.onerror = function () { timer = setTimeout(poll, 3000); };
    x.open('GET', '/scripts/location_switch.php?state=1&_=' + Date.now(), true);
    x.send();
  }

  window.locationSwitch = function (id, name) {
    var x = new XMLHttpRequest();
    x.onload = function () {
      var r = this.responseText.trim();
      if (r !== 'OK') { alert(this.status === 401 ? t('Log in first.') : (r || t('Not saved: {reason}', {reason: 'HTTP ' + this.status}))); return; }
      show({status: 'running', name: name, steps: [{key: 'stop', status: 'pending'}, {key: 'analyse', status: 'pending'}, {key: 'change', status: 'pending'}, {key: 'start', status: 'pending'}]});
      clearTimeout(timer); poll();
    };
    x.open('POST', '/scripts/location_switch.php?start=' + encodeURIComponent(id), true);
    x.send();
  };

  // a change already running (started elsewhere, or before a reload): its window
  var x = new XMLHttpRequest();
  x.onload = function () {
    var st = null; try { st = JSON.parse(this.responseText); } catch (e) {}
    if (st && st.status === 'running') { show(st); poll(); }
  };
  x.open('GET', '/scripts/location_switch.php?state=1&_=' + Date.now(), true);
  x.send();

  // the microphone came back after a removal (US-58, owner 2026-10-24: a window on any page, also one already open):
  // is the station still at its location? Same place, another registered one, or a new one; no answer in 5 minutes =
  // the same place (the station closes the question by itself)
  var micBox = null, micTimer = null;
  function micAnswer(q, done) {
    var y = new XMLHttpRequest();
    y.onload = function () {
      var r = this.responseText.trim();
      if (r.indexOf('ID:') === 0 || r === 'OK') return done(r);
      if (q !== 'same') alert(this.status === 401 ? t('Log in first.') : r);  // staying never shows an error
    };
    y.open('POST', '/scripts/location_switch.php?mic=' + q, true);
    y.send();
  }
  function micClose() { if (micBox) { micBox.remove(); micBox = null; } clearTimeout(micTimer); }
  function micShow(m) {
    if (micBox || box) return;
    micBox = document.createElement('div');
    micBox.className = 'lsback';
    var cur = m.current ? m.current.id : null, g = m.guess || {};
    micBox.innerHTML = '<style>.lsback { position: fixed; inset: 0; z-index: 5000; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; padding: 16px; }'
      + '.lsbox { background: #fff; color: #222; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,.35); max-width: 480px; width: 100%; padding: 18px 20px; font-size: 15px; text-align: left; }'
      + '.lsbox h3 { margin: 0 0 10px; font-size: 18px; } .lsbox .mrow { margin: 10px 0; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }'
      + '.lsbox input, .lsbox select { padding: 4px 6px; } .lsbox small { color: #777; } .lsbox .mcount { font-weight: bold; font-variant-numeric: tabular-nums; }'
      + '.lsbox .lsok-btn { padding: 6px 18px; border-radius: 14px; border: 1px solid var(--accent, #2b5e22); background: var(--accent, #2b5e22); color: #fff; font-weight: 600; cursor: pointer; }'
      + '.lsbox .lsok-btn:hover { filter: brightness(1.1); }'
      + '.lsbox .lsclose-btn { padding: 6px 14px; border-radius: 14px; border: 1px solid #999; background: #fff; color: #333; cursor: pointer; }</style>'
      + '<div class="lsbox"><button type="button" id="mic_x" title="' + esc(t('Close')) + '" style="float:right;border:0;background:none;font-size:22px;line-height:1;cursor:pointer;color:#666">&times;</button><h3>&#127908; ' + esc(m.at ? t('The microphone was reconnected at {time}.', {time: m.at}) : t('The microphone was reconnected.')) + '</h3>'
      + '<div>' + esc(t('Where is the station now?')) + '<br><small>' + esc(t('Without an answer it stays at the current location in')) + ' <span class="mcount" id="mic_count"></span></small></div>'
      + '<div class="mrow">' + esc(t('Location')) + ' <select id="mic_sel">' + (m.locations || []).map(function (l) {
          return '<option value="' + l.id + '"' + (l.id === cur ? ' selected' : '') + '>' + esc(l.name) + '</option>'; }).join('')
      + '<option value="new">' + esc(t('New location…')) + '</option></select> <button type="button" id="mic_ok" class="lsok-btn">' + esc(t('Confirm')) + '</button> <button type="button" id="mic_close" class="lsclose-btn">' + esc(t('Close')) + '</button></div>'
      + '<div id="mic_newrow" style="display:none"><div class="mrow"><input id="mic_name" placeholder="' + esc(t('Name')) + '" size="16">'
      + ' <input id="mic_lat" size="9" value="' + esc(g.lat !== undefined ? g.lat : '') + '" placeholder="' + esc(t('Latitude')) + '">'
      + ' <input id="mic_lon" size="9" value="' + esc(g.lon !== undefined ? g.lon : '') + '" placeholder="' + esc(t('Longitude')) + '"></div>'
      + '<small>' + esc(g.from === 'network' ? t('Coordinates from the network position{place}; fix them later in Settings › Locations.', {place: g.place ? ' (' + g.place + ')' : ''})
          : t('Coordinates of the current location; fix them later in Settings › Locations.')) + '</small></div></div>';
    document.body.appendChild(micBox);
    var sel = micBox.querySelector('#mic_sel');
    // Close, the × and Escape: the station stays where it is (owner 2026-10-24: no way out of the window)
    var stay = function () { micClose(); micAnswer('same', function () {}); };
    micBox.querySelector('#mic_x').onclick = stay;
    micBox.querySelector('#mic_close').onclick = stay;
    micBox.addEventListener('keydown', function (e) { if (e.key === 'Escape') stay(); });
    document.addEventListener('keydown', function esc2(e) { if (e.key === 'Escape' && micBox) { document.removeEventListener('keydown', esc2); stay(); } });
    sel.onchange = function () { micBox.querySelector('#mic_newrow').style.display = sel.value === 'new' ? '' : 'none'; };
    micBox.querySelector('#mic_ok').onclick = function () {
      if (sel.value === 'new') {
        var nm = micBox.querySelector('#mic_name').value;
        micAnswer('new&name=' + encodeURIComponent(nm) + '&lat=' + encodeURIComponent(micBox.querySelector('#mic_lat').value.replace(',', '.'))
          + '&lon=' + encodeURIComponent(micBox.querySelector('#mic_lon').value.replace(',', '.')), function (r) { micClose(); window.locationSwitch(parseInt(r.slice(3), 10), nm); });
      } else if (parseInt(sel.value, 10) === cur) { micClose(); micAnswer('same', function () {}); }
      else { var id = parseInt(sel.value, 10), nm2 = sel.options[sel.selectedIndex].text; micClose(); micAnswer('same', function () { window.locationSwitch(id, nm2); }); }
    };
    // the countdown; at zero the station stays where it is
    var end = Date.now() + m.left * 1000;
    var tick = function () {
      var s = Math.max(0, Math.round((end - Date.now()) / 1000));
      var c = micBox && micBox.querySelector('#mic_count');
      if (c) c.textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
      if (s <= 0) { micClose(); micAnswer('same', function () {}); return; }
      micTimer = setTimeout(tick, 1000);
    };
    tick();
  }
  function micPoll() {
    var y = new XMLHttpRequest();
    y.onload = function () { var m = null; try { m = JSON.parse(this.responseText); } catch (e) {} if (m && m.pending) micShow(m); };
    y.open('GET', '/scripts/location_switch.php?mic=1&_=' + Date.now(), true);
    y.send();
  }
  micPoll();
  setInterval(micPoll, 30000);

  // the list of locations in the header
  var btn = document.getElementById('loc_btn');
  var pop = document.getElementById('loc_pop');
  if (!btn || !pop) return;
  function render(d) {
    pop.innerHTML = (d.locations || []).map(function (l) {
      var cur = l.id === d.current;
      return '<button type="button" class="stnitem' + (cur ? ' cur' : '') + '" data-id="' + l.id + '"' + (cur ? ' disabled' : '') + '>'
        + '<span class="stnname">' + (cur ? '&#10003; ' : '') + esc(l.name) + '</span></button>';
    }).join('') + '<a class="stnmanage" href="/?view=Locations">' + esc(t('Manage locations…')) + '</a>';
    pop.querySelectorAll('.stnitem:not(.cur)').forEach(function (b) {
      b.onclick = function () {
        pop.classList.remove('open');
        window.locationSwitch(parseInt(b.dataset.id, 10), b.textContent);
      };
    });
  }
  function toggle(open) {
    var showIt = open === undefined ? !pop.classList.contains('open') : open;
    pop.classList.toggle('open', showIt);
    if (!showIt) return;
    var other = document.getElementById('station_pop'); if (other) other.classList.remove('open'); // one list at a time
    var y = new XMLHttpRequest();
    y.onload = function () { try { render(JSON.parse(this.responseText)); } catch (e) {} };
    y.open('GET', '/scripts/locations.php?json=1', true);
    y.send();
  }
  btn.onclick = function (e) { e.stopPropagation(); toggle(); };
  document.addEventListener('click', function (e) { if (!pop.contains(e.target)) toggle(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') toggle(false); });
})();
