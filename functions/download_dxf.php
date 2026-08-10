<?php
require_once __DIR__ . '/dxf_storage.php';

$path = resolve_dxf_path($_GET['file'] ?? '');
if ($path === null) {
    http_response_code(404);
    exit('File not found.');
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . addslashes(basename($path)) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
