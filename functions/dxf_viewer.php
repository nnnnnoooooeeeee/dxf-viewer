<?php
defined('DXFV_MAX_BYTES') || define('DXFV_MAX_BYTES', 24 * 1024 * 1024); // anything larger is refused, not parsed
defined('DXFV_MAX_ENTITIES') || define('DXFV_MAX_ENTITIES', 60000);            // caps both the JSON size and the redraw cost
defined('DXFV_MAX_POINTS') || define('DXFV_MAX_POINTS', 600000);           // ditto — a few entities can hold a lot of vertices
defined('DXFV_MAX_DEPTH') || define('DXFV_MAX_DEPTH', 4);                // nested INSERT expansion

function dxfv_layer($e)
{
    $l = trim($e['g'][8] ?? '');
    return $l !== '' ? $l : '0';
}

function dxfv_push(&$ents, &$blocks, $blkName, $geom)
{
    if ($geom === null)
        return;
    if ($blkName !== '' && isset($blocks[$blkName]))
        $blocks[$blkName]['ents'][] = $geom;
    else
        $ents[] = $geom;
}

function dxfv_plain_text($s)
{
    $s = preg_replace('/\\\\[PX]/', ' ', $s);
    $s = preg_replace('/\\\\[A-Za-z][^;\\\\]*;/', '', $s);
    $s = str_replace(['{', '}', '%%d', '%%c', '%%p'], ['', '', '°', 'Ø', '±'], $s);
    return trim($s);
}

function dxfv_geom($e)
{
    $g = $e['g'];
    $pts = $e['pts'];
    $pts2 = $e['pts2'];
    $lay = dxfv_layer($e);

    switch ($e['t']) {
        case 'LINE':
            if (!$pts || !$pts2)
                return null;
            return ['t' => 'l', 'y' => $lay, 'p' => [$pts[0][0], $pts[0][1], $pts2[0][0], $pts2[0][1]]];

        case 'LWPOLYLINE':
            if (count($pts) < 2)
                return null;
            $flat = [];
            foreach ($pts as $p) {
                $flat[] = $p[0];
                $flat[] = $p[1];
            }
            return ['t' => 'p', 'y' => $lay, 'c' => ((int) ($g[70] ?? 0)) & 1, 'p' => $flat];

        case 'SPLINE':
            $src = $pts2 ?: $pts;
            if (count($src) < 2)
                return null;
            $flat = [];
            foreach ($src as $p) {
                $flat[] = $p[0];
                $flat[] = $p[1];
            }
            return ['t' => 'p', 'y' => $lay, 'c' => ((int) ($g[70] ?? 0)) & 1, 'p' => $flat];

        case 'CIRCLE':
            if (!$pts)
                return null;
            return ['t' => 'c', 'y' => $lay, 'p' => [$pts[0][0], $pts[0][1]], 'r' => (float) ($g[40] ?? 0)];

        case 'ARC':
            if (!$pts)
                return null;
            return [
                't' => 'a',
                'y' => $lay,
                'p' => [$pts[0][0], $pts[0][1]],
                'r' => (float) ($g[40] ?? 0),
                'a0' => (float) ($g[50] ?? 0),
                'a1' => (float) ($g[51] ?? 0)
            ];

        case 'POINT':
            if (!$pts)
                return null;
            return ['t' => 'd', 'y' => $lay, 'p' => [$pts[0][0], $pts[0][1]]];

        case 'TEXT':
        case 'MTEXT':
        case 'ATTRIB':
            $s = dxfv_plain_text(($e['pre'] ?? '') . ($g[1] ?? ''));
            if ($s === '')
                return null;
            $anchor = ($e['t'] === 'TEXT' && $pts2 && ((int) ($g[72] ?? 0) || (int) ($g[73] ?? 0)))
                ? $pts2[0] : ($pts[0] ?? null);
            if (!$anchor)
                return null;
            if ($e['t'] === 'MTEXT') {
                $dir = $pts2[0] ?? null;
                $rot = ($dir && ($dir[0] != 0.0 || $dir[1] != 0.0))
                    ? rad2deg(atan2($dir[1], $dir[0]))
                    : rad2deg((float) ($g[50] ?? 0));
            } else {
                $rot = (float) ($g[50] ?? 0);
            }
            return [
                't' => 'x',
                'y' => $lay,
                'p' => [$anchor[0], $anchor[1]],
                's' => mb_substr($s, 0, 120),
                'h' => (float) ($g[40] ?? 0),
                'g' => $rot
            ];

        case 'INSERT':
            $name = trim($g[2] ?? '');
            if ($name === '' || !$pts)
                return null;
            return [
                't' => 'i',
                'n' => $name,
                'p' => [$pts[0][0], $pts[0][1]],
                'sx' => ((float) ($g[41] ?? 1)) ?: 1.0,
                'sy' => ((float) ($g[42] ?? 1)) ?: 1.0,
                'g' => (float) ($g[50] ?? 0)
            ];
    }
    return null;
}

