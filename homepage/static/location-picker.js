//##############################################################################
// homepage/static/location-picker.js - LOCATION PICKER
//
// PURPOSE:
//   Location picker (owner 2026-10-20): a Map button beside a pair of latitude / longitude fields; the map
//   (Leaflet, three views: OpenStreetMap, OpenTopoMap relief, Esri satellite; loaded on first use, needs
//   internet) opens under the given element, centred on the fields or on the station. A click, or dragging the
//   pin, fills the fields (4 decimals); typing in them moves the pin. Without internet (in the field) an
//   offline world map shipped with the station takes its place (countries: Natural Earth 1:110m,
//   model/maps/world-countries.geojson; Brazilian states as detail): a click on a country zooms into it, the
//   next click picks the point — enough to place a station; type the exact coordinates when known. Usage:
//   locationPicker({lat: inputEl, lon: inputEl, button: buttonEl, box: elementTheMapGoesIn, home: [lat, lon],
//   open: true (map shown at once), locked: function () { return true while the point must not change },
//   unlock: function () { opens the point for editing (the GPS icon calls it when locked) }})
//
// DEPENDENCIES:
//   - scripts/gps.php
//
// USAGE:
//   <script src="static/location-picker.js">, loaded by scripts/external_files.php, scripts/gps.php,
//   scripts/locations.php, scripts/setup_wizard.php, scripts/stations.php; named in
//   homepage/static/station-map.js
//##############################################################################

var LOCATION_PICKER_LEAFLET = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/';
// subresource integrity of those two files (2026-10-23 test run; the sums cdnjs publishes for 1.9.4)
var LOCATION_PICKER_SRI = {
	js: 'sha512-puJW3E/qXDqYp9IfhAI54BJEaWIfloJ7JWs7OeD5i6ruC9JZL1gERT1wjtwXFlh7CjE7ZJ+/vcRZRkIYIb6p4g==',
	css: 'sha512-h9FcoyWjHcOcmEVkxOfTLnmZFWIH0iZhZT1H2TbOq55xssQGEJHEaIm+PgoUaZbRvQTNTluNOEfb1ZRy6D3BOw=='
};
// a "my location" target, drawn here (no icon font needed offline)
var LOCATION_PICKER_GPS_ICON = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#333" stroke-width="2"><circle cx="12" cy="12" r="7"/>'
	+ '<circle cx="12" cy="12" r="2.5" fill="#1f6feb" stroke="none"/><path d="M12 1v4M12 19v4M1 12h4M19 12h4"/></svg>';

function locationPickerLoad(done) {
	if (window.L) { done(true); return; }
	if (!document.getElementById('leafletCss')) {
		var css = document.createElement('link');
		css.id = 'leafletCss'; css.rel = 'stylesheet'; css.href = LOCATION_PICKER_LEAFLET + 'leaflet.min.css';
		css.integrity = LOCATION_PICKER_SRI.css; css.crossOrigin = 'anonymous';
		document.head.appendChild(css);
	}
	var s = document.createElement('script');
	s.src = LOCATION_PICKER_LEAFLET + 'leaflet.min.js';
	// a changed file at the CDN (or the captive page answering in its place) fails the check and lands in onerror: the
	// offline map then
	s.integrity = LOCATION_PICKER_SRI.js; s.crossOrigin = 'anonymous';
	// on the station WiFi every name answers with the station (captive page): loaded is not enough, Leaflet must exist
	s.onload = function () { done(!!window.L); };
	s.onerror = function () { done(false); };
	document.head.appendChild(s);
}

// the map views (owner 2026-10-20): map, relief, satellite, hybrid (satellite + roads and names), the names translated;
// chosen = the one remembered (the shown name, localStorage lp_view), Hybrid by default. Also used by the stations map.
function locationPickerViews() {
	var views = {};
	views[t('str_map')] = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'});
	views[t('str_relief')] = L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {maxZoom: 17,
			attribution: 'Map data &copy; OpenStreetMap contributors, SRTM | Style &copy; OpenTopoMap (CC-BY-SA)'});
	views[t('str_satellite')] = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {maxZoom: 19,
			attribution: 'Imagery &copy; Esri, Maxar, Earthstar Geographics, and the GIS User Community'});
	views[t('str_hybrid')] = L.layerGroup([
			L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {maxZoom: 19,
				attribution: 'Imagery &copy; Esri, Maxar, Earthstar Geographics, and the GIS User Community'}),
			L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Transportation/MapServer/tile/{z}/{y}/{x}', {maxZoom: 19}),
			L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', {maxZoom: 19})
		]);
	var chosen = t('str_hybrid');
	try { chosen = localStorage.getItem('lp_view') || chosen; } catch (e) {}
	return {layers: views, chosen: views[chosen] || views[t('str_hybrid')]};
}

