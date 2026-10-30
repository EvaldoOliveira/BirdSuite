// Station switch (owner 2026-10-20, US-117): the station name in the header opens the list of the registered stations
// (Settings › Stations, scripts/stations.php) as name and address. Picking another station opens the same page there,
// through the first of its addresses that answers (a picture of the station is loaded as the probe: it works across
// stations without any server change); addresses that do not answer are greyed out. The station shown now is marked.
// stationProbe() is also used by the Stations page (Test).

// the station's own WiFi name, BirdSuite-<its ID> (scripts/field_net.py ap_ssid); the ID is the MAC of the Pi's own network
// port (common.php station_mac), the key of stations.json; the name is an alias (owner 2026-10-27)
function stationWifi(mac) {
  mac = String(mac || '').replace(/:/g, '').toUpperCase();
  return mac.length === 12 ? 'BirdSuite-' + mac : '';
}
// the name of a station as the lists show it: with the last 6 digits of its MAC when another station of the list has the
// same name (owner 2026-10-27: cloned stations may share a name), e.g. "BirdSuite · 1AEC7C"
function stationLabel(s, list) {
  var n = String(s.name || ''), mac = String(s.mac || '').replace(/:/g, '').toUpperCase();
  var twin = (list || []).some(function (o) { return o !== s && String(o.name || '').toLowerCase() === n.toLowerCase(); });
  return twin && mac.length >= 6 ? n + ' · ' + mac.slice(-6) : n;
}

// calls done(true) when <address>/ (same scheme as this page) answers within ms milliseconds
function stationProbe(addr, ms, done) {
  var img = new Image(), fired = false;
  var end = function (ok) { if (fired) return; fired = true; img.onload = img.onerror = null; img.src = ''; done(ok); };
  img.onload = function () { end(true); };
  img.onerror = function () { end(false); };
  setTimeout(function () { end(false); }, ms);
  img.src = location.protocol + '//' + addr + '/images/BirdNetBr.png?probe=' + Date.now();  // the scheme of this page (an http image is blocked on an https page)
}

// the station's name, IP and MAC as it reports them (scripts/stations.php?json=1, readable from another station)
function stationInfo(addr, done) {
  var x = new XMLHttpRequest(), fired = false;
  var end = function (j) { if (!fired) { fired = true; done(j); } };
  x.timeout = 3000;
  x.onload = function () { try { end(JSON.parse(this.responseText)); } catch (e) { end(null); } };
  x.onerror = x.ontimeout = function () { end(null); };
  x.open('GET', location.protocol + '//' + addr + '/scripts/stations.php?json=1', true);
  x.send();
}

(function () {
  var btn = document.getElementById('station_btn');
  var pop = document.getElementById('station_pop');
  if (!btn || !pop) return;
  var data = null;
  var here = location.host.toLowerCase();

  var ip = '';
  function isHere(s) {
    return (s.addresses || []).some(function (a) { a = a.toLowerCase(); return a === here || (ip && a === ip); });
  }
  function load(then) {
    var x = new XMLHttpRequest();
    x.onload = function () {
      try { data = JSON.parse(this.responseText); } catch (e) { data = {site: btn.textContent, stations: []}; }
      ip = (data.ip || '').toLowerCase();
      // this station first when it is not registered, so the list always shows where you are
      if (!data.stations.some(isHere)) data.stations.unshift({name: data.site, addresses: [data.ip || location.host], mac: data.mac, here: true});
      data.stations.forEach(function (s) { if (s.here || isHere(s)) s.mac = data.mac; });
      btn.classList.toggle('many', data.stations.length > 1);
      if (then) then();
    };
    x.open('GET', 'scripts/stations.php?json=1', true);
    x.send();
  }
  // the page shown now (the address of the top window: /?view=...), opened on the other station
  function go(addr) {
    location.href = location.protocol + '//' + addr + location.pathname + location.search;
  }
  function render() {
    var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
    pop.innerHTML = data.stations.map(function (s, i) {
      var cur = s.here || isHere(s);
      return '<button type="button" class="stnitem' + (cur ? ' cur' : '') + '" data-i="' + i + '"' + (cur ? ' disabled' : '') + '>'
        // the note of the Stations list under the name, small like the MAC (owner 2026-10-20)
        + '<span class="stnname">' + (cur ? '&#10003; ' : '') + esc(stationLabel(s, data.stations)) + (s.note ? '<br><small class="stnmac">' + esc(s.note) + '</small>' : '') + '</span>'
        + '<span class="stnaddr">' + esc(cur && ip ? ip : ((s.addresses || [])[0] || '')) + (s.mac ? '<br><small class="stnmac">ID ' + esc(s.mac) + '</small>' : '') + '</span></button>';
    }).join('')
      + '<a class="stnmanage" href="/?view=Stations">' + esc(t('Manage stations…')) + '</a>';
    pop.querySelectorAll('.stnitem:not(.cur)').forEach(function (b) {
      var s = data.stations[parseInt(b.dataset.i, 10)];
      var addrs = s.addresses || [];
      var left = addrs.length, best = -1, shown = false;
      // the first address (in the registered order) that answers; the item shows it
      addrs.forEach(function (a, k) {
        stationProbe(a, 2500, function (ok) {
          left--;
          if (ok && (best < 0 || k < best)) best = k;
          if (best >= 0 && !shown && (left === 0 || best === 0)) {
            shown = true;
            b.dataset.addr = addrs[best];
            b.querySelector('.stnaddr').innerHTML = esc(addrs[best]) + (s.mac ? '<br><small class="stnmac">ID ' + esc(s.mac) + '</small>' : '');
            b.classList.add('up');
            // its MAC as it reports it now
            stationInfo(addrs[best], function (j) { if (j && j.mac) b.querySelector('.stnaddr').innerHTML = esc(addrs[best]) + '<br><small class="stnmac">ID ' + esc(j.mac) + '</small>'; });
          }
          if (left === 0 && best < 0) { b.classList.add('down'); b.title = t('No address answers'); }
        });
      });
      b.onclick = function () {
        if (b.dataset.addr) { go(b.dataset.addr); return; }
        b.querySelector('.stnaddr').textContent = t('checking…');
        // not probed yet (or down): try once more, in order, and go to the first that answers
        var k = 0;
        (function next() {
          if (k >= addrs.length) { b.querySelector('.stnaddr').textContent = t('no address answers'); return; }
          var a = addrs[k++];
          stationProbe(a, 2500, function (ok) { if (ok) go(a); else next(); });
        })();
      };
    });
  }
  function toggle(open) {
    var show = open === undefined ? !pop.classList.contains('open') : open;
    pop.classList.toggle('open', show);
    if (show) { var other = document.getElementById('loc_pop'); if (other) other.classList.remove('open'); } // one list at a time
    if (show) { if (data) render(); load(render); }
  }
  btn.onclick = function (e) { e.stopPropagation(); toggle(); };
  document.addEventListener('click', function (e) { if (!pop.contains(e.target)) toggle(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') toggle(false); });
  // the Stations page (inside the frame) refreshes the list after a save
  window.stationSwitchReload = function () { load(function () { if (pop.classList.contains('open')) render(); }); };
  load();
})();
