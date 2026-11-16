<?php
// Vercel entry point: dispatches requests to the app's top-level PHP pages.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$page = ltrim($path, '/');
if ($page === '') $page = 'index.php';
$allowed = ['index.php', 'admin.php', 'api.php'];
if (!in_array($page, $allowed, true)) { http_response_code(404); echo 'Not found'; exit; }
$_SERVER['SCRIPT_NAME'] = '/' . $page;
$_SERVER['PHP_SELF'] = '/' . $page;
chdir(dirname(__DIR__));
require dirname(__DIR__) . '/' . $page;
