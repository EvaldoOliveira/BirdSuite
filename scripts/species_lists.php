<?php
/* Station species lists (Settings > Location > Species list filter): save the active list under a name,
 * or make a station list (or a Brazilian state, built on first use) the active one (owner 2026-10-08). POST only, authenticated.
 * kind=exclude|whitelist: the same for the Excluded and Whitelisted lists (save / load / delete, owner 2026-10-20). */
error_reporting(E_ERROR);
ini_set('display_errors', 0);
require_once __DIR__ . '/common.php';
ensure_authenticated();

$home = get_home();
$dir = $home . '/BirdNET-Pi/config/species_lists/custom';
if (!is_dir($dir)) {
  mkdir($dir, 0775, true);
}
$action = $_POST['action'] ?? '';

// keep only "Scientific name_Common name" lines, without anything a shell or the config could trip on
function clean_list($text) {
  $out = array();
  foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {
    $line = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $line));
    if ($line !== '' && strpos($line, '_') !== false) {
      $out[] = $line;
    }
  }
  $out = array_values(array_unique($out));
  sort($out);
  return $out;
}

// Excluded and Whitelisted (owner 2026-10-20): saved under species_lists/exclude|whitelist/<name>.txt; loading copies the
// saved list over the current one (they are plain files, unlike the include list, which is a link chosen by SPECIES_LIST)
$kind = $_POST['kind'] ?? 'include';
if (in_array($kind, array('exclude', 'whitelist', 'nocturnal'), true)) {
  $kdir = "$dir/$kind";
  if (!is_dir($kdir)) { mkdir($kdir, 0775, true); @chmod($kdir, 0775); }
  $current = BS_LISTS . "/{$kind}_species_list.txt";
  $name = $_POST['name'] ?? '';
  if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $name)) die(t('Invalid name'));
  if ($action === 'save') {
    $lines = clean_list(is_file($current) ? file_get_contents($current) : '');
    if (empty($lines)) die(t('The current list is empty: nothing to save'));
    file_put_contents("$kdir/$name.txt", implode("\n", $lines) . "\n");
    @chmod("$kdir/$name.txt", 0664);
    echo t('Saved as {name} ({n} species).', array('name' => $name, 'n' => count($lines)));
  } elseif ($action === 'activate') {
    if (!is_file("$kdir/$name.txt")) die(t('No such list'));
    $lines = clean_list(file_get_contents("$kdir/$name.txt"));
    if (file_put_contents($current, implode("\n", $lines) . "\n") === false) die(t('Cannot write the list'));
    @file_put_contents("$kdir/.loaded", $name); // the name shown as current, even when another saved list is identical
    echo t('{name} loaded ({n} species): it applies from the next recording.', array('name' => $name, 'n' => count($lines)));
  } elseif ($action === 'delete') {
    if (!is_file("$kdir/$name.txt")) die(t('No such list'));
    unlink("$kdir/$name.txt");
    echo t('{name} deleted (the current list is unchanged).', array('name' => $name));
  } else {
    http_response_code(400);
    echo t('Unknown action');
  }
  die();
}

if ($action === 'save') {
  $name = $_POST['name'] ?? '';
  if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $name)) die(t('Invalid name'));
  $active = $home . '/BirdNET-Pi/config/species_lists/include_species_list.txt';
  $lines = clean_list(is_file($active) ? file_get_contents($active) : '');
  if (empty($lines)) die(t('The current list is empty: nothing to save'));
  file_put_contents("$dir/$name.txt", implode("\n", $lines) . "\n");
  @chmod("$dir/$name.txt", 0664); // the station user edits it too, like the other lists
  echo t('Saved as {name} ({n} species): an identical copy of the active list, Load this list makes it the active one (then edit it).', array('name' => $name, 'n' => count($lines)));
} elseif ($action === 'activate') {
  // make a station list the active one: SPECIES_LIST in BirdSuite.conf, then link the active list to it
  $name = $_POST['name'] ?? '';
  $state = preg_match('/^BR-[A-Z]{2}$/', $name) && is_file($home . "/BirdNET-Pi/model/include_lists/$name.txt");
  if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $name) || (!is_file("$dir/$name.txt") && !$state)) die(t('No such list'));
  $conf = file_get_contents(BS_CONF);
  $conf = preg_match('/^SPECIES_LIST=/m', $conf) ? preg_replace('/^SPECIES_LIST=.*/m', "SPECIES_LIST=$name", $conf)
                                                  : $conf . "\nSPECIES_LIST=$name\n";
  file_put_contents(BS_CONF, $conf);
  shell_exec(station_prefix() . 'python3 ' . escapeshellarg($home . '/BirdNET-Pi/scripts/select_species_list.py') . ' 2>&1');
  echo t('{name} is now the active species list.', array('name' => $name));
} elseif ($action === 'delete') {
  // a saved list (owner 2026-10-20); never the active one — the include list is a link to it
  $name = $_POST['name'] ?? '';
  if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $name) || !is_file("$dir/$name.txt")) die(t('No such list'));
  if (realpath($home . '/BirdNET-Pi/config/species_lists/include_species_list.txt') === realpath("$dir/$name.txt")) die(t('{name} is the active list: load another one first', array('name' => $name)));
  unlink("$dir/$name.txt");
  echo preg_match('/^BR-[A-Z]{2}$/', $name) ? t('{name} deleted (the state list is built again when loaded).', array('name' => $name)) : t('{name} deleted.', array('name' => $name));
} else {
  http_response_code(400);
  echo t('Unknown action');
}
