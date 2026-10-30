// Detection actions shared by the Recordings, Today's Detections and Overview pages: delete, change the
// species, protect from purge (lock) and frequency shift — all through play.php (owner 2026-10-08).
// US-122: window.t comes from ui_js() in the page head; a page opened on its own without it shows the English text
if (typeof window.t !== 'function') window.t = function (s, v) { if (v) for (var k in v) s = s.split('{' + k + '}').join(v[k]); return s; };
function deleteDetection(filename,copylink=false) {
  if (confirm(t("Delete this detection? It leaves the lists, charts and statistics and moves to the Removed folder (erased for good only by hand, in Tools › Data › Purge).")) == true) {
    const xhttp = new XMLHttpRequest();
    xhttp.onload = function() {
      if(this.responseText == "OK"){
        if(copylink == true) {
          window.top.close();
        } else {
          location.reload();
        }
      } else {
        alert(this.responseText);
      }
    }
    xhttp.open("GET", "play.php?deletefile="+filename, true);
    xhttp.send();
  }
}

function toggleLock(filename, type, elem) {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    if(this.responseText == "OK"){
      if(type == "add") {
        elem.setAttribute("src","images/lock.svg");
        elem.setAttribute("title", t("This file is excluded from being purged."));
        elem.setAttribute("onclick", elem.getAttribute("onclick").replace("add","del"));
      } else {
        elem.setAttribute("src","images/unlock.svg");
        elem.setAttribute("title", t("This file will be deleted when disk space needs to be freed."));
        elem.setAttribute("onclick", elem.getAttribute("onclick").replace("del","add"));
      }
    }
  }
  if(type == "add") {
    xhttp.open("GET", "play.php?excludefile="+filename+"&exclude_add=true", true);
  } else {
    xhttp.open("GET", "play.php?excludefile="+filename+"&exclude_del=true", true);  
  }
  xhttp.send();
  elem.setAttribute("src","images/spinner.gif");
}

function toggleShiftFreq(filename, shiftAction, elem) {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    if(this.responseText == "OK"){
      if(shiftAction == "shift") {
        elem.setAttribute("src","images/unshift.svg");
        elem.setAttribute("title", t("This file has been shifted down in frequency."));
        elem.setAttribute("onclick", elem.getAttribute("onclick").replace("shift","unshift"));
	console.log("shifted freqs of " + filename);
        const audioDiv = elem.parentNode.querySelector(".custom-audio-player");
        if (audioDiv) {
          audioDiv.setAttribute("data-audio-src", audioDiv.getAttribute("data-audio-src").replace("/By_Date/", "/By_Date/shifted/"));
        } else {
          const atag = elem.parentNode.querySelector("a");
          if (atag) {
            atag.setAttribute("href", atag.getAttribute("href").replace("/By_Date/", "/By_Date/shifted/"));
          }
        }
      } else {
        elem.setAttribute("src","images/shift.svg");
        elem.setAttribute("title", t("This file is not shifted in frequency."));
        elem.setAttribute("onclick", elem.getAttribute("onclick").replace("unshift","shift"));
        console.log("unshifted freqs of " + filename);
        const audioDiv = elem.parentNode.querySelector(".custom-audio-player");
        if (audioDiv) {
          audioDiv.setAttribute("data-audio-src", audioDiv.getAttribute("data-audio-src").replace("/By_Date/shifted/", "/By_Date/"));
        } else {
          const atag = elem.parentNode.querySelector("a");
          if (atag) {
            atag.setAttribute("href", atag.getAttribute("href").replace("/By_Date/shifted/", "/By_Date/"));
          }
        }
      }
    }
  }
  if(shiftAction == "shift") {
    console.log("shifting freqs of " + filename);
    xhttp.open("GET", "play.php?shiftfile="+filename+"&doshift=true", true);
  } else {
    console.log("unshifting freqs of " + filename);
    xhttp.open("GET", "play.php?shiftfile="+filename, true);  
  }
  xhttp.send();
  elem.setAttribute("src","images/spinner.gif");
}

// Review: reviewDetection() lives in static/review-player.js (the one review widget of the interface)
