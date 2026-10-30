<?php
/**
 * based on the upload.php example by Moxiecode Systems AB
 */

session_start(array('cookie_httponly' => true, 'cookie_samesite' => 'Lax'));  // as common.php BS_SESSION_OPTIONS (2026-10-23 test run)

define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__.'/scripts/common.php');
ensure_authenticated(t('You must be authenticated to upload backup files.'));
$home = get_home();
$user = get_user();


// Make sure file is not cached (as it happens for example on iOS devices)
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 5 minutes execution time
@set_time_limit(5 * 60);

// Uncomment this one to fake upload time
// usleep(5000);

// Chunking might be enabled
$chunk = isset($_REQUEST["chunk"]) ? intval($_REQUEST["chunk"]) : 0;
$chunks = isset($_REQUEST["chunks"]) ? intval($_REQUEST["chunks"]) : 0;

// the pipe lives in a folder of its own with a random name, made by the web server (2026-10-23 test run: the fixed
// /tmp/bird_pipe could be replaced by a symlink between unlink and mkfifo); the later chunks find it in the session
$pipe = $_SESSION['restore_pipe'] ?? '';
$log="$home/BirdSongs/restore.log";

if (!$chunks || $chunk == 0) {
    exec(station_prefix() . "ps xf | grep -v grep | grep 'BirdNET-Pi/scripts/backup_data.sh -a restore -f -' | awk '{ print $1 }'", $ProcessIDs);
    foreach ($ProcessIDs as $pid) {
        exec(station_prefix() . "kill -9 $pid");
    }
    if($pipe !== '' && file_exists($pipe)){
        unlink($pipe);
        @rmdir(dirname($pipe));
    }
    $pipe_dir = sys_get_temp_dir() . '/bs-restore-' . bin2hex(random_bytes(8));
    if (!@mkdir($pipe_dir, 0700)) {
        die('{"jsonrpc" : "2.0", "error" : {"code": 100, "message": "Failed to open temp directory."}, "id" : "id"}');
    }
    $pipe = $pipe_dir . '/bird_pipe';
    $_SESSION['restore_pipe'] = $pipe;
    if(file_exists($log)){
        unlink($log);
    }

    if (!posix_mkfifo($pipe, 0660)){
        die('{"jsonrpc" : "2.0", "error" : {"code": 100, "message": "Failed to open temp directory."}, "id" : "id"}');
    }
    $read_chunks = ($chunks > 0) ? $chunks : 1;
    $pID = shell_exec("nohup $home/BirdNET-Pi/scripts/read_chunks.sh -n $read_chunks -f " . escapeshellarg($pipe) . " | " . station_prefix() . "$home/BirdNET-Pi/scripts/backup_data.sh -a restore -f - > $log 2>&1 & echo $!");
    $_SESSION['pID'] = $pID;
}

// Open pipe
if ($pipe === '' || @filetype($pipe) !== 'fifo' || !$out = @fopen("{$pipe}", "ab")) {
	die('{"jsonrpc" : "2.0", "error" : {"code": 102, "message": "Failed to open output stream."}, "id" : "id"}');
}

if (!empty($_FILES)) {
	if ($_FILES["file"]["error"] || !is_uploaded_file($_FILES["file"]["tmp_name"])) {
		die('{"jsonrpc" : "2.0", "error" : {"code": 103, "message": "Failed to move uploaded file."}, "id" : "id"}');
	}

	// Read binary input stream and append it to temp file
	if (!$in = @fopen($_FILES["file"]["tmp_name"], "rb")) {
		die('{"jsonrpc" : "2.0", "error" : {"code": 101, "message": "Failed to open input stream."}, "id" : "id"}');
	}
} else {	
	if (!$in = @fopen("php://input", "rb")) {
		die('{"jsonrpc" : "2.0", "error" : {"code": 101, "message": "Failed to open input stream."}, "id" : "id"}');
	}
}

while ($buff = fread($in, 4096)) {
	fwrite($out, $buff);
}

@fclose($out);
@fclose($in);

// Check if file has been uploaded
if (!$chunks || $chunk == $chunks - 1) {
    $pID = $_SESSION['pID'];
    exec("ps $pID", $ProcessState);

    $i = 0;
    while ($i < 100 && count($ProcessState) >= 2) {
        unset($ProcessState);
        exec("ps $pID", $ProcessState);
        $i++;
        usleep(200000);
    }
    unlink($pipe);
    @rmdir(dirname($pipe));
    unset($_SESSION['pID'], $_SESSION['restore_pipe']);
}

// Return Success JSON-RPC response
die('{"jsonrpc" : "2.0", "result" : null, "id" : "id"}');
