// Location switch (owner 2026-10-21): the location beside the station name in the header opens the list of locations,
// like the station name; a click on one changes the current location. Every change (here, Settings › Locations, the
// Latest page) runs on the station through scripts/location_switch.php: the recording stops, the recordings already made are
// analysed, the location changes, the recording starts again. A window shows the steps and their state while it runs,
// on every page that is opened meanwhile too.
// locationSwitch(id, name) starts a change; inside the page frame it is handed to the top window.

(function () {
  // Esc belongs to this box only while the keyboard is in it or nowhere: a window opened over it (the review player)
  // keeps its own Esc (run 5: Esc on the player also confirmed the microphone box underneath)
  function escHere(box) { var a = document.activeElement; return !a || a === document.body || box.contains(a); }
  var top = window;
  try { if (window.top !== window && window.top.locationSwitch) top = window.top; } catch (e) {}
  if (top !== window) { window.locationSwitch = function (id, name) { top.locationSwitch(id, name); }; return; }

  var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
  var box = null, timer = null;
  // the boxes take the theme's panel and text (release run 5, NEW-UX-R5-13: on a fixed white box the Dark stylesheet,
  // which colours every element white, left white text on white); the same colours as the shared question (askModal)
  var LSTHEME = '.lsbox { background: var(--panel, #fff); color: var(--text, #222); } .lsbox * { color: inherit; }'
    + '.lsbox input, .lsbox select { background: var(--panel, #fff); color: inherit; border: 1px solid rgba(128,128,128,.6); border-radius: 4px; }'
    + '.lsbox .lsok-btn { padding: 6px 18px; border-radius: 14px; border: 1px solid var(--accent, #2b5e22); background: var(--accent, #2b5e22); color: #fff !important; font-weight: 600; cursor: pointer; }'
    + '.lsbox .lsok-btn:disabled { opacity: .5; cursor: default; }';

  function label(s, st) {
    if (s.key === 'stop') return t('str_stop_the_recording');
    if (s.key === 'analyse') return t('str_analyse_the_recordings_already_help') + (s.status === 'running' && s.left ? ' ' + t('str_left', {n: s.left}) : '');
    if (s.key === 'change') return t('str_change_the_location_to', {name: st.name});
    return t('str_start_the_recording_again');
  }
  var ICON = {pending: '&#9675;', running: '<span class="lsspin"></span>', done: '&#10003;', skipped: '&#8211;', error: '&#10007;'};

  function show(st) {
    if (!box) {
      box = document.createElement('div');
      box.className = 'lsback';
      box.innerHTML = '<style>'
        + '.lsback { position: fixed; inset: 0; z-index: 5000; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; padding: 16px; }'
        + LSTHEME
        + '.lsbox { border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,.35); max-width: 460px; width: 100%; padding: 18px 20px; font-size: 15px; letter-spacing: 0; font-weight: normal; text-align: left; }'
        + '.lsbox:focus { outline: none; }'
        + '.lsbox h3 { margin: 0 0 12px; font-size: 18px; }'
        + '.lsbox ol { list-style: none; margin: 0; padding: 0; }'
        + '.lsbox li { display: flex; gap: 10px; padding: 6px 0; align-items: flex-start; }'
        + '.lsbox li .lsi { width: 20px; height: 20px; line-height: 20px; text-align: center; flex: none; border-radius: 50%; }'
        + '.lsbox li.pending, .lsbox li.skipped, .lsbox li small, .lsbox .lsnote { opacity: .75; }'
        + '.lsbox li.done .lsi { background: #2e7d32; color: #fff !important; } .lsbox li.error .lsi { background: #b71c1c; color: #fff !important; }'
        + '.lsbox li small { display: block; }'
        + '.lsbox .lserr { margin-top: 10px; } .lsbox .lsok { font-weight: 600; margin-top: 10px; }'
        + '.lsbox .lsfoot { text-align: right; margin-top: 14px; } .lsbox .lsfoot button { padding: 6px 16px; }'
        + '.lsspin { display: inline-block; width: 12px; height: 12px; border: 2px solid #1f6feb; border-right-color: transparent; border-radius: 50%; animation: lsspin 1s linear infinite; }'
        + '@keyframes lsspin { to { transform: rotate(360deg); } }'
        + '</style><div class="lsbox" role="dialog" aria-modal="true" aria-labelledby="ls_title" tabindex="-1"></div>';
      document.body.appendChild(box);
    }
    var end = st.status !== 'running', lb = box.querySelector('.lsbox');
    // the box is drawn again at every answer of the station: the focus stays in it, on Close once it can be used
    var had = lb.contains(document.activeElement) || document.activeElement === document.body || !document.activeElement;
    lb.innerHTML = '<h3 id="ls_title">' + esc(t('str_changing_the_location_to', {name: st.name || ''})) + '</h3><ol>'
      + (st.steps || []).map(function (s) {
        return '<li class="' + s.status + '"><span class="lsi">' + ICON[s.status] + '</span><span>' + esc(label(s, st))
          + (s.note ? '<small>' + esc(s.note) + '</small>' : '') + '</span></li>';
      }).join('') + '</ol>'
      + (st.status === 'error' ? '<div class="lserr"><span class="badchip">' + esc(st.error || t('str_error')) + '</span></div>' : '')
      + (st.status === 'done' ? '<div class="lsok">' + esc(t('str_the_current_location_is_now', {name: st.name})) + '</div>' : '')
      + (end ? '' : '<div class="lsnote" style="margin-top:10px;font-size:13px">' + esc(t('str_the_steps_go_on_at_the_station_help')) + '</div>')
      + '<div class="lsfoot"><button type="button" class="lsok-btn"' + (end ? '' : ' disabled') + '>' + esc(t('str_close')) + '</button></div>';
    box.querySelector('.lsfoot button').onclick = function () { (window.top || window).location.reload(); };
    if (had) (end ? box.querySelector('.lsfoot button') : lb).focus();
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
      if (r !== 'OK') { bsNotice(this.status === 401 ? t('str_log_in_first') : (r || t('str_not_saved_reason', {reason: 'HTTP ' + this.status}))); return; }
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
      if (q !== 'same') bsNotice(this.status === 401 ? t('str_log_in_first') : r);  // staying never shows an error
    };
    y.open('POST', '/scripts/location_switch.php?mic=' + q, true);
    y.send();
  }
  function micClose() { if (micBox) { micBox.remove(); micBox = null; } clearTimeout(micTimer); }
  // the microphones that went or came, by name, with what used them (2026-11-03): one sentence per line
  function micEventHtml(ev) {
    if (!ev) return '';
    var nm = function (l) { return l.map(function (x) { return x.name; }).join(', '); };
    var use = function (x) { return x.station && x.raw ? t('str_mic_used_both') : x.station ? t('str_mic_used_station') : x.raw ? t('str_mic_used_raw') : ''; };
    var lines = [];
    (ev.removed || []).forEach(function (x) { var u = use(x); lines.push(t('str_mic_removed', {name: x.name}) + (u ? ' (' + u + ')' : '')); });
    (ev.added || []).forEach(function (x) { lines.push(t('str_mic_connected', {name: x.name})); });
    lines.push(ev.recording ? t('str_mic_records_now', {name: ev.recording}) : t('str_mic_records_nothing'));
    if ((ev.raw_waiting || []).length) lines.push(t('str_mic_raw_waits', {name: ev.raw_waiting.join(', ')}));
    return '<div class="mevt">' + lines.map(function (l) { return '<div>' + esc(l) + '</div>'; }).join('') + '</div>';
  }
  function micAck() { var y = new XMLHttpRequest(); y.open('POST', '/scripts/location_switch.php?mic=ack', true); y.send(); }
  function micShow(m) {
    if (micBox || box) return;
    if (!m.pending) {  // only the change of the microphones: the names and OK
      micBox = document.createElement('div');
      micBox.className = 'lsback';
      micBox.innerHTML = '<style>.lsback { position: fixed; inset: 0; z-index: 5000; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; padding: 16px; }'
        + LSTHEME
        + '.lsbox { border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,.35); max-width: 480px; width: 100%; padding: 18px 20px; font-size: 15px; text-align: left; }'
        + '.lsbox h3 { margin: 0 0 10px; font-size: 18px; } .lsbox .mevt div { margin: 4px 0; }'
        + '.lsbox .lsok-btn { padding: 6px 18px; border-radius: 14px; border: 1px solid var(--accent, #2b5e22); background: var(--accent, #2b5e22); color: #fff; font-weight: 600; cursor: pointer; }</style>'
        + '<div class="lsbox" role="dialog" aria-modal="true" aria-labelledby="mic_title"><h3 id="mic_title">&#127908; ' + esc(m.event.at ? t('str_microphones_at', {time: m.event.at}) : t('str_microphones')) + '</h3>' + micEventHtml(m.event)
        + '<div style="margin-top:12px;text-align:right"><button type="button" class="lsok-btn" id="mic_evok">' + esc(t('str_ok')) + '</button></div></div>';
      document.body.appendChild(micBox);
      var ok = function () { micClose(); micAck(); };
      micBox.querySelector('#mic_evok').onclick = ok;
      micBox.querySelector('#mic_evok').focus();
      document.addEventListener('keydown', function esc3(e) { if (e.key === 'Escape' && micBox && escHere(micBox)) { document.removeEventListener('keydown', esc3); ok(); } });
      return;
    }
    micBox = document.createElement('div');
    micBox.className = 'lsback';
    var cur = m.current ? m.current.id : null, g = m.guess || {};
    micBox.innerHTML = '<style>.lsback { position: fixed; inset: 0; z-index: 5000; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; padding: 16px; }'
      + LSTHEME
      + '.lsbox { border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,.35); max-width: 480px; width: 100%; padding: 18px 20px; font-size: 15px; text-align: left; }'
      + '.lsbox h3 { margin: 0 0 10px; font-size: 18px; } .lsbox .mrow { margin: 10px 0; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }'
      + '.lsbox input, .lsbox select { padding: 4px 6px; } .lsbox small { opacity: .75; } .lsbox .mcount { font-weight: bold; font-variant-numeric: tabular-nums; }'
      + '.lsbox .lsok-btn { padding: 6px 18px; border-radius: 14px; border: 1px solid var(--accent, #2b5e22); background: var(--accent, #2b5e22); color: #fff; font-weight: 600; cursor: pointer; }'
      + '.lsbox .lsok-btn:hover { filter: brightness(1.1); }'
      + '.lsbox .lsclose-btn { padding: 6px 14px; border-radius: 14px; border: 1px solid #999; background: #fff; color: #333; cursor: pointer; }</style>'
      + '<div class="lsbox" role="dialog" aria-modal="true" aria-labelledby="mic_title"><button type="button" id="mic_x" title="' + esc(t('str_close')) + '" aria-label="' + esc(t('str_close')) + '" style="float:right;border:0;background:none;font-size:22px;line-height:1;cursor:pointer;color:inherit;width:32px;height:32px;padding:0">&times;</button><h3 id="mic_title">&#127908; ' + esc(m.at ? t('str_the_microphone_was_reconnected_at', {time: m.at}) : t('str_the_microphone_was_reconnected')) + '</h3>'
      + micEventHtml(m.event) + '<div>' + esc(t('str_where_is_the_station_now')) + '<br><small>' + esc(t('str_without_an_answer_it_stays_at_help')) + ' <span class="mcount" id="mic_count"></span></small></div>'
      + '<div class="mrow">' + esc(t('str_location')) + ' <select id="mic_sel" aria-label="' + esc(t('str_location')) + '">' + (m.locations || []).map(function (l) {
          return '<option value="' + l.id + '"' + (l.id === cur ? ' selected' : '') + '>' + esc(l.name) + '</option>'; }).join('')
      + '<option value="new">' + esc(t('str_new_location_ellipsis')) + '</option></select> <button type="button" id="mic_ok" class="lsok-btn">' + esc(t('str_confirm')) + '</button> <button type="button" id="mic_close" class="lsclose-btn">' + esc(t('str_close')) + '</button></div>'
      + '<div id="mic_newrow" style="display:none"><div class="mrow"><input id="mic_name" placeholder="' + esc(t('str_name')) + '" size="16">'
      + ' <input id="mic_lat" size="9" value="' + esc(g.lat !== undefined ? g.lat : '') + '" placeholder="' + esc(t('str_latitude')) + '">'
      + ' <input id="mic_lon" size="9" value="' + esc(g.lon !== undefined ? g.lon : '') + '" placeholder="' + esc(t('str_longitude')) + '"></div>'
      + '<small>' + esc(g.from === 'network' ? t('str_coordinates_from_the_network_help', {place: g.place ? ' (' + g.place + ')' : ''})
          : t('str_coordinates_of_the_current_help')) + '</small></div></div>';
    document.body.appendChild(micBox);
    var sel = micBox.querySelector('#mic_sel');
    // Close, the × and Escape: the station stays where it is (owner 2026-10-24: no way out of the window)
    if (m.event) micAck();  // the names are shown in this window: seen
    var stay = function () { micClose(); micAnswer('same', function () {}); };
    micBox.querySelector('#mic_x').onclick = stay;
    micBox.querySelector('#mic_close').onclick = stay;
    micBox.addEventListener('keydown', function (e) { if (e.key === 'Escape') stay(); });
    document.addEventListener('keydown', function esc2(e) { if (e.key === 'Escape' && micBox && escHere(micBox)) { document.removeEventListener('keydown', esc2); stay(); } });
    micBox.querySelector('#mic_ok').focus();
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
    y.onload = function () { var m = null; try { m = JSON.parse(this.responseText); } catch (e) {} if (m && (m.pending || m.event)) micShow(m); };
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
    }).join('') + '<a class="stnmanage" href="/?view=Locations">' + esc(t('str_manage_locations')) + '</a>';
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