function locationPicker(o) {
	var map = null, pin = null, wrap = null;
	var num = function (el) { var v = parseFloat(String(el.value).replace(',', '.')); return isFinite(v) ? v : null; };
	var locked = function () { return o.locked ? o.locked() : false; };
	function fill(lat, lon) {
		o.lat.value = lat.toFixed(4); o.lon.value = lon.toFixed(4);
		[o.lat, o.lon].forEach(function (i) { i.dispatchEvent(new Event('input', {bubbles: true})); i.dispatchEvent(new Event('change', {bubbles: true})); });
		preview();
	}
	// o.preview (owner 2026-10-29, Settings › Locations): what is under the point while it is fine-tuned, before Save —
	// biome, ecoregion, IBGE biome, land cover and setting of the point of the fields (map click, pin, GPS, typing)
	// the saved point: the coordinates in the fields when the page opened
	var previewTimer = null, previewSeq = 0, previewAt = (function () { var a = num(o.lat), b = num(o.lon); return a === null || b === null ? null : a.toFixed(4) + ',' + b.toFixed(4); })();
	function preview() {
		if (!o.preview || !wrap) return;
		var box = wrap.querySelector('.lp-biome'), lat = num(o.lat), lon = num(o.lon);
		clearTimeout(previewTimer);
		if (lat === null || lon === null || (lat === 0 && lon === 0)) { box.innerHTML = ''; return; }
		// the saved point (o.previewSaved, the values stored with the location): shown at once, nothing looked up
		if (o.previewSaved && lat.toFixed(4) + ',' + lon.toFixed(4) === previewAt) { previewSeq++; box.innerHTML = '&#127807; ' + o.previewSaved; return; }
		previewTimer = setTimeout(function () {
			var seq = ++previewSeq, x = new XMLHttpRequest();
			box.innerHTML = '<span style="opacity:.7">' + t('str_at_this_point_looking_up') + '</span>';
			x.onload = function () {
				if (seq !== previewSeq) return;  // a newer point is being looked up
				var r = {}; try { r = JSON.parse(this.responseText); } catch (e) {}
				box.innerHTML = r.html ? '&#127807; ' + t('str_at_this_point') + ' ' + r.html + (r.land ? '' : ' <span style="opacity:.7">' + t('str_land_cover_no_answer_online_only') + '</span>') : '';
			};
			x.open('GET', (location.pathname.indexOf('/scripts/') >= 0 ? '' : 'scripts/') + 'locations.php?biome=1&lat=' + lat + '&lon=' + lon, true);
			x.send();
		}, 900);
	}
	function place(lat, lon, move) {
		if (!pin) {
			// a name for the marker (2026-10-23 accessibility run: the pin image had none)
			pin = L.marker([lat, lon], {draggable: true, alt: (window.t ? window.t('str_location') : 'Location'), title: (window.t ? window.t('str_location') : 'Location')}).addTo(map);
			pin.on('dragend', function () { var p = pin.getLatLng(); if (locked()) { sync(); return; } fill(p.lat, p.lng); });
		} else pin.setLatLng([lat, lon]);
		if (pin.dragging) { if (locked()) pin.dragging.disable(); else pin.dragging.enable(); }
		if (move) map.setView([lat, lon], Math.max(map.getZoom(), 13));
	}
	function sync() {
		var lat = num(o.lat), lon = num(o.lon);
		if (map && lat !== null && lon !== null && Math.abs(lat) <= 90 && Math.abs(lon) <= 180 && !(lat === 0 && lon === 0)) place(lat, lon, true);
	}
	// the page calls this after a lock change or a value set from outside (pin draggable or not, pin at the fields)
	o.refresh = function () { sync(); if (pin && pin.dragging) { if (locked()) pin.dragging.disable(); else pin.dragging.enable(); } };
	// the device's GPS from outside the map (owner 2026-10-29: Auto Locate Now uses it first); fail() when no position came
	// (cancelled, refused, blocked), so the page can fall back to the network position
	o.gps = function (fail) { if (!wrap) o.button.click(); else if (wrap.style.display === 'none') o.button.click(); o.gpsRun(fail); };
	if (o.open) setTimeout(function () { o.button.click(); }, 0);
	o.lat.addEventListener('input', function (e) { if (e.isTrusted) { sync(); preview(); } });
	o.lon.addEventListener('input', function (e) { if (e.isTrusted) { sync(); preview(); } });
	o.button.addEventListener('click', function () {
		if (wrap) { wrap.style.display = wrap.style.display === 'none' ? '' : 'none'; if (wrap.style.display === '' && map) setTimeout(function () { map.invalidateSize(); }, 50); return; }
		wrap = document.createElement('div');
		// the GPS icon sits on the map, under the zoom buttons (the offline map gets the same icon)
		wrap.innerHTML = '<div style="position:relative;max-width:640px"><div class="lp-map" style="height:320px;border-radius:8px;margin:6px 0"></div>'
			+ '<button type="button" class="lp-gps" title="' + t('str_use_this_devices_position_gps') + '" style="position:absolute;left:10px;top:86px;z-index:1000;width:34px;height:34px;padding:0;'
			+ 'border-radius:4px;border:2px solid rgba(0,0,0,.25);background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center">' + LOCATION_PICKER_GPS_ICON + '</button></div>'
			+ '<small class="lp-gpsmsg"></small><div class="lp-biome small" style="margin:4px 0"></div>'
			+ '<div class="lp-note" style="font-size:12px;opacity:.8">' + t('str_click_the_map_or_drag_the_pin_help') + '</div>';
		o.box.appendChild(wrap);
		// the phone's or computer's own position (owner 2026-10-20); browsers allow it only on https pages (or localhost)
		wrap.querySelector('.lp-gps').onclick = function () { o.gpsRun(); };
		o.gpsRun = function (fail) {
			fail = fail || function () {};
			var msg = wrap.querySelector('.lp-gpsmsg');
			// a locked point is opened for editing by itself (owner 2026-10-24: "Press Adjust first" was not seen), when the
			// page offers it (o.unlock); else the message
			if (locked()) { if (o.unlock) o.unlock(); else { msg.textContent = t('str_press_adjust_first'); fail(); return; } }
			if (!window.isSecureContext && location.protocol === 'http:') { locationPickerHttpsGps(msg, got, fail); return; }
			if (!navigator.geolocation) { msg.textContent = t('str_this_browser_gives_no_position'); fail(); return; }
			msg.textContent = t('str_reading_the_position');
			navigator.geolocation.getCurrentPosition(function (p) { got(p.coords.latitude, p.coords.longitude, p.coords.accuracy); },
				function (e) { msg.textContent = t('str_no_position', {error: e.message}); fail(); }, {enableHighAccuracy: true, timeout: 30000});
		};
		function got(lat, lon, acc) {
			fill(lat, lon);
			if (map) place(lat, lon, true);
			else wrap.dispatchEvent(new CustomEvent('lp-point', {detail: [lat, lon]}));
			wrap.querySelector('.lp-gpsmsg').textContent = t('str_position_m', {m: Math.round(acc)});
			// the page may ask what to do with it (Settings › Locations: update the location now, owner 2026-11-03)
			if (o.onGps) o.onGps(lat, lon, acc);
		}
		locationPickerLoad(function (ok) {
			if (!ok) { locationPickerOffline(wrap, o, fill); return; }
			var lat = num(o.lat), lon = num(o.lon);
			var start = (lat !== null && lon !== null && !(lat === 0 && lon === 0)) ? [lat, lon] : (o.home || [0, 0]);
			// the registered location (the fields, else home); none = (0, 0) with the whole world in view (owner 2026-10-26)
			var known = !(start[0] === 0 && start[1] === 0);
			map = L.map(wrap.querySelector('.lp-map')).setView(start, known ? 13 : 2);
			// the scale bar at the bottom right, metres / kilometres (owner 2026-10-29: as in Google Maps)
			L.control.scale({position: 'bottomright', imperial: false}).addTo(map);
			var views = locationPickerViews();
			views.chosen.addTo(map);
			L.control.layers(views.layers, null, {position: 'topright', collapsed: false}).addTo(map);
			map.on('baselayerchange', function (e) { try { localStorage.setItem('lp_view', e.name); } catch (err) {} });
			map.on('click', function (e) { if (locked()) return; place(e.latlng.lat, e.latlng.lng, false); fill(e.latlng.lat, e.latlng.lng); });
			if (lat !== null && lon !== null && !(lat === 0 && lon === 0)) place(lat, lon, false);
			setTimeout(function () { map.invalidateSize(); }, 50);
			preview();
		});
	});
}

