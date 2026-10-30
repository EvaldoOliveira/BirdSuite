// Stations map (owner 2026-10-27), on Settings › Stations (drawn when the page opens and by Refresh, synchronous: the
// station asks every registered one at that moment): one pin per registered station at its current location,
// green when it answers now, red when it does not (the last position it reported, with the time it was seen); the other
// locations registered on each station in yellow, with their names (owner 2026-10-27: grey dots were too small to see).
// One layer per station, its checkbox in the list beside the map (owner 2026-10-27: outside the map, it can grow; the
// unchecked ones are remembered in this browser); a click on a name centres the map there. The positions come from
// scripts/stations.php?map=1, which asks every station. Leaflet as the location picker (static/location-picker.js:
// locationPickerLoad, locationPickerViews); without internet the offline world map of the station, with the same list.
var STATION_MAP_COLORS = {up: '#2e7d32', down: '#c62828', place: '#f9a825'};
var stationMapLeafletMap = null;  // the map drawn, removed before Refresh draws it again

// Maximize / Back (owner 2026-10-27): the map fills the window, Back (or Esc) returns it to the page; an icon at the bottom
// right, above the credits, as the full-screen button of the common map apps
function stationMapMaxButton(box) {
  if (!document.getElementById('stnMapMaxCss')) {
    var st = document.createElement('style');
    st.id = 'stnMapMaxCss';
    st.textContent = '.stn-map-max{position:fixed!important;left:0;top:0;right:0;bottom:0;width:auto!important;height:auto!important;max-width:none!important;'
      + 'margin:0!important;padding:8px;box-sizing:border-box;border-radius:0!important;z-index:5000}'
      + '.stn-map-max #stnmap{height:auto!important}.stn-map-max .stn-map-list{max-height:none!important}'
      + '.stn-map-maxbtn{position:absolute;right:10px;bottom:26px;z-index:1001;width:34px;height:34px;padding:0;border-radius:4px;'
      + 'border:2px solid rgba(0,0,0,.25);background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center}'
      // on a phone the credits take two lines
      + '@media (max-width:600px){.stn-map-maxbtn{bottom:48px}}';
    document.head.appendChild(st);
  }
  box.style.position = box.style.position || 'relative';
  var b = document.createElement('button');
  b.type = 'button'; b.className = 'stn-map-maxbtn';
  var wrap = box.parentNode;  // the map and its list
  var set = function (max) {
    wrap.classList.toggle('stn-map-max', max);
    wrap.style.background = max ? getComputedStyle(document.body).backgroundColor : '';
    // the four corners pointing out (maximize) or in (back), drawn here (no icon font offline)
    b.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#333" stroke-width="2.4" stroke-linecap="round">'
      + (max ? '<path d="M9 3v6H3M15 3v6h6M9 21v-6H3M15 21v-6h6"/>' : '<path d="M3 9V3h6M21 9V3h-6M3 15v6h6M21 15v6h-6"/>') + '</svg>';
    b.title = max ? t('Back to the page (Esc)') : t('Map in the whole window');
    b.setAttribute('aria-label', max ? t('Back') : t('Maximize'));
    if (stationMapLeafletMap) setTimeout(function () { stationMapLeafletMap.invalidateSize(); }, 60);
  };
  ['mousedown', 'dblclick', 'click', 'touchstart'].forEach(function (ev) { b.addEventListener(ev, function (e) { e.stopPropagation(); }); });
  b.onclick = function (e) { e.stopPropagation(); set(!wrap.classList.contains('stn-map-max')); };
  if (!window.stnMapEsc) {
    window.stnMapEsc = true;
    document.addEventListener('keydown', function (e) {
      var m = document.querySelector('.stn-map-max .stn-map-maxbtn');
      if (e.key === 'Escape' && m) m.click();
    });
  }
  set(wrap.classList.contains('stn-map-max'));
  box.appendChild(b);
}

