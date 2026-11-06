<?php
################################################################################
# scripts/core.php - THE PHP READER OF CORE
#
# PURPOSE:
#   The PHP reader of core.env (owner 2026-11-02): every BS_* key of that file as a constant, read as text
#   (only ${KEY} of an earlier key is replaced). Seeds: the program folder is the one above this file, BS_HOME
#   the folder above that. Loaded by common.php.
#
# DEPENDENCIES:
#   - scripts/common.php
#
# USAGE:
#   Referenced by homepage/index.php, scripts/common.php, scripts/lib/auth.php, scripts/lib/clips.php,
#   scripts/lib/db.php, scripts/lib/detections.php, scripts/lib/files.php …
################################################################################

if (!defined('BS_PROG')) {
	(function () {
		$seed = array('BS_PROG' => dirname(__DIR__), 'BS_HOME' => dirname(dirname(__DIR__)));
		$v = $seed;
		foreach (@file(__DIR__ . '/core.env', FILE_IGNORE_NEW_LINES) ?: array() as $line) {
			if (!preg_match('/^(BS_[A-Z0-9_]+)=(.*)$/', $line, $m) || isset($v[$m[1]])) continue;
			$v[$m[1]] = preg_replace_callback('/\$\{(BS_[A-Z0-9_]+)\}/', function ($r) use (&$v) { return $v[$r[1]] ?? ''; }, $m[2]);
		}
		foreach ($v as $k => $val) define($k, $val);
	})();
}
