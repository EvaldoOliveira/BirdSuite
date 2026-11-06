//##############################################################################
// homepage/static/live-audio.js - LIVE AUDIO
//
// PURPOSE:
//   Live Audio (owner 2026-10-10): the station's microphone, from the header of every page. One click listens,
//   another stops; while it plays the button is red and a speaker on its left opens a vertical volume slider
//   (the player itself stays hidden since 2026-10-21: on a phone it slid under the page and could not be
//   stopped). It plays in place: the page below is not reloaded. The volume is remembered in this browser.
//   /stream asks for the login when the station has a web password.
//
// DEPENDENCIES:
//   - homepage/static/hearing-audio.js
//
// USAGE:
//   <script src="static/live-audio.js">, loaded by homepage/index.php
//##############################################################################

(function () {
	function el(id) { return document.getElementById(id); }
	function storedVolume() {
		var v = 0.8;
		try { v = parseFloat(localStorage.getItem('now_live_volume') || '0.8'); } catch (e) {}
		return isNaN(v) ? 0.8 : Math.min(1, Math.max(0, v));
	}
	function setIcon(a) {
		el('live_volbtn').innerHTML = a.volume === 0 ? '&#128263;' : (a.volume < 0.5 ? '&#128265;' : '&#128266;');
	}
	window.liveVolume = function (v) {
		var a = el('live_audio');
		a.volume = parseFloat(v);
		a.muted = a.volume === 0;
		try { localStorage.setItem('now_live_volume', String(a.volume)); } catch (e) {}
		setIcon(a);
	};
	window.liveVolumeToggle = function () {
		el('live_volrange').value = storedVolume();
		el('live_volpop').classList.toggle('open');
	};
	function stop(a) {
		a.pause(); a.removeAttribute('src'); a.load();
		a.style.display = 'none';
		el('live_vol').style.display = 'none';
		el('live_volpop').classList.remove('open');
		var b = el('live_btn'); b.innerHTML = '&#9654;'; b.classList.remove('on');
	}
	window.liveAudio = function () {
		var a = el('live_audio');
		if (a.paused) {
			a.src = '/stream?' + Date.now();   // a fresh connection: the live point, not a stale buffer
			el('live_vol').style.display = ''; // the player stays hidden (owner 2026-10-21): the red button stops, the speaker sets the volume
			a.volume = storedVolume(); setIcon(a);
			liveRoute();
			if (liveChain && liveChain.ctx.state === 'suspended') liveChain.ctx.resume();
			a.play().catch(function () { stop(a); });
		} else {
			stop(a);
		}
	};
	document.addEventListener('click', function (e) {
		var pop = el('live_volpop');
		if (pop && pop.classList.contains('open') && !e.target.closest('#live_vol')) pop.classList.remove('open');
	});
	// the hearing aid (owner 2026-11-04): switched here, for this device (kept in the browser). On = what goes to the
	// speaker is lowered: the live sound in the browser (static/hearing-audio.js), every clip through play.php?lowered=
	// (nothing saved). Every change goes down to the page and its frames (bs:hearing): the Sonogram's sound and scale, the
	// review player's scale and sound
	window.hearSend = function () {
		var h = window.BS_HEARING;
		if (liveChain) liveChain.update();
		hearPaint();
		document.dispatchEvent(new CustomEvent('bs:hearing', {detail: h}));
		document.querySelectorAll('iframe').forEach(function (f) { try { f.contentWindow.postMessage({bsHearing: h}, location.origin); } catch (e) {} });
	};
	// the header player goes through the browser's lowering once the hearing aid is on (built inside a click: browsers
	// allow sound only from the user's gesture); built once, it stays (off = it passes the sound through)
	var liveChain = null;
	function liveRoute() {
		var a = el('live_audio');
		if (liveChain || !a || !(window.BS_HEARING || {}).on || !window.bsHearAudio) return;
		try {
			var ctx = new (window.AudioContext || window.webkitAudioContext)();
			var src = ctx.createMediaElementSource(a);
			liveChain = bsHearAudio(ctx);
			src.connect(liveChain.input); liveChain.output.connect(ctx.destination);
			liveChain.ctx = ctx;
		} catch (e) { liveChain = null; }
	}
	function hearPaint() {
		var h = window.BS_HEARING || {}, b = el('hear_btn'), g = el('hear_gear');
		if (!b) return;
		b.classList.toggle('on', !!h.on); b.setAttribute('aria-pressed', h.on ? 'true' : 'false');
		g.hidden = !h.on;
		if (!h.on && window.bsHearing) bsHearing.close();
	}
	window.hearToggle = function () {
		var h = window.BS_HEARING = window.BS_HEARING || {on: false, r: 1, base: 0, v: ''};
		h.on = !h.on;
		try { localStorage.setItem('bs_hearing', h.on ? '1' : '0'); } catch (e) {}
		liveRoute();
		if (liveChain && liveChain.ctx.state === 'suspended') liveChain.ctx.resume();
		hearSend();
	};
	window.hearConfig = function () {
		if (!window.bsHearing) return;
		bsHearing.toggle({place: function (box) {
			var r = el('hear_gear').getBoundingClientRect();
			box.style.top = (r.bottom + 6) + 'px';
			box.style.left = Math.max(8, Math.min(window.innerWidth - box.offsetWidth - 8, r.right - box.offsetWidth)) + 'px';
		}, onSaved: function (st, say) {
			var h = window.BS_HEARING;
			h.r = st.r; h.base = st.base; h.v = String(Date.now());
			hearSend();
		}});
	};
	document.addEventListener('DOMContentLoaded', hearPaint);
	// going Back to the page, or the browser restoring it, must not leave the sound running by itself
	window.addEventListener('pageshow', function (e) { var a = el('live_audio'); if (e.persisted && a && !a.paused) stop(a); });
	// the button follows the player itself (its own controls can pause and resume it too)
	document.addEventListener('DOMContentLoaded', function () {
		var a = el('live_audio'), b = el('live_btn');
		if (!a || !b) return;
		a.addEventListener('playing', function () { b.innerHTML = '&#9632;'; b.classList.add('on'); });
		a.addEventListener('pause', function () { b.innerHTML = '&#9654;'; b.classList.remove('on'); });
	});
})();