function stationMap(box, note) {
  if (stationMapLeafletMap) { stationMapLeafletMap.remove(); stationMapLeafletMap = null; }
  box.innerHTML = '';
  var base = (location.pathname.indexOf('/scripts/') >= 0 ? '' : 'scripts/') + 'stations.php?map=1';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
  var hidden = {};
  try { hidden = JSON.parse(localStorage.getItem('stn_map_hidden') || '{}') || {}; } catch (e) {}
  // a station is remembered by its MAC (two stations may share a name), else by its name
  var remember = function (key, off) {
    if (off) hidden[key] = 1; else delete hidden[key];
    try { localStorage.setItem('stn_map_hidden', JSON.stringify(hidden)); } catch (e) {}
  };
  // what the pin of a station says: name, location, connected or the time it was last seen; Open for another station
  var popup = function (s) {
    var wifi = typeof stationWifi === 'function' ? stationWifi(s.mac) : '';
    var h = '<b>' + esc(s.label) + '</b>' + (s.mac ? '<br><small>ID ' + esc(s.mac) + (wifi ? ' · ' + esc(wifi) : '') + '</small>' : '') + (s.where ? '<br>' + esc(s.where) : '') + '<br>';
    h += s.up ? '<span style="color:' + STATION_MAP_COLORS.up + '">' + esc(s.here ? t('This station') : t('Connected')) + '</span>'
      : '<span style="color:' + STATION_MAP_COLORS.down + '">' + esc(s.seen ? t('No answer; last seen {time}', {time: s.seen}) : t('No answer')) + '</span>';
    if (s.up && !s.here && s.address) h += '<br><a href="' + esc(location.protocol + '//' + s.address + '/') + '" target="_top">' + esc(t('Open')) + '</a>';
    return h;
  };
  note.textContent = t('Asking the stations where they are…');
  var x = new XMLHttpRequest();
  x.timeout = 20000;
  x.onload = function () {
    var j = null;
    try { j = JSON.parse(this.responseText); } catch (e) {}
    if (!j || !j.stations) { note.textContent = t('The map could not be loaded.'); return; }
    j.stations.forEach(function (s) {
      s.label = typeof stationLabel === 'function' ? stationLabel(s, j.stations) : s.name;
      s.key = s.mac || s.name;
    });
    var placed = j.stations.filter(function (s) { return s.lat !== null; });
    var none = j.stations.filter(function (s) { return s.lat === null; }).map(function (s) { return s.label; });
    note.innerHTML = '<span style="color:' + STATION_MAP_COLORS.up + '">&#9679;</span> ' + esc(t('connected, at its current location')) + ' &nbsp; '
      + '<span style="color:' + STATION_MAP_COLORS.down + '">&#9679;</span> ' + esc(t('no answer, at the last position it reported')) + ' &nbsp; '
      + '<span style="color:' + STATION_MAP_COLORS.place + '">&#9679;</span> ' + esc(t('other locations of the station'))
      + (none.length ? '<br>' + esc(t('No position known yet: {names}', {names: none.join(', ')})) : '');
    locationPickerLoad(function (ok) {
      var list = box.parentNode.querySelector('.stn-map-list');
      if (ok) stationMapLeaflet(box, list, placed, popup, hidden, remember);
      else stationMapOffline(box, list, placed, popup, hidden, remember);
    });
  };
  x.onerror = x.ontimeout = function () { note.textContent = t('The map could not be loaded.'); };
  x.open('GET', base, true);
  x.send();
}

