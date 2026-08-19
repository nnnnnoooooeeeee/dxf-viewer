<?php
require_once __DIR__ . '/functions/dxf_storage.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$files = list_dxf_files();
function asset_url($rel)
{
    $abs = __DIR__ . '/' . $rel;
    return $rel . (is_file($abs) ? '?v=' . filemtime($abs) : '');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DXF Viewer</title>

    <link rel="stylesheet" href="<?= asset_url('assets/app.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('assets/dxf-viewer.css') ?>">
</head>

<body>
    <div class="app">
        <header class="app-header">
            <h1>DXF Viewer</h1>
            <p class="tagline">Drop <code>.dxf</code> files into <code>files/</code> and they show up here. View opens
                them on a canvas, nothing is downloaded.</p>
        </header>

        <main class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>File</th>
                        <th>Size</th>
                        <th>Modified</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$files): ?>
                        <tr>
                            <td colspan="4" class="empty">No .dxf files in <code>files/</code> yet.</td>
                        </tr>
                    <?php else:
                        foreach ($files as $path):
                            $name = basename($path);
                            $name_j = htmlspecialchars(json_encode($name, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG), ENT_QUOTES);
                            $name_u = rawurlencode($name);
                            ?>
                            <tr>
                                <td><span class="name"><?= htmlspecialchars($name) ?></span></td>
                                <td class="size"><?= number_format(filesize($path) / 1024, 1) ?> KB</td>
                                <td class="modified"><?= date('Y-m-d H:i', filemtime($path)) ?></td>
                                <td class="actions">
                                    <button type="button" class="btn primary" onclick="DxfViewer.open({
                            url:         'functions/view_dxf.php?file=<?= $name_u ?>',
                            name:        <?= $name_j ?>,
                            downloadUrl: 'functions/download_dxf.php?file=<?= $name_u ?>'
                        })">View</button>
                                    <a class="btn" href="functions/download_dxf.php?file=<?= $name_u ?>">Download</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                </tbody>
            </table>
        </main>
    </div>

    <script src="<?= asset_url('assets/dxf-viewer.js') ?>"></script>
</body>

</html>