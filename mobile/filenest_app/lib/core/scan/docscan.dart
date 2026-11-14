import 'dart:math' as math;
import 'dart:typed_data';

/// A small document scanner in plain Dart (2026-11-14), the same method as the website's
/// (resources/js/lib/docscan.js), so it works with no internet: the page's four corners
/// are found, the tilted quadrilateral is straightened, and light / colour filters applied.
/// Pictures are RGBA bytes with a width and a height.
class ScanImage {
  ScanImage(this.data, this.width, this.height);
  final Uint8List data;
  final int width;
  final int height;
}

class ScanPoint {
  const ScanPoint(this.x, this.y);
  final double x;
  final double y;
}

class DetectedQuad {
  const DetectedQuad(this.quad, this.found, {this.whole = false});

  /// Top-left, top-right, bottom-right, bottom-left, in the picture's own pixels.
  final List<ScanPoint> quad;
  final bool found;
  final bool whole;
}

const scanFilters = ['original', 'auto', 'light', 'magic', 'gray', 'bw'];

double _clampD(double v, double a, double b) => v < a ? a : (v > b ? b : v);
int _clampI(int v, int a, int b) => v < a ? a : (v > b ? b : v);
int _byte(double v) => v <= 0 ? 0 : (v >= 255 ? 255 : v.round());

Float32List _toGray(ScanImage img) {
  final d = img.data;
  final g = Float32List(img.width * img.height);
  for (var i = 0, p = 0; i < g.length; i++, p += 4) {
    g[i] = 0.299 * d[p] + 0.587 * d[p + 1] + 0.114 * d[p + 2];
  }
  return g;
}

Float32List _boxBlur(Float32List src, int w, int h, int r) {
  final tmp = Float32List(src.length);
  final out = Float32List(src.length);
  final n = 2 * r + 1;
  for (var y = 0; y < h; y++) {
    final row = y * w;
    var sum = 0.0;
    for (var x = -r; x <= r; x++) {
      sum += src[row + _clampI(x, 0, w - 1)];
    }
    for (var x = 0; x < w; x++) {
      tmp[row + x] = sum / n;
      sum += src[row + _clampI(x + r + 1, 0, w - 1)] - src[row + _clampI(x - r, 0, w - 1)];
    }
  }
  for (var x = 0; x < w; x++) {
    var sum = 0.0;
    for (var y = -r; y <= r; y++) {
      sum += tmp[_clampI(y, 0, h - 1) * w + x];
    }
    for (var y = 0; y < h; y++) {
      out[y * w + x] = sum / n;
      sum += tmp[_clampI(y + r + 1, 0, h - 1) * w + x] - tmp[_clampI(y - r, 0, h - 1) * w + x];
    }
  }
  return out;
}

List<ScanPoint> defaultQuad(int width, int height, [double margin = 0.06]) {
  final mx = width * margin;
  final my = height * margin;
  return [ScanPoint(mx, my), ScanPoint(width - mx, my), ScanPoint(width - mx, height - my), ScanPoint(mx, height - my)];
}

double _cross(ScanPoint o, ScanPoint a, ScanPoint b) => (a.x - o.x) * (b.y - o.y) - (a.y - o.y) * (b.x - o.x);

List<ScanPoint> _convexHull(List<ScanPoint> points) {
  final pts = [...points]..sort((a, b) => a.x != b.x ? a.x.compareTo(b.x) : a.y.compareTo(b.y));
  if (pts.length < 3) return pts;
  final lower = <ScanPoint>[];
  for (final p in pts) {
    while (lower.length >= 2 && _cross(lower[lower.length - 2], lower[lower.length - 1], p) <= 0) {
      lower.removeLast();
    }
    lower.add(p);
  }
  final upper = <ScanPoint>[];
  for (var i = pts.length - 1; i >= 0; i--) {
    final p = pts[i];
    while (upper.length >= 2 && _cross(upper[upper.length - 2], upper[upper.length - 1], p) <= 0) {
      upper.removeLast();
    }
    upper.add(p);
  }
  lower.removeLast();
  upper.removeLast();
  return [...lower, ...upper];
}

