<?php
/* The view registry (US-50, owner 2026-11-04): every page of the station once, the single source of the side menu,
 * the menu levels, the login to open a page and the old addresses. views.php reads it; nothing else lists views.
 *
 * Each entry: the view key (the English menu name, owner 2026-11-02) =>
 *   group   the menu group label key, or '' for a top-level item, or null for a page outside the menu
 *   label   the menu label key (also the page title)
 *   level   essential | birder | expert: the lowest level whose menu shows the page
 *   open    viewer | operator | admin: the role needed to open it (viewer = no login)
 *   change  the role needed for a change on it (any POST)
 *   alias   old view keys that still open it
 *   when    optional switch function: the page exists only while it answers true
 *   also    optional context that shows the page below its level ('field', US-57)
 * The order of the array is the order of the menu. Included from views.php and the gate test. */

function view_registry() {
  static $r = null;
  if ($r !== null) return $r;
  $v = function ($group, $label, $level, $open = 'viewer', $extra = array()) {
    return $extra + array('group' => $group, 'label' => $label, 'level' => $level, 'open' => $open, 'change' => 'admin',
                          'alias' => array(), 'when' => null, 'also' => null);
  };
  $r = array(
    // the menu by task (US-55, owner 2026-11-04): watch, review, species, reports, alerts, the station's settings, the
    // field, the data, the system; levels (US-52): essential = watch and hear the birds, alerts, where the station is,
    // its access and health; birder = + review, lists, eBird, sharing, backup, the microphone settings; expert = all
    // Now is the start page (US-130)
    'Now' => $v('', 'str_now', 'essential'),
    'Latest' => $v('str_watch_menu', 'str_latest', 'essential'),
    'Sonogram' => $v('str_watch_menu', 'str_spectrogram_menu', 'essential', 'viewer', array('alias' => array('Spectrogram'))),
    'Analysis log' => $v('str_watch_menu', 'str_analysis_log_menu', 'expert', 'admin', array('alias' => array('Detection Log'))),
    'Review' => $v('', 'str_review_menu', 'birder', 'viewer', array('alias' => array('Validation'))),
    'Species' => $v('str_species_menu', 'str_species_menu', 'essential', 'viewer', array('alias' => array('Species Cards'))),
    // the lists: a household device on the LAN may change them without the login (US-53, change = operator)
    'Allowed species' => $v('str_species_menu', 'str_allowed_species_menu', 'birder', 'viewer', array('alias' => array('Custom'), 'change' => 'operator')),
    'Never accept' => $v('str_species_menu', 'str_never_accept_menu', 'birder', 'viewer', array('alias' => array('Excluded'), 'change' => 'operator')),
    'Always accept' => $v('str_species_menu', 'str_always_accept_menu', 'birder', 'viewer', array('alias' => array('Whitelisted'), 'change' => 'operator')),
    'Night-only species' => $v('str_species_menu', 'str_night_only_species_menu', 'birder', 'viewer', array('alias' => array('Nocturnal'), 'change' => 'operator')),
    'By Hour' => $v('str_reports_menu', 'str_by_hour', 'essential'),
    'By Week' => $v('str_reports_menu', 'str_by_week', 'essential'),
    'eBird' => $v('str_reports_menu', 'str_ebird_menu', 'birder'),
    // what leaves the station: the alerts
    'What and when' => $v('str_alerts_menu', 'str_what_and_when_menu', 'essential', 'admin', array('alias' => array('Content'))),
    'Telegram' => $v('str_alerts_menu', 'str_telegram', 'essential'),
    'E-mail' => $v('str_alerts_menu', 'str_e_mail_menu', 'essential'),
    // the station, in the order it is set up (owner 2026-10-25): where, what it hears, what it detects, the network,
    // what it shares, its look, who may enter
    'Locations' => $v('str_station_menu', 'str_locations_menu', 'essential', 'admin'),
    'Microphone' => $v('str_station_menu', 'str_microphone_menu', 'birder', 'admin', array('alias' => array('Audio'))),
    'Model' => $v('str_station_menu', 'str_model_menu', 'expert', 'admin'),
    'Model comparison' => $v('str_station_menu', 'str_model_comparison', 'expert', 'viewer', array('alias' => array('Shadow'))),
    'Network' => $v('str_station_menu', 'str_network', 'essential'),
    'Sharing' => $v('str_station_menu', 'str_sharing_menu', 'birder', 'admin', array('alias' => array('Repositories'))),
    'Themes' => $v('str_station_menu', 'str_themes', 'essential'),
    'Access' => $v('str_station_menu', 'str_access', 'essential', 'admin'),
    // the field (US-57): at Expert, or at any level on a station that runs outdoors (UI_CONTEXT)
    'Field' => $v('str_field_menu', 'str_field_page', 'expert', 'viewer', array('also' => 'field')),
    'Raw Recording' => $v('str_field_menu', 'str_raw_recording_menu', 'expert', 'admin', array('also' => 'field')),
    // the station's data: what fills the disk, backups, moving and deleting, the database
    'Disk Space' => $v('str_data_menu', 'str_disk_space', 'essential'),
    // Backup/Restore became Data (2026-10-21) and was split in two on 2026-10-24: its backups and imports are here;
    // Species › Purge Files went into Data (Purge Removed, Purge Ignored); the old addresses opened an empty page (run 5 RV2)
    'Backup/Import' => $v('str_data_menu', 'str_backup_import', 'birder', 'admin', array('alias' => array('Backup/Restore'))),
    'Data' => $v('str_data_menu', 'str_data', 'expert', 'admin', array('alias' => array('Purge Files'))),
    'Database' => $v('str_data_menu', 'str_database_menu', 'expert', 'admin'),
    // the health and control of the station; Status in every menu (owner 2026-10-24)
    'Status' => $v('str_system_menu', 'str_status', 'essential'),
    'Stations' => $v('str_system_menu', 'str_stations', 'expert', 'admin', array('when' => 'multi_station')),
    // outside the menu (owner 2026-11-04): a new station opens on it; afterwards the button of System › All settings
    // starts it again. Its login is views.php's own rule: open while the first run is pending and no password is set
    'First Run' => $v(null, 'str_first_run', 'essential'),
    'Kiosk' => $v('str_system_menu', 'str_kiosk', 'essential', 'viewer', array('when' => 'kiosk_active')),
    'Terminal' => $v('str_system_menu', 'str_terminal_menu', 'expert', 'admin', array('alias' => array('Console Login'))),
    // Advanced Settings was split among the pages that use each option (2026-10-25): every one of them is listed here
    'All settings' => $v('str_system_menu', 'str_all_settings_menu', 'expert', 'admin', array('alias' => array('Defaults', 'Advanced Settings'))),
    // pages outside the menu
    'Files' => $v(null, 'str_files_menu', 'expert', 'admin', array('alias' => array('Browse files'))),
    'External files' => $v(null, 'str_external_files', 'expert', 'admin'),
    'Adminer' => $v(null, 'str_database_menu', 'expert', 'admin'),
    'Detection' => $v(null, 'str_detection', 'essential'),
    'Hardware details' => $v(null, 'str_hardware_details', 'expert'),
  );
  return $r;
}

