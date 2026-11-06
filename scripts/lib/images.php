<?php
################################################################################
# scripts/lib/images.php - PICTURES OF THE SPECIES: WIKIPEDIA CACHE AND XENO-CANTO
#
# PURPOSE:
#   The pictures of the species: the Wikipedia cache and xeno-canto: the helpers of the BirdSuite pages on
#   this subject, moved out of common.php (one file per subject, owner 2026-11-06).
#
# DEPENDENCIES:
#   - scripts/common.php (loads this file after core.php and view_registry.php; every lib may call the others)
#
# USAGE:
#   Loaded by common.php on every page; never requested on its own (404 without BS_PROG).
################################################################################

if (!defined('BS_PROG')) { http_response_code(404); exit; }

class ImageProvider {

	protected $db = null;
	protected $db_path = null;
	protected $db_reset = false;
	protected $context = null;

	public function __construct() {
		$this->set_db();
		// short timeout: a station in the field has no internet and the page waits on each species picture
		$opts = ['http' => ['header' => "User-Agent: BirdSuite", 'timeout' => 5]];
		$this->context = stream_context_create($opts);
	}

	// A miss is remembered too (owner 2026-11-06, Gitea #73), in the same table, no schema change: a row with an empty
	// image_url is "no picture". Its title tells the two kinds apart:
	//   title ''        the page answered but has no picture (or there is no page): asked again after 15-25 days, as a
	//                   picture is refreshed;
	//   title 'offline' no answer at all (a station without internet, a Wikimedia error): asked again after an hour;
	//                   date_created then holds the UTC time ('Y-m-d H:i:s'), not only the day.
	// Callers get false for both, never an empty address.
	const OFFLINE = 'offline';

	public function get_image($sci_name) {
		$image = $old = $this->get_image_from_db($sci_name);
		if ($image !== false && $this->is_expired($image)) {
			$image = false;
		}
		if ($image === false) {
			$status = $this->get_from_source($sci_name);
			if ($status === 'ok') {
				$image = $this->get_image_from_db($sci_name);
			} elseif ($old !== false && ($old['image_url'] !== '' || $old['title'] !== self::OFFLINE) && ($status === self::OFFLINE || $old['image_url'] !== '')) {
				// no answer (a station without internet), or the page lost its picture: the old picture (or the old "no
				// picture") is kept for another period instead of asking again on every page load, each time waiting for the
				// timeout
				$st = $this->db->prepare('UPDATE images SET date_created = DATE("now") WHERE sci_name == :sci_name');
				$st->bindValue(':sci_name', $sci_name);
				@$st->execute();
				$image = $this->get_image_from_db($sci_name);
			} else {
				// nothing kept: the miss itself is kept (an hour without an answer, a period when there is no picture)
				$com_en_name = get_com_en_name($sci_name) ?? ($old !== false ? (string)$old['com_en_name'] : '');
				if ($status === self::OFFLINE) {
					$this->set_image_in_db($sci_name, $com_en_name, '', self::OFFLINE, $sci_name, '', '', gmdate('Y-m-d H:i:s'));
				} else {
					$this->set_image_in_db($sci_name, $com_en_name, '', '', $sci_name, '', '');
				}
				$image = false;
			}
		}
		if ($image === false || (string)$image['image_url'] === '') return false;
		return $image;
	}

	// a kept row is asked again: a picture or "no picture" after 15-25 days, "no answer" after an hour
	protected function is_expired($row) {
		$created = (string)$row['date_created'];
		if ($row['image_url'] === '' && $row['title'] === self::OFFLINE) {
			$t = strtotime($created . ' UTC');
			return $t === false || time() - $t > 3600;
		}
		$datetime = DateTime::createFromFormat('!Y-m-d', substr($created, 0, 10), new DateTimeZone('UTC'));
		if ($datetime === false) return true;
		$interval = (new DateTime('now', new DateTimeZone('UTC')))->diff($datetime);
		return $interval->days > rand(15, 25);
	}

