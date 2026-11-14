import 'dart:math' as math;
import 'dart:typed_data';

import 'package:filenest_app/core/scan/docscan.dart';
import 'package:flutter_test/flutter_test.dart';

ScanImage scene(int w, int h, List<ScanPoint> quad, List<int> bg, List<int> fg, {int noise = 6, bool shade = false}) {
  final data = Uint8List(w * h * 4);
  bool inside(int x, int y) {
    for (var i = 0; i < 4; i++) {
      final a = quad[i], b = quad[(i + 1) % 4];
      if ((b.x - a.x) * (y - a.y) - (b.y - a.y) * (x - a.x) < 0) return false;
    }
    return true;
  }

  final rnd = math.Random(7);
  for (var y = 0; y < h; y++) {
    for (var x = 0; x < w; x++) {
      final i = (y * w + x) * 4;
      final on = inside(x, y);
      var c = on ? fg : bg;
      if (on && ((x >> 3) + (y >> 4)) % 7 == 0) c = [c[0] - 60, c[1] - 60, c[2] - 60];
      final k = shade ? 0.45 + 0.55 * (x / w) : 1.0;
      for (var j = 0; j < 3; j++) {
        data[i + j] = ((c[j] + (rnd.nextDouble() - 0.5) * 2 * noise) * k).round().clamp(0, 255).toInt();
      }
      data[i + 3] = 255;
    }
  }
  return ScanImage(data, w, h);
}

void main() {
  test('the corners of a tilted book are found', () {
    final cases = [
      ([const ScanPoint(60, 40), const ScanPoint(250, 55), const ScanPoint(270, 200), const ScanPoint(45, 190)], [40, 45, 50], [230, 225, 215]),
      ([const ScanPoint(90, 30), const ScanPoint(240, 60), const ScanPoint(215, 215), const ScanPoint(55, 170)], [200, 190, 170], [40, 60, 120]),
    ];
    for (final c in cases) {
      final r = detectQuad(scene(320, 240, c.$1, c.$2, c.$3));
      expect(r.found, isTrue);
      for (var i = 0; i < 4; i++) {
        final e = math.sqrt(math.pow(r.quad[i].x - c.$1[i].x, 2) + math.pow(r.quad[i].y - c.$1[i].y, 2));
        expect(e, lessThan(6), reason: 'corner $i');
      }
    }
  });

  test('a picture with nothing in it gives no page', () {
    final flat = scene(320, 240, [const ScanPoint(-5, -5), const ScanPoint(400, -5), const ScanPoint(400, 300), const ScanPoint(-5, 300)], [0, 0, 0], [200, 200, 200], noise: 3);
    expect(detectQuad(flat).found, isFalse);
  });

  test('straightening keeps only the page, and light evens a shadow', () {
    final q = [const ScanPoint(60, 40), const ScanPoint(250, 55), const ScanPoint(270, 200), const ScanPoint(45, 190)];
    final img = scene(320, 240, q, [40, 45, 50], [230, 225, 215], noise: 0);
    final size = quadSize(q);
    final out = warpQuad(img, q, size.$1, size.$2);
    var dark = 0;
    for (var i = 0; i < out.data.length; i += 4) {
      if (out.data[i] < 100) dark++;
    }
    expect(dark / (out.data.length / 4), lessThan(0.01));

    final whole = [const ScanPoint(-5, -5), const ScanPoint(400, -5), const ScanPoint(400, 300), const ScanPoint(-5, 300)];
    final shaded = scene(300, 200, whole, [0, 0, 0], [235, 235, 230], noise: 2, shade: true);
    int at(ScanImage im, int x, int y) => im.data[(y * im.width + x) * 4];
    expect(at(shaded, 280, 101) - at(shaded, 20, 101), greaterThan(80));
    final lit = applyScanFilter(shaded, 'light');
    expect((at(lit, 20, 101) - at(lit, 280, 101)).abs(), lessThan(30));
    expect(at(lit, 20, 101), greaterThan(200));
    for (final f in scanFilters) {
      expect(applyScanFilter(shaded, f, brightness: 10, contrast: -10).data.length, shaded.data.length);
    }
  });

  test('shrinking and turning keep the picture', () {
    final img = scene(640, 480, [const ScanPoint(100, 100), const ScanPoint(500, 100), const ScanPoint(500, 400), const ScanPoint(100, 400)], [20, 20, 20], [240, 240, 240], noise: 0);
    final s = shrink(img, 320);
    expect([s.width, s.height], [320, 240]);
    final r = rotateRight(s);
    expect([r.width, r.height], [240, 320]);
    // top-left of the original ends at the top-right after a quarter turn clockwise
    expect(r.data[(0 * r.width + (r.width - 1)) * 4], s.data[0]);
  });
}