function dxfv_flush(&$cur, &$poly, &$ents, &$blocks, &$blkName)
{
    if ($cur === null)
        return;
    $e = $cur;
    $cur = null;

    if ($e['t'] === 'BLOCK') {
        $name = trim($e['g'][2] ?? '');
        if ($name !== '') {
            $blocks[$name] = ['base' => $e['pts'][0] ?? [0.0, 0.0], 'ents' => []];
            $blkName = $name;
        }
        return;
    }
    if ($e['t'] === 'POLYLINE') {
        $poly = ['t' => 'p', 'y' => dxfv_layer($e), 'c' => ((int) ($e['g'][70] ?? 0)) & 1, 'p' => []];
        return;
    }
    if ($e['t'] === 'VERTEX') {
        if ($poly !== null && $e['pts']) {
            $poly['p'][] = $e['pts'][0][0];
            $poly['p'][] = $e['pts'][0][1];
        }
        return;
    }
    dxfv_push($ents, $blocks, $blkName, dxfv_geom($e));
}

function dxfv_place($g, $base, $ins, $sx, $sy, $rot, $cos, $sin)
{
    $p = $g['p'];
    for ($i = 0, $n = count($p); $i < $n; $i += 2) {
        $x = ($p[$i] - $base[0]) * $sx;
        $y = ($p[$i + 1] - $base[1]) * $sy;
        $p[$i] = $ins[0] + $x * $cos - $y * $sin;
        $p[$i + 1] = $ins[1] + $x * $sin + $y * $cos;
    }
    $g['p'] = $p;
    $s = max(abs($sx), abs($sy));
    if (isset($g['r']))
        $g['r'] *= $s;
    if (isset($g['h']))
        $g['h'] *= $s;
    if (isset($g['a0'])) {
        $g['a0'] += $rot;
        $g['a1'] += $rot;
    }
    if (isset($g['g']))
        $g['g'] += $rot;
    return $g;
}

function dxfv_expand($ents, $blocks, $depth = 0, &$cache = null)
{
    if ($cache === null)
        $cache = [];
    $out = [];
    foreach ($ents as $g) {
        if ($g['t'] !== 'i') {
            $out[] = $g;
            continue;
        }
        if ($depth >= DXFV_MAX_DEPTH || !isset($blocks[$g['n']]))
            continue;

        $blk = $blocks[$g['n']];
        if (!isset($cache[$g['n']])) {
            $cache[$g['n']] = dxfv_expand($blk['ents'], $blocks, $depth + 1, $cache);
        }
        $cos = cos(deg2rad($g['g']));
        $sin = sin(deg2rad($g['g']));
        foreach ($cache[$g['n']] as $b) {
            $out[] = dxfv_place($b, $blk['base'], $g['p'], $g['sx'], $g['sy'], $g['g'], $cos, $sin);
        }
        if (count($out) > DXFV_MAX_ENTITIES)
            break;
    }
    return $out;
}

function dxfv_bounds($geoms)
{
    $minX = $minY = INF;
    $maxX = $maxY = -INF;
    foreach ($geoms as $g) {
        $r = $g['r'] ?? 0;
        $p = $g['p'];
        for ($i = 0, $n = count($p); $i < $n; $i += 2) {
            $x = $p[$i];
            $y = $p[$i + 1];
            if (!is_finite($x) || !is_finite($y))
                continue;
            if ($x - $r < $minX)
                $minX = $x - $r;
            if ($x + $r > $maxX)
                $maxX = $x + $r;
            if ($y - $r < $minY)
                $minY = $y - $r;
            if ($y + $r > $maxY)
                $maxY = $y + $r;
        }
    }
    return $minX === INF ? null : [$minX, $minY, $maxX, $maxY];
}