// the view key an address names: the key itself, or the view an old key (alias) points to; '' when unknown
function view_resolve($key) {
  $key = (string)$key;
  $r = view_registry();
  if (isset($r[$key])) return $key;
  foreach ($r as $k => $e) if (in_array($key, $e['alias'], true)) return $k;
  return '';
}

// the entry of a view (after view_resolve), or null
function view_entry($key) {
  $r = view_registry();
  return $r[$key] ?? null;
}

// the page exists now (its switch, when it has one, answers true)
function view_available($key) {
  $e = view_entry($key);
  if ($e === null) return false;
  return $e['when'] === null || (function_exists($e['when']) && call_user_func($e['when']));
}

// the levels in order; a level reaches the ones before it
function ui_levels() { return array('essential' => 0, 'birder' => 1, 'expert' => 2); }

// the menu: array of array(group label key or '', array(view => label key)), in the registry's order, only the pages
// that exist now; every level is drawn, the menu hides by class (navlvl-<level>)
function views_for_menu() {
  $out = array();
  $idx = array();
  foreach (view_registry() as $k => $e) {
    if ($e['group'] === null || !view_available($k)) continue;
    if ($e['group'] === '') { $out[] = array($e['label'], $k); continue; }
    if (!isset($idx[$e['group']])) { $idx[$e['group']] = count($out); $out[] = array($e['group'], array()); }
    $out[$idx[$e['group']]][1][$k] = $e['label'];
  }
  return $out;
}

// the views that ask for the login when opened (role above viewer)
function views_protected() {
  $p = array();
  foreach (view_registry() as $k => $e) {
    if ($e['open'] !== 'viewer') {
      $p[] = $k;
      foreach ($e['alias'] as $a) $p[] = $a;
    }
  }
  return $p;
}

// the lowest level of a menu group: the group shows from the lowest level of its pages
function view_group_level($group) {
  $lv = ui_levels();
  $min = 'expert';
  foreach (view_registry() as $k => $e) {
    if ($e['group'] === $group && view_available($k) && $lv[$e['level']] < $lv[$min]) $min = $e['level'];
  }
  return $min;
}