// the list beside the map: per station its checkbox (the layer), state dot, name (a click centres the map on it), location
// and state, and its other locations in yellow (a click centres on one); on.toggle(s, shown), on.focus(lat, lon, s|null)
function stationMapList(el, list, hidden, remember, on) {
  var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
  el.innerHTML = '';
  list.forEach(function (s) {
    var row = document.createElement('div');
    row.className = 'stn-map-row';
    var color = s.up ? STATION_MAP_COLORS.up : STATION_MAP_COLORS.down;
    row.innerHTML = '<label><input type="checkbox"' + (hidden[s.key] ? '' : ' checked') + '></label>'
      + '<div><a href="#" class="stn-map-name"><span style="color:' + color + '">&#9679;</span> ' + esc(s.label) + '</a>'
      + '<div class="stn-map-sub">' + esc(s.where) + (s.where ? ' · ' : '') + esc(s.up ? (s.here ? t('This station') : t('Connected')) : (s.seen ? t('No answer; last seen {time}', {time: s.seen}) : t('No answer'))) + '</div>'
      + (s.places || []).map(function (p, i) {
        return '<a href="#" class="stn-map-place" data-p="' + i + '"><span style="color:' + STATION_MAP_COLORS.place + '">&#9679;</span> ' + esc(p.name) + '</a>';
      }).join('') + '</div>';
    row.querySelector('input').onchange = function () { remember(s.key, !this.checked); on.toggle(s, this.checked); };
    row.querySelector('.stn-map-name').onclick = function (e) { e.preventDefault(); on.focus(s.lat, s.lon, s); };
    row.querySelectorAll('.stn-map-place').forEach(function (a) {
      a.onclick = function (e) { e.preventDefault(); var p = s.places[parseInt(a.dataset.p, 10)]; on.focus(p.lat, p.lon, null); };
    });
    el.appendChild(row);
  });
}

function stationMapLeaflet(box, listEl, list, popup, hidden, remember) {
  var map = stationMapLeafletMap = L.map(box), views = locationPickerViews(), groups = {}, pins = {}, all = [];
  L.control.scale({position: 'bottomright', imperial: false}).addTo(map);  // the scale bar, as on the location map (owner 2026-10-29)
  views.chosen.addTo(map);
  list.forEach(function (s) {
    var g = L.layerGroup();
    (s.places || []).forEach(function (p) {
      L.circleMarker([p.lat, p.lon], {radius: 8, color: '#fff', weight: 2, fillColor: STATION_MAP_COLORS.place, fillOpacity: 1})
        .bindTooltip(String(p.name), {permanent: true, direction: 'right', offset: [7, 0]})
        .bindPopup('<b>' + String(p.name).replace(/</g, '&lt;') + '</b><br>' + String(s.label).replace(/</g, '&lt;')).addTo(g);
      all.push([p.lat, p.lon]);
    });
    pins[s.key] = L.circleMarker([s.lat, s.lon], {radius: 9, color: '#fff', weight: 2, fillColor: s.up ? STATION_MAP_COLORS.up : STATION_MAP_COLORS.down, fillOpacity: 1})
      .bindTooltip(String(s.label), {permanent: true, direction: 'right', offset: [8, 0]}).bindPopup(popup(s)).addTo(g);
    all.push([s.lat, s.lon]);
    groups[s.key] = g;
    if (!hidden[s.key]) g.addTo(map);
  });
  // on a phone the map views fold into the layers icon (the list of four covered a third of the map)
  L.control.layers(views.layers, null, {position: 'topright', collapsed: box.clientWidth < 600}).addTo(map);
  map.on('baselayerchange', function (e) { try { localStorage.setItem('lp_view', e.name); } catch (err) {} });
  stationMapList(listEl, list, hidden, remember, {
    toggle: function (s, shown) { if (shown) groups[s.key].addTo(map); else map.removeLayer(groups[s.key]); },
    focus: function (lat, lon, s) {
      map.setView([lat, lon], Math.max(map.getZoom(), 14));
      if (s && map.hasLayer(groups[s.key])) pins[s.key].openPopup();
    }
  });
  // more room at the right and the bottom: the names sit right of the dots, the Maximize button at the bottom right
  if (all.length > 1) map.fitBounds(all, {paddingTopLeft: [30, 30], paddingBottomRight: [130, 60], maxZoom: 14});
  else if (all.length) map.setView(all[0], 12);
  else map.setView([0, 0], 2);
  stationMapMaxButton(box);
  setTimeout(function () { map.invalidateSize(); }, 50);
}

