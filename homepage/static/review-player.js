// Review player (owner 2026-10-09): the detection's spectrogram, playing, with the review answers on the right.
// An answer is saved and the next detection of the same list starts at once, so a list is reviewed in a few clicks.
// Items are any elements with data-ri (data-file = "<date>/<folder>/<file>", data-clip = "/By_Date/...",
// data-label = title line) inside the closest [data-review-list] (or the page). Their button.validatebtn shows the
// verdict and is updated. Needs static/custom-audio-player.js. Keys: Y N U C, → next, ← previous, Esc close.
// Change species asks which bird it was (the station's model re-analyses the clip: scripts/clip_alternatives.py) or
// the cause (insect, dog, rain...), optionally for every unreviewed detection of that species in the same hour.
// Closing the player in a review started from Curation goes back to Curation, at the species reviewed (owner 2026-10-20).
// Speed: the clip repeats until paused (Loop 3 s: only the detected 3 s), 1.5x playback, the next clip preloaded, swipe right = Yes / left = Change species.
// Refined 2026-10-09: reviewed detections skipped when advancing, progress bar with one mark per detection, Undo of the
// last answer or removal for 5 s, the detected 3 s highlighted on the spectrogram, a shortcuts line, the answers as a
// bottom bar on a phone, and the spectrogram without its title in the palette / floor / range / contrast chosen here
// (redrawn by scripts/review_worker.py, which also keeps the model loaded for "Which bird was it?").
// US-122: window.t comes from ui_js() in the page head; a page opened on its own without it shows the English text
if (typeof window.t !== 'function') window.t = function (s, v) { if (v) for (var k in v) s = s.split('{' + k + '}').join(v[k]); return s; };
var REVIEW_OPTS = [
  // the answers without explanations under them (owner 2026-10-22)
  ['yes', '✓', '#2e7d32', t('Yes, this bird'), '', 'Y'],
  ['no', '✗', '#c62828', t('Change species'), '', 'N'],  // was "Not this bird" (owner 2026-10-22)
  ['unsure', '?', '#9e9e9e', t('Review later'), '', 'R'],  // R (owner 2026-10-23, was U)  // the answer "unsure" shown as Review later (owner 2026-10-22)
  ['clear', '↺', '#607d8b', t('Clear the review'), '', 'C']  // no explanation under it (owner 2026-10-22)
];
var REVIEW_LABELS = {yes: '✓ ' + t('Yes'), no: '✗ ' + t('Change species'), unsure: '? ' + t('Review later'), clear: t('Review')};
// the Review button names the answer chosen (owner 2026-10-22): the cause in red, Yes / Changed in green, Unknown bird in grey
function reviewLabel(v, reason) {
  if (v === 'yes' && reason === 'changed') return '✓ ' + t('Changed');
  if (v === 'no' && reason) { var c = REVIEW_CAUSES.concat([['mechanical', t('Mechanical / noise')]], REVIEW_OLD_CAUSES).filter(function (x) { return x[0] === reason; })[0]; if (c) return '✗ ' + c[1]; }
  return REVIEW_LABELS[v];
}
// the causes of the Change species panel are the same as the quick buttons (owner 2026-10-22: one set of names); the
// older causes stay readable on answers given before
var REVIEW_CAUSES = [['dog', t('Dog')], ['human', t('Human')], ['engine', t('Engine')], ['noise', t('Noise')], ['ignore', t('Ignore')]];
var REVIEW_OLD_CAUSES = [['insect', t('Insect')], ['rain', t('Rain / wind')], ['other bird', t('Another bird (unknown)')], ['unknown', t('Unknown')]];
// one-click causes under the answers (owner 2026-10-22): Dog, Human, Engine, Noise as half-width buttons, Ignore under
// the player; another species through Change species
// two rows of half-width answer buttons (owner 2026-10-22); Ignore lives only in the button under the player
var REVIEW_QUICK = [['dog', t('Dog')], ['human', t('Human')], ['engine', t('Engine')], ['noise', t('Noise')]];