	public function is_reset() {
		return $this->db_reset;
	}

	// whether the last get_json() had an answer from the server (Gitea #73: no answer is remembered for an hour only,
	// "no such page" for a whole period); a 429 or 5xx counts as no answer
	protected $answered = false;

	protected function get_json($url) {
		if (function_exists('http_clear_last_response_headers')) http_clear_last_response_headers();
		$body = @file_get_contents($url, false, $this->context);
		$headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? null);
		$code = is_array($headers) && preg_match('~^HTTP/\S+\s+(\d{3})~', (string)($headers[0] ?? ''), $m) ? intval($m[1]) : 0;
		$this->answered = $code > 0 && $code < 500 && $code !== 429;
		return $body === false ? false : json_decode($body, true);
	}

	protected function set_db() {
		try {
			if ($this->db === null) {
				$db = new SQLite3($this->db_path, SQLITE3_OPEN_READWRITE);
				$this->db = $db;
			}
		} catch (Exception $ex) {
			$this->create_tables();
		}
		$this->db->busyTimeout(1000);
	}

	protected function create_tables() {
		$tbl_def = "CREATE TABLE images (sci_name VARCHAR(63) NOT NULL PRIMARY KEY, com_en_name VARCHAR(63) NOT NULL, image_url TEXT NOT NULL, title TEXT NOT NULL, id TEXT NOT NULL UNIQUE, author_url TEXT NOT NULL, license_url TEXT NOT NULL, date_created DATE)";
		$db = new SQLite3($this->db_path);
		$db->exec($tbl_def);
		$this->db_reset = true;
		$this->db = $db;
	}

	protected function delete_image_from_db($sci_name) {
		$statement0 = $this->db->prepare('DELETE FROM images WHERE sci_name == :sci_name');
		$statement0->bindValue(':sci_name', $sci_name);
		$statement0->execute();
	}

	protected function get_image_from_db($sci_name) {
		$statement0 = $this->db->prepare('SELECT sci_name, com_en_name, image_url, title, id, author_url, license_url, date_created FROM images WHERE sci_name == :sci_name');
		$statement0->bindValue(':sci_name', $sci_name);
		$result = $statement0->execute();
		$row = $result->fetchArray(SQLITE3_ASSOC);
		return $row;
	}

	// $created: null = today (UTC); the "no answer" rows keep the time too (get_image())
	protected function set_image_in_db($sci_name, $com_en_name, $image_url, $title, $id, $author_url, $license_url, $created = null) {
		$statement0 = $this->db->prepare("INSERT OR REPLACE INTO images VALUES (:sci_name, :com_en_name, :image_url, :title, :id, :author_url, :license_url, COALESCE(:created, DATE(\"now\")))");
		$statement0->bindValue(':created', $created, $created === null ? SQLITE3_NULL : SQLITE3_TEXT);
		$statement0->bindValue(':sci_name', $sci_name);
		$statement0->bindValue(':com_en_name', $com_en_name);
		$statement0->bindValue(':image_url', $image_url);
		$statement0->bindValue(':title', $title);
		$statement0->bindValue(':id', $id);
		$statement0->bindValue(':author_url', $author_url);
		$statement0->bindValue(':license_url', $license_url);
		$statement0->execute();
	}
}

class Wikipedia extends ImageProvider {

	protected $db_path = __ROOT__ . '/scripts/wikipedia.db';
	// a standard Wikimedia thumbnail width (other widths answer 400 since 2026); the pictures are shown at 90-300 px
	const THUMB_WIDTH = 330;

