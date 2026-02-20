<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (ob_get_level() > 0) { @ob_end_clean(); }
ob_start();

@session_start();

$out = [
  'time' => date('c'),
  'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
  'host' => $_SERVER['HTTP_HOST'] ?? '',
  'https' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  'cookies_received' => array_keys($_COOKIE ?? []),
  'cookie_values_preview' => (function () {
      $p = [];
      foreach (($_COOKIE ?? []) as $k => $v) {
          $p[$k] = is_string($v) ? substr($v, 0, 12) . '…' : gettype($v);
      }
      return $p;
  })(),
  'php_session_name' => session_name(),
  'php_session_id' => session_id(),
  'session_keys' => array_keys($_SESSION ?? []),
];

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);