<?php

/* Prevent XSS input */
require_once 'scripts/sanitize_input.php';
$_GET   = bs_sanitize_input(INPUT_GET);
$_POST  = bs_sanitize_input(INPUT_POST);

ini_set('user_agent', 'PHP_Flickr/1.0');
error_reporting(E_ERROR);
ini_set('display_errors', 0);
require_once 'scripts/common.php';
$home = get_home();

$result = fetch_species_array($_GET['sort']);

if(!file_exists($home."/BirdNET-Pi/config/scripts/disk_check_exclude.txt") || strpos(file_get_contents($home."/BirdNET-Pi/config/scripts/disk_check_exclude.txt"),"##start") === false) {
  file_put_contents($home."/BirdNET-Pi/config/scripts/disk_check_exclude.txt", "");
  file_put_contents($home."/BirdNET-Pi/config/scripts/disk_check_exclude.txt", "##start\n##end\n");
}

if (get_included_files()[0] === __FILE__) {
  echo '<!DOCTYPE html>
<html lang="' . str_replace('_', '-', ui_lang()) . '">
<head>
' . ui_js() . '
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>BirdSuite</title>
</head>';
}
?>

<div class="stats">
<div class="column">
<div style="width: auto;
   text-align: center">
   <form action="views.php" method="GET">
    <input type="hidden" name="sort" value="<?php if(isset($_GET['sort'])){echo $_GET['sort'];}?>">
      <input type="hidden" name="view" value="Species Stats">
      <span class="sortlabel"><?php echo t('Sort by:'); ?></span>
      <button <?php if(!isset($_GET['sort']) || $_GET['sort'] == "alphabetical"){ echo "class='sortbutton active'";} else { echo "class='sortbutton'"; }?> type="submit" name="sort" value="alphabetical">
         <span title="<?php echo t('Sort by alphabetical'); ?>"><?php echo t('Name'); ?></span>
      </button>
      <button <?php if(isset($_GET['sort']) && $_GET['sort'] == "occurrences"){ echo "class='sortbutton active'";} else { echo "class='sortbutton'"; }?> type="submit" name="sort" value="occurrences">
         <span title="<?php echo t('Sort by occurrences'); ?>"><?php echo t('Occur.'); ?></span>
      </button>
      <button <?php if(isset($_GET['sort']) && $_GET['sort'] == "confidence"){ echo "class='sortbutton active'";} else { echo "class='sortbutton'"; }?> type="submit" name="sort" value="confidence">
         <span title="<?php echo t('Sort by confidence'); ?>"><?php echo t('Conf.'); ?></span>
      </button>
      <button <?php if(isset($_GET['sort']) && $_GET['sort'] == "date"){ echo "class='sortbutton active'";} else { echo "class='sortbutton'"; }?> type="submit" name="sort" value="date">
         <span title="<?php echo t('Sort by date'); ?>"><?php echo t('Date'); ?></span>
      </button>
   </form>
</div>
<br>
<?php if (isset($_GET['species'])) { // the gallery replaces the list while no species is chosen ?>
<form action="views.php" method="GET">
<input type="hidden" name="sort" value="<?php if(isset($_GET['sort'])){echo $_GET['sort'];}?>">
<input type="hidden" name="view" value="Species Stats">
<table>
  <?php
  $birds = array();
  $values = array();

  while($results=$result->fetchArray(SQLITE3_ASSOC))
  {
    $comname = species_folder($results['Sci_Name'] ?? '', $results['Com_Name'], $results['File_Name']);
    $filename = "/By_Date/".$results['Date']."/".$comname."/".$results['File_Name'];
    $birds[] = $results['Com_Name'];
    $values[] = get_label($results, $_GET['sort']);
  }

  if(count($birds) > 45) {
    $num_cols = 3;
  } else {
    $num_cols = 1;
  }
  $num_rows = ceil(count($birds) / $num_cols);

  for ($row = 0; $row < $num_rows; $row++) {
    echo "<tr>";

    for ($col = 0; $col < $num_cols; $col++) {
      $index = $row + $col * $num_rows;

      if ($index < count($birds)) {
        ?>
        <td>
            <button type="submit" name="species" value="<?php echo $birds[$index];?>"><?php echo $values[$index];?></button>
        </td>
        <?php
      } else {
        echo "<td></td>";
      }
    }

    echo "</tr>";
  }
  ?>
</table>
</form>
<?php } ?>
</div>
<dialog style="margin-top: 5px;max-height: 95vh;
  overflow-y: auto;overscroll-behavior:contain" id="attribution-dialog">
  <h1 id="modalHeading"></h1>
  <p id="modalText"></p>
  <button onclick="hideDialog()"><?php echo t('Close'); ?></button>