double polygonArea(List<ScanPoint> poly) {
  var a = 0.0;
  for (var i = 0; i < poly.length; i++) {
    final p = poly[i];
    final q = poly[(i + 1) % poly.length];
    a += p.x * q.y - q.x * p.y;
  }
  return a.abs() / 2;
}

/// Top-left, top-right, bottom-right, bottom-left.
List<ScanPoint> orderQuad(List<ScanPoint> q) {
  final cx = q.fold<double>(0, (s, p) => s + p.x) / 4;
  final cy = q.fold<double>(0, (s, p) => s + p.y) / 4;
  final sorted = [...q]..sort((a, b) => math.atan2(a.y - cy, a.x - cx).compareTo(math.atan2(b.y - cy, b.x - cx)));
  var start = 0;
  var best = double.infinity;
  for (var i = 0; i < 4; i++) {
    final d = sorted[i].x + sorted[i].y;
    if (d < best) {
      best = d;
      start = i;
    }
  }
  return [for (var i = 0; i < 4; i++) sorted[(start + i) % 4]];
}

bool isConvexQuad(List<ScanPoint> q) {
  if (q.length != 4) return false;
  var sign = 0;
  for (var i = 0; i < 4; i++) {
    final c = _cross(q[i], q[(i + 1) % 4], q[(i + 2) % 4]);
    if (c.abs() < 1e-6) return false;
    final s = c > 0 ? 1 : -1;
    if (sign == 0) {
      sign = s;
    } else if (s != sign) {
      return false;
    }
  }
  return true;
}

/// Drops hull corners one at a time, always the one that costs the least area, until four remain.
List<ScanPoint> _reduceToQuad(List<ScanPoint> hull) {
  final poly = [...hull];
  while (poly.length > 4) {
    var best = 0;
    var cost = double.infinity;
    for (var i = 0; i < poly.length; i++) {
      final a = poly[(i + poly.length - 1) % poly.length];
      final c = _cross(a, poly[i], poly[(i + 1) % poly.length]).abs();
      if (c < cost) {
        cost = c;
        best = i;
      }
    }
    poly.removeAt(best);
  }
  return poly;
}

class _Line {
  _Line(this.x, this.y, this.dx, this.dy);
  final double x, y, dx, dy;
}

_Line _fitLine(List<ScanPoint> points) {
  var mx = 0.0, my = 0.0;
  for (final p in points) {
    mx += p.x;
    my += p.y;
  }
  mx /= points.length;
  my /= points.length;
  var sxx = 0.0, sxy = 0.0, syy = 0.0;
  for (final p in points) {
    sxx += (p.x - mx) * (p.x - mx);
    sxy += (p.x - mx) * (p.y - my);
    syy += (p.y - my) * (p.y - my);
  }
  final angle = 0.5 * math.atan2(2 * sxy, sxx - syy);
  return _Line(mx, my, math.cos(angle), math.sin(angle));
}

ScanPoint? _intersect(_Line a, _Line b) {
  final det = a.dx * b.dy - a.dy * b.dx;
  if (det.abs() < 1e-9) return null;
  final t = ((b.x - a.x) * b.dy - (b.y - a.y) * b.dx) / det;
  return ScanPoint(a.x + a.dx * t, a.y + a.dy * t);
}

double _hyp(double a, double b) => math.sqrt(a * a + b * b);

double _distToSegment(ScanPoint p, ScanPoint a, ScanPoint b) {
  final dx = b.x - a.x;
  final dy = b.y - a.y;
  var len = dx * dx + dy * dy;
  if (len == 0) len = 1;
  final t = _clampD(((p.x - a.x) * dx + (p.y - a.y) * dy) / len, 0, 1);
  return _hyp(p.x - (a.x + t * dx), p.y - (a.y + t * dy));
}

/// The reduced quad cuts its corners a little: each side is refitted through the outline points near it.
List<ScanPoint> _refine(List<ScanPoint> quad, List<ScanPoint> outline) {
  final groups = List.generate(4, (_) => <ScanPoint>[]);
  for (final p in outline) {
    var best = 0;
    var d = double.infinity;
    for (var i = 0; i < 4; i++) {
      final di = _distToSegment(p, quad[i], quad[(i + 1) % 4]);
      if (di < d) {
        d = di;
        best = i;
      }
    }
    if (d < 4) groups[best].add(p);
  }
  if (groups.any((g) => g.length < 6)) return quad;
  final lines = groups.map(_fitLine).toList();
  final out = <ScanPoint>[];
  for (var i = 0; i < 4; i++) {
    final p = _intersect(lines[(i + 3) % 4], lines[i]);
    if (p == null || _hyp(p.x - quad[i].x, p.y - quad[i].y) > 25) return quad;
    out.add(p);
  }
  return out;
}

/// Finds the page in a small picture (about 320 pixels on its long side): strong edges are
/// walls, everything reachable from the border without crossing a wall is background, and
/// the biggest thing left is the book.
DetectedQuad detectQuad(ScanImage img) {
  final w = img.width;
  final h = img.height;
  if (w < 24 || h < 24) return DetectedQuad(defaultQuad(w, h), false);

  final gray = _boxBlur(_boxBlur(_toGray(img), w, h, 2), w, h, 1);
  final mag = Float32List(w * h);
  var sum = 0.0;
  for (var y = 1; y < h - 1; y++) {
    for (var x = 1; x < w - 1; x++) {
      final i = y * w + x;
      final gx = gray[i - w + 1] + 2 * gray[i + 1] + gray[i + w + 1] - gray[i - w - 1] - 2 * gray[i - 1] - gray[i + w - 1];
      final gy = gray[i + w - 1] + 2 * gray[i + w] + gray[i + w + 1] - gray[i - w - 1] - 2 * gray[i - w] - gray[i - w + 1];
      final m = _hyp(gx, gy);
      mag[i] = m;
      sum += m;
    }
  }
  final mean = sum / (w * h);
  var varSum = 0.0;
  for (var i = 0; i < mag.length; i++) {
    varSum += (mag[i] - mean) * (mag[i] - mean);
  }
  final sigma = math.sqrt(varSum / mag.length);

  // A grainy table or cloth makes weak edges everywhere: when nothing is found, only
  // stronger and stronger edges are kept as walls.
  DetectedQuad? whole;
  for (final k in const [0.9, 1.6, 2.4, 3.2]) {
    final r = _quadFromEdges(mag, w, h, math.max(18.0, mean + k * sigma));
    if (r.found) return r;
    if (r.whole) whole = r;
  }
  return whole ?? DetectedQuad(defaultQuad(w, h), false);
}

DetectedQuad _quadFromEdges(Float32List mag, int w, int h, double threshold) {
  final fallback = DetectedQuad(defaultQuad(w, h), false);

  // Walls, thickened by one pixel so small gaps in an edge do not leak.
  final wall = Uint8List(w * h);
  for (var y = 1; y < h - 1; y++) {
    for (var x = 1; x < w - 1; x++) {
      final i = y * w + x;
      if (mag[i] > threshold) {
        wall[i] = 1;
        wall[i - 1] = 1;
        wall[i + 1] = 1;
        wall[i - w] = 1;
        wall[i + w] = 1;
      }
    }
  }

  // Background: flood from the border.
  final state = Uint8List(w * h); // 0 unknown, 1 background, 2 object seen
  final stack = <int>[];
  void push(int i) {
    if (wall[i] == 0 && state[i] == 0) {
      state[i] = 1;
      stack.add(i);
    }
  }

  for (var x = 0; x < w; x++) {
    push(x);
    push((h - 1) * w + x);
  }
  for (var y = 0; y < h; y++) {
    push(y * w);
    push(y * w + w - 1);
  }
  while (stack.isNotEmpty) {
    final i = stack.removeLast();
    final x = i % w;
    if (x > 0) push(i - 1);
    if (x < w - 1) push(i + 1);
    if (i >= w) push(i - w);
    if (i < w * (h - 1)) push(i + w);
  }

  // The biggest connected piece that is not background (walls belong to the object).
  List<int>? bestPixels;
  for (var s = 0; s < state.length; s++) {
    if (state[s] != 0) continue;
    final pixels = <int>[];
    state[s] = 2;
    final st = <int>[s];
    void tryPush(int j) {
      if (state[j] == 0) {
        state[j] = 2;
        st.add(j);
      }
    }

    while (st.isNotEmpty) {
      final i = st.removeLast();
      pixels.add(i);
      final x = i % w;
      if (x > 0) tryPush(i - 1);
      if (x < w - 1) tryPush(i + 1);
      if (i >= w) tryPush(i - w);
      if (i < w * (h - 1)) tryPush(i + w);
    }
    if (bestPixels == null || pixels.length > bestPixels.length) bestPixels = pixels;
  }
  if (bestPixels == null || bestPixels.length < w * h * 0.08) return fallback;

  // Its outline: the first and last pixel of every row and column.
  final inObj = Uint8List(w * h);
  for (final i in bestPixels) {
    inObj[i] = 1;
  }
  final outline = <ScanPoint>[];
  for (var y = 0; y < h; y++) {
    var a = -1, b = -1;
    for (var x = 0; x < w; x++) {
      if (inObj[y * w + x] == 1) {
        if (a < 0) a = x;
        b = x;
      }
    }
    if (a >= 0) {
      outline.add(ScanPoint(a.toDouble(), y.toDouble()));
      outline.add(ScanPoint(b.toDouble(), y.toDouble()));
    }
  }
  for (var x = 0; x < w; x++) {
    var a = -1, b = -1;
    for (var y = 0; y < h; y++) {
      if (inObj[y * w + x] == 1) {
        if (a < 0) a = y;
        b = y;
      }
    }
    if (a >= 0) {
      outline.add(ScanPoint(x.toDouble(), a.toDouble()));
      outline.add(ScanPoint(x.toDouble(), b.toDouble()));
    }
  }

  final hull = _convexHull(outline);
  if (hull.length < 4) return fallback;
  var quad = orderQuad(_reduceToQuad(hull));
  quad = orderQuad(_refine(quad, outline));
  // The blur and the thickened walls push the outline outwards by about three pixels.
  final cx = quad.fold<double>(0, (t, p) => t + p.x) / 4;
  final cy = quad.fold<double>(0, (t, p) => t + p.y) / 4;
  quad = [
    for (final p in quad)
      ScanPoint(
        _clampD(p.x + (cx - p.x).sign * 3, 0, w - 1.0),
        _clampD(p.y + (cy - p.y).sign * 3, 0, h - 1.0),
      ),
  ];

  final area = polygonArea(quad);
  if (!isConvexQuad(quad) || area < w * h * 0.1) return fallback;
  // A "page" that is the whole picture means nothing was really found.
  if (area > w * h * 0.97) return DetectedQuad(defaultQuad(w, h, 0), false, whole: true);

  return DetectedQuad(quad, true);
}

/// The 3×3 matrix taking the w×h rectangle onto the quad (TL, TR, BR, BL).
List<double> homography(List<ScanPoint> quad, int w, int h) {
  final src = [
    [0.0, 0.0],
    [w.toDouble(), 0.0],
    [w.toDouble(), h.toDouble()],
    [0.0, h.toDouble()],
  ];
  final a = <List<double>>[];
  for (var i = 0; i < 4; i++) {
    final x = src[i][0];
    final y = src[i][1];
    final u = quad[i].x;
    final v = quad[i].y;
    a.add([x, y, 1, 0, 0, 0, -u * x, -u * y, u]);
    a.add([0, 0, 0, x, y, 1, -v * x, -v * y, v]);
  }
  for (var c = 0; c < 8; c++) {
    var pivot = c;
    for (var r = c + 1; r < 8; r++) {
      if (a[r][c].abs() > a[pivot][c].abs()) pivot = r;
    }
    final t = a[c];
    a[c] = a[pivot];
    a[pivot] = t;
    final d = a[c][c] == 0 ? 1e-12 : a[c][c];
    for (var k = c; k < 9; k++) {
      a[c][k] /= d;
    }
    for (var r = 0; r < 8; r++) {
      if (r == c) continue;
      final f = a[r][c];
      if (f != 0) {
        for (var k = c; k < 9; k++) {
          a[r][k] -= f * a[c][k];
        }
      }
    }
  }
  return [for (final row in a) row[8], 1];
}

/// The natural size of the straightened page, from the lengths of the quad's sides.
(int, int) quadSize(List<ScanPoint> quad, [int maxSide = 1600]) {
  double d(ScanPoint a, ScanPoint b) => _hyp(a.x - b.x, a.y - b.y);
  var w = math.max(d(quad[0], quad[1]), d(quad[3], quad[2]));
  var h = math.max(d(quad[0], quad[3]), d(quad[1], quad[2]));
  final scale = math.min(1.0, maxSide / math.max(math.max(w, h), 1.0));
  w = math.max(8.0, (w * scale).roundToDouble());
  h = math.max(8.0, (h * scale).roundToDouble());
  return (w.toInt(), h.toInt());
}

ScanImage warpQuad(ScanImage img, List<ScanPoint> quad, int outW, int outH) {
  final data = img.data;
  final w = img.width;
  final h = img.height;
  final m = homography(quad, outW, outH);
  final out = Uint8List(outW * outH * 4);
  for (var y = 0; y < outH; y++) {
    final py = y + 0.5;
    for (var x = 0; x < outW; x++) {
      final px = x + 0.5;
      final z = m[6] * px + m[7] * py + m[8];
      final u = _clampD((m[0] * px + m[1] * py + m[2]) / z - 0.5, 0, w - 1.0);
      final v = _clampD((m[3] * px + m[4] * py + m[5]) / z - 0.5, 0, h - 1.0);
      final x0 = u.floor();
      final y0 = v.floor();
      final x1 = math.min(w - 1, x0 + 1);
      final y1 = math.min(h - 1, y0 + 1);
      final fx = u - x0;
      final fy = v - y0;
      final a = (y0 * w + x0) * 4;
      final b = (y0 * w + x1) * 4;
      final c = (y1 * w + x0) * 4;
      final d = (y1 * w + x1) * 4;
      final o = (y * outW + x) * 4;
      for (var k = 0; k < 3; k++) {
        out[o + k] = _byte((data[a + k] * (1 - fx) + data[b + k] * fx) * (1 - fy) + (data[c + k] * (1 - fx) + data[d + k] * fx) * fy);
      }
      out[o + 3] = 255;
    }
  }
  return ScanImage(out, outW, outH);
}

// ---------------------------------------------------------------- filters

int _percentile(Uint32List hist, int total, double p) {
  final target = total * p;
  var acc = 0;
  for (var i = 0; i < 256; i++) {
    acc += hist[i];
    if (acc >= target) return i;
  }
  return 255;
}

/// Stretches each colour between its dark and bright ends: fixes a colour cast and flat contrast.
void _autoLevels(Uint8List d, [double strength = 1]) {
  final total = d.length ~/ 4;
  for (var k = 0; k < 3; k++) {
    final hist = Uint32List(256);
    for (var i = k; i < d.length; i += 4) {
      hist[d[i]]++;
    }
    // A dark cover is meant to be dark: the low end is only pulled down a little.
    final lo = math.min(40, _percentile(hist, total, 0.01)).toDouble() * strength;
    final hi = 255 - (255 - _percentile(hist, total, 0.99)) * strength;
    if (hi - lo < 24) continue;
    final scale = 255 / (hi - lo);
    for (var i = k; i < d.length; i += 4) {
      d[i] = _byte((d[i] - lo) * scale);
    }
  }
}

class _Grid {
  _Grid(this.grid, this.tw, this.th, this.tile);
  final Float32List grid;
  final int tw, th, tile;
}

/// The middle brightness of each cell of a coarse grid (print is ignored: it is the minority).
_Grid _lightGrid(Uint8List d, int w, int h, [int cells = 8]) {
  final tile = math.max(4, (math.max(w, h) / cells).ceil());
  final tw = (w / tile).ceil();
  final th = (h / tile).ceil();
  final grid = Float32List(tw * th);
  final step = math.max(1, tile ~/ 24);
  for (var ty = 0; ty < th; ty++) {
    for (var tx = 0; tx < tw; tx++) {
      final hist = Uint32List(256);
      var n = 0;
      for (var y = ty * tile; y < math.min(h, (ty + 1) * tile); y += step) {
        for (var x = tx * tile; x < math.min(w, (tx + 1) * tile); x += step) {
          final i = (y * w + x) * 4;
          hist[(0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2]).round()]++;
          n++;
        }
      }
      grid[ty * tw + tx] = math.max(12, _percentile(hist, n, 0.5)).toDouble();
    }
  }
  return _Grid(grid, tw, th, tile);
}

/// Evens out the light: only the slow change of brightness across the picture is corrected
/// (a hand's or a phone's shadow, a lamp on one side), so it works on dark and colourful
/// covers as well as on white paper and leaves no halo around the letters.
void _evenLight(Uint8List d, int w, int h) {
  final g = _lightGrid(d, w, h);
  final sorted = [...g.grid]..sort();
  final target = sorted[_clampI((sorted.length * 0.75).floor(), 0, sorted.length - 1)];
  final smooth = _boxBlur(g.grid, g.tw, g.th, 1);
  final tw = g.tw, th = g.th, tile = g.tile;
  for (var y = 0; y < h; y++) {
    final gy = _clampD(y / tile - 0.5, 0, th - 1.0);
    final y0 = gy.floor();
    final y1 = math.min(th - 1, y0 + 1);
    final fy = gy - y0;
    for (var x = 0; x < w; x++) {
      final gx = _clampD(x / tile - 0.5, 0, tw - 1.0);
      final x0 = gx.floor();
      final x1 = math.min(tw - 1, x0 + 1);
      final fx = gx - x0;
      final bg = (smooth[y0 * tw + x0] * (1 - fx) + smooth[y0 * tw + x1] * fx) * (1 - fy) + (smooth[y1 * tw + x0] * (1 - fx) + smooth[y1 * tw + x1] * fx) * fy;
      final gain = _clampD(target / bg, 0.6, 2.4);
      final i = (y * w + x) * 4;
      d[i] = _byte(d[i] * gain);
      d[i + 1] = _byte(d[i + 1] * gain);
      d[i + 2] = _byte(d[i + 2] * gain);
    }
  }
}

/// A dim photo is lifted so that its brightest parts are bright (never darkened).
void _expose(Uint8List d, [double aim = 242]) {
  final hist = Uint32List(256);
  for (var i = 0; i < d.length; i += 4) {
    hist[math.max(d[i], math.max(d[i + 1], d[i + 2]))]++;
  }
  final top = _percentile(hist, d.length ~/ 4, 0.97);
  final gain = _clampD(aim / math.max(top, 1), 1, 1.9);
  if (gain > 1.02) {
    for (var i = 0; i < d.length; i += 4) {
      d[i] = _byte(d[i] * gain);
      d[i + 1] = _byte(d[i + 1] * gain);
      d[i + 2] = _byte(d[i + 2] * gain);
    }
  }
}

void _saturate(Uint8List d, double amount) {
  for (var i = 0; i < d.length; i += 4) {
    final g = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
    d[i] = _byte(g + (d[i] - g) * amount);
    d[i + 1] = _byte(g + (d[i + 1] - g) * amount);
    d[i + 2] = _byte(g + (d[i + 2] - g) * amount);
  }
}