// the offline map: the world's countries (model/maps, scripts/network.php?world=1), framed on the points; the same list
// beside it
function stationMapOffline(box, listEl, list, popup, hidden, remember) {
  var x = new XMLHttpRequest();
  x.onload = function () {
    var world = null;
    try { world = JSON.parse(this.responseText); } catch (e) {}
    var NS = 'http://www.w3.org/2000/svg', svg = document.createElementNS(NS, 'svg');
    svg.style.cssText = 'width:100%;height:100%;background:#dfeaf5;border-radius:8px';
    var pts = [];
    list.forEach(function (s) { pts.push([s.lon, -s.lat]); (s.places || []).forEach(function (p) { pts.push([p.lon, -p.lat]); }); });
    var vb = [-180, -84, 360, 144];
    if (pts.length) {
      var x0 = Math.min.apply(null, pts.map(function (p) { return p[0]; })), x1 = Math.max.apply(null, pts.map(function (p) { return p[0]; }));
      var y0 = Math.min.apply(null, pts.map(function (p) { return p[1]; })), y1 = Math.max.apply(null, pts.map(function (p) { return p[1]; }));
      var m = Math.max(x1 - x0, y1 - y0, 4) * 0.3;
      vb = [x0 - m, y0 - m, x1 - x0 + 2 * m, y1 - y0 + 2 * m];
    }
    svg.setAttribute('viewBox', vb.join(' '));
    var w = vb[2];
    (world && world.features || []).forEach(function (f) {
      var polys = f.geometry.type === 'MultiPolygon' ? f.geometry.coordinates : [f.geometry.coordinates], d = '';
      polys.forEach(function (poly) { poly.forEach(function (ring) { ring.forEach(function (c, i) { d += (i ? 'L' : 'M') + c[0] + ' ' + (-c[1]); }); d += 'Z'; }); });
      var path = document.createElementNS(NS, 'path');
      path.setAttribute('d', d); path.setAttribute('fill', '#f4f1e8'); path.setAttribute('stroke', '#7a8a99'); path.setAttribute('stroke-width', w / 450);
      svg.appendChild(path);
    });
    var dot = function (g, lon, lat, r, color, title) {
      var c = document.createElementNS(NS, 'circle');
      c.setAttribute('cx', lon); c.setAttribute('cy', -lat); c.setAttribute('r', w * r); c.setAttribute('fill', color);
      c.setAttribute('stroke', '#fff'); c.setAttribute('stroke-width', w / 500);
      var tt = document.createElementNS(NS, 'title'); tt.textContent = title; c.appendChild(tt);
      g.appendChild(c);
    };
    // a name beside a dot: drawn at a normal font size and scaled down to the map's units (a font size of a fraction of a
    // degree is raised to the browser's minimum and then magnified by the view box: one black glyph over the map)
    var label = function (x, y, px, text) {
      var el = document.createElementNS(NS, 'text');
      el.setAttribute('font-size', px); el.setAttribute('transform', 'translate(' + x + ' ' + y + ') scale(' + (w / 700) + ')');
      el.textContent = text;
      return el;
    };
    var layers = {};
    list.forEach(function (s) {
      var g = document.createElementNS(NS, 'g');
      (s.places || []).forEach(function (p) {
        dot(g, p.lon, p.lat, 1 / 110, STATION_MAP_COLORS.place, p.name + ' (' + s.label + ')');
        g.appendChild(label(p.lon + w / 80, -p.lat + w / 220, 11, p.name));
      });
      dot(g, s.lon, s.lat, 1 / 90, s.up ? STATION_MAP_COLORS.up : STATION_MAP_COLORS.down, popup(s).replace(/<br>/g, '\n').replace(/<[^>]+>/g, ''));
      g.appendChild(label(s.lon + w / 70, -s.lat + w / 200, 13, s.label));
      g.style.display = hidden[s.key] ? 'none' : '';
      svg.appendChild(g);
      layers[s.key] = g;
    });
    box.appendChild(svg);
    stationMapMaxButton(box);
    stationMapList(listEl, list, hidden, remember, {
      toggle: function (s, shown) { layers[s.key].style.display = shown ? '' : 'none'; },
      focus: function (lat, lon) { var z = Math.max(w / 6, 2); svg.setAttribute('viewBox', [lon - z / 2, -lat - z / 3, z, z * 2 / 3].join(' ')); }
    });
  };
  x.open('GET', (location.pathname.indexOf('/scripts/') >= 0 ? '' : 'scripts/') + 'network.php?world=1', true);
  x.send();
}
