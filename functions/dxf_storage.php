<?php
function resolve_dxf_path($name)
{
    $root = realpath(dirname(__DIR__) . '/files');
    if ($root === false)
        return null;

    $name = basename((string) $name);
    if ($name === '' || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'dxf')
        return null;

    $path = realpath($root . DIRECTORY_SEPARATOR . $name);
    if ($path === false)
        return null;
    if (strncmp($path, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0)
        return null;

    return $path;
}

function list_dxf_files()
{
    $root = realpath(dirname(__DIR__) . '/files');
    $files = $root === false ? [] : glob($root . '/*.dxf');
    if (!$files)
        return [];
    usort($files, function ($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    return $files;
}