	// 'ok' (the picture is kept), 'none' (the page answered without a picture, or there is no page) or 'offline' (no
	// answer); get_image() remembers the misses (Gitea #73)
	protected function get_from_source($sci_name) {
		$page_title = str_replace(' ', '_', $sci_name);
		$data = $this->get_json("https://en.wikipedia.org/api/rest_v1/page/summary/$page_title");
		if ($data == false)
			return $this->answered ? 'none' : self::OFFLINE;
		if (!isset($data['originalimage']))
			return 'none';

		// the file name without the "?utm_..." query; "originalimage" may itself be a thumbnail (.../thumb/<file>/3840px-<file>)
		$image_url = strtok($data['originalimage']['source'], '?');
		$image_name = substr($image_url, strrpos($image_url, '/') + 1);
		if (strpos($image_url, '/thumb/') !== false) {
			$parts = explode('/', $image_url);
			$image_name = $parts[count($parts) - 2];
		}
		// iiurlwidth: Commons answers with the URL of a small thumbnail (2026-10-23 test run: the stored originals and
		// 3840 px thumbnails were up to 45 MB, downloaded on every Now / Kiosk load for a 90 px picture)
		$metadata = $this->get_json("https://commons.wikimedia.org/w/api.php?action=query&titles=File:$image_name&prop=imageinfo&iiprop=extmetadata|size|url&iiurlwidth=" . self::THUMB_WIDTH . "&format=json");
		if ($metadata == false or !isset($metadata['query']['pages']))
			return self::OFFLINE;  // the page has a picture: asked again within the hour

		$title = $data['title'];

		foreach ($metadata['query']['pages'] as $page) {
			$details = $page['imageinfo']['0']['extmetadata'];
			$author = $details['Artist']['value'];
			$matches = [];
			if (preg_match('/href="(http\S*)"/', $author, $matches)) {
				$author_url = $matches[1];
			} else {
				$author_url = $this->get_external_link($image_url);
			}
			if (isset($details['LicenseUrl'])) {
				$license_url = $details['LicenseUrl']['value'];
			} else {
				$license_url = $this->get_external_link($image_url);
			}
			if (!empty($page['imageinfo'][0]['thumburl'])) {
				$image_url = strtok($page['imageinfo'][0]['thumburl'], '?');
			} elseif (isset($data['thumbnail']['source'])) {
				$image_url = strtok($data['thumbnail']['source'], '?');
			}
		}

		// a species the model's English labels do not have (a CBRO name such as Troglodytes musculus): the title of its
		// Wikipedia page; the column is NOT NULL, a null lost the picture without a word (owner 2026-10-30)
		$engname = get_com_en_name($sci_name) ?? $title;

		//                     $sci_name, $com_en_name, $image_url, $title, $id, $author_url, $license_url
		$this->set_image_in_db($sci_name, $engname, $image_url, $title, $sci_name, $author_url, $license_url);
		return 'ok';
	}

	public function get_image($sci_name) {
		$image = parent::get_image($sci_name);
		if ($image === false)
			return false;

		$image['photos_url'] = $this->get_external_link($image['image_url']);
		// the pages show the station's own copy (scripts/species_image.php: downloaded once, then served locally, so the
		// pictures also show offline, US-22 / US-29); the Wikimedia address stays in source_url
		$image['source_url'] = $image['image_url'];
		$image['image_url'] = '/scripts/species_image.php?sci=' . rawurlencode($sci_name) . '&v=' . rawurlencode((string)$image['date_created']);
		return $image;
	}

	// the Wikimedia address of the species' picture as kept (no lookup), or '' (also for a remembered miss, Gitea #73)
	public function source_url($sci_name) {
		$row = $this->get_image_from_db($sci_name);
		return $row ? (string)$row['image_url'] : '';
	}

	private function get_external_link($image_url) {
		if (strpos($image_url, '/commons/thumb/') !== false) {
			$parts = explode('/', $image_url);
			$image_name = $parts[count($parts) - 2];
		} else {
			$image_name = substr($image_url, strrpos($image_url, '/') + 1);
		}
		$photo_url = "https://en.wikipedia.org/wiki/File:$image_name";
		return $photo_url;
	}
}

// Photo of a species from Wikipedia (cached in its own database); false when none
function species_photo($sciname) {
	static $provider = null;
	if ($provider === null) $provider = new Wikipedia();
	try { return $provider->get_image($sciname); } catch (Throwable $e) { return false; }
}

