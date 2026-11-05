<?php
/* The First Run presets (US-53, owner 2026-11-04): the answer to "How will you use BirdSuite?" turns into a few settings.
 * A preset writes a key only while it is untouched: still at its factory value, or at the value the previous preset
 * gave it, so choosing a preset (again, later) never overwrites a setting the owner changed by hand.
 * Included by setup_wizard.php. */

function ui_presets() {
  return array(
    // see and hear the birds of the home or garden
    'home' => array('UI_LEVEL' => 'essential'),
    // review the detections, keep lists, send to eBird
    'birder' => array('UI_LEVEL' => 'birder'),
    // several places or stations, raw recordings, model comparison: every page
    'research' => array('UI_LEVEL' => 'expert'),
  );
}

// the keys a preset would write now: array(key => value), only the untouched ones
function preset_changes($preset, $config) {
  $all = ui_presets();
  if (!isset($all[$preset])) return array();
  $factory = birdsuite_defaults();
  $prev = $all[trim((string)($config['UI_PRESET'] ?? ''), " \"'")] ?? array();
  $out = array();
  foreach ($all[$preset] as $k => $v) {
    $cur = trim((string)($config[$k] ?? ''), " \"'");
    $untouched = !array_key_exists($k, $config) || $cur === trim((string)($factory[$k] ?? ''), " \"'") || (isset($prev[$k]) && $cur === $prev[$k]);
    if ($untouched) $out[$k] = $v;
  }
  $out['UI_PRESET'] = $preset;
  return $out;
}

// the alert choice of the First Run: what is sent (the channel itself is set up on its page afterwards)
//   rare  = only the rare tier (the other species muted by default), first = the first detection of each species each
//   day, every = every detection, new = the factory behaviour (the first detection of a species ever, and the rare ones)
function alert_changes($what) {
  switch ($what) {
    case 'rare': return array('NOTIFICATION_DEFAULT_TIER' => 'muted', 'APPRISE_NOTIFY_EACH_DETECTION' => '0', 'APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY' => '0');
    case 'first': return array('NOTIFICATION_DEFAULT_TIER' => 'normal', 'APPRISE_NOTIFY_EACH_DETECTION' => '0', 'APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY' => '1');
    case 'every': return array('NOTIFICATION_DEFAULT_TIER' => 'normal', 'APPRISE_NOTIFY_EACH_DETECTION' => '1');
    default: return array();
  }
}
