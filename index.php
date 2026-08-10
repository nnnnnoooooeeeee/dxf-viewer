<?php
require_once __DIR__ . '/functions/dxf_storage.php';

$files = list_dxf_files();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DXF Viewer</title>

    <link rel="stylesheet" href="assets/dxf-viewer.css">

    <style>
        body {
            margin: 0;
            padding: 32px 20px;
            background: #f1f5f9;
            color: #1e293b;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }

        .wrap {
            max-width: 900px;
            margin: 0 auto;
        }

        h1 {
            font-size: 1.25rem;
            margin: 0 0 4px;
        }

        p.lede {
            margin: 0 0 24px;
            color: #64748b;
            font-size: .88rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .06), 0 10px 30px rgba(15, 23, 42, .06);
        }

        th,
        td {
            padding: 10px 14px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
            font-size: .85rem;
        }

        th {
            background: #f8fafc;
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #0284c7;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .name {
            font-family: ui-monospace, SFMono-Regular, monospace;
            font-size: .78rem;
            word-break: break-all;
        }

        td.actions {
            text-align: right;
            white-space: nowrap;
        }

        .btn {
            display: inline-block;
            padding: .25rem .7rem;
            margin-left: 4px;
            border: 1px solid #e2e8f0;
            border-radius: 7px;
            background: #fff;
            color: #1e293b;
            font: inherit;
            font-size: .78rem;
            text-decoration: none;
            cursor: pointer;
        }

        .btn:hover {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #0284c7;
        }

        .empty {
            padding: 28px 14px;
            text-align: center;
            color: #64748b;
            font-size: .85rem;
        }
    </style>
</head>

<body>
    <div class="wrap">
        <h1>DXF Viewer</h1>
        <p class="lede">Drop <code>.dxf</code> files into <code>files/</code> and they show up here. View opens them on
            a canvas, nothing is downloaded.</p>

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
                            <td><?= number_format(filesize($path) / 1024, 1) ?> KB</td>
                            <td><?= date('Y-m-d H:i', filemtime($path)) ?></td>
                            <td class="actions">
                                <button type="button" class="btn" onclick="DxfViewer.open({
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
    </div>

    <script src="assets/dxf-viewer.js"></script>
</body>

</html>