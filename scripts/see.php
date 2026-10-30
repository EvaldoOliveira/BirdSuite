<?php
/* Menu name Now (view=See, owner 2026-10-30, US-130): the pictures of the birds singing now. The detections of Now above the confidence
 * chosen in the picklist, one card per species, each shown for the period of the other picklist (1 minute at first); a
 * species that sings again goes to the front and starts its period over. Under the picture: the species card (a new tab) and Listen, the last sound of
 * the species, playing with nothing else changing on the page. Opened as views.php?view=See; scripts/see.php?data=1&conf=0.3 gives the cards. */
require_once __DIR__ . '/common.php';
set_timezone();

if (isset($_GET['data'])) {
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  header('Content-Type: application/json');
  $conf = is_numeric($_GET['conf'] ?? '') ? max(0, min(1, (float)$_GET['conf'])) : 0.3;
  $min = intval($_GET['min'] ?? 1);
  if (!in_array($min, array(1, 5, 15, 60, 240, 480, 720), true)) $min = 1;
  // a clip reaches the database some 15 s after its time: two minutes more, the page decides what is still in its period
  $now = time();
  $since = $now - $min * 60 - 120;
  $db = get_db();
  $st = $db->prepare("SELECT Sci_Name, Com_Name, Date, Time, Confidence, File_Name, MAX(Date || ' ' || Time) AS last FROM detections
                      WHERE Date BETWEEN :d0 AND :d1 AND Date || ' ' || Time >= :since AND Confidence >= :conf" . not_rejected_sql() . ' GROUP BY Sci_Name');
  ensure_db_ok($st);
  $st->bindValue(':d0', date('Y-m-d', $since));
  $st->bindValue(':d1', date('Y-m-d', $now));
  $st->bindValue(':since', date('Y-m-d H:i:s', $since));
  $st->bindValue(':conf', $conf);
  $res = $st->execute();
  $rows = array();
  while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
    $photo = species_photo($r['Sci_Name']);
    $rows[] = array('sci' => $r['Sci_Name'], 'com' => $r['Com_Name'], 'time' => $r['Time'], 'conf' => round($r['Confidence'] * 100),
      'clip' => 'By_Date/' . rawurlencode($r['Date']) . '/' . rawurlencode(species_folder($r['Sci_Name'], $r['Com_Name'], $r['File_Name'])) . '/' . rawurlencode($r['File_Name']),
      'key' => $r['File_Name'], 'last' => $r['last'], 'age' => max(0, $now - strtotime($r['last'])), 'img' => $photo ? (string)($photo['image_url'] ?? '') : '');
  }
  echo json_encode($rows, JSON_UNESCAPED_UNICODE);
  exit;
}
?>
<style>
.see { padding: 0 12px 12px; }
.see .seeline { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 10px; }
.see .seegrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
.see .seecard { border-radius: 10px; overflow: hidden; background: var(--bg,#fff); box-shadow: 0 1px 5px rgba(0,0,0,.3); }
.see .seecard img { display: block; width: 100%; aspect-ratio: 4 / 3; object-fit: contain; background: rgba(0,0,0,.08); }
.see .seecard b { display: block; padding: 8px 10px 0; font-size: 16px; }
.see .seecard small { display: block; padding: 2px 10px 8px; }
.see .seebtns { display: flex; gap: 8px; padding: 0 10px 10px; }
.see .seebtns a, .see .seebtns button { flex: 1; box-sizing: border-box; width: auto; margin: 0; padding: 6px 8px; border: 1px solid var(--accent,#2b5e22); border-radius: 14px; background: transparent; color: inherit; font: inherit; font-size: 13px; text-align: center; text-decoration: none; cursor: pointer; }
.see .seecard.seenew { animation: seenew 1.5s ease-out; }
@keyframes seenew { from { box-shadow: 0 0 0 5px var(--accent,#2b5e22); } to { box-shadow: 0 1px 5px rgba(0,0,0,.3); } }
@media (prefers-reduced-motion: reduce) { .see .seecard.seenew { animation: none; } }
</style>
<div class="see">
  <div class="seeline"><label for="seeconf"><?php echo t('Minimum confidence'); ?></label>
    <select id="seeconf"><?php foreach (array(10, 20, 30, 40, 50, 60, 70, 80, 90) as $v) echo '<option value="' . $v . '"' . ($v === 30 ? ' selected' : '') . '>' . $v . '%</option>'; ?></select>
    <label for="seeperiod"><?php echo t('Period'); ?></label>
    <select id="seeperiod"><?php foreach (array(1 => 'Last minute', 5 => 'Last 5 minutes', 15 => 'Last 15 minutes', 60 => 'Last hour', 240 => 'Last 4 hours', 480 => 'Last 8 hours', 720 => 'Last 12 hours') as $v => $l) echo '<option value="' . $v . '">' . t($l) . '</option>'; ?></select></div>
  <div class="seegrid" id="seegrid" aria-live="polite"></div>
  <p id="seeempty" hidden><?php echo t('No bird heard in this period at this confidence.'); ?></p>
</div>
<script>
(function () {
  var grid = document.getElementById('seegrid'), empty = document.getElementById('seeempty'), sel = document.getElementById('seeconf'), per = document.getElementById('seeperiod');
  var cards = {}, seq = 0, first = true;
  // one sound at a time; only the button of its card shows it
  var player = new Audio(), playing = null;
  player.preload = 'none';
  function btnState(btn, on) { btn.textContent = (on ? '\u23F8 ' + t('Pause') : '\u25B6 ' + t('Listen')); btn.setAttribute('aria-pressed', on ? 'true' : 'false'); }
  function stopped() { if (playing) btnState(playing.btn, false); playing = null; }
  player.onended = stopped;
  player.onerror = stopped;
  function listen(c) {
    if (playing === c) {
      if (player.paused) { player.play().catch(stopped); btnState(c.btn, true); }
      else { player.pause(); btnState(c.btn, false); }
      return;
    }
    stopped();
    playing = c;
    player.src = c.clip;
    btnState(c.btn, true);
    player.play().catch(stopped);
  }
  try { var saved = localStorage.getItem('see_conf'); if (saved && sel.querySelector('option[value="' + saved + '"]')) sel.value = saved;
    saved = localStorage.getItem('see_period'); if (saved && per.querySelector('option[value="' + saved + '"]')) per.value = saved; } catch (e) {}
  function refreshEmpty() { empty.hidden = grid.children.length > 0; }
  function show(r, ms) {
    var c = cards[r.sci];
    if (!c) {
      var a = document.createElement('div');
      a.className = 'seecard';
      a.appendChild(document.createElement('img'));
      a.appendChild(document.createElement('b'));
      a.appendChild(document.createElement('small'));
      var bar = document.createElement('div'), card = document.createElement('a'), btn = document.createElement('button');
      bar.className = 'seebtns';
      card.href = 'views.php?view=Bird&sci=' + encodeURIComponent(r.sci);
      card.target = '_blank';
      card.rel = 'noopener';
      card.textContent = t('Species card');
      btn.type = 'button';
      btnState(btn, false);
      bar.appendChild(card);
      bar.appendChild(btn);
      a.appendChild(bar);
      c = cards[r.sci] = {el: a, btn: btn};
      btn.onclick = listen.bind(null, c);
    }
    var img = c.el.querySelector('img');
    img.alt = r.com;
    if (img.dataset.src !== (r.img || 'none')) { img.dataset.src = r.img || 'none'; img.src = r.img || 'images/bird.png'; }
    c.el.querySelector('b').textContent = r.com;
    c.el.querySelector('small').textContent = r.time + ' · ' + r.conf + '%';
    c.key = r.key;
    c.clip = r.clip;
    c.last = r.last;
    c.until = Date.now() + ms;
    c.el.classList.remove('seenew');
    void c.el.offsetWidth;  // the highlight runs again when the same card sings again
    c.el.classList.add('seenew');
    if (!c.el.parentNode) grid.appendChild(c.el);
  }
  // always by the last detection, the newest top left: a clip may reach the database after a newer one of another species
  function order() {
    var list = Object.keys(cards).map(function (s) { return cards[s]; }).sort(function (a, b) { return a.last < b.last ? 1 : (a.last > b.last ? -1 : 0); });
    list.forEach(function (c, i) { if (grid.children[i] !== c.el) grid.insertBefore(c.el, grid.children[i] || null); });
  }
  function expire() {
    Object.keys(cards).forEach(function (s) { if (cards[s].until <= Date.now() && !(playing === cards[s] && !player.paused)) { cards[s].el.remove(); delete cards[s]; } });
    refreshEmpty();
  }
  function load() {
    if (document.hidden) return;
    var mine = ++seq, x = new XMLHttpRequest();
    x.onload = function () {
      if (mine !== seq || this.status !== 200) return;
      var rows;
      try { rows = JSON.parse(this.responseText); } catch (e) { return; }
      var period = parseInt(per.value, 10) * 60000;
      // On opening a card has what is left of its period; a new song all of it
      rows.forEach(function (r) {
        var c = cards[r.sci];
        if (c && c.key === r.key) return;
        var left = period - r.age * 1000;
        if (!first && r.age < 120) left = period;
        if (left > 0) show(r, left);
      });
      first = false;
      expire();
      order();
    };
    x.open('GET', 'scripts/see.php?data=1&conf=' + (parseInt(sel.value, 10) / 100) + '&min=' + per.value, true);
    x.send();
  }
  sel.onchange = per.onchange = function () {
    try { localStorage.setItem('see_conf', sel.value); localStorage.setItem('see_period', per.value); } catch (e) {}
    player.pause();
    stopped();
    Object.keys(cards).forEach(function (s) { cards[s].el.remove(); });
    cards = {}; first = true; load();
  };
  load();
  setInterval(load, 5000);
  setInterval(expire, 1000);
  // back on a tab that slept: what is on screen may be old
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { expire(); load(); } });
})();
</script>