</dialog>
<script src="static/dialog-polyfill.js"></script>
<script src="static/custom-audio-player.js"></script>
<script src="static/detection-actions.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/detection-actions.js"); ?>"></script>
<script src="static/review-player.js"></script>
<script>
var dialog = document.querySelector('dialog');
dialogPolyfill.registerDialog(dialog);

function showDialog() {
  document.getElementById('attribution-dialog').showModal();
}

function hideDialog() {
  document.getElementById('attribution-dialog').close();
}

function setModalText(iter, title, text, authorlink) {
  document.getElementById('modalHeading').innerHTML = "Photo "+iter+": \""+title+"\" Attribution";
  document.getElementById('modalText').innerHTML = "<div style='white-space:nowrap'>Image link: <a target='_blank' href="+text+">"+text+"</a><br>Author link: <a target='_blank' href="+authorlink+">"+authorlink+"</a></div>";
  showDialog();
}
</script>  
<div class="column center">
<?php if(!isset($_GET['species'])){
  // Gallery (owner 2026-10-08): the best detection of every species side by side — names, links,
  // detections, best confidence and its clip; sorted like the buttons above; a click opens the species page
  $gallery = fetch_species_array($_GET['sort'] ?? 'alphabetical');
  echo '<input type="search" placeholder="' . htmlspecialchars(t('Filter species...'), ENT_QUOTES) . '" oninput="galFilter(this.value)" style="width:100%;max-width:360px;margin:4px 0">';
  echo '<div class="spgallery" id="spgallery">';
  while ($g = $gallery->fetchArray(SQLITE3_ASSOC)) {
    $folder = species_folder($g['Sci_Name'] ?? '', $g['Com_Name'], $g['File_Name']);
    $clip = '/By_Date/' . $g['Date'] . '/' . $folder . '/' . $g['File_Name'];
    $page = 'views.php?view=Bird&amp;sci=' . rawurlencode($g['Sci_Name']);
    echo '<div class="gcard"' . review_item_attrs($g['Date'] . '/' . $folder . '/' . $g['File_Name'], $g['Com_Name'] . ', ' . $g['Date'] . ' ' . $g['Time'] . ', ' . round($g['MaxConfidence'] * 100) . '%', $g['Sci_Name'], array('Date' => $g['Date'], 'Time' => $g['Time'], 'Com_Name' => $g['Com_Name'], 'Confidence' => $g['MaxConfidence'])) . ' data-q="' . htmlspecialchars(mb_strtolower($g['Com_Name'] . ' ' . $g['Sci_Name']), ENT_QUOTES) . '">'
      . '<div class="gbody">' . species_title($g['Sci_Name'], '<a href="' . $page . '" title="' . htmlspecialchars(t('Open the species page'), ENT_QUOTES) . '"><b>' . htmlspecialchars($g['Com_Name']) . '</b></a>', '', false, false)
      . '<div class="gmeta">' . (intval($g['Count']) == 1 ? t('1 detection') : t('{n} detections', array('n' => fmt_num(intval($g['Count']))))) . ', ' . t('best {pct}%', array('pct' => round($g['MaxConfidence'] * 100))) . ', ' . htmlspecialchars($g['Date'] . ' ' . $g['Time']) . '</div>'
      . '<img class="gspec" loading="lazy" src="' . htmlspecialchars($clip) . '.png" onerror="if (/\\.png$/.test(this.src)) this.src = this.src.replace(/\\.png$/, \'.jpg\')" alt="' . htmlspecialchars(t('Spectrogram'), ENT_QUOTES) . '" title="' . htmlspecialchars(t('Listen and review'), ENT_QUOTES) . '" onclick="reviewDetection(this)"></div></div>';
  }
  echo '</div><script>function galFilter(q){q=q.toLowerCase();document.querySelectorAll("#spgallery .gcard").forEach(function(c){c.style.display=c.dataset.q.indexOf(q)<0?"none":"";});}'
    . '</script><script src="static/spectro-dialog.js"></script>';
};?>
<?php if(isset($_GET['species'])){
  $species = $_GET['species'];
  $iter=0;
  $config = get_config();
  $result3 = fetch_best_detection(htmlspecialchars_decode($_GET['species'], ENT_QUOTES));
while($results=$result3->fetchArray(SQLITE3_ASSOC)){
  $count = $results['COUNT(*)'];
  $maxconf = round((float)round($results['MAX(Confidence)'],2) * 100 ) . '%';
  $date = $results['Date'];
  $time = $results['Time'];
  $name = $results['Com_Name'];
  $sciname = $results['Sci_Name'];
  $dbsciname = preg_replace('/ /', '_', $sciname);
  $comname = species_folder($results['Sci_Name'] ?? '', $results['Com_Name'], $results['File_Name']);
  $linkname = preg_replace('/_/', '+', $dbsciname);
  $filename = "/By_Date/".$date."/".$comname."/".$results['File_Name'];
  $engname = get_com_en_name($sciname);

  $info_url = get_info_url($results['Sci_Name']);
  $url = $info_url['URL'];
  $url_title = $info_url['TITLE'];
  echo str_pad("<h3>" . species_title($sciname, $species) . "</h3>
    <table><tr>
  <td class=\"relative\">" . detection_actions($date . '/' . $comname . '/' . $results['File_Name']) . "
  " . t('Occurrences:') . " $count<br>
  " . t('Max Confidence:') . " $maxconf<br>
  " . t('Best Detection:') . " $date $time<br><br>
  <div class='custom-audio-player' data-audio-src=\"$filename\" data-image-src=\"$filename.png\"></div></td>
  </tr>
    </table>", '6096');
  
  echo "<script>document.getElementsByTagName(\"h3\")[0].scrollIntoView();</script>";
  
  ob_flush();
  flush();

  if (false) {  // Flickr photos retired (owner 2026-10-08)
    $flickrjson = json_decode(file_get_contents("https://www.flickr.com/services/rest/?method=flickr.photos.search&api_key=".$config["FLICKR_API_KEY"]."&text=\"".str_replace(' ', '%20', $engname)."\"&license=2%2C3%2C4%2C5%2C6%2C9&sort=relevance&per_page=15&format=json&nojsoncallback=1"), true)["photos"]["photo"];

    foreach ($flickrjson as $val) {

      $iter++;
      $modaltext = "https://flickr.com/photos/".$val["owner"]."/".$val["id"];
      $authorlink = "https://flickr.com/people/".$val["owner"];
      $imageurl = 'https://farm' .$val["farm"]. '.static.flickr.com/' .$val["server"]. '/' .$val["id"]. '_'  .$val["secret"].  '.jpg';
      echo "<span style='cursor:pointer;' onclick='setModalText(".$iter.",\"".$val["title"]."\",\"".$modaltext."\", \"".$authorlink."\")'><img style='vertical-align:top' src=\"$imageurl\"></span>";
    }
  }
}
}
?>
<?php if(isset($_GET['species'])){?>
<br><br>
<div class="brbanner"><?php echo t('Best Detections for Other Species:'); ?></div><br>
<?php } else {?>
<hr><br>
<?php } ?>
  <form action="views.php" method="GET">
    <input type="hidden" name="sort" value="<?php if(isset($_GET['sort'])){echo $_GET['sort'];}?>">
    <input type="hidden" name="view" value="Species Stats">
    <table>
<?php
$excludelines = [];
while($results=$result->fetchArray(SQLITE3_ASSOC))
{
$comname = species_folder($results['Sci_Name'] ?? '', $results['Com_Name'], $results['File_Name']);
$filename = "/By_Date/".$results['Date']."/".$comname."/".$results['File_Name'];

array_push($excludelines, $results['Date']."/".$comname."/".$results['File_Name']);
array_push($excludelines, $results['Date']."/".$comname."/".$results['File_Name'].".png");
?>
      <tr>
      <td class="relative"><?php echo detection_actions($results['Date'] . '/' . $comname . '/' . $results['File_Name']); ?>
        <?php echo species_title($results['Sci_Name'], '<button type="submit" name="species" value="' . $results['Com_Name'] . '">' . $results['Com_Name'] . '</button>'); ?><b><?php echo t('Occurrences:'); ?></b> <?php echo $results['Count'];?><br>
      <b><?php echo t('Max Confidence:'); ?></b> <?php echo $percent = round((float)round($results['MaxConfidence'],2) * 100 ) . '%';?><br>
      <b><?php echo t('Best Detection:'); ?></b> <?php echo $results['Date']." ".$results['Time'];?><br><div class='custom-audio-player' data-audio-src="<?php echo $filename; ?>" data-image-src="<?php echo $filename.".png"; ?>"></div></td>
      </tr>
<?php
}

// the automatic protection (##start..##end) is written by purge_protection.py: top N per species + confirmed
?>
    </table>
  </form>
</div>
</div>
<?php
if (get_included_files()[0] === __FILE__) {
  echo '</body></html>';
}
