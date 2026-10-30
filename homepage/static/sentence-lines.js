// One sentence per line, on every page (owner's standard 2026-10-20, applied everywhere 2026-10-21): a full stop (or ? !)
// that ends a sentence breaks the line. Done once here for every text the pages show, whatever wrote it (templates, t(),
// tables filled later): text gets a <br>, tooltips (title) and alert / confirm messages a line break.
// A sentence ends at . ? ! after a word (or a closing bracket or quote), followed by a space and a
// capital letter; abbreviations (Min. Conf., Sens. Sigma, e.g., z. B., p. ex.) are one letter or listed in ABBR, so
// they stay on one line. CJK: after 。！？ when more text follows.
(function () {
  var END = /((?<!\p{L})\p{L}{2,}[.!?]|[)"'»”][.!?]|[.!?][)"'»”])[\s\u00a0]+(?=[\p{Lu}¿¡(«“])|([。！？])(?=[^\s」』）)])/gu;
  var SKIP = {SCRIPT: 1, STYLE: 1, TEXTAREA: 1, INPUT: 1, SELECT: 1, OPTION: 1, PRE: 1, CODE: 1, NOSCRIPT: 1, svg: 1, SVG: 1};
  // the abbreviations of the interface (column names and "e.g." in every language): no line break after them
  var ABBR = {};
  ('min max mín maks loc lok sigm sens rec sp conf konf thresh empf czuł чувств увер pewn nagr grab grav enr reg op '
    + 'eg ex ej es np bijv напр usw bzw vgl ggf tzn itp vs ca etc approx').split(' ').forEach(function (w) { ABBR[w] = 1; });

  function split(text, sep) {
    END.lastIndex = 0;
    if (!END.test(text)) return null;
    END.lastIndex = 0;
    var out = text.replace(END, function (m, a, cjk, off, all) {
      if (cjk) return cjk + sep;
      var word = a.replace(/[.!?]$/, '').toLowerCase();
      if (ABBR[word] && a.charAt(a.length - 1) === '.') return m;
      return a + sep;
    });
    return out === text ? null : out;
  }
  window.bsSentenceLines = function (s) { return typeof s === 'string' ? (split(s, '\n') || s) : s; };

  function skipped(el) {
    for (; el && el.nodeType === 1; el = el.parentNode) {
      if (SKIP[el.nodeName] || el.isContentEditable || (el.classList && el.classList.contains('nolines'))) return true;
    }
    return false;
  }
  function textNode(n) {
    // a text node already taken out of the page (replaced again before this queued pass, 2026-10-29: the askModal text)
    if (!n.parentNode || !n.nodeValue || n.nodeValue.length < 8 || skipped(n.parentNode)) return;
    var s = split(n.nodeValue, '\u0000');
    if (!s) return;
    var parts = s.split('\u0000'), frag = document.createDocumentFragment();
    parts.forEach(function (p, i) {
      if (i) frag.appendChild(document.createElement('br'));
      frag.appendChild(document.createTextNode(p));
    });
    n.parentNode.replaceChild(frag, n);
  }
  function titles(root) {
    var list = root.querySelectorAll ? root.querySelectorAll('[title]') : [];
    if (root.getAttribute && root.hasAttribute('title')) list = [root].concat(Array.prototype.slice.call(list));
    Array.prototype.forEach.call(list, function (el) {
      var s = split(el.getAttribute('title'), '\n');
      if (s) el.setAttribute('title', s);
    });
  }
  function walk(root) {
    if (root.nodeType === 3) { textNode(root); return; }
    if (root.nodeType !== 1 || skipped(root)) return;
    var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null), nodes = [], n;
    while ((n = w.nextNode())) nodes.push(n);
    nodes.forEach(textNode);
    titles(root);
  }

  var oAlert = window.alert, oConfirm = window.confirm;
  window.alert = function (m) { return oAlert.call(window, window.bsSentenceLines(m)); };
  window.confirm = function (m) { return oConfirm.call(window, window.bsSentenceLines(m)); };

  function start() {
    walk(document.body);
    // text added later (tables loaded by the pages, messages, windows)
    new MutationObserver(function (muts) {
      muts.forEach(function (m) {
        if (m.type === 'attributes') { var s = split(m.target.getAttribute('title') || '', '\n'); if (s) m.target.setAttribute('title', s); return; }
        if (m.type === 'characterData') { textNode(m.target); return; }
        m.addedNodes.forEach(walk);
      });
    }).observe(document.body, {childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['title']});
  }
  if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
})();
