//##############################################################################
// homepage/static/hearing-audio.js - THE HEARING AID IN THE BROWSER
//
// PURPOSE:
//   The hearing aid in the browser (owner 2026-11-04): the live sound is lowered here, on its way to the
//   speaker only, so the station sends one stream as recorded and every device chooses for itself
//   (window.BS_HEARING.on, kept in the browser). bsHearAudio(ctx) gives a piece of an audio graph, input ->
//   output: off it passes the sound through; on it cuts below BS_HEARING.base (two high-passes, 24 dB/octave,
//   on the real sound) and lowers every frequency by BS_HEARING.r with two crossfaded delay taps (a granular
//   shifter: simple, a little rough on large steps). update() follows a change.
//
// DEPENDENCIES:
//   - (none beyond the system)
//
// USAGE:
//   <script src="static/hearing-audio.js">, loaded by homepage/index.php, scripts/lib/ui.php,
//   scripts/spectrogram.php; named in homepage/static/live-audio.js, scripts/livestream.sh
//##############################################################################

(function () {
	if (window.bsHearAudio) return;
	window.bsHearAudio = function (ctx) {
		var input = ctx.createGain(), output = ctx.createGain();
		var hp1 = ctx.createBiquadFilter(), hp2 = ctx.createBiquadFilter();
		hp1.type = hp2.type = 'highpass';
		var proc = ctx.createScriptProcessor(2048, 1, 1);
		var N = 16384, buf = new Float32Array(N), w = 0, phase = 0, W = Math.round(ctx.sampleRate * 0.06), ratio = 1;
		function tap(d) {
			var p = w - d;
			while (p < 0) p += N;
			var i = Math.floor(p), f = p - i, a = buf[i & (N - 1)];
			return a + (buf[(i + 1) & (N - 1)] - a) * f;
		}
		// the read point falls behind the write point by (1 - ratio) per sample: the sound comes out ratio times lower; two
		// taps half a window apart, each faded out where its delay jumps back
		proc.onaudioprocess = function (e) {
			var x = e.inputBuffer.getChannelData(0), y = e.outputBuffer.getChannelData(0), step = (1 - ratio) / W;
			for (var i = 0; i < x.length; i++) {
				buf[w] = x[i];
				var p2 = phase + 0.5; if (p2 >= 1) p2 -= 1;
				var g = Math.sin(Math.PI * phase); g *= g;
				y[i] = g * tap(phase * W + 1) + (1 - g) * tap(p2 * W + 1);
				phase += step; if (phase >= 1) phase -= 1;
				w = (w + 1) & (N - 1);
			}
		};
		var state = '';
		function update() {
			var h = window.BS_HEARING || {}, on = !!h.on && h.r > 0 && h.r < 1, base = on ? Math.max(0, h.base || 0) : 0;
			var key = on + '|' + base;
			ratio = on ? h.r : 1;
			if (key === state) return;
			state = key;
			[input, hp1, hp2, proc].forEach(function (n) { try { n.disconnect(); } catch (e) {} });
			if (!on) { input.connect(output); return; }
			if (base > 0) { hp1.frequency.value = hp2.frequency.value = base; input.connect(hp1); hp1.connect(hp2); hp2.connect(proc); }
			else input.connect(proc);
			proc.connect(output);
		}
		update();
		return {input: input, output: output, update: update};
	};
})();
