<?php
/* What FILTER_SANITIZE_STRING did (deprecated since PHP 8.1, 2026-10-23 run 3 MNT-06), kept so every page reads the
 * request exactly as before: tags (and NUL bytes) stripped, ' and " encoded as &#39; and &#34;. Read from the raw
 * request each time, like filter_input_array, so a page included by another one is not encoded twice; null when the
 * request has no such part, as before. */
function bs_sanitize_value($v) {
  if (is_array($v)) return array_map('bs_sanitize_value', $v);
  if (!is_string($v)) return $v;
  // tags with spaces inside ("< b >") went too: the old filter allowed spaces in a tag
  $v = preg_replace('/<[^>]*(>|$)/s', '', strip_tags($v));
  return str_replace(array("'", '"'), array('&#39;', '&#34;'), $v);
}
function bs_sanitize_input($type) {
  $raw = filter_input_array($type, FILTER_UNSAFE_RAW);
  return is_array($raw) ? array_map('bs_sanitize_value', $raw) : $raw;
}