// The best xeno-canto recording of a species (owner 2026-11-01: its spectrogram under the picture of the species page):
// the first of the sonograms view sorted by quality (A first), of the current location's country when known, else of
// anywhere. Kept 30 days in state/xc_best.json (by species and country; a miss is kept a day). Returns nr, by (recordist),
// lic (licence URL), img (grey-medium spectrogram), page, or null. Reads xeno-canto's public pages (API v2 is gone,
// v3 needs a key), 5 s at most.
// the same, from the kept list only (no network): the entry, null for a kept miss, false when nothing is kept yet; the
// species page draws a kept one at once, so the box never pushes the page down after it is drawn (release run 4, PERF-44)
function xc_best_kept($sci) {
	$cache = json_decode((string)@file_get_contents(BS_STATE . '/xc_best.json'), true) ?: array();
	$e = $cache[$sci . '|' . location_country()] ?? null;
	if (!is_array($e) || time() - intval($e['at'] ?? 0) >= (empty($e['nr']) ? 86400 : 30 * 86400)) return false;
	if (empty($e['nr'])) return null;
	return !empty($e['local']) ? $e : false;
}

function xc_best($sci) {
	$cnt = location_country();
	$key = $sci . '|' . $cnt;
	$path = BS_STATE . '/xc_best.json';
	$cache = json_decode((string)@file_get_contents($path), true) ?: array();
	// a found recording without its kept spectrogram (an entry from before the copy) is looked up again
	if (isset($cache[$key]) && (empty($cache[$key]['nr']) || !empty($cache[$key]['local']))
			&& time() - intval($cache[$key]['at'] ?? 0) < (empty($cache[$key]['nr']) ? 86400 : 30 * 86400)) {
		return empty($cache[$key]['nr']) ? null : $cache[$key];
	}
	$ctx = stream_context_create(array('http' => array('timeout' => 5, 'header' => "User-Agent: BirdSuite (species page)\r\n")));
	$best = null;
	foreach ($cnt !== '' ? array($sci . ' cnt:' . $cnt, $sci) : array($sci) as $query) {
		$html = (string)@file_get_contents('https://xeno-canto.org/explore?query=' . rawurlencode($query) . '&view=3&order=qual&dir=0', false, $ctx);
		if (!preg_match("~<a rel='sono' title='XC(\d+): [^']* by ([^']*)' href='[^']*'>\s*<img class='spectro-image' src='(https://xeno-canto\.org/sounds/spectrograms/[^']+?)grey-small\.png'~", $html, $m)) continue;
		$lic = preg_match("~<a href='(https://creativecommons\.org/licenses/[^']+)'~", substr($html, max(0, strpos($html, 'xc_audio_' . $m[1]) ?: 0), 4000), $l) ? $l[1] : '';
		$best = array('nr' => $m[1], 'by' => html_entity_decode($m[2], ENT_QUOTES), 'lic' => $lic, 'img' => $m[3] . 'grey-medium.png',
			'page' => 'https://xeno-canto.org/' . $m[1], 'country' => strpos($query, ' cnt:') !== false ? $cnt : '');
		break;
	}
	// the spectrogram kept on the station (state/xc/<nr>.png) and served from it: a page never loads it from xeno-canto
	if ($best) {
		$dir = BS_STATE . '/xc';
		$file = $dir . '/' . $best['nr'] . '.png';
		if (!is_file($file)) {
			@mkdir($dir, 0775, true);
			$png = @file_get_contents($best['img'], false, $ctx);
			if ($png !== false && strncmp($png, "\x89PNG", 4) === 0 && @file_put_contents($file, $png, LOCK_EX)) @chmod($file, 0664);
		}
		if (is_file($file)) $best['local'] = 'scripts/species_page.php?xcimg=' . $best['nr'];
	}
	$cache[$key] = ($best ?: array()) + array('at' => time());
	if (@file_put_contents($path, json_encode($cache), LOCK_EX)) @chmod($path, 0664);  // the web server and the station user
	return $best;
}
