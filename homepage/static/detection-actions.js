// Detection actions shared by the Recordings, Today's Detections and Overview pages: delete, change the
// species, protect from purge (lock) and frequency shift — all through play.php (owner 2026-10-08).
// US-122: window.t comes from ui_js() in the page head; a page opened on its own without it shows the English text
if (typeof window.t !== 'function') window.t = function (s, v) { if (v) for (var k in v) s = s.split('{' + k + '}').join(v[k]); return s; };
function deleteDetection(filename,copylink=false) {
  // asked through the app's modal (bsAsk, static/ui-standard.js), No is the default answer
  bsAsk(t("str_delete_this_detection_it_leaves_help"), true).then(function (ok) {
    if (!ok) return;
    const xhttp = new XMLHttpRequest();
    xhttp.onload = function() {
      if(this.responseText == "OK"){
        if(copylink == true) {
          window.top.close();
        } else {
          location.reload();
        }
      } else {
        bsNotice(this.responseText);
      }
    }
    xhttp.open("POST", "play.php?deletefile="+filename, true);
    xhttp.send();
  });
}

function toggleLock(filename, type, elem) {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    if(this.responseText == "OK"){
      if(type == "add") {
        elem.setAttribute("src","images/lock.svg");
        elem.setAttribute("title", t("str_this_file_is_excluded_from_help"));
        elem.setAttribute("onclick", elem.getAttribute("onclick").replace("add","del"));
      } else {
        elem.setAttribute("src","images/unlock.svg");
        elem.setAttribute("title", t("str_this_file_will_be_deleted_when_help"));
        elem.setAttribute("onclick", elem.getAttribute("onclick").replace("del","add"));
      }
    }
  }
  if(type == "add") {
    xhttp.open("POST", "play.php?excludefile="+filename+"&exclude_add=true", true);
  } else {
    xhttp.open("POST", "play.php?excludefile="+filename+"&exclude_del=true", true);  
  }
  xhttp.send();
  elem.setAttribute("src","images/spinner.gif");
}


// Review: reviewDetection() lives in static/review-player.js (the one review widget of the interface)