function dxfv_parse_file($path)
{
    $bytes = @filesize($path);
    if ($bytes === false)
        return ['ok' => false, 'message' => 'File is not readable.'];
    if ($bytes > DXFV_MAX_BYTES) {
        return [
            'ok' => false,
            'message' => 'File is too large to preview ('
                . round($bytes / 1048576, 1) . ' MB). Please download it instead.'
        ];
    }
    $fh = @fopen($path, 'rb');
    if (!$fh)
        return ['ok' => false, 'message' => 'File could not be opened.'];

    // Binary DXF has no group-code text to walk; only the ASCII flavour is previewable.
    if (fread($fh, 18) === 'AutoCAD Binary DXF') {
        fclose($fh);
        return ['ok' => false, 'message' => 'This is a binary DXF. Only ASCII DXF files can be previewed.'];
    }
    rewind($fh);

    $section = '';    // current SECTION name
    $wantSect = false; // the 2-code right after "0 SECTION" carries that name
    $hdrVar = '';    // last $-variable seen in HEADER
    $units = 0;
    $blocks = [];    // block name => ['base' => [x, y], 'ents' => [...]]
    $ents = [];    // drawing geometry, INSERTs still unexpanded
    $blkName = '';    // block being defined; '' while outside BLOCK…ENDBLK
    $cur = null;  // record being read
    $poly = null;  // POLYLINE collecting its vertices
    $truncated = false;

    while (($codeLine = fgets($fh)) !== false) {
        $valLine = fgets($fh);
        if ($valLine === false)
            break;
        $codeStr = trim($codeLine);
        $val = rtrim($valLine, "\r\n");
        if ($codeStr === '' || !ctype_digit($codeStr))
            continue;
        $code = (int) $codeStr;

        if ($code === 0) {
            $type = strtoupper(trim($val));
            $wasPolyHdr = ($cur !== null && $cur['t'] === 'POLYLINE');
            dxfv_flush($cur, $poly, $ents, $blocks, $blkName);

            if ($poly !== null && !$wasPolyHdr && $type !== 'VERTEX') {
                if (count($poly['p']) >= 4)
                    dxfv_push($ents, $blocks, $blkName, $poly);
                $poly = null;
            }

            if ($type === 'SECTION')
                $wantSect = true;
            elseif ($type === 'ENDSEC') {
                $section = '';
                $blkName = '';
            } elseif ($type === 'EOF')
                break;
            elseif ($type === 'ENDBLK')
                $blkName = '';
            elseif ($type === 'BLOCK')
                $cur = ['t' => 'BLOCK', 'g' => [], 'pts' => [], 'pts2' => []];
            elseif ($section === 'ENTITIES' || $blkName !== '') {
                $cur = ['t' => $type, 'g' => [], 'pts' => [], 'pts2' => []];
            }

            if (count($ents) >= DXFV_MAX_ENTITIES) {
                $truncated = true;
                break;
            }
            continue;
        }

        if ($wantSect && $code === 2) {
            $section = strtoupper(trim($val));
            $wantSect = false;
            continue;
        }

        if ($section === 'HEADER') {
            if ($code === 9)
                $hdrVar = trim($val);
            elseif ($code === 70 && $hdrVar === '$INSUNITS')
                $units = (int) $val;
            continue;
        }

        if ($cur === null)
            continue;

        if ($code === 10)
            $cur['pts'][] = [(float) $val, 0.0];
        elseif ($code === 20) {
            if ($cur['pts'])
                $cur['pts'][count($cur['pts']) - 1][1] = (float) $val;
        } elseif ($code === 11)
            $cur['pts2'][] = [(float) $val, 0.0];
        elseif ($code === 21) {
            if ($cur['pts2'])
                $cur['pts2'][count($cur['pts2']) - 1][1] = (float) $val;
        } elseif ($code === 3)
            $cur['pre'] = ($cur['pre'] ?? '') . $val;   // MTEXT continuation
        else
            $cur['g'][$code] = $val;
    }
    fclose($fh);
    dxfv_flush($cur, $poly, $ents, $blocks, $blkName);
    if ($poly !== null && count($poly['p']) >= 4)
        dxfv_push($ents, $blocks, $blkName, $poly);

    $geoms = dxfv_expand($ents, $blocks);
    if (count($geoms) > DXFV_MAX_ENTITIES) {
        $geoms = array_slice($geoms, 0, DXFV_MAX_ENTITIES);
        $truncated = true;
    }
    if (!$geoms) {
        return ['ok' => false, 'message' => 'No drawable geometry found in this DXF.'];
    }

    $bounds = dxfv_bounds($geoms);

    $layers = [];
    $out = [];
    $points = 0;
    foreach ($geoms as $g) {
        $points += count($g['p']) / 2;
        if ($points > DXFV_MAX_POINTS) {
            $truncated = true;
            break;
        }

        $name = $g['y'] ?? '0';
        if (!isset($layers[$name]))
            $layers[$name] = count($layers);
        $g['y'] = $layers[$name];
        foreach ($g['p'] as $i => $v)
            $g['p'][$i] = round($v, 3);
        foreach (['r', 'h', 'a0', 'a1', 'g'] as $k) {
            if (isset($g[$k]))
                $g[$k] = round($g[$k], 3);
        }
        $out[] = $g;
    }

    $unit_names = [1 => 'in', 2 => 'ft', 4 => 'mm', 5 => 'cm', 6 => 'm', 11 => 'Å', 12 => 'nm', 13 => 'µm', 14 => 'dm'];

    return [
        'ok' => true,
        'bounds' => $bounds,
        'layers' => array_map('strval', array_keys($layers)),
        'units' => $unit_names[$units] ?? '',
        'count' => count($out),
        'truncated' => $truncated,
        'ents' => $out,
    ];
}
