<?php
require_once __DIR__ . '/common.php';
ensure_data_files();

$config = load_json(CONFIG_FILE, []);
$token = $_GET['token'] ?? '';

if (!$token || $token !== ($config['check_token'] ?? '')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('forbidden');
}

$ret = run_check();

header('Content-Type: application/json; charset=utf-8');
echo json_encode($ret, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);