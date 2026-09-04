/* Traffic Monitor - self-contained SVG charts and live refresh.
   No external library: the panel must keep working on a router LAN with no
   internet access, so nothing here is loaded from a CDN. */
(function (global) {
  'use strict';

  var NS = 'http://www.w3.org/2000/svg';

  function fmtBps(v) {
    v = Number(v) || 0;
    if (v >= 1e9) return (v / 1e9).toFixed(2) + ' Gbps';
    if (v >= 1e6) return (v / 1e6).toFixed(2) + ' Mbps';
    if (v >= 1e3) return (v / 1e3).toFixed(1) + ' kbps';
    return Math.round(v) + ' bps';
  }

  function fmtBytes(v) {
    v = Number(v) || 0;
    if (v >= 1099511627776) return (v / 1099511627776).toFixed(2) + ' TB';
    if (v >= 1073741824) return (v / 1073741824).toFixed(2) + ' GB';
    if (v >= 1048576) return (v / 1048576).toFixed(1) + ' MB';
    if (v >= 1024) return (v / 1024).toFixed(1) + ' KB';
    return Math.round(v) + ' B';
  }

  function two(n) { return (n < 10 ? '0' : '') + n; }

  function fmtTime(ts, spanSec) {
    var d = new Date(ts * 1000);
    // Over a day of history the clock alone is ambiguous, so show the date too.
    if (spanSec > 86400) return two(d.getDate()) + '/' + two(d.getMonth() + 1) + ' ' + two(d.getHours()) + ':' + two(d.getMinutes());
    return two(d.getHours()) + ':' + two(d.getMinutes());
  }

  function el(tag, attrs) {
    var n = document.createElementNS(NS, tag);
    for (var k in attrs) if (attrs.hasOwnProperty(k)) n.setAttribute(k, attrs[k]);
    return n;
  }

  /** A "nice" round axis maximum, so the gridline labels are readable numbers. */
  function niceMax(v) {
    if (v <= 0) return 1;
    var exp = Math.pow(10, Math.floor(Math.log(v) / Math.LN10));
    var f = v / exp;
    var nice = f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10;
    return nice * exp;
  }

  /**
   * Draw a two-series area chart.
   * opts = { points: [[unixTs, down, up], ...], height, format: 'bps'|'bytes',
   *          labels: [downLabel, upLabel] }
   */
  function chart(host, opts) {
    if (typeof host === 'string') host = document.getElementById(host);
    if (!host) return;
    opts = opts || {};
    var pts = opts.points || [];
    var fmt = opts.format === 'bytes' ? fmtBytes : fmtBps;
    var H = opts.height || 190;
    var W = Math.max(260, host.clientWidth || 700);
    var padL = 62, padR = 12, padT = 12, padB = 24;
    var iw = W - padL - padR, ih = H - padT - padB;

    host.innerHTML = '';
    if (!pts.length) {
      host.innerHTML = '<div style="padding:34px 0;text-align:center;color:#6b7994;font-size:13px">'
        + (opts.empty || 'No data collected for this period yet.') + '</div>';
      return;
    }

    var t0 = pts[0][0], t1 = pts[pts.length - 1][0];
    var span = Math.max(1, t1 - t0);
    var peak = 0, i;
    for (i = 0; i < pts.length; i++) {
      if (pts[i][1] > peak) peak = pts[i][1];
      if (pts[i][2] > peak) peak = pts[i][2];
    }
    var ymax = niceMax(peak || 1);

    var x = function (t) { return padL + (t - t0) / span * iw; };
    var y = function (v) { return padT + ih - (v / ymax) * ih; };

    var svg = el('svg', { 'class': 'tmchart', width: W, height: H, viewBox: '0 0 ' + W + ' ' + H });

    // horizontal gridlines + value labels
    for (i = 0; i <= 4; i++) {
      var gv = ymax * i / 4, gy = y(gv);
      svg.appendChild(el('line', { x1: padL, y1: gy, x2: W - padR, y2: gy,
        stroke: '#1b263f', 'stroke-width': 1 }));
      var lab = el('text', { x: padL - 8, y: gy + 4, 'text-anchor': 'end',
        fill: '#6b7994', 'font-size': 10.5 });
      lab.textContent = fmt(gv);
      svg.appendChild(lab);
    }

    // time labels
    var ticks = Math.max(2, Math.min(6, Math.floor(iw / 110)));
    for (i = 0; i <= ticks; i++) {
      var tt = t0 + span * i / ticks;
      var tx = x(tt);
      var tl = el('text', { x: tx, y: H - 6,
        'text-anchor': i === 0 ? 'start' : (i === ticks ? 'end' : 'middle'),
        fill: '#6b7994', 'font-size': 10.5 });
      tl.textContent = fmtTime(tt, span);
      svg.appendChild(tl);
    }

    function series(idx, color, gid) {
      var d = '', a = '', p;
      for (var j = 0; j < pts.length; j++) {
        p = pts[j];
        d += (j ? 'L' : 'M') + x(p[0]).toFixed(1) + ' ' + y(p[idx]).toFixed(1) + ' ';
      }
      a = d + 'L' + x(t1).toFixed(1) + ' ' + (padT + ih) + ' L' + x(t0).toFixed(1) + ' ' + (padT + ih) + ' Z';
      var grad = el('linearGradient', { id: gid, x1: 0, y1: 0, x2: 0, y2: 1 });
      grad.appendChild(el('stop', { offset: '0%', 'stop-color': color, 'stop-opacity': .35 }));
      grad.appendChild(el('stop', { offset: '100%', 'stop-color': color, 'stop-opacity': 0 }));
      var defs = el('defs', {});
      defs.appendChild(grad);
      svg.appendChild(defs);
      svg.appendChild(el('path', { d: a, fill: 'url(#' + gid + ')', stroke: 'none' }));
      svg.appendChild(el('path', { d: d, fill: 'none', stroke: color, 'stroke-width': 1.8,
        'stroke-linejoin': 'round', 'stroke-linecap': 'round' }));
    }

    var uid = 'g' + Math.floor(Math.random() * 1e9);
    series(1, opts.colorDown || '#34d399', uid + 'd');
    series(2, opts.colorUp || '#60a5fa', uid + 'u');

    var cursor = el('line', { x1: 0, y1: padT, x2: 0, y2: padT + ih, stroke: '#4b5b80',
      'stroke-width': 1, 'stroke-dasharray': '3 3', opacity: 0 });
    svg.appendChild(cursor);

    var wrap = document.createElement('div');
    wrap.className = 'tmchart-wrap';
    wrap.appendChild(svg);
    var tip = document.createElement('div');
    tip.className = 'tmtip';
    wrap.appendChild(tip);
    host.appendChild(wrap);

    var labels = opts.labels || ['Download', 'Upload'];
    svg.addEventListener('mousemove', function (ev) {
      var r = svg.getBoundingClientRect();
      var mx = ev.clientX - r.left;
      if (mx < padL || mx > W - padR) { tip.style.opacity = 0; cursor.setAttribute('opacity', 0); return; }
      var tAt = t0 + (mx - padL) / iw * span;
      var best = 0, bd = Infinity;
      for (var j = 0; j < pts.length; j++) {
        var dd = Math.abs(pts[j][0] - tAt);
        if (dd < bd) { bd = dd; best = j; }
      }
      var p = pts[best];
      cursor.setAttribute('x1', x(p[0])); cursor.setAttribute('x2', x(p[0]));
      cursor.setAttribute('opacity', 1);
      tip.innerHTML = '<b>' + fmtTime(p[0], span) + '</b><br>'
        + '<span style="color:' + (opts.colorDown || '#34d399') + '">&#9632;</span> ' + labels[0] + ' ' + fmt(p[1]) + '<br>'
        + '<span style="color:' + (opts.colorUp || '#60a5fa') + '">&#9632;</span> ' + labels[1] + ' ' + fmt(p[2]);
      tip.style.opacity = 1;
      var tw = tip.offsetWidth;
      var left = x(p[0]) + 12;
      if (left + tw > W) left = x(p[0]) - tw - 12;
      tip.style.left = left + 'px';
      tip.style.top = (padT + 4) + 'px';
    });
    svg.addEventListener('mouseleave', function () {
      tip.style.opacity = 0; cursor.setAttribute('opacity', 0);
    });
  }

  /** Re-draw every registered chart when the window is resized. */
  var registry = [];
  function draw(host, opts) {
    if (typeof host === 'string') host = document.getElementById(host);
    if (!host) return;
    registry.push([host, opts]);
    chart(host, opts);
  }
  var rt;
  global.addEventListener('resize', function () {
    clearTimeout(rt);
    rt = setTimeout(function () {
      for (var i = 0; i < registry.length; i++) chart(registry[i][0], registry[i][1]);
    }, 180);
  });

  /** Poll a JSON endpoint and hand the result to a callback. Stops on errors
   *  only long enough to try again - a router hiccup must not freeze the page. */
  function poll(url, everyMs, cb) {
    function tick() {
      fetch(url, { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (j) { try { cb(j); } catch (e) {} })
        .catch(function () {})
        .then(function () { setTimeout(tick, everyMs); });
    }
    tick();
  }

  global.TM = { chart: draw, fmtBps: fmtBps, fmtBytes: fmtBytes, poll: poll };
})(window);
