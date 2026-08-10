(function (global) {
    'use strict';

    var PALETTE = ['#0284c7', '#db2777', '#059669', '#d97706', '#7c3aed', '#ea580c', '#0891b2', '#dc2626', '#4d7c0f', '#475569'];

    var v = {
        root: null, els: null, cv: null, ctx: null, w: 0, h: 0,
        data: null, byLayer: null, hidden: {}, showText: true,
        textH: 1,
        scale: 1, ox: 0, oy: 0,
        drag: null, pending: false
    };

    function color(i) { return PALETTE[i % PALETTE.length]; }

    function esc(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(s)));
        return d.innerHTML;
    }

    function msg(text) {
        v.els.msg.textContent = text || '';
        v.els.msg.style.display = text ? 'flex' : 'none';
    }

    function ensureDom() {
        if (v.root) return;

        var root = document.createElement('div');
        root.className = 'dxfv-overlay';
        root.innerHTML =
            '<div class="dxfv-panel" role="dialog" aria-modal="true" aria-label="DXF preview">' +
            '<div class="dxfv-head">' +
            '<div class="dxfv-titles">' +
            '<strong>DXF Preview</strong>' +
            '<span class="dxfv-name"></span>' +
            '</div>' +
            '<a class="dxfv-btn dxfv-download" href="#">Download</a>' +
            '<button type="button" class="dxfv-btn dxfv-x" aria-label="Close">&times;</button>' +
            '</div>' +
            '<div class="dxfv-bar">' +
            '<button type="button" class="dxfv-btn" data-act="fit">Fit</button>' +
            '<button type="button" class="dxfv-btn" data-act="in">+</button>' +
            '<button type="button" class="dxfv-btn" data-act="out">−</button>' +
            '<button type="button" class="dxfv-btn" data-act="text">Hide text</button>' +
            '<span class="dxfv-meta dxfv-stats"></span>' +
            '<span class="dxfv-meta dxfv-cursor"></span>' +
            '</div>' +
            '<div class="dxfv-stage">' +
            '<canvas></canvas>' +
            '<div class="dxfv-msg">Loading…</div>' +
            '</div>' +
            '<div class="dxfv-legend"></div>' +
            '</div>';
        document.body.appendChild(root);

        v.root = root;
        v.els = {
            name: root.querySelector('.dxfv-name'),
            download: root.querySelector('.dxfv-download'),
            textBtn: root.querySelector('[data-act="text"]'),
            stats: root.querySelector('.dxfv-stats'),
            cursor: root.querySelector('.dxfv-cursor'),
            msg: root.querySelector('.dxfv-msg'),
            legend: root.querySelector('.dxfv-legend')
        };
        v.cv = root.querySelector('canvas');
        v.ctx = v.cv.getContext('2d');

        root.querySelector('.dxfv-bar').addEventListener('click', function (ev) {
            var act = ev.target.getAttribute('data-act');
            if (act === 'fit') fit();
            else if (act === 'in') zoomStep(1.25);
            else if (act === 'out') zoomStep(0.8);
            else if (act === 'text') toggleText();
        });
        root.querySelector('.dxfv-x').addEventListener('click', close);
        root.addEventListener('mousedown', function (ev) { if (ev.target === root) close(); });

        v.els.legend.addEventListener('click', function (ev) {
            var chip = ev.target.closest('.dxfv-chip');
            if (chip) toggleLayer(parseInt(chip.getAttribute('data-layer'), 10));
        });

        v.cv.addEventListener('wheel', function (ev) {
            ev.preventDefault();
            zoomAt(ev.offsetX, ev.offsetY, ev.deltaY < 0 ? 1.15 : 1 / 1.15);
        }, { passive: false });

        v.cv.addEventListener('mousedown', function (ev) {
            v.drag = { x: ev.offsetX, y: ev.offsetY };
            v.cv.classList.add('dragging');
        });
        v.cv.addEventListener('mousemove', function (ev) {
            if (v.drag) {
                v.ox += ev.offsetX - v.drag.x;
                v.oy += ev.offsetY - v.drag.y;
                v.drag = { x: ev.offsetX, y: ev.offsetY };
                drawSoon();
            }
            if (v.data) {
                v.els.cursor.textContent =
                    'x ' + ((ev.offsetX - v.ox) / v.scale).toFixed(2) +
                    '   y ' + ((v.oy - ev.offsetY) / v.scale).toFixed(2);
            }
        });
        ['mouseup', 'mouseleave'].forEach(function (evt) {
            v.cv.addEventListener(evt, function () {
                v.drag = null;
                v.cv.classList.remove('dragging');
            });
        });

        window.addEventListener('resize', function () {
            if (!v.root.classList.contains('is-open')) return;
            resize();
            draw();
        });
    }

    function onKey(ev) { if (ev.key === 'Escape') close(); }

    function open(opts) {
        if (typeof opts === 'string') opts = { url: opts };
        if (!opts || !opts.url) throw new Error('DxfViewer.open needs a url.');

        ensureDom();
        v.data = null;
        v.byLayer = null;
        v.hidden = {};
        v.els.name.textContent = opts.name || '';
        v.els.legend.innerHTML = '';
        v.els.stats.textContent = '';
        v.els.cursor.textContent = '';
        if (opts.downloadUrl) {
            v.els.download.href = opts.downloadUrl;
            v.els.download.style.display = '';
        } else {
            v.els.download.style.display = 'none';
        }

        v.root.classList.add('is-open');
        document.body.classList.add('dxfv-lock');
        document.addEventListener('keydown', onKey);
        msg('Loading…');
        requestAnimationFrame(function () {
            resize();
            if (v.data) fit(); else draw();
        });

        fetch(opts.url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok) { msg(res.message || 'This file could not be previewed.'); return; }
                v.data = res;
                v.byLayer = res.layers.map(function () { return []; });
                var hSum = 0, hCount = 0;
                res.ents.forEach(function (e) {
                    (v.byLayer[e.y] || v.byLayer[0]).push(e);
                    if (e.t === 'x' && e.h > 0) { hSum += e.h; hCount++; }
                });
                v.textH = hCount ? hSum / hCount
                    : (res.bounds ? Math.max(res.bounds[3] - res.bounds[1], 1) / 80 : 1);
                legend();
                stats();
                msg('');
                if (v.w) fit(); else draw();
            })
            .catch(function (err) { msg('Failed to load preview: ' + err.message); });
    }

    function close() {
        if (!v.root) return;
        v.root.classList.remove('is-open');
        document.body.classList.remove('dxfv-lock');
        document.removeEventListener('keydown', onKey);
        v.data = null;
        v.byLayer = null;
    }

    function resize() {
        var rect = v.cv.parentNode.getBoundingClientRect();
        var dpr = window.devicePixelRatio || 1;
        v.w = rect.width;
        v.h = rect.height;
        v.cv.width = Math.max(1, Math.round(rect.width * dpr));
        v.cv.height = Math.max(1, Math.round(rect.height * dpr));
        v.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    function fit() {
        var b = v.data && v.data.bounds;
        if (!b || !v.w) { draw(); return; }
        var bw = Math.max(b[2] - b[0], 1e-6);
        var bh = Math.max(b[3] - b[1], 1e-6);
        var s = Math.min((v.w - 48) / bw, (v.h - 48) / bh);
        if (!isFinite(s) || s <= 0) s = 1;
        v.scale = s;
        v.ox = v.w / 2 - (b[0] + b[2]) / 2 * s;
        v.oy = v.h / 2 + (b[1] + b[3]) / 2 * s;
        draw();
    }

    function zoomAt(px, py, f) {
        if (!v.data) return;
        v.ox = px - (px - v.ox) * f;
        v.oy = py - (py - v.oy) * f;
        v.scale *= f;
        draw();
    }

    function zoomStep(f) { zoomAt(v.w / 2, v.h / 2, f); }

    function toggleText() {
        v.showText = !v.showText;
        v.els.textBtn.textContent = v.showText ? 'Hide text' : 'Show text';
        draw();
    }

    function toggleLayer(i) {
        v.hidden[i] = !v.hidden[i];
        v.els.legend.querySelector('.dxfv-chip[data-layer="' + i + '"]').classList.toggle('off', !!v.hidden[i]);
        draw();
    }

    function legend() {
        v.els.legend.innerHTML = v.data.layers.map(function (name, i) {
            return '<button type="button" class="dxfv-chip" data-layer="' + i + '">' +
                '<span class="dxfv-dot" style="background:' + color(i) + '"></span>' +
                esc(name || '0') + '</button>';
        }).join('');
    }

    function stats() {
        var d = v.data, b = d.bounds, txt = d.count + ' entities';
        if (b) {
            var u = d.units ? ' ' + d.units : '';
            txt += '   ·   ' + (b[2] - b[0]).toFixed(1) + ' × ' + (b[3] - b[1]).toFixed(1) + u;
        }
        if (d.truncated) txt += '   ·   truncated for preview';
        v.els.stats.textContent = txt;
    }

    function drawSoon() {
        if (v.pending) return;
        v.pending = true;
        requestAnimationFrame(function () { v.pending = false; draw(); });
    }

    function draw() {
        var ctx = v.ctx;
        if (!ctx) return;
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, v.w, v.h);
        if (!v.data) return;

        var s = v.scale, ox = v.ox, oy = v.oy, texts = [];
        ctx.lineWidth = 1;
        ctx.lineJoin = 'round';

        v.byLayer.forEach(function (list, li) {
            if (v.hidden[li]) return;
            ctx.beginPath();
            for (var i = 0; i < list.length; i++) {
                var e = list[i], p = e.p, x, y, j;
                if (e.t === 'l') {
                    ctx.moveTo(p[0] * s + ox, oy - p[1] * s);
                    ctx.lineTo(p[2] * s + ox, oy - p[3] * s);
                } else if (e.t === 'p') {
                    ctx.moveTo(p[0] * s + ox, oy - p[1] * s);
                    for (j = 2; j < p.length; j += 2) ctx.lineTo(p[j] * s + ox, oy - p[j + 1] * s);
                    if (e.c) ctx.closePath();
                } else if (e.t === 'c') {
                    x = p[0] * s + ox; y = oy - p[1] * s;
                    ctx.moveTo(x + e.r * s, y);
                    ctx.arc(x, y, Math.max(e.r * s, 0.1), 0, Math.PI * 2);
                } else if (e.t === 'a') {
                    x = p[0] * s + ox; y = oy - p[1] * s;
                    var r = Math.max(e.r * s, 0.1),
                        a0 = -e.a0 * Math.PI / 180,
                        a1 = -e.a1 * Math.PI / 180;
                    ctx.moveTo(x + r * Math.cos(a0), y + r * Math.sin(a0));
                    ctx.arc(x, y, r, a0, a1, true);
                } else if (e.t === 'd') {
                    x = p[0] * s + ox; y = oy - p[1] * s;
                    ctx.moveTo(x - 2, y); ctx.lineTo(x + 2, y);
                    ctx.moveTo(x, y - 2); ctx.lineTo(x, y + 2);
                } else if (e.t === 'x' && v.showText) {
                    texts.push(e);
                }
            }
            ctx.strokeStyle = color(li);
            ctx.stroke();
        });

        if (texts.length) {
            ctx.textBaseline = 'bottom';
            for (var k = 0; k < texts.length; k++) {
                var t = texts[k];
                var px = (t.h > 0 ? t.h : v.textH) * s;
                if (px < 3) continue;
                ctx.save();
                ctx.translate(t.p[0] * s + ox, oy - t.p[1] * s);
                if (t.g) ctx.rotate(-t.g * Math.PI / 180);
                ctx.fillStyle = color(t.y);
                ctx.font = px.toFixed(1) + 'px ui-monospace, monospace';
                ctx.fillText(t.s, 0, 0);
                ctx.restore();
            }
        }
    }

    global.DxfViewer = { open: open, close: close };

})(window);