void _grayscale(Uint8List d) {
  for (var i = 0; i < d.length; i += 4) {
    final g = _byte(0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2]);
    d[i] = g;
    d[i + 1] = g;
    d[i + 2] = g;
  }
}

/// A new picture with the filter applied, then brightness and contrast (each −100…100).
ScanImage applyScanFilter(ScanImage img, String name, {int brightness = 0, int contrast = 0}) {
  final d = Uint8List.fromList(img.data);
  final w = img.width;
  final h = img.height;

  switch (name) {
    case 'auto':
      // Smart default: a dim photo is brightened and a colour cast removed; the cover's own look is kept.
      _expose(d);
      _autoLevels(d, 0.6);
      _saturate(d, 1.08);
    case 'light':
      _evenLight(d, w, h);
      _expose(d);
    case 'magic':
      _evenLight(d, w, h);
      _expose(d);
      _autoLevels(d, 0.7);
      _saturate(d, 1.3);
    case 'gray':
      _expose(d);
      _autoLevels(d, 0.9);
      _grayscale(d);
    case 'bw':
      _evenLight(d, w, h);
      _grayscale(d);
      _autoLevels(d);
      // A steep curve around the middle: print and paper part cleanly, edges stay smooth.
      for (var i = 0; i < d.length; i += 4) {
        final v = _byte((d[i] - 128) * 3.0 + 128);
        d[i] = v;
        d[i + 1] = v;
        d[i + 2] = v;
      }
  }

  if (brightness != 0 || contrast != 0) {
    final b = brightness * 1.2;
    final c = contrast >= 0 ? 1 + contrast / 50 : 1 + contrast / 100;
    for (var i = 0; i < d.length; i += 4) {
      d[i] = _byte((d[i] - 128) * c + 128 + b);
      d[i + 1] = _byte((d[i + 1] - 128) * c + 128 + b);
      d[i + 2] = _byte((d[i + 2] - 128) * c + 128 + b);
    }
  }

  return ScanImage(d, w, h);
}

/// Halves a picture (averaging 2×2 blocks), for a clean small copy to find the edges in.
ScanImage halve(ScanImage img) {
  final w = img.width ~/ 2;
  final h = img.height ~/ 2;
  final d = img.data;
  final out = Uint8List(w * h * 4);
  for (var y = 0; y < h; y++) {
    for (var x = 0; x < w; x++) {
      final a = ((y * 2) * img.width + x * 2) * 4;
      final b = a + 4;
      final c = a + img.width * 4;
      final e = c + 4;
      final o = (y * w + x) * 4;
      for (var k = 0; k < 4; k++) {
        out[o + k] = (d[a + k] + d[b + k] + d[c + k] + d[e + k] + 2) >> 2;
      }
    }
  }
  return ScanImage(out, w, h);
}

/// Shrinks to at most [max] on the long side: halved step by step, then a plain resample.
ScanImage shrink(ScanImage img, int max) {
  var cur = img;
  while (math.max(cur.width, cur.height) >= max * 2) {
    cur = halve(cur);
  }
  final long = math.max(cur.width, cur.height);
  if (long <= max) return cur;
  final w = math.max(1, (cur.width * max / long).round());
  final h = math.max(1, (cur.height * max / long).round());
  return warpQuad(cur, [const ScanPoint(0, 0), ScanPoint(cur.width.toDouble(), 0), ScanPoint(cur.width.toDouble(), cur.height.toDouble()), ScanPoint(0, cur.height.toDouble())], w, h);
}

/// Turns a picture a quarter turn clockwise.
ScanImage rotateRight(ScanImage img) {
  final w = img.width;
  final h = img.height;
  final d = img.data;
  final out = Uint8List(d.length);
  for (var y = 0; y < h; y++) {
    for (var x = 0; x < w; x++) {
      final s = (y * w + x) * 4;
      final o = (x * h + (h - 1 - y)) * 4;
      out[o] = d[s];
      out[o + 1] = d[s + 1];
      out[o + 2] = d[s + 2];
      out[o + 3] = d[s + 3];
    }
  }
  return ScanImage(out, h, w);
}
