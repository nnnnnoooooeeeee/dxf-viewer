<?php
require_once __DIR__ . '/dxf_viewer.php';
require_once __DIR__ . '/dxf_storage.php';

header('Content-Type: application/json; charset=utf-8');

$path = resolve_dxf_path($_GET['file'] ?? '');
if ($path === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'File not found.']);
    exit;
}

$view = dxfv_parse_file($path);
$view['name'] = basename($path);
echo json_encode($view);