function reviewPlayerStyle() {
  if (document.getElementById('rpStyle')) return;
  var st = document.createElement('style');
  st.id = 'rpStyle';
  st.textContent = '#rpDialog{position:fixed;inset:0;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;z-index:1000}'
    + '#rpDialog .rp-box{background:#1b1b1b;border-radius:10px;padding:10px;width:min(calc(100% - 20px),calc((100vh - 190px) * 1.672 + 302px));max-height:calc(100% - 20px);overflow:auto;display:flex;gap:12px;position:relative}'
    + '#rpDialog .rp-left{flex:1 1 auto;min-width:0}#rpDialog .rp-title{display:grid;grid-template-columns:1fr auto 1fr;align-items:start;gap:12px;margin:0 30px 6px 4px;color:#fff}'
    + '#rpDialog .rp-when{font-size:16px;color:#ddd}#rpDialog .rp-tc{text-align:center}#rpDialog .rp-com{font-size:19px;font-weight:600}'
    + '#rpDialog .rp-tr{text-align:right}#rpDialog .rp-sci{font-style:italic;font-size:14px;color:#ddd}'
    + '#rpDialog .rp-bottom{display:flex;justify-content:flex-end;align-items:flex-end;gap:10px;flex-wrap:nowrap}'
    + '#rpDialog .rp-right{display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex:0 0 auto}'
    + '#rpDialog .rp-folder{color:#fff;background:#2a2a2a;border:1px solid #555;border-radius:6px;padding:4px 9px;font-size:12px;text-decoration:none;white-space:nowrap}'
    + '#rpDialog .rp-folder:hover{border-color:#d97a00}'
    // phone only (owner 2026-10-18): Spectrogram / Audio buttons fold their settings, a Previous · Delete · Next row
    + '#rpDialog .rp-tg,#rpDialog .rp-mbar{display:none}'
    + '#rpDialog .rp-tg{width:auto;background:#2a2a2a;color:#fff;border:1px solid #555;border-radius:6px;padding:4px 9px;font-size:12px;cursor:pointer;white-space:nowrap}'
    + '#rpDialog .rp-tg.on{border-color:#d97a00}'
    + '#rpDialog.rp-leaving .rp-box{opacity:.35;pointer-events:none}#rpDialog.rp-leaving .rp-opts{opacity:.35;pointer-events:none}'
    + '#rpDialog .rp-leavemsg{position:fixed;left:50%;top:40%;transform:translate(-50%,-50%);background:#1b1b1b;color:#fff;border:1px solid #d97a00;border-radius:10px;padding:14px 18px;font-size:16px;z-index:1002;max-width:85%;text-align:center;box-shadow:0 4px 18px rgba(0,0,0,.5)}'
    + '#rpDialog .rp-leavebtns{display:flex;justify-content:center;gap:10px;margin-top:12px}'
    + '#rpDialog .rp-leavebtns button{width:auto;min-width:90px;padding:8px 16px;border-radius:16px;border:1px solid #fff;background:#2a2a2a;color:#fff;font-weight:600;font-size:15px;cursor:pointer}'
    + '#rpDialog .rp-vol{display:flex;align-items:center;gap:6px;margin:0 4px 2px;flex:0 0 auto}'
    + '#rpDialog .rp-vol button{width:auto;background:#2a2a2a;color:#fff;border:1px solid #555;border-radius:6px;padding:6px 9px;cursor:pointer;font-size:14px}'
    + '#rpDialog .rp-vol button:hover{border-color:#d97a00}#rpDialog .rp-vol button.on{background:#c62828;border-color:#c62828}'
    + '#rpDialog .rp-vollevel{color:#bbb;font-size:12px;min-width:3em}'
    + '#rpDialog .rp-bigconf{display:flex;align-items:baseline;gap:8px;margin:2px 0 6px}#rpDialog .rp-bigconf small{color:#555;font-size:14px}#rpDialog .rp-bigconf b{font-size:34px;line-height:1}'
    + '#rpDialog .rp-sppage{margin-left:auto;align-self:center;display:inline-flex;padding:4px;border-radius:6px}#rpDialog .rp-sppage:hover{background:#eee}#rpDialog .rp-sppage{gap:6px;align-items:center;color:#333;text-decoration:none;font-size:14px;border:1px solid #ccc}#rpDialog .rp-sppage img{width:22px;height:22px}#rpDialog .rp-spcell{display:none}#rpDialog .rp-params i{font-style:normal}#rpDialog .rp-lph{display:none}'
    + '#rpDialog .rp-params{display:flex;flex-wrap:nowrap;gap:5px;margin:0 4px 6px;color:#fff;min-width:0}'
    + '#rpDialog .rp-params span{display:flex;flex-direction:column;background:#2a2a2a;border-radius:6px;padding:4px 7px;flex:1 1 auto;min-width:0}'
    + '#rpDialog .rp-params small{color:#aaa;font-size:10.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}#rpDialog .rp-params b{font-size:14px;white-space:nowrap}'
    + '#rpDialog .rp-params b small.rp-min{display:inline;font-size:11px;color:#bbb;font-weight:normal}#rpDialog .rp-params span{flex:0 1 auto}#rpDialog .rp-params .rp-pr1{margin-left:auto}#rpDialog .rp-params .rp-pr{align-items:flex-end}'
    + '#rpDialog .rp-close{position:absolute;top:6px;right:8px;width:auto;min-width:32px;min-height:32px;background:none;border:none;color:#fff;font-size:22px;cursor:pointer}'
    + '#rpDialog .rp-side{flex:0 0 270px;background:#fff;color:#000;border-radius:8px;padding:10px;display:flex;flex-direction:column;gap:7px;text-align:left;font-size:13px;margin-top:26px}'
    + '#rpDialog .rp-now{font-size:12px;color:#444}'
    + '#rpDialog .rp-opt{display:flex;align-items:center;gap:10px;width:100%;text-align:left;padding:6px 8px;border-radius:8px;border:1px solid #ccc;background:#fff;color:#000;cursor:pointer;font-size:13px;line-height:1.3}'
    + '#rpDialog .rp-opt:hover,#rpDialog .rp-opt:focus{border-color:#d97a00;background:#fff7ec;outline:none}'
    + '#rpDialog .rp-opt.current{border:2px solid var(--accent,#2b5e22)}'
    + '#rpDialog .rp-qrow{display:flex;gap:6px;margin-top:6px}#rpDialog .rp-qrow .rp-opt{flex:1 1 0;width:auto;margin:0}'
    + '#rpDialog .rp-ico{flex:0 0 30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:17px;font-weight:bold}'
    + '#rpDialog .rp-opt b{display:block}#rpDialog .rp-opt .rp-txt{font-size:11.5px;color:#444}#rpDialog .rp-key{margin-left:auto;font-size:11px;color:#888;border:1px solid #ccc;border-radius:4px;padding:0 5px}'
    + '#rpDialog .rp-nav{display:flex;justify-content:space-between;gap:6px;margin-top:4px}'
    + '#rpDialog .rp-nav button{flex:1;width:auto;padding:5px 8px;border-radius:14px;border:1px solid var(--accent,#2b5e22);background:#fff;color:var(--accent,#2b5e22);font-weight:600;cursor:pointer}'
    + '#rpDialog .rp-nav button:disabled{opacity:.35;cursor:default}'
    + '#rpDialog .rp-msg{font-size:12px;color:var(--accent,#2b5e22);min-height:1.2em}#rpDialog .rp-msg:empty{display:none}'
    + '#rpDialog .rp-tools{display:flex;justify-content:flex-end;gap:12px;margin:6px 0 0}#rpDialog .rp-tools img{width:24px;height:24px;cursor:pointer}'
    + '#rpDialog .rp-tools img:hover{transform:scale(1.15)}'
    + '#rpDialog .rp-delete{margin-top:8px;width:100%;padding:7px;border-radius:8px;border:1px solid #c62828;background:#fff;color:#c62828;font-weight:600;cursor:pointer}'
    + '#rpDialog .rp-delete:hover{background:#c62828;color:#fff}'
    + '#rpDialog .rp-not{display:none;flex-direction:column;gap:6px}#rpDialog.notmode .rp-not{display:flex}#rpDialog.notmode .rp-main{display:none}'
    + '#rpDialog .rp-not h4{margin:2px 0;font-size:14px}#rpDialog .rp-sub{font-size:11.5px;color:#555;margin-top:4px}'
    + '#rpDialog .rp-alt{display:flex;justify-content:space-between;align-items:center;gap:6px;width:100%;text-align:left;padding:5px 8px;border-radius:7px;border:1px solid #ccc;background:#fff;color:#000;cursor:pointer;font-size:12.5px}'
    + '#rpDialog .rp-alt:hover{border-color:#d97a00;background:#fff7ec}#rpDialog .rp-alt i{color:#555}#rpDialog .rp-alt small{color:#666;white-space:nowrap}'
    + '#rpDialog .rp-alt.far{opacity:.6}#rpDialog .rp-alts .rp-wait{font-size:12px;color:#666}'
    + '#rpDialog .rp-search{width:100%;padding:5px 7px;font-size:12.5px;border:1px solid #bbb;border-radius:6px;box-sizing:border-box}'
    + '#rpDialog .rp-causes{display:flex;flex-wrap:wrap;gap:5px}#rpDialog .rp-cause{width:auto;padding:4px 8px;border-radius:12px;border:1px solid #c62828;background:#fff;color:#c62828;font-size:12px;cursor:pointer}'
    + '#rpDialog .rp-cause:hover{background:#c62828;color:#fff}#rpDialog .rp-quick{display:flex;flex-wrap:wrap;gap:4px;align-items:center;margin-top:6px}#rpDialog .rp-quick .rp-cause{padding:3px 7px;font-size:11px}#rpDialog .rp-quick small{color:#c62828;font-weight:600}#rpDialog .rp-qother{width:auto;padding:3px 7px;border-radius:12px;border:1px solid #c62828;background:#fff;color:#c62828;font-size:11px;cursor:pointer}#rpDialog .rp-qother:hover{background:#c62828;color:#fff}#rpDialog .rp-batch{font-size:12px;display:flex;gap:6px;align-items:flex-start}'
    + '#rpDialog .rp-back{width:auto;align-self:flex-start;padding:4px 12px;border-radius:12px;border:1px solid var(--accent,#2b5e22);background:#fff;color:var(--accent,#2b5e22);cursor:pointer;font-weight:600}'
    + '#rpDialog .rp-toolsrow{display:flex;justify-content:space-between;align-items:center;gap:8px;margin:6px 4px 0}'
    + '#rpDialog .rp-speed{display:flex;gap:6px}#rpDialog .rp-speed button{width:auto;white-space:nowrap;background:#2a2a2a;color:#fff;border:1px solid #555;border-radius:6px;padding:4px 9px;cursor:pointer;font-size:12px}'
    + '#rpDialog .rp-speed button.on{background:var(--accent,#2b5e22);border-color:var(--accent,#2b5e22)}'
    + '#rpDialog .rp-rates{display:inline-flex;margin-left:4px}#rpDialog .rp-rates button{border-radius:0;margin-left:-1px}'
    + '#rpDialog .rp-rates button:first-child{border-radius:6px 0 0 6px}#rpDialog .rp-rates button:last-child{border-radius:0 6px 6px 0}'
    + '#rpDialog .rp-player img.rp-crop{margin-top:-2.48%}'
    // the play bar below the spectrogram, never over it (owner 2026-10-31: it hid an important part of the picture);
    // always shown, so the picture does not move when it appears
    + '#rpDialog .rp-player .cap-controls{position:static!important;display:flex!important;height:32px!important;box-sizing:border-box;background:#1e1e1e!important;backdrop-filter:none!important;-webkit-backdrop-filter:none!important}'
    + '#rpDialog .rp-player .cap-indicator{bottom:32px!important}'
    + '#rpDialog .rp-spec{display:flex;align-items:center;gap:6px;color:#bbb;font-size:12px;flex-wrap:wrap}'
    + '#rpDialog .rp-spec select,#rpDialog .rp-spec input{background:#2a2a2a;color:#fff;border:1px solid #555;border-radius:6px;padding:3px 5px;font-size:12px;min-height:26px;box-sizing:border-box}'
    + '#rpDialog .rp-spec label{font-weight:normal;font-size:12px;color:#bbb;display:flex;align-items:center;gap:4px;margin:0}#rpDialog .rp-spec input{width:4.4em}#rpDialog .rp-spec button{width:auto;background:#2a2a2a;color:#fff;border:1px solid #555;border-radius:6px;padding:3px 8px;cursor:pointer;font-size:12px}'
    + '#rpDialog .rp-tl2{display:flex;align-items:center;gap:10px;flex-wrap:wrap}'
    + '#rpDialog .rp-keys{color:#999;font-size:11.5px;margin:6px 4px 0}#rpDialog .rp-keys kbd{background:#2a2a2a;border:1px solid #555;border-radius:4px;padding:0 4px;color:#ddd;font-family:inherit}'
    + '#rpDialog .rp-prog small{color:#444;font-size:12px}#rpDialog .rp-bar{display:flex;gap:1px;height:9px;margin-top:3px}'
    + '#rpDialog .rp-bar i{flex:1 1 0;min-width:0;background:#dfe3e0;border-radius:1px;cursor:pointer}'
    + '#rpDialog .rp-bar i.yes{background:#2e7d32}#rpDialog .rp-bar i.no{background:#c62828}#rpDialog .rp-bar i.unsure{background:#9e9e9e}'
    + '#rpDialog .rp-bar i.cur{box-shadow:0 0 0 2px #d97a00;position:relative;z-index:1}'
    + '#rpDialog .rp-skip{font-size:12px;display:flex;gap:6px;align-items:center;color:#333}'
    // 24 px touch targets (WCAG 2.5.8, 2026-10-23 run 3): the skip box with its spacing, the spectrogram reset button
    + '#rpDialog .rp-skipbox{width:18px;height:18px;margin:3px}#rpDialog .rp-spec button.rp-specreset{min-height:26px;min-width:28px}'
    + '#rpDialog .rp-undo{width:auto;margin-left:6px;padding:1px 9px;border-radius:10px;border:1px solid var(--accent,#2b5e22);background:var(--accent,#2b5e22);color:#fff;cursor:pointer;font-size:12px}'
    + '@media (max-width:800px){#rpDialog .rp-params,#rpDialog .rp-bottom{flex-wrap:wrap}#rpDialog .rp-box{flex-direction:column;width:100%;max-height:100%;border-radius:0;padding:8px 8px 96px}'
    + '#rpDialog .rp-side{flex:0 0 auto;margin-top:0}#rpDialog .rp-keys{display:none}#rpDialog .rp-toolsrow{flex-wrap:wrap}'
    // phone: only Conf., Manual Conf., Prob. and Min. Prob. (owner 2026-10-18)
    + '#rpDialog .rp-params .rp-pr{display:none}#rpDialog .rp-params span{flex:1 1 0}'
    + '#rpDialog .rp-tg{display:inline-block}#rpDialog:not(.show-spec) .rp-spec{display:none}'
    + '#rpDialog:not(.show-audio) .rp-speed,#rpDialog:not(.show-audio) .rp-bottom{display:none}'
    + '#rpDialog .rp-box{padding-bottom:150px}#rpDialog .rp-side .rp-nav:not(.rp-spnav),#rpDialog .rp-side>.rp-delete{display:none}'
    + '#rpDialog .rp-opts{flex-wrap:wrap}#rpDialog .rp-mbar{display:flex;flex:0 0 100%;gap:6px}#rpDialog .rp-mspnav{display:none;order:2}#rpDialog .rp-mspnav.on{display:flex}'
    // the species buttons only in the fixed bar; the progress on top of it
    + '#rpDialog .rp-side .rp-spnav{display:none}#rpDialog .rp-opts>.rp-prog{flex:0 0 100%;order:-1;margin:0 0 2px;display:flex;align-items:center;gap:8px}#rpDialog .rp-opts>.rp-prog small{color:#ddd;white-space:nowrap;font-weight:600}#rpDialog .rp-opts>.rp-prog .rp-bar{flex:1 1 auto;margin-top:0}'
    // Previous / Next species right under the progress, above the answers (owner 2026-10-25); Previous / Ignore / Next last
    + '#rpDialog .rp-opts .rp-mbar.rp-mspnav{order:0;margin-bottom:2px}'
    + '#rpDialog .rp-mbar button{flex:1 1 0;width:auto;padding:7px 4px;border-radius:14px;border:1px solid #888;background:#2a2a2a;color:#fff;font-size:13px;font-weight:600;cursor:pointer}'
    + '#rpDialog .rp-mbar button:disabled{opacity:.35}#rpDialog .rp-mbar .rp-mdel{border-color:#c62828;color:#ff8a80}'
    // phone: the answers become a bar of big buttons fixed at the bottom of the screen
    + '#rpDialog .rp-opts{position:fixed;left:0;right:0;bottom:0;display:flex;gap:6px;padding:8px;background:#1b1b1b;z-index:1001}'
    + '#rpDialog .rp-opts .rp-qrow{flex:0 0 100%;margin:0}#rpDialog .rp-opts .rp-qrow .rp-opt{flex-direction:row;padding:6px 4px}#rpDialog .rp-opts .rp-qrow .rp-ico{flex:0 0 26px;width:26px;height:26px;font-size:15px}'
    + '#rpDialog .rp-opts .rp-opt{flex:1 1 0;flex-direction:column;justify-content:center;gap:4px;padding:8px 2px;text-align:center;font-size:12px}'
    + '#rpDialog .rp-opts .rp-txt,#rpDialog .rp-opts .rp-key,#rpDialog .rp-opts .rp-bigconf{display:none}#rpDialog .rp-opts .rp-mbar{order:1}#rpDialog .rp-params .rp-lw{display:none}#rpDialog .rp-params .rp-lph{display:inline}#rpDialog .rp-params .rp-spcell{display:flex;flex:0 0 auto;flex-direction:column;align-items:center;justify-content:center;gap:2px;background:#2a2a2a;border-radius:6px;padding:4px 8px;color:#fff;text-decoration:none}#rpDialog .rp-spcell img{filter:invert(1);width:18px;height:18px}#rpDialog .rp-spcell small{font-size:10.5px;color:#ddd}#rpDialog .rp-opts .rp-ico{flex:0 0 38px;width:38px;height:38px;font-size:21px}'
    // the four quick causes (Dog, Human, Engine, Noise) lower (owner 2026-10-25)
    + '#rpDialog .rp-opts .rp-qrow .rp-opt{padding:3px 4px;font-size:12px}#rpDialog .rp-opts .rp-qrow .rp-ico{flex:0 0 20px;width:20px;height:20px;font-size:12px}'
    // the four main answers smaller (owner 2026-10-25: they took a third of the screen): a small icon over a short label
    + '#rpDialog .rp-opts > .rp-opt{gap:2px;padding:4px 2px;font-size:11px;line-height:1.15}#rpDialog .rp-opts > .rp-opt .rp-ico{flex:0 0 24px;width:24px;height:24px;font-size:14px}'
    + '#rpDialog.notmode .rp-opts{display:none}}';
  document.head.appendChild(st);
}

// Curation queue (owner 2026-10-18): the Curation page keeps its species list in the order shown (sessionStorage
// rp_queue, see curation.php). In review mode (views.php?view=Bird&sci=...&review=1) the player goes on to the next
// species of that list when the last detection of this one has its answer — after delay ms with a "Stay" button in
// msgEl, at once when delay is 0. Returns false when there is no next species.
var rpQueueTimer = null, rpLeaveRestore = null;
// dir -1 = the previous species (a species with nothing left to review is then skipped backwards, &dir=-1)
// species with nothing left to review (todo 0) are skipped here, not by opening their page (owner 2026-10-30: several
// species pages flashed by before the next sound)
function reviewQueueNeighbour(sci, dir) {
  var list = [];
  try { list = JSON.parse(sessionStorage.getItem('rp_queue') || '[]'); } catch (e) {}
  dir = dir || 1;
  for (var j = 0; j < list.length; j++) if (list[j].sci === sci) {
    for (var k = j + dir; k >= 0 && k < list.length; k += dir) if (list[k].todo !== 0) return list[k];
    return null;
  }
  return null;
}
// a species reviewed to the end (or with no audio left) leaves the queue's work: going back skips it too
function reviewQueueDone(sci, todo) {
  var list = [];
  try { list = JSON.parse(sessionStorage.getItem('rp_queue') || '[]'); } catch (e) {}
  list.forEach(function (x) { if (x.sci === sci) x.todo = todo || 0; });
  try { sessionStorage.setItem('rp_queue', JSON.stringify(list)); } catch (e) {}
}
// the change of species answers AT ONCE (owner 2026-10-18: a button with no visible effect looks frozen): the player is
// greyed out with "<why> — opening next species: X…"; after delay ms (or the next paint when 0) the page changes.
// With a delay the box offers Stay (and Undo when undoFn is given), which bring the player back.
function rpLeaveCancel() {
  clearTimeout(rpQueueTimer);
  if (rpLeaveRestore) rpLeaveRestore();
  rpLeaveRestore = null;
}
function reviewQueueNext(sci, delay, why, dir, undoFn) {
  dir = dir || 1;
  var next = reviewQueueNeighbour(sci, dir);
  if (!next) return false;
  var sp = next.com || next.sci;
  rpLeave((why ? why + ', ' : '') + (dir < 0 ? t('Opening previous species: {name}…', {name: sp}) : t('Opening next species: {name}…', {name: sp})),
    delay, undoFn, 'views.php?view=Bird&sci=' + encodeURIComponent(next.sci) + '&review=1' + (dir < 0 ? '&dir=-1' : ''));
  return true;
}
// the end of the list (owner 2026-10-22: the player stayed open on the last answer): the player greys out with the message,
// Stay / Undo for delay ms, then the page goes to url (review mode: back to the Validation table) or, with no url, the
// player closes on the table it was opened from
function rpLeave(text, delay, undoFn, url) {
  var dlg = document.getElementById('rpDialog');
  rpLeaveCancel();
  if (dlg) {
    var saved = Array.prototype.slice.call(dlg.querySelectorAll('button, select, input')).map(function (b) { return [b, b.disabled]; });
    dlg.classList.add('rp-leaving');
    dlg.querySelectorAll('audio').forEach(function (a) { a.pause(); });
    saved.forEach(function (x) { x[0].disabled = true; });
    var w = document.createElement('div');
    w.className = 'rp-leavemsg';
    var tx = document.createElement('div');
    tx.textContent = text;
    w.appendChild(tx);
    if (delay) {
      var bar = document.createElement('div');
      bar.className = 'rp-leavebtns';
      var mk = function (label, fn) { var b = document.createElement('button'); b.type = 'button'; b.textContent = label; b.onclick = fn; bar.appendChild(b); };
      mk(t('Stay'), function () { rpLeaveCancel(); });
      if (undoFn) mk(t('Undo'), function () { rpLeaveCancel(); undoFn(); });
      w.appendChild(bar);
    }
    dlg.appendChild(w);
    rpLeaveRestore = function () {
      dlg.classList.remove('rp-leaving');
      w.remove();
      saved.forEach(function (x) { x[0].disabled = x[1]; });
    };
  }
  // 60 ms: the browser paints the box before the navigation starts
  rpQueueTimer = setTimeout(function () {
    if (url) { rpLeaveRestore = null; location.href = url; return; }
    // the player's buttons were disabled while the message showed: enable them again before closing through × (a
    // disabled button ignores click(), the player stayed greyed out, owner 2026-10-22)
    if (rpLeaveRestore) rpLeaveRestore();
    rpLeaveRestore = null;
    var c = dlg && dlg.querySelector('.rp-close');
    if (c) c.click(); else if (dlg) dlg.remove();
  }, delay || 60);
}
function reviewModeOn(item) {
  return /[?&]review=1/.test(location.search) && item && item.dataset.sci;
}
// review mode: is this species still unconfirmed in the Curation list (the first Yes then moves on to the next species,
// owner 2026-10-18: confirm the unseen species first; a confirmed species is reviewed to the end)
function reviewModeUnconfirmed(item) {
  if (!reviewModeOn(item)) return false;
  var list = [];
  try { list = JSON.parse(sessionStorage.getItem('rp_queue') || '[]'); } catch (e) {}
  var e = list.filter(function (x) { return x.sci === item.dataset.sci; })[0];
  return !!(e && !e.conf && reviewQueueNeighbour(item.dataset.sci, 1));
}
function reviewModeConfirmed(item, undoFn) {
  var list = [];
  try { list = JSON.parse(sessionStorage.getItem('rp_queue') || '[]'); } catch (e) {}
  list.forEach(function (x) { if (x.sci === item.dataset.sci) { x.conf = true; x.wasUnconf = true; } });
  try { sessionStorage.setItem('rp_queue', JSON.stringify(list)); } catch (err) {}
  return reviewQueueNext(item.dataset.sci, 1500, '\u2713 ' + t('Species confirmed'), 1, undoFn);
}
function reviewModeEnd(item, undoFn) {
  if (reviewModeOn(item)) reviewQueueDone(item.dataset.sci);
  // Undo brings the species back into the queue's work
  var undo = undoFn && function () { reviewQueueDone(item.dataset.sci, 1); undoFn(); };
  if (reviewModeOn(item) && reviewQueueNext(item.dataset.sci, 1500, t('Species done'), 1, undo)) return;
  // closing goes through leave(): in review mode back to the Validation table, at the species just finished
  // the end of the list closes at once, no Stay / Undo (owner 2026-10-22)
  if (reviewModeOn(item)) {
    // Validation then says so in a notice (curation.php curationNothingLeft)
    try { sessionStorage.setItem('cur_done', '1'); } catch (e) {}
    rpLeave(t('That was the last species of the list, back to Validation…'), 0, null, null);
  }
  else rpLeave(t('That was the last detection of this list, closing…'), 0, null, null);
}
// THE entry point of every Review link or button in the interface (owner 2026-10-09: one code, no local variants):
// reviewDetection(this) from any element. Inside a review item ([data-ri]) it reviews that item's list; elsewhere the
// element's data-file (or the older call reviewDetection(file, this)) makes a one-detection item on the spot.
function reviewDetection(a, b) {
  var elem = (a && a.nodeType) ? a : b;
  var file = (a && a.nodeType) ? (elem.dataset.file || '') : a;
  if (!elem.closest('[data-ri]')) {
    if (!file) return;
    var box = elem.closest('td, .gcard, .clip, tr') || elem.parentNode;
    var parts = file.split('/');
    box.dataset.ri = '1';
    box.dataset.file = file;
    box.dataset.clip = '/By_Date/' + file;
    box.dataset.label = (parts[1] ? parts[1].replace(/_/g, ' ') + ', ' : '') + (parts[0] || '');
  }
  openReviewPlayer(elem);
}

// a short message at the bottom of the page (no dialog)
function rpToast(msg) {
  var b = document.createElement('div');
  b.textContent = msg;
  b.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#333;color:#fff;padding:8px 14px;border-radius:8px;z-index:2000;max-width:90vw';
  document.body.appendChild(b);
  setTimeout(function () { b.remove(); }, 4000);
}
function openReviewPlayer(el) {
  var opener = document.activeElement;  // focus goes back there on close (WCAG 2.4.3, 2026-10-23 run 3)
  var start = el.closest('[data-ri]');
  if (!start) return;
  var scope = start.closest('[data-review-list]') || document;
  var all = Array.prototype.slice.call(scope.querySelectorAll('[data-ri]'));
  // detections without their audio on disk (data-gone, common.php) never enter the review (owner 2026-10-22): a click on
  // one starts at the next detection that has its audio
  var items = all.filter(function (it) { return it.dataset.gone !== '1'; });
  var idx = items.indexOf(start), answered = false;
  if (idx < 0) {
    var after = all.slice(all.indexOf(start) + 1).filter(function (it) { return it.dataset.gone !== '1'; })[0];
    idx = after ? items.indexOf(after) : items.length - 1;
  }
  if (!items.length) {
    // review mode: nothing with audio left in this species = on to the next one
    if (reviewModeOn(start)) reviewQueueDone(start.dataset.sci);
    if (reviewModeOn(start) && reviewQueueNext(start.dataset.sci, 0, null, 1)) return;
    rpToast(t('The audio of this detection is no longer on disk (removed by the nightly clean-up).'));
    return;
  }
  reviewPlayerStyle();
  var old = document.getElementById('rpDialog');
  if (old) old.remove();
  document.querySelectorAll('audio').forEach(function (a) { a.pause(); });
  // keyboard (2026-10-23 test run, WCAG 2.2): the player is a modal dialog; focus moves into it, Tab stays inside while
  // it is open and closing it gives the focus back to what opened it
  var opener = document.activeElement && document.activeElement !== document.body ? document.activeElement : el;
  var d = document.createElement('div');
  d.id = 'rpDialog';
  d.setAttribute('role', 'dialog');
  d.setAttribute('aria-modal', 'true');
  d.setAttribute('aria-label', t('Listen and review'));
  d.innerHTML = '<div class="rp-box"><button type="button" class="rp-close" title="' + t('Close (Esc)') + '" aria-label="' + t('Close') + '">&times;</button>'
    + '<div class="rp-left"><div class="rp-title"><div class="rp-when"></div>'
    + '<div class="rp-tc"><div class="rp-com"></div></div>'
    + '<div class="rp-tr"><div class="rp-sci"></div></div></div>'
    + '<div class="rp-params"></div>'
    + '<div class="rp-player"></div>'
    + '<div class="rp-toolsrow"><div class="rp-tl2"><a class="rp-folder" target="_blank" title="">&#128194; ' + t('File') + '</a>'
    + '<button type="button" class="rp-tg" data-tg="spec">&#127912; ' + t('Spectrogram') + '</button><button type="button" class="rp-tg" data-tg="audio">&#128266; ' + t('Audio') + '</button>'
    + '<span class="rp-spec" title="' + t('Spectrogram picture (remembered in this browser)') + '"><select data-k="palette" aria-label="' + t('Spectrogram palette') + '">'
    + [['turbo', t('Turbo (blue → green → red)')], ['inferno', t('Inferno (black → red → yellow)')],
      ['roseus', t('Roseus (black → violet → yellow)')], ['soxheat', t('SoX heat (black → purple → orange → white)')],
      ['birdnet', t('Classic (purple → red → orange)')], ['viridis', t('Viridis (dark → yellow)')],
      ['ocean', t('Ocean (black → cyan)')], ['grayscale', t('Grayscale (black → white)')]]
      .map(function (p) { return '<option value="' + p[0] + '">' + p[1] + '</option>'; }).join('')
    + '</select><label>' + t('Floor') + ' <input type="number" data-k="floor" min="-120" max="-40" step="5"></label>'
    + '<label>' + t('Range') + ' <input type="number" data-k="range" min="30" max="120" step="5"></label>'
    + '<label>' + t('Contrast') + ' <input type="number" data-k="contrast" min="0.2" max="3" step="0.1"></label>'
    + '<button type="button" class="rp-specreset" title="' + t('Back to the station\'s spectrogram settings') + '">&#8634;</button></span></div>'
    + '<div class="rp-speed"><button type="button" data-sp="loop" title="' + t('Repeat only the detected 3 seconds') + '">&#10227; ' + t('Loop 3 s') + '</button>'
    + '<span class="rp-rates" title="' + t('Playback speed') + '">' + [0.5, 0.8, 1, 1.5, 2].map(function (r) { return '<button type="button" data-rate="' + r + '">' + String(r).replace('.', ',') + '&times;</button>'; }).join('') + '</span></div></div>'
    + '<div class="rp-bottom">'
    + '<div class="rp-right">'
    + '<div class="rp-vol"><button type="button" data-vol="down" title="' + t('Volume down') + '">&#128265;&minus;</button><button type="button" data-vol="up" title="' + t('Volume up') + '">&#128266;+</button>'
    + '<button type="button" data-vol="mute" title="' + t('Mute') + '">&#128263;</button><span class="rp-vollevel"></span></div></div></div>'
    + '<div class="rp-keys">' + t('Keys:') + ' <kbd>Y</kbd> ' + t('yes') + ' | <kbd>N</kbd> ' + t('change species') + ' | <kbd>R</kbd> ' + t('review later') + ' | <kbd>C</kbd> ' + t('clear') + ' | <kbd>X</kbd> ' + t('ignore') + ' | '
    + '<kbd>Z</kbd> ' + t('undo') + ' | <kbd>\u2190</kbd> <kbd>\u2192</kbd> ' + t('previous / next') + ' | <kbd>Space</kbd> ' + t('play / pause') + ' | <kbd>L</kbd> ' + t('loop') + ' | <kbd>Esc</kbd> ' + t('close') + '</div></div>'
    // side panel (owner 2026-10-18): progress and the species row on top — visible on a phone without scrolling —, the
    // answers, then the clip tools (change species, protect, frequency shift) at the bottom
    + '<div class="rp-side">'
    + '<div class="rp-prog"><small class="rp-now"></small><div class="rp-bar"></div></div>'
    + '<div class="rp-nav rp-spnav"><button type="button" data-spnav="-1">&#9664; ' + t('Previous species') + '</button><button type="button" data-spnav="1">' + t('Next species') + ' &#9654;</button></div>'
    + '<div class="rp-main"><div class="rp-opts">'
    + '<div class="rp-mbar"><button type="button" data-nav="-1">&#9664; ' + t('Previous') + '</button><button type="button" class="rp-mdel" title="' + t('Not a bird worth keeping: the detection stays counted, its audio goes in Tools › Data › Purge') + '">&#128683; ' + t('Ignore') + '</button><button type="button" data-nav="1">' + t('Next') + ' &#9654;</button></div>'
    // Previous / Next species in the phone's fixed bar too (owner 2026-10-25: out of sight under it before)
    + '<div class="rp-mbar rp-mspnav"><button type="button" data-spnav="-1">&#9664; ' + t('Previous species') + '</button><button type="button" data-spnav="1">' + t('Next species') + ' &#9654;</button></div>'
    + '<div class="rp-bigconf"><small>' + t('Conf.') + '</small><b></b>'  // the detection's confidence, large (owner 2026-10-22)
    // beside it the species page (owner 2026-10-26)
    + '<a class="rp-sppage" href="#" title="' + t('Open the species page') + '"><img src="images/species.svg" alt=""><span>' + t('Species card') + '</span></a></div>'
    + REVIEW_OPTS.map(function (o) {
        return '<button type="button" class="rp-opt" data-v="' + o[0] + '"><span class="rp-ico" style="background:' + o[2] + '">' + o[1] + '</span>'
          + '<span><b>' + o[3] + '</b>' + (o[4] ? '<span class="rp-txt">' + o[4] + '</span>' : '') + '</span><span class="rp-key">' + o[5] + '</span></button>';
      }).join('')
    + [0, 2].map(function (i) {
        return '<div class="rp-qrow">' + REVIEW_QUICK.slice(i, i + 2).map(function (c) {
          return '<button type="button" class="rp-opt rp-qopt" data-r="' + c[0] + '"><span class="rp-ico" style="background:#c62828">&#10007;</span><span><b>' + c[1] + '</b></span></button>';
        }).join('') + '</div>';
      }).join('')
    + '</div>'
    + '<div class="rp-msg"></div>'
    + '<div class="rp-nav"><button type="button" data-nav="-1">&#9664; ' + t('Previous') + '</button><button type="button" data-nav="1">' + t('Next') + ' &#9654;</button></div>'
    + '<label class="rp-skip" title="' + t('Next, Previous and the answers jump over detections already reviewed') + '"><input type="checkbox" class="rp-skipbox"> ' + t('Skip reviewed detections') + '</label></div>'
    + '<div class="rp-not"><h4>' + t('Change species: which was it?') + '</h4>'
    // the causes first (owner 2026-10-22): shown at once, they no longer move down when the model's species arrive
    + '<div class="rp-sub">' + t('Reject it with the cause:') + '</div>'
    + '<label class="rp-batch"><input type="checkbox" class="rp-batchbox"> <span class="rp-batchtxt"></span></label>'
    + '<div class="rp-causes">'
    + REVIEW_CAUSES.map(function (c) { return '<button type="button" class="rp-cause" data-r="' + c[0] + '">' + c[1] + '</button>'; }).join('') + '</div>'
    + '<div class="rp-sub">' + t('Or one of the species the station\'s model hears in this clip (expected here first):') + '</div><div class="rp-alts"></div>'
    + '<input type="search" class="rp-search" placeholder="' + t('Or search any species...') + '"><div class="rp-found"></div>'
    + '<button type="button" class="rp-back">&#9664; ' + t('Back') + '</button></div>'
    + '<button type="button" class="rp-delete" title="' + t('Not a bird worth keeping: the detection stays counted, its audio goes in Tools › Data › Purge') + '">&#128683; ' + t('Ignore') + '</button>'
    + '<div class="rp-tools">'
    + '<img data-t="lock" src="images/unlock.svg" title="" alt="" role="button" tabindex="0" data-kbd="1">'
    + '<img data-t="shift" src="images/shift.svg" title="" alt="" role="button" tabindex="0" data-kbd="1">'
    + '</div></div></div>';
  document.body.appendChild(d);

  function verdictOf(item) {
    var b = item.querySelector('button.validatebtn');
    var m = b ? b.className.match(/\bv-(\w+)/) : null;
    return m && m[1] !== 'none' ? m[1] : '';
  }
  function toolIcons(item) {
    var lk = d.querySelector('.rp-tools [data-t=lock]'), sh = d.querySelector('.rp-tools [data-t=shift]');
    var locked = item.dataset.locked === '1', shifted = item.dataset.shifted === '1';
    lk.src = locked ? 'images/lock.svg' : 'images/unlock.svg';
    lk.title = lk.alt = locked ? t('Protected from the disk purge (click to unprotect)') : t('Protect this clip from the disk purge');
    sh.src = shifted ? 'images/unshift.svg' : 'images/shift.svg';
    sh.title = sh.alt = shifted ? t('Frequency shifted down (click to undo)') : t('Shift the frequency down (high-pitched birds)');
  }
  function show(i, msg) {
    d.querySelector('.rp-batchbox').checked = false;  // "the whole hour" belongs to the detection it was ticked on
    idx = i;
    var item = items[i];
    // title: date and time on the left, common name centred, scientific name on the right (owner 2026-10-18: no
    // English name — one line less on the phone)
    var lab = (item.dataset.label || '').split(', ');
    d.querySelector('.rp-when').textContent = item.dataset.when || lab[1] || '';
    d.querySelector('.rp-com').textContent = item.dataset.com || lab[0] || '';
    d.querySelector('.rp-sci').textContent = item.dataset.sci || '';
    // File (owner 2026-10-18): the clip's folder in the station File Manager (new tab), filtered on "<clip name>.*" —
    // the audio file and its spectrogram picture
    var dir = item.dataset.dir || ('BirdSongs/Extracted/By_Date/' + (item.dataset.file || '').split('/').slice(0, 2).join('/'));
    var base = (item.dataset.file || '').split('/').pop().replace(/\.[^.]+$/, '') + '.*';
    var fl = d.querySelector('.rp-folder');
    // over SFTP in the viewer's own file manager (owner 2026-10-23: no web file manager); dir is relative to /home
    fl.href = (window.BS_SFTP || '') + ('/home/' + dir).split('/').map(encodeURIComponent).join('/');
    fl.removeAttribute('target');
    fl.title = t('Opens the clip\'s folder in your own file manager (SFTP: Dolphin, WinSCP)');
    // analysis settings of this detection, under the spectrogram
    var params = {};
    try { params = JSON.parse(item.dataset.params || '{}'); } catch (e) {}
    d.querySelector('.rp-bigconf b').textContent = params['Conf.'] || '-';
    d.querySelectorAll('.rp-sppage').forEach(function (a) { a.href = 'views.php?view=Bird&sci=' + encodeURIComponent(item.dataset.sci || ''); });
    // the top cells (owner 2026-10-26): Conf. and Prob. on the left, each with its minimum inside (the manual
    // confidence when the species has one), then Sigm. Sens., Rec. Length and Overlap on the right; a phone shows the
    // first two only
    var pe = function (v) { return String(v == null ? '-' : v).replace(/</g, '&lt;'); };
    // ph: the longer label a phone shows instead (owner 2026-10-26)
    var cell = function (k, v, cls, ph) { return '<span data-k="' + k.replace(/[^A-Za-z]/g, '') + '"' + (cls ? ' class="' + cls + '"' : '') + '><small>' + (ph ? '<i class="rp-lw">' + t(k) + '</i><i class="rp-lph">' + ph + '</i>' : t(k)) + '</small><b>' + v + '</b></span>'; };
    var manual = params['Manual Conf.'] && params['Manual Conf.'] !== '-';
    var cmin = manual ? t('manual {v}', {v: pe(params['Manual Conf.'])}) : (params['Min. Conf.'] && params['Min. Conf.'] !== '-' ? t('min. {v}', {v: pe(params['Min. Conf.'])}) : '');
    var pmin = params['Min. Prob.'] && params['Min. Prob.'] !== '-' ? t('min. {v}', {v: pe(params['Min. Prob.'])}) : '';
    var spurl = 'views.php?view=Bird&sci=' + encodeURIComponent(item.dataset.sci || '');
    // on a phone the species card is the first cell, left of Conf. (owner 2026-10-26)
    d.querySelector('.rp-params').innerHTML = '<a class="rp-spcell" href="' + spurl + '" title="' + t('Open the species page') + '"><img src="images/species.svg" alt=""><small>' + t('Species card') + '</small></a>'
      + cell('Conf.', pe(params['Conf.']) + (cmin ? ' <small class="rp-min">(' + cmin + ')</small>' : ''), '', t('Detection conf.'))
      + cell('Prob.', pe(params['Prob.']) + (pmin ? ' <small class="rp-min">(' + pmin + ')</small>' : ''), '', t('Prob. at the location'))
      + ['Sigm. Sens.', 'Rec. Length', 'Overlap'].map(function (k, i) { return cell(k, pe(params[k]), 'rp-pr' + (i === 0 ? ' rp-pr1' : '')); }).join('');
    progress();
    d.querySelector('.rp-msg').textContent = msg || '';
    var v = verdictOf(item);
    toolIcons(item);
    d.querySelectorAll('.rp-opt:not(.rp-qopt)').forEach(function (b) { b.classList.toggle('current', b.dataset.v === v); });
    d.querySelectorAll('[data-nav="-1"]').forEach(function (b) { b.disabled = (step(i, -1) < 0); });
    d.querySelectorAll('[data-nav="1"]').forEach(function (b) { b.disabled = (step(i, 1) < 0); });
    d.querySelectorAll('.rp-player audio').forEach(function (a) { a.pause(); });
    var holder = d.querySelector('.rp-player');
    holder.innerHTML = '<div class="custom-audio-player"></div>';
    var p = holder.firstChild;
    p.dataset.audioSrc = item.dataset.clip;
    p.dataset.imageSrc = specSrc(item);
    initCustomAudioPlayers(holder);
    var img = p.querySelector('img');
    if (img) { img.classList.toggle('rp-crop', !specOverride()); img.alt = t('Spectrogram'); }
    // started inside the click that led here (browsers only allow sound from the user's own gesture)
    var a = p.querySelector('audio');
    // a clip removed by the nightly clean-up (N files per species; the line stays): say so instead of an empty player
    // (purged after the page was drawn): it leaves the list and the next detection starts
    if (a) a.addEventListener('error', function () {
      if (items[idx] !== item || !a.error || a.error.code !== 4) return;
      item.dataset.gone = '1';
      items.splice(idx, 1);
      if (!items.length) { if (reviewModeOn(item)) { reviewModeEnd(item, null); return; } close(); rpToast(t('The audio of this detection is no longer on disk (removed by the nightly clean-up).')); return; }
      show(Math.min(idx, items.length - 1), t('The audio of this detection is no longer on disk (removed by the nightly clean-up).'));
    });
    if (a) { applyVolume(a); applySpeed(a); a.src = item.dataset.clip; a.play().catch(function () {}); }
    d.classList.remove('notmode');
    preloadNext();
    item.scrollIntoView({block: 'nearest'});
  }
  function answer(v, reason) {
    if (v === 'no' && reason === undefined) { openNot(); return; }
    d.classList.remove('notmode');
    var item = items[idx];
    var before = verdictOf(item), beforeReason = item.dataset.reason || '';
    send(item, v, reason);
    setVerdict(item, v, reviewLabel(v, reason), reason);
    progress();
    // the next detection starts right away (inside this click)
    var said = t('Saved: {what}', {what: reviewLabel(v, reason).replace(/^\W+ /, '')});
    var nx = step(idx, 1);
    // the first Yes of a species not confirmed yet moves on to the next species (no next detection of this one)
    var confirms = v === 'yes' && reviewModeUnconfirmed(item);
    if (confirms) d.querySelector('.rp-msg').textContent = said;
    else if (nx >= 0) show(nx, said + ', ' + t('next detection'));
    else d.querySelector('.rp-msg').textContent = said + '. ' + t('That was the last detection to review in this list.');
    offerUndo(function () {
      // the answer before, with its cause (data-reason): an undone Ignore over a Dog stays Dog
      send(item, before || 'clear', beforeReason || undefined);
      setVerdict(item, before || 'clear', before ? reviewLabel(before, beforeReason) : undefined, beforeReason);
      show(items.indexOf(item), t('Answer undone'));
      if (v === 'yes') {
        try {
          var q = JSON.parse(sessionStorage.getItem('rp_queue') || '[]');
          q.forEach(function (x) { if (x.sci === item.dataset.sci && x.wasUnconf) { x.conf = false; delete x.wasUnconf; } });
          sessionStorage.setItem('rp_queue', JSON.stringify(q));
        } catch (e) {}
      }
    });
    if (confirms) { reviewModeConfirmed(item, doUndo); return; }
    if (nx < 0) reviewModeEnd(item, doUndo);
  }
  function send(item, v, reason) {
    answered = true;
    var x = new XMLHttpRequest();
    x.onload = function () {
      if (this.responseText != 'OK') {
        d.querySelector('.rp-msg').textContent = (this.status === 401 ? t('Log in first to review detections.') : t('Not saved: {msg}', {msg: this.responseText}));
        setVerdict(item, 'clear');
        progress();
      }
    };
    x.open('GET', 'play.php?review=' + encodeURIComponent(item.dataset.file) + '&verdict=' + v + (reason ? '&reason=' + encodeURIComponent(reason) : ''), true);
    x.send();
  }
  // the next (dir 1) or previous (-1) detection; with "Skip reviewed" on, the next one without an answer (-1: none)
  function step(from, dir) {
    var skip = d.querySelector('.rp-skipbox').checked;
    for (var j = from + dir; j >= 0 && j < items.length; j += dir) if (!skip || !verdictOf(items[j])) return j;
    return -1;
  }
  // "Detection 5 of 30 · 12 reviewed" and one mark per detection (green yes, red no, grey review later); a click jumps there
  function progress() {
    var done = items.filter(function (it) { return verdictOf(it); }).length;
    // on a phone the short "1/4" in one line with its bar (owner 2026-10-25); the whole sentence as its tooltip
    var nowTxt = t('Detection {i} of {n}, {done} reviewed', {i: idx + 1, n: items.length, done: done}), nowEl = d.querySelector('.rp-now');
    nowEl.textContent = window.matchMedia('(max-width:800px)').matches ? (idx + 1) + '/' + items.length : nowTxt;
    nowEl.title = nowTxt;
    var bar = d.querySelector('.rp-bar');
    bar.innerHTML = items.map(function (it, j) { return '<i class="' + verdictOf(it) + (j === idx ? ' cur' : '') + '" data-j="' + j + '"></i>'; }).join('');
  }
  // Undo of the last answer or removal, offered for 5 s (button or Z)
  var undoFn = null, undoTimer = null;
  function offerUndo(fn) {
    var msg = d.querySelector('.rp-msg');
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'rp-undo';
    b.textContent = t('Undo');
    b.onclick = function () { doUndo(); };
    msg.appendChild(b);
    undoFn = fn;
    clearTimeout(undoTimer);
    undoTimer = setTimeout(function () { undoFn = null; if (b.parentNode) b.remove(); }, 5000);
  }
  function doUndo() {
    rpLeaveCancel();
    var fn = undoFn;
    undoFn = null;
    clearTimeout(undoTimer);
    d.querySelectorAll('.rp-undo').forEach(function (b) { b.remove(); });
    if (fn) fn();
  }
  // ---- Change species: which bird it was, or the cause ----
  var labels = null;
  // every Review button of this detection on the page, not only the item's (owner 2026-10-22: the same detection also in
  // Precision's band lists or another table kept "Review" after its answer)
  function setVerdict(item, v, text, reason) {
    item.dataset.reason = v === 'clear' ? '' : (reason || '');
    var f = item.dataset.file, same = f ? document.querySelectorAll('button.validatebtn[data-file="' + (window.CSS && CSS.escape ? CSS.escape(f) : f.replace(/"/g, '\\"')) + '"]') : [];
    Array.prototype.slice.call(item.querySelectorAll('button.validatebtn')).concat(Array.prototype.slice.call(same)).forEach(function (b) {
      b.textContent = text || REVIEW_LABELS[v];
      b.className = b.className.replace(/\bv-\w+/, 'v-' + (v === 'clear' ? 'none' : v));
    });
  }
  function nextAfter(msg) {
    var nx = step(idx, 1);
    if (nx >= 0) show(nx, msg + ', ' + t('next detection'));
    else { d.classList.remove('notmode'); d.querySelector('.rp-msg').textContent = msg + '. ' + t('That was the last detection of this list.'); reviewModeEnd(items[idx], null); }
  }
  function altButton(a, i) {
    return '<button type="button" class="rp-alt' + (a.expected === false ? ' far' : '') + '" data-label="' + a.label.replace(/"/g, '&quot;') + '" data-com="' + a.com.replace(/"/g, '&quot;') + '">'
      + '<span>' + (i !== null ? '<b>' + (i + 1) + '</b> ' : '') + a.com + ' <i>' + a.sci + '</i></span><small>'
      + (a.score !== undefined ? Math.round(a.score * 100) + '%' : '') + (a.prob !== undefined && a.prob !== null ? ' | ' + t('here {p}%', {p: Math.round(a.prob * 100)}) : (a.expected === false ? ' | ' + t('not expected here') : '')) + '</small></button>';
  }
  function bindAlts(box) {
    box.querySelectorAll('.rp-alt').forEach(function (b) { b.onclick = function () { changeTo(b.dataset.label, b.dataset.com); }; });
  }
  function openNot() {
    var item = items[idx];
    d.classList.add('notmode');
    var when = item.dataset.when || '';
    // tick BEFORE choosing the cause (the cause saves at once); the count says what it will reach in this list
    var others = items.filter(function (it) {
      var w = it.dataset.when || '';
      return it !== item && it.dataset.sci === item.dataset.sci && w.slice(0, 13) === when.slice(0, 13) && !verdictOf(it);
    }).length;
    var span = when.slice(0, 10) + ' ' + when.slice(11, 13) + ':00\u2013' + when.slice(11, 13) + ':59';
    d.querySelector('.rp-batchtxt').textContent = others
      ? t('Also apply the cause to the {n} other unreviewed {name} of {span} (tick first, then choose the cause)', {n: others, name: item.dataset.com || t('detection'), span: span})
      : t('No other unreviewed {name} in {span}', {name: item.dataset.com || t('detection'), span: span});
    d.querySelector('.rp-batchbox').checked = false;
    d.querySelector('.rp-batchbox').disabled = !others || !when;
    d.querySelector('.rp-search').value = '';
    d.querySelector('.rp-found').innerHTML = '';
    var box = d.querySelector('.rp-alts');
    box.innerHTML = '<div class="rp-wait">' + t('Asking the station\'s model about this clip…') + '</div>';
    var asked = item;
    var x = new XMLHttpRequest();
    x.onload = function () {
      if (items[idx] !== asked || !d.classList.contains('notmode')) return;
      var alts = [];
      try { alts = JSON.parse(this.responseText); } catch (e) {}
      box.innerHTML = alts.length ? alts.map(altButton).join('') : '<div class="rp-wait">' + (this.status === 401 ? t('Log in first.') : t('The model found no other species in this clip.')) + '</div>';
      bindAlts(box);
    };
    x.open('GET', 'play.php?alternatives=' + encodeURIComponent(item.dataset.file), true);
    x.send();
    focusFirst();
  }
  function search(q) {
    var found = d.querySelector('.rp-found');
    q = q.trim().toLowerCase();
    if (q.length < 2) { found.innerHTML = ''; return; }
    function render() {
      var hits = labels.filter(function (l) { return l.toLowerCase().indexOf(q) >= 0; }).slice(0, 8);
      found.innerHTML = hits.map(function (l) { var p = l.split('_'); return altButton({label: l, sci: p[0], com: p.slice(1).join('_') || p[0]}, null); }).join('');
      bindAlts(found);
    }
    if (labels) { render(); return; }
    var x = new XMLHttpRequest();
    x.onload = function () { try { labels = JSON.parse(this.responseText); } catch (e) { labels = []; } render(); };
    x.open('GET', 'play.php?getlabels=1', true);
    x.send();
  }
  function changeTo(label, com) {
    var item = items[idx];
    var x = new XMLHttpRequest();
    x.onload = function () {
      if (this.responseText.trim() == 'OK') setVerdict(item, 'yes', reviewLabel('yes', 'changed'));
      else d.querySelector('.rp-msg').textContent = t('Species not changed: {msg}', {msg: this.responseText});
    };
    x.open('GET', 'play.php?changefile=' + encodeURIComponent(item.dataset.file) + '&newname=' + encodeURIComponent(label), true);
    x.send();
    d.classList.remove('notmode');
    nextAfter(t('Changed to {name}', {name: com}));
  }
  function reject(reason) {
    var item = items[idx];
    if (!d.querySelector('.rp-batchbox').checked) { answer('no', reason); return; }
    var when = item.dataset.when || '', date = when.slice(0, 10), hour = when.slice(11, 13), sci = item.dataset.sci || '';
    // exactly the detections the label counted (2026-10-23 run 3: the station marked the whole hour of every location)
    var files = items.filter(function (it) {
      var w = it.dataset.when || '';
      return it !== item && !verdictOf(it) && it.dataset.sci === sci && w.slice(0, 10) === date && w.slice(11, 13) === hour;
    }).map(function (it) { return it.dataset.file; });
    var x = new XMLHttpRequest();
    x.onload = function () {
      var m = this.responseText.match(/^OK (\d+)/);
      if (!m) { d.querySelector('.rp-msg').textContent = t('Not saved: {msg}', {msg: this.responseText}); return; }
      items.forEach(function (it) {
        var w = it.dataset.when || '';
        // the server keeps answers given before (INSERT OR IGNORE): only the ones without an answer change here
        if (it !== item && !verdictOf(it) && it.dataset.sci === sci && w.slice(0, 10) === date && w.slice(11, 13) === hour) setVerdict(it, 'no', reviewLabel('no', reason), reason);
      });
    };
    d.querySelector('.rp-batchbox').checked = false;  // one hour per tick (a later one-click cause must not repeat it)
    x.open('GET', 'play.php?review_batch=1&sci=' + encodeURIComponent(sci) + '&date=' + date + '&hour=' + parseInt(hour, 10) + '&reason=' + encodeURIComponent(reason) + '&files=' + encodeURIComponent(files.join('\n')), true);
    x.send();
    // this detection too (it may have had an answer already), then the next one outside that hour
    answer('no', reason);
  }
  d.querySelectorAll('.rp-cause, .rp-qopt').forEach(function (b) { b.onclick = function () { reject(b.dataset.r); }; });  // also the cause buttons
  d.querySelector('.rp-back').onclick = function () { d.classList.remove('notmode'); focusFirst(); };
  d.querySelector('.rp-search').oninput = function () { search(this.value); };

  // ---- speed: loop of the detected 3 s, 1.5x, preload of the next clip, swipe ----
  var RATES = [0.5, 0.8, 1, 1.5, 2];
  function spState() {
    var st = {loop: false, rate: 1};
    try {
      st.loop = localStorage.getItem('rp_loop') === '1';
      var r = parseFloat(localStorage.getItem('rp_speed') || '1');
      st.rate = RATES.indexOf(r) >= 0 ? r : 1;
    } catch (e) {}
    return st;
  }
  function applySpeed(a) {
    var st = spState();
    d.querySelector('[data-sp=loop]').classList.toggle('on', st.loop);
    d.querySelectorAll('.rp-rates [data-rate]').forEach(function (b) { b.classList.toggle('on', parseFloat(b.dataset.rate) === st.rate); });
    (a ? [a] : Array.prototype.slice.call(d.querySelectorAll('.rp-player audio'))).forEach(function (x) {
      x.playbackRate = st.rate;
      x.defaultPlaybackRate = st.rate;
      // the clip plays over and over until paused (owner 2026-10-18); Loop 3 s narrows the repeat to the detected window
      x.loop = true;
      if (!x.dataset.loopHooked) {
        x.dataset.loopHooked = '1';
        // the extraction keeps the detected window in the middle of the clip
        x.addEventListener('timeupdate', function () {
          if (!spState().loop || !x.duration) return;
          var start = Math.max(0, (x.duration - 3) / 2), end = Math.min(x.duration, start + 3);
          if (x.currentTime < start - 0.1 || x.currentTime >= end) x.currentTime = start;
        });
      }
    });
  }
  d.querySelectorAll('[data-sp]').forEach(function (b) {
    b.onclick = function () {
      var st = spState(), k = b.dataset.sp;
      try {
        localStorage.setItem('rp_loop', st.loop ? '0' : '1');
      } catch (e) {}
      applySpeed(null);
    };
  });
  // speed buttons: one click picks the speed (remembered in this browser)
  d.querySelectorAll('.rp-rates [data-rate]').forEach(function (b) {
    b.onclick = function () {
      try { localStorage.setItem('rp_speed', b.dataset.rate); } catch (e) {}
      applySpeed(null);
    };
  });
  var preloaded = null;
  function preloadNext() {
    var nx = items[idx + 1];
    if (!nx) return;
    preloaded = new Audio();
    preloaded.preload = 'auto';
    preloaded.src = nx.dataset.clip;
    (new Image()).src = specSrc(nx);
  }
  var touch = null;
  d.querySelector('.rp-left').addEventListener('touchstart', function (e) { var t = e.changedTouches[0]; touch = {x: t.clientX, y: t.clientY}; }, {passive: true});
  d.querySelector('.rp-left').addEventListener('touchend', function (e) {
    if (!touch) return;
    var t = e.changedTouches[0], dx = t.clientX - touch.x, dy = t.clientY - touch.y;
    touch = null;
    if (Math.abs(dx) > 80 && Math.abs(dy) < 60) answer(dx > 0 ? 'yes' : 'no');
  }, {passive: true});

  // volume (remembered in this browser): steps of 10 %, mute
  function volState() {
    var v = 1, m = false;
    try { v = parseFloat(localStorage.getItem('rp_volume') || '1'); m = localStorage.getItem('rp_muted') === '1'; } catch (e) {}
    return {v: isNaN(v) ? 1 : Math.min(1, Math.max(0, v)), m: m};
  }
  function applyVolume(a) {
    var s = volState();
    (a ? [a] : Array.prototype.slice.call(d.querySelectorAll('.rp-player audio'))).forEach(function (x) { x.volume = s.v; x.muted = s.m; });
    d.querySelector('.rp-vollevel').textContent = s.m ? t('muted') : Math.round(s.v * 100) + '%';
    d.querySelector('.rp-vol [data-vol=mute]').classList.toggle('on', s.m);
  }
  function volume(op) {
    var s = volState();
    if (op === 'mute') s.m = !s.m;
    else { s.m = false; s.v = Math.min(1, Math.max(0, Math.round((s.v + (op === 'up' ? 0.1 : -0.1)) * 10) / 10)); }
    try { localStorage.setItem('rp_volume', String(s.v)); localStorage.setItem('rp_muted', s.m ? '1' : '0'); } catch (e) {}
    applyVolume(null);
  }
  // ---- spectrogram picture: palette / floor / range / contrast (remembered in this browser) ----
  // The station's settings show the stored picture with its title band cropped off; other values are drawn on demand
  // (play.php?spectro=, scripts/review_worker.py) without a title.
  var specDefaults = null, specTimer = null;
  function specOverride() {
    try { return JSON.parse(localStorage.getItem('rp_spec') || 'null'); } catch (e) { return null; }
  }
  function specSrc(item) {
    var o = specOverride();
    if (!o) return item.dataset.spec || item.dataset.clip + '.png';  // data-spec: .png or the archived .jpg (common.php)
    return 'play.php?spectro=' + encodeURIComponent(item.dataset.clip.replace(/^\/(By_Date|Purge)\//, '')) + '&palette=' + encodeURIComponent(o.palette)
      + '&floor=' + encodeURIComponent(o.floor) + '&range=' + encodeURIComponent(o.range) + '&contrast=' + encodeURIComponent(o.contrast);
  }
  function specFill() {
    var o = specOverride() || specDefaults;
    if (!o) return;
    d.querySelectorAll('.rp-spec [data-k]').forEach(function (f) { f.value = o[f.dataset.k]; });
  }
  function specInit() {
    var x = new XMLHttpRequest();
    x.onload = function () { try { specDefaults = JSON.parse(this.responseText); } catch (e) {} specFill(); };
    x.open('GET', 'play.php?review_worker=1', true);
    x.send();
    specFill();
    d.querySelectorAll('.rp-spec [data-k]').forEach(function (f) {
      f.addEventListener(f.tagName === 'SELECT' ? 'change' : 'input', function () {
        clearTimeout(specTimer);
        specTimer = setTimeout(specApply, 500);
      });
    });
    d.querySelector('.rp-specreset').onclick = function () {
      try { localStorage.removeItem('rp_spec'); } catch (e) {}
      specFill();
      specRedraw();
    };
  }
  function specApply() {
    var o = {};
    d.querySelectorAll('.rp-spec [data-k]').forEach(function (f) { o[f.dataset.k] = f.value; });
    var same = specDefaults && Object.keys(o).every(function (k) { return String(o[k]) === String(specDefaults[k]); });
    try { if (same) localStorage.removeItem('rp_spec'); else localStorage.setItem('rp_spec', JSON.stringify(o)); } catch (e) {}
    specRedraw();
  }
  // only the picture changes: the sound keeps playing
  function specRedraw() {
    var img = d.querySelector('.rp-player img');
    if (!img) return;
    img.classList.toggle('rp-crop', !specOverride());
    img.src = specSrc(items[idx]);
    if (items[idx + 1]) (new Image()).src = specSrc(items[idx + 1]);
  }
  // rp:closed tells the page whether answers were given, so it can refresh what depends on them (species page Precision)
  function close() {
    d.querySelectorAll('audio').forEach(function (a) { a.pause(); }); d.remove(); document.removeEventListener('keydown', key);
    if (opener && opener.isConnected && opener.focus) opener.focus(); else if (el && el.isConnected && el.focus) el.focus();
    document.dispatchEvent(new CustomEvent('rp:closed', {detail: {answered: answered}}));
    // the focus goes back to what opened the player (its row's Review button when the opener left the page)
    var back = opener && document.contains(opener) ? opener : (items[idx] && document.contains(items[idx]) ? items[idx].querySelector('button.validatebtn') : null);
    if (back && back.focus) try { back.focus({preventScroll: true}); } catch (e) { back.focus(); }
  }
  // Tab and Shift+Tab cycle through the player's own controls while it is open
  function trap(e) {
    var list = Array.prototype.filter.call(d.querySelectorAll('button, select, input, textarea, a[href], [tabindex="0"]'), function (x) {
      return !x.disabled && x.getClientRects().length && getComputedStyle(x).visibility !== 'hidden';
    });
    if (!list.length) return;
    var i = list.indexOf(document.activeElement);
    if (i < 0) { e.preventDefault(); list[e.shiftKey ? list.length - 1 : 0].focus(); }
    else if (!e.shiftKey && i === list.length - 1) { e.preventDefault(); list[0].focus(); }
    else if (e.shiftKey && i === 0) { e.preventDefault(); list[list.length - 1].focus(); }
  }
  function focusFirst() {
    var b = d.classList.contains('notmode') ? d.querySelector('.rp-cause') : d.querySelector('.rp-opt:not(.rp-qopt)');
    if (b && b.getClientRects().length) try { b.focus({preventScroll: true}); } catch (e) { b.focus(); }
  }
  // the reviewer closes the player (×, Esc, a click outside): a review started from Curation goes back there, at the
  // species reviewed (curation.php scrolls to it), owner 2026-10-20
  function leave() {
    var sci = items[idx] && items[idx].dataset.sci;
    close();
    if (/[?&]review=1/.test(location.search)) {
      rpLeaveCancel();
      try { if (sci) sessionStorage.setItem('cur_return', sci); } catch (e) {}
      location.href = 'views.php?view=Curation';
    }
  }
  function key(e) {
    if (e.key === 'Tab') { trap(e); return; }
    if (e.defaultPrevented) return;  // already handled (ui-standard.js: Enter / Space on an icon button)
    if (e.target && e.target.classList && e.target.classList.contains('rp-search')) { if (e.key === 'Escape') { d.classList.remove('notmode'); focusFirst(); } return; }
    if (d.classList.contains('notmode')) {
      if (e.key === 'Escape') { d.classList.remove('notmode'); focusFirst(); return; }
      var n = parseInt(e.key, 10), alts = d.querySelectorAll('.rp-alts .rp-alt');
      if (n >= 1 && n <= alts.length) { e.preventDefault(); alts[n - 1].click(); }
      return;
    }
    if (e.target && e.target.closest && e.target.closest('.rp-spec')) return;
    if (e.key === 'Escape') { leave(); return; }
    var j = e.key === 'ArrowRight' ? step(idx, 1) : (e.key === 'ArrowLeft' ? step(idx, -1) : -2);
    if (j >= 0) { e.preventDefault(); show(j); return; }
    if (j === -1) return;
    if (e.key === ' ') {
      e.preventDefault();
      var a = d.querySelector('.rp-player audio');
      if (a) { if (a.paused) a.play().catch(function () {}); else a.pause(); }
      return;
    }
    if (e.key === 'l' || e.key === 'L') { e.preventDefault(); d.querySelector('[data-sp=loop]').click(); return; }
    if (e.key === 'z' || e.key === 'Z') { e.preventDefault(); doUndo(); return; }
    if (e.key === 'x' || e.key === 'X') { e.preventDefault(); reject('ignore'); return; }  // Ignore (owner 2026-10-23)
    var k = {y: 'yes', n: 'no', r: 'unsure', c: 'clear'}[e.key.toLowerCase()];  // R = Review later (owner 2026-10-23, was U)
    if (k) { e.preventDefault(); answer(k); }
  }
  // tools: protect / frequency shift (top right) and delete (bottom), on the current detection
  function tool(which) {
    var item = items[idx], file = item.dataset.file;
    var x = new XMLHttpRequest();
    if (which === 'lock') {
      var add = item.dataset.locked !== '1';
      // only the icon changes: the sound keeps playing
      x.onload = function () { if (this.responseText == 'OK') { item.dataset.locked = add ? '1' : '0'; toolIcons(item); d.querySelector('.rp-msg').textContent = add ? t('Protected from the disk purge') : t('No longer protected'); } };
      x.open('GET', 'play.php?excludefile=' + encodeURIComponent(file) + (add ? '&exclude_add=true' : '&exclude_del=true'), true);
      x.send();
    } else if (which === 'shift') {
      var on = item.dataset.shifted !== '1';
      var msg = d.querySelector('.rp-msg');
      msg.textContent = on ? t('Shifting the frequency…') : t('Undoing the frequency shift…');
      x.onload = function () {
        if (this.responseText == 'OK') {
          item.dataset.shifted = on ? '1' : '0';
          item.dataset.clip = '/By_Date/' + (on ? 'shifted/' : '') + file;
          msg.textContent = on ? t('Frequency shifted: press play') : t('Original frequency: press play');
          var keep = msg.textContent;
          show(idx, keep);
        } else msg.textContent = t('Not changed: {msg}', {msg: this.responseText});
      };
      x.open('GET', 'play.php?shiftfile=' + encodeURIComponent(file) + (on ? '&doshift=true' : ''), true);
      x.send();
    }
  }
  d.querySelectorAll('.rp-vol [data-vol]').forEach(function (b) { b.onclick = function () { volume(b.dataset.vol); }; });
  applyVolume(null);
  applySpeed(null);
  d.querySelectorAll('.rp-tools [data-t]').forEach(function (b) { b.onclick = function () { tool(b.dataset.t); }; });
  // Delete became Ignore (owner 2026-10-22): nothing leaves the database in a review, every detection keeps its answer
  d.querySelector('.rp-delete').onclick = function () { reject('ignore'); };
  d.querySelector('.rp-mdel').onclick = function () { reject('ignore'); };
  // Previous / Next species (owner 2026-10-18): only in a review started from Curation (review mode + its species list)
  var spnav = d.querySelector('.rp-spnav'), spSci = (items[idx] && items[idx].dataset.sci) || '';
  var inQueue = /[?&]review=1/.test(location.search) && spSci && (reviewQueueNeighbour(spSci, 1) || reviewQueueNeighbour(spSci, -1));
  spnav.style.display = inQueue ? '' : 'none';  // .rp-nav is display:flex, so not the hidden attribute
  d.querySelector('.rp-mspnav').classList.toggle('on', !!inQueue);
  d.querySelectorAll('[data-spnav]').forEach(function (b) {
    var dir = parseInt(b.dataset.spnav, 10);
    b.disabled = !reviewQueueNeighbour(spSci, dir);
    b.onclick = function () { reviewQueueNext(spSci, 0, null, dir); };
  });
  // phone: Spectrogram / Audio fold their settings (remembered in this browser)
  d.querySelectorAll('.rp-tg').forEach(function (b) {
    var cls = 'show-' + b.dataset.tg, k = 'rp_tg_' + b.dataset.tg;
    var set = function (on) { d.classList.toggle(cls, on); b.classList.toggle('on', on); try { localStorage.setItem(k, on ? '1' : '0'); } catch (e) {} };
    try { set(localStorage.getItem(k) === '1'); } catch (e) { set(false); }
    b.onclick = function () { set(!d.classList.contains(cls)); };
  });
  // phone (owner 2026-10-25): "Detection 1 of 4" and its bar on top of the fixed answer bar, always in sight, and the
  // page scrolls far enough for nothing to stay under that bar
  if (window.matchMedia('(max-width:800px)').matches) {
    var opts = d.querySelector('.rp-opts'), prog = d.querySelector('.rp-prog');
    if (opts && prog && prog.parentNode !== opts) opts.insertBefore(prog, opts.firstChild);
    var box = d.querySelector('.rp-box');
    if (opts && box && window.ResizeObserver) new ResizeObserver(function () { box.style.paddingBottom = (opts.offsetHeight + 12) + 'px'; }).observe(opts);
  }
  d.querySelector('.rp-close').onclick = leave;
  d.onclick = function (e) { if (e.target === d) leave(); };
  // the cause buttons share the look (rp-opt) but answer through reject() (bound above, with the hour option)
  d.querySelectorAll('.rp-opt:not(.rp-qopt)').forEach(function (b) { b.onclick = function () { answer(b.dataset.v); }; });
  d.querySelectorAll('[data-nav]').forEach(function (b) { b.onclick = function () { var j = step(idx, parseInt(b.dataset.nav, 10)); if (j >= 0) show(j); }; });
  d.querySelector('.rp-bar').onclick = function (e) { if (e.target.dataset.j) show(parseInt(e.target.dataset.j, 10)); };
  // Skip reviewed: on unless switched off in this browser
  var skipBox = d.querySelector('.rp-skipbox');
  try { skipBox.checked = localStorage.getItem('rp_skip') !== '0'; } catch (e) { skipBox.checked = true; }
  skipBox.onchange = function () {
    try { localStorage.setItem('rp_skip', skipBox.checked ? '1' : '0'); } catch (e) {}
    show(idx, d.querySelector('.rp-msg').textContent);
  };
  specInit();
  document.addEventListener('keydown', key);
  show(idx);
  focusFirst();
}