// the GPS on a station that runs on http (owner 2026-10-29, US-126): browsers give the position only to https pages, so
// after a notice (what the browser will say and what to do) https is switched on for a moment, a small https window
// (scripts/gps.php) reads the position and hands it back here, and https goes off again; the page keeps what was typed
function locationPickerHttpsGps(msg, got, fail) {
	fail = fail || function () {};
	var base = location.pathname.indexOf('/scripts/') >= 0 ? '' : 'scripts/';
	var text = t('str_the_browser_gives_the_devices_help') + '\n'
		+ t('str_a_small_window_opens') + ' '
		+ t('str_the_first_or_that_the_help') + '\n'
		+ t('str_this_is_expected_the_station_help') + '\n'
		+ t('str_click_advanced_and_then_proceed_help') + '\n'
		+ t('str_then_allow_the_browser_to_use_help') + '\n'
		+ t('str_the_window_closes_by_itself_the_help');
	askModal(t('str_use_the_devices_gps'), text, t('str_continue'), false, t('str_cancel')).then(function (ok) {
		if (!ok) { fail(); return; }
		// opened now, on the click, so no popup blocker stops it; it gets its address once https answers
		var w = window.open('', 'bsgps', 'width=440,height=380');
		if (!w) { msg.textContent = t('str_the_browser_blocked_the_window_help'); fail(); return; }
		try { w.document.body.innerHTML = '<p style="font-family:sans-serif">' + t('str_switching_https_on') + '</p>'; } catch (e) {}
		msg.textContent = t('str_switching_https_on');
		var off = function () { var x = new XMLHttpRequest(); x.open('POST', base + 'https_temp.php?off=1', true); x.send(); };
		var arrived = false;
		var onMsg = function (e) {
			if (e.origin !== 'https://' + location.host || !e.data || !e.data.bsgps) return;
			arrived = true;
			window.removeEventListener('message', onMsg);
			got(e.data.bsgps[0], e.data.bsgps[1], e.data.bsgps[2]);
			off();
		};
		window.addEventListener('message', onMsg);
		var x = new XMLHttpRequest();
		x.onload = function () {
			var r = {}; try { r = JSON.parse(this.responseText); } catch (e) {}
			if (this.status === 401) r.error = t('str_log_in_first');
			if (!r.ok) { try { w.close(); } catch (e) {} window.removeEventListener('message', onMsg); msg.textContent = r.error || t('str_cannot_switch_https_on'); fail(); return; }
			msg.textContent = t('str_waiting_for_the_position_in_the_help');
			w.location.href = 'https://' + location.host + '/scripts/gps.php';
			// the window closed without a position: https off
			var watch = setInterval(function () {
				if (!w.closed) return;
				clearInterval(watch);
				if (arrived) return;
				window.removeEventListener('message', onMsg);
				off();
				if (msg.textContent === t('str_waiting_for_the_position_in_the_help')) msg.textContent = '';
				fail();
			}, 1000);
		};
		x.onerror = function () { try { w.close(); } catch (e) {} msg.textContent = t('str_cannot_switch_https_on'); fail(); };
		x.open('POST', base + 'https_temp.php?on=1', true);
		x.send();
	});
}

// the offline map: SVG, equirectangular (x = lon, y = -lat) — the world's countries (Natural Earth 1:110m, shipped in
// model/maps) with the Brazilian states as detail; a click on a country zooms into it, the next click picks the point
function locationPickerOffline(wrap, o, fill) {
	var note = wrap.querySelector('.lp-note'), box = wrap.querySelector('.lp-map');
	var base = (location.pathname.indexOf('/scripts/') >= 0 ? '' : 'scripts/') + 'network.php?';
	note.textContent = t('str_offline_map_no_internet_click_a_help');
	function get(q, done) {
		var x = new XMLHttpRequest();
		x.onload = function () { var g = null; try { g = JSON.parse(this.responseText); } catch (e) {} done(g); };
		x.onerror = function () { done(null); };
		x.open('GET', base + q, true); x.send();
	}
	get('world=1', function (world) {
		if (!world) { note.textContent = t('str_no_map_available_offline_type_help'); return; }
		var NS = 'http://www.w3.org/2000/svg', svg = document.createElementNS(NS, 'svg'), full = [-180, -84, 360, 144];
		svg.style.cssText = 'width:100%;height:100%;background:#dfeaf5;border-radius:8px;cursor:crosshair';
		var pin = document.createElementNS(NS, 'circle');
		pin.setAttribute('fill', '#c62828'); pin.setAttribute('stroke', '#fff'); pin.style.display = 'none';
		var zoomed = false, layer = document.createElementNS(NS, 'g'), detail = document.createElementNS(NS, 'g');
		detail.style.pointerEvents = 'none';
		function pathOf(f) {
			var polys = f.geometry.type === 'MultiPolygon' ? f.geometry.coordinates : [f.geometry.coordinates];
			var d = '', bb = [999, 999, -999, -999];
			polys.forEach(function (poly) { poly.forEach(function (ring) {
				ring.forEach(function (c, i) { d += (i ? 'L' : 'M') + c[0] + ' ' + (-c[1]);
					bb[0] = Math.min(bb[0], c[0]); bb[1] = Math.min(bb[1], -c[1]); bb[2] = Math.max(bb[2], c[0]); bb[3] = Math.max(bb[3], -c[1]); });
				d += 'Z'; }); });
			return {d: d, bb: bb};
		}
		function setView(vb) {
			svg.setAttribute('viewBox', vb.join(' '));
			var w = vb[2];
			svg.querySelectorAll('path').forEach(function (p) { p.setAttribute('stroke-width', w / (p.parentNode === detail ? 700 : 450)); });
			pin.setAttribute('r', w / 90); pin.setAttribute('stroke-width', w / 450);
		}
		world.features.forEach(function (f) {
			var pb = pathOf(f), path = document.createElementNS(NS, 'path');
			path.setAttribute('d', pb.d); path.setAttribute('fill', '#f4f1e8'); path.setAttribute('stroke', '#7a8a99');
			var ttl = document.createElementNS(NS, 'title'); ttl.textContent = f.properties.name || ''; path.appendChild(ttl);
			path.addEventListener('click', function (e) {
				if (zoomed) return;
				e.stopPropagation(); zoomed = true;
				var bb = pb.bb, w = bb[2] - bb[0], h = bb[3] - bb[1];
				if (w > 200) { bb = [bb[0], bb[1], bb[0] + 200, bb[3]]; w = 200; } // a country across the date line
				var m = Math.max(w, h) * 0.08;
				setView([bb[0] - m, bb[1] - m, w + 2 * m, h + 2 * m]);
				note.textContent = t('str_click_the_place_double_click_help', {country: f.properties.name || ''});
			});
			layer.appendChild(path);
		});
		svg.appendChild(layer); svg.appendChild(detail); svg.appendChild(pin);
		// the Brazilian states, drawn as detail over the countries
		get('states=1', function (states) {
			(states && states.features || []).forEach(function (f) {
				var path = document.createElementNS(NS, 'path');
				path.setAttribute('d', pathOf(f).d); path.setAttribute('fill', 'none'); path.setAttribute('stroke', '#a8b4bf');
				detail.appendChild(path);
			});
			setView(svg.getAttribute('viewBox').split(' ').map(Number));
		});
		svg.addEventListener('click', function (e) {
			if (!zoomed || (o.locked && o.locked())) return;
			var p = svg.createSVGPoint(); p.x = e.clientX; p.y = e.clientY;
			var q = p.matrixTransform(svg.getScreenCTM().inverse());
			pin.setAttribute('cx', q.x); pin.setAttribute('cy', q.y); pin.style.display = '';
			fill(-q.y, q.x);
		});
		svg.addEventListener('dblclick', function () { zoomed = false; setView(full); note.textContent = t('str_click_a_country_to_zoom_in_then_help'); });
		wrap.addEventListener('lp-point', function (e) { var ll = e.detail; pin.setAttribute('cx', ll[1]); pin.setAttribute('cy', -ll[0]); pin.style.display = ''; });
		setView(full);
		box.appendChild(svg);
		var lat = parseFloat(o.lat.value), lon = parseFloat(o.lon.value);
		if (isFinite(lat) && isFinite(lon) && !(lat === 0 && lon === 0)) { pin.setAttribute('cx', lon); pin.setAttribute('cy', -lat); pin.style.display = ''; }
	});
}
