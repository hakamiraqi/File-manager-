import 'dart:async';
import 'dart:io';
import 'dart:math' as math;
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:file_picker/file_picker.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:image/image.dart' as img;
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';

import '../widgets/ui_kit.dart';
import 'docscan.dart';

/// What the scanner gives back: the straightened picture (a JPEG file) and, when the
/// cover's text was read, the fields the librarian assigned from it.
class ScanResult {
  const ScanResult(this.path, [this.fields = const {}]);
  final String path;
  final Map<String, String> fields;
}

/// Reads the text of a picture: returns {lines: [{text, size}], title, isbn}.
typedef CoverTextReader = Future<Map<String, dynamic>> Function(String jpegPath);

/// Asks where the picture comes from (camera on phones, or a picture file) and returns its path.
Future<String?> pickCoverSource(BuildContext context) async {
  final phone = !kIsWeb && (Platform.isAndroid || Platform.isIOS);
  var source = 'file';
  if (phone) {
    final picked = await showModalBottomSheet<String>(
      context: context,
      builder: (ctx) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          ListTile(leading: const Icon(Icons.photo_camera_outlined), title: Text(ctx.l('تصوير بالكاميرا', 'Take a photo')), onTap: () => Navigator.pop(ctx, 'camera')),
          ListTile(leading: const Icon(Icons.photo_library_outlined), title: Text(ctx.l('صورة من الجهاز', 'Pick a picture')), onTap: () => Navigator.pop(ctx, 'file')),
        ]),
      ),
    );
    if (picked == null) return null;
    source = picked;
  }
  if (source == 'camera') {
    final shot = await ImagePicker().pickImage(source: ImageSource.camera, imageQuality: 95);
    return shot?.path;
  }
  final r = await FilePicker.platform.pickFiles(type: FileType.image);
  return r?.files.single.path;
}

/// Picks a picture and runs it through the scanner. Null when cancelled.
Future<ScanResult?> scanCover(BuildContext context, {CoverTextReader? readText}) async {
  final path = await pickCoverSource(context);
  if (path == null || !context.mounted) return null;
  return Navigator.of(context).push<ScanResult>(MaterialPageRoute(builder: (_) => CoverScannerScreen(imagePath: path, readText: readText)));
}

// ---- work done off the main thread

class _Loaded {
  _Loaded(this.image, this.quad, this.found);
  final ScanImage image;
  final List<ScanPoint> quad;
  final bool found;
}

DetectedQuad _detectScaled(ScanImage image) {
  final small = shrink(image, 320);
  final r = detectQuad(small);
  final sx = image.width / small.width;
  final sy = image.height / small.height;
  return DetectedQuad([for (final p in r.quad) ScanPoint(p.x * sx, p.y * sy)], r.found);
}

_Loaded? _loadJob(Uint8List bytes) {
  final decoded = img.decodeImage(bytes);
  if (decoded == null) return null;
  var image = img.bakeOrientation(decoded);
  final long = math.max(image.width, image.height);
  if (long > 2000) {
    image = image.width >= image.height ? img.copyResize(image, width: 2000) : img.copyResize(image, height: 2000);
  }
  final rgba = image.convert(numChannels: 4).getBytes(order: img.ChannelOrder.rgba);
  final scan = ScanImage(Uint8List.fromList(rgba), image.width, image.height);
  final d = _detectScaled(scan);
  return _Loaded(scan, d.quad, d.found);
}

_Loaded _rotateJob(ScanImage image) {
  final r = rotateRight(image);
  final d = _detectScaled(r);
  return _Loaded(r, d.quad, d.found);
}

DetectedQuad _detectJob(ScanImage image) => _detectScaled(image);

class _WarpArgs {
  _WarpArgs(this.image, this.quad, this.max);
  final ScanImage image;
  final List<ScanPoint> quad;
  final int max;
}

ScanImage _warpJob(_WarpArgs a) {
  final size = quadSize(a.quad, a.max);
  return warpQuad(a.image, a.quad, size.$1, size.$2);
}

class _FilterArgs {
  _FilterArgs(this.image, this.filter, this.brightness, this.contrast, {this.jpeg = false});
  final ScanImage image;
  final String filter;
  final int brightness;
  final int contrast;
  final bool jpeg;
}

/// The filtered picture; as JPEG bytes (in `data`) when [jpeg] is set.
ScanImage _filterJob(_FilterArgs a) {
  final out = applyScanFilter(a.image, a.filter, brightness: a.brightness, contrast: a.contrast);
  if (!a.jpeg) return out;
  final picture = img.Image.fromBytes(width: out.width, height: out.height, bytes: out.data.buffer, numChannels: 4);
  return ScanImage(Uint8List.fromList(img.encodeJpg(picture, quality: 90)), out.width, out.height);
}

Future<ui.Image> _toUiImage(ScanImage s) {
  final c = Completer<ui.Image>();
  ui.decodeImageFromPixels(s.data, s.width, s.height, ui.PixelFormat.rgba8888, c.complete);
  return c.future;
}

/// The cover scanner (2026-11-14), like the website's: the edges are found by themselves,
/// the corners and sides are dragged with a magnifier showing the exact spot, the tilted
/// cover is straightened, then light and colour filters; the cover's text can be read to
/// fill the book's details. All on the device except reading the text.
class CoverScannerScreen extends StatefulWidget {
  const CoverScannerScreen({super.key, required this.imagePath, this.readText});
  final String imagePath;
  final CoverTextReader? readText;

  @override
  State<CoverScannerScreen> createState() => _CoverScannerScreenState();
}

class _CoverScannerScreenState extends State<CoverScannerScreen> {
  static const _accent = Color(0xFF34D399);
  static const _fields = ['title', 'authors', 'publisher'];

  ScanImage? _work;
  ui.Image? _shown;
  List<ScanPoint> _quad = [];
  bool _found = false;
  bool _busy = true;
  String? _error;
  bool _enhance = false;

  // dragging
  int? _corner;
  int? _side;
  Offset? _touch;

  // enhance
  ScanImage? _flat;
  ui.Image? _result;
  String _filter = 'auto';
  double _brightness = 0;
  double _contrast = 0;
  int _renderId = 0;

  // text
  bool _reading = false;
  String? _textError;
  List<Map<String, dynamic>> _lines = [];
  String? _isbn;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final bytes = await File(widget.imagePath).readAsBytes();
      final r = await compute(_loadJob, bytes);
      if (r == null) throw Exception('bad image');
      await _setWork(r);
    } catch (_) {
      if (mounted) {
        setState(() {
          _busy = false;
          _error = context.l('تعذّر فتح الصورة.', 'Could not open the picture.');
        });
      }
    }
  }

  Future<void> _setWork(_Loaded r) async {
    final shown = await _toUiImage(r.image);
    if (!mounted) return;
    setState(() {
      _work = r.image;
      _shown = shown;
      _quad = r.quad;
      _found = r.found;
      _busy = false;
    });
  }

  Future<void> _autoDetect() async {
    final w = _work;
    if (w == null) return;
    setState(() => _busy = true);
    final r = await compute(_detectJob, w);
    if (!mounted) return;
    setState(() {
      _quad = r.quad;
      _found = r.found;
      _busy = false;
    });
  }

  void _whole() {
    final w = _work;
    if (w == null) return;
    setState(() {
      _quad = defaultQuad(w.width, w.height, 0);
      _found = false;
    });
  }

  Future<void> _rotate() async {
    final w = _work;
    if (w == null) return;
    setState(() => _busy = true);
    await _setWork(await compute(_rotateJob, w));
  }

  // ---- the stage: where the picture sits on the screen
  static const _pad = 28.0;

  Rect _imageRect(Size stage) {
    final w = _work!;
    final scale = math.min((stage.width - _pad * 2) / w.width, (stage.height - _pad * 2) / w.height);
    final size = Size(w.width * scale, w.height * scale);
    return Rect.fromLTWH((stage.width - size.width) / 2, (stage.height - size.height) / 2, size.width, size.height);
  }

  Offset _toScreen(ScanPoint p, Rect r) => Offset(r.left + p.x * r.width / _work!.width, r.top + p.y * r.height / _work!.height);

  void _panStart(DragStartDetails d, Size stage) {
    if (_work == null || _quad.length != 4) return;
    final r = _imageRect(stage);
    final pts = [for (final p in _quad) _toScreen(p, r)];
    var best = double.infinity;
    int? corner;
    for (var i = 0; i < 4; i++) {
      final dist = (pts[i] - d.localPosition).distance;
      if (dist < best) {
        best = dist;
        corner = i;
      }
    }
    int? side;
    var bestSide = double.infinity;
    for (var i = 0; i < 4; i++) {
      final mid = (pts[i] + pts[(i + 1) % 4]) / 2;
      final dist = (mid - d.localPosition).distance;
      if (dist < bestSide) {
        bestSide = dist;
        side = i;
      }
    }
    setState(() {
      if (best <= 40 && best <= bestSide) {
        _corner = corner;
        _side = null;
      } else if (bestSide <= 40) {
        _side = side;
        _corner = null;
      } else {
        _corner = null;
        _side = null;
      }
      _touch = d.localPosition;
    });
  }

  void _panUpdate(DragUpdateDetails d, Size stage) {
    if (_corner == null && _side == null) return;
    final w = _work!;
    final r = _imageRect(stage);
    final dx = d.delta.dx * w.width / r.width;
    final dy = d.delta.dy * w.height / r.height;
    final next = [..._quad];
    void shift(int i) => next[i] = ScanPoint((next[i].x + dx).clamp(0, w.width.toDouble()).toDouble(), (next[i].y + dy).clamp(0, w.height.toDouble()).toDouble());
    if (_corner != null) {
      shift(_corner!);
    } else {
      shift(_side!);
      shift((_side! + 1) % 4);
    }
    setState(() {
      if (isConvexQuad(next)) _quad = next;
      _touch = d.localPosition;
    });
  }

  void _panEnd() => setState(() {
        _corner = null;
        _side = null;
        _touch = null;
      });

  // ---- straighten, then filters
  Future<void> _toEnhance() async {
    final w = _work;
    if (w == null || !isConvexQuad(_quad)) return;
    setState(() => _busy = true);
    final flat = await compute(_warpJob, _WarpArgs(w, _quad, 900));
    if (!mounted) return;
    setState(() {
      _flat = flat;
      _enhance = true;
      _busy = false;
      _lines = [];
      _isbn = null;
      _textError = null;
    });
    _render();
  }

  Future<void> _render() async {
    final flat = _flat;
    if (flat == null) return;
    final id = ++_renderId;
    final out = await compute(_filterJob, _FilterArgs(flat, _filter, _brightness.round(), _contrast.round()));
    final image = await _toUiImage(out);
    if (!mounted || id != _renderId) return;
    setState(() => _result = image);
  }

  Future<String> _finalJpeg(int max, String filter) async {
    final flat = await compute(_warpJob, _WarpArgs(_work!, _quad, max));
    final jpeg = await compute(_filterJob, _FilterArgs(flat, filter, _brightness.round(), _contrast.round(), jpeg: true));
    final dir = await getTemporaryDirectory();
    final file = File('${dir.path}/cover-${DateTime.now().millisecondsSinceEpoch}.jpg');
    await file.writeAsBytes(jpeg.data);
    return file.path;
  }

  Future<void> _readText() async {
    final reader = widget.readText;
    if (reader == null) return;
    setState(() {
      _reading = true;
      _textError = null;
    });
    try {
      // The evenly lit picture reads best.
      final path = await _finalJpeg(1400, _filter == 'bw' ? 'bw' : 'light');
      final r = await reader(path);
      File(path).delete().ignore();
      var left = '${r['title'] ?? ''}'.trim();
      final lines = <Map<String, dynamic>>[];
      for (final l in (r['lines'] as List? ?? [])) {
        final text = '${(l as Map)['text']}';
        String? field;
        // The lines that make up the suggested title start as «العنوان».
        if (left.isNotEmpty && left.startsWith(text)) {
          field = 'title';
          left = left.substring(text.length).trim();
        }
        lines.add({'text': text, 'field': field});
      }
      if (!mounted) return;
      setState(() {
        _lines = lines;
        _isbn = r['isbn'] as String?;
        _textError = lines.isEmpty ? context.l('لم يُعثر على نص واضح في الصورة.', 'No clear text was found.') : null;
      });
    } catch (e) {
      if (mounted) setState(() => _textError = context.l('تعذّر استخراج النص (يحتاج اتصالاً بالخادم).', 'Could not read the text (needs the server).'));
    }
    if (mounted) setState(() => _reading = false);
  }

  Map<String, String> get _chosen {
    final out = <String, String>{};
    for (final f in _fields) {
      final text = _lines.where((l) => l['field'] == f).map((l) => '${l['text']}').join(f == 'authors' ? ', ' : ' ').trim();
      if (text.isNotEmpty) out[f] = text;
    }
    if (_isbn != null) out['isbn'] = _isbn!;
    return out;
  }

  Future<void> _done() async {
    setState(() => _busy = true);
    try {
      final path = await _finalJpeg(1600, _filter);
      if (mounted) Navigator.of(context).pop(ScanResult(path, _chosen));
    } catch (_) {
      if (mounted) setState(() => _busy = false);
    }
  }

  String _filterName(String f) => switch (f) {
        'auto' => context.l('تلقائي', 'Auto'),
        'light' => context.l('إضاءة', 'Light'),
        'magic' => context.l('ألوان زاهية', 'Vivid'),
        'gray' => context.l('رمادي', 'Gray'),
        'bw' => context.l('أبيض وأسود', 'B&W'),
        _ => context.l('الأصل', 'Original'),
      };

  String _fieldName(String f) => switch (f) {
        'title' => context.l('العنوان', 'Title'),
        'authors' => context.l('المؤلف', 'Author'),
        _ => context.l('الناشر', 'Publisher'),
      };

  @override
  Widget build(BuildContext context) {
    return Theme(
      data: ThemeData.dark(useMaterial3: true).copyWith(colorScheme: const ColorScheme.dark(primary: _accent, secondary: _accent)),
      child: Builder(
        builder: (context) => Scaffold(
          backgroundColor: const Color(0xFF05070D),
          appBar: AppBar(
            backgroundColor: Colors.transparent,
            centerTitle: true,
            titleTextStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600, color: Colors.white),
            leading: IconButton(icon: const Icon(Icons.close_rounded), onPressed: () => Navigator.of(context).pop()),
            title: Text(_enhance ? context.l('الإضاءة والفلاتر', 'Light and filters') : context.l('حدّد حواف الغلاف', 'Mark the cover\'s edges')),
            actions: [
              if (_enhance)
                TextButton(onPressed: () => setState(() => _enhance = false), child: Text(context.l('الحواف', 'Edges')))
              else
                TextButton(
                  onPressed: () => Navigator.of(context).pop(ScanResult(widget.imagePath)),
                  child: Text(context.l('بدون تعديل', 'As it is'), style: const TextStyle(color: Colors.white70)),
                ),
            ],
          ),
          body: SafeArea(
            child: _error != null
                ? Center(child: Text(_error!, style: const TextStyle(color: Colors.redAccent)))
                : (_enhance ? _enhanceStep(context) : _cropStep(context)),
          ),
        ),
      ),
    );
  }

  Widget _spinner() => const ColoredBox(color: Colors.black38, child: Center(child: CircularProgressIndicator()));

  Widget _cropStep(BuildContext context) {
    return Column(children: [
      Expanded(
        child: LayoutBuilder(builder: (context, c) {
          final stage = c.biggest;
          return Stack(fit: StackFit.expand, children: [
            if (_work != null && _shown != null)
              GestureDetector(
                behavior: HitTestBehavior.opaque,
                onPanStart: (d) => _panStart(d, stage),
                onPanUpdate: (d) => _panUpdate(d, stage),
                onPanEnd: (_) => _panEnd(),
                onPanCancel: _panEnd,
                child: CustomPaint(
                  painter: _CropPainter(
                    image: _shown!,
                    rect: _imageRect(stage),
                    quad: [for (final p in _quad) _toScreen(p, _imageRect(stage))],
                    valid: isConvexQuad(_quad),
                    corner: _corner,
                    side: _side,
                    touch: _touch,
                    accent: _accent,
                  ),
                ),
              ),
            if (_busy) _spinner(),
          ]);
        }),
      ),
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 6, 16, 0),
        child: Text(
          _found
              ? context.l('تم التعرّف على الحواف. اسحب الزوايا أو الأضلاع للتعديل.', 'Edges found. Drag the corners or sides to adjust.')
              : context.l('لم تتضح الحواف. اسحب الزوايا إلى أركان الغلاف.', 'Edges not clear. Drag the corners onto the cover\'s corners.'),
          textAlign: TextAlign.center,
          style: const TextStyle(color: Colors.white60, fontSize: 12),
        ),
      ),
      Padding(
        padding: const EdgeInsets.fromLTRB(8, 6, 12, 12),
        child: Row(children: [
          _tool(Icons.auto_awesome_outlined, context.l('تلقائي', 'Auto'), _busy ? null : _autoDetect),
          _tool(Icons.fullscreen_rounded, context.l('الكل', 'All'), _busy ? null : _whole),
          _tool(Icons.rotate_right_rounded, context.l('تدوير', 'Rotate'), _busy ? null : _rotate),
          const Spacer(),
          FilledButton(
            onPressed: _busy || _work == null || !isConvexQuad(_quad) ? null : _toEnhance,
            style: FilledButton.styleFrom(backgroundColor: _accent, foregroundColor: Colors.white, padding: const EdgeInsets.symmetric(horizontal: 26, vertical: 12)),
            child: Text(context.l('التالي', 'Next')),
          ),
        ]),
      ),
    ]);
  }

  Widget _tool(IconData icon, String label, VoidCallback? onTap) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(10),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Icon(icon, color: Colors.white, size: 22),
            const SizedBox(height: 2),
            Text(label, style: const TextStyle(color: Colors.white, fontSize: 11)),
          ]),
        ),
      );

  Widget _enhanceStep(BuildContext context) {
    return Column(children: [
      Expanded(
        child: Stack(fit: StackFit.expand, children: [
          Padding(
            padding: const EdgeInsets.all(12),
            child: _result == null ? const SizedBox() : RawImage(image: _result, fit: BoxFit.contain),
          ),
          if (_busy || _result == null) _spinner(),
        ]),
      ),
      SizedBox(
        height: 44,
        child: ListView(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 10),
          children: [
            for (final f in scanFilters)
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 3),
                child: ChoiceChip(
                  label: Text(_filterName(f)),
                  selected: _filter == f,
                  onSelected: (_) {
                    setState(() => _filter = f);
                    _render();
                  },
                ),
              ),
          ],
        ),
      ),
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 10),
        child: Row(children: [
          const Icon(Icons.light_mode_outlined, size: 18, color: Colors.white70),
          Expanded(child: Slider(value: _brightness, min: -60, max: 60, onChanged: (v) => setState(() => _brightness = v), onChangeEnd: (_) => _render())),
          const Icon(Icons.contrast_rounded, size: 18, color: Colors.white70),
          Expanded(child: Slider(value: _contrast, min: -60, max: 60, onChanged: (v) => setState(() => _contrast = v), onChangeEnd: (_) => _render())),
          IconButton(
            tooltip: context.l('إرجاع', 'Reset'),
            icon: const Icon(Icons.restart_alt_rounded, size: 20),
            onPressed: _brightness == 0 && _contrast == 0
                ? null
                : () {
                    setState(() {
                      _brightness = 0;
                      _contrast = 0;
                    });
                    _render();
                  },
          ),
        ]),
      ),
      if (_textError != null || _lines.isNotEmpty)
        Container(
          constraints: const BoxConstraints(maxHeight: 170),
          margin: const EdgeInsets.symmetric(horizontal: 12),
          padding: const EdgeInsets.all(8),
          decoration: BoxDecoration(color: Colors.white10, borderRadius: BorderRadius.circular(10)),
          child: _textError != null
              ? Text(_textError!, style: const TextStyle(color: Colors.amberAccent, fontSize: 12))
              : ListView(shrinkWrap: true, children: [
                  Text(context.l('اختر لكل سطر مكانه في بيانات الكتاب (اختياري):', 'Choose where each line goes (optional):'), style: const TextStyle(color: Colors.white60, fontSize: 11)),
                  if (_isbn != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text('ISBN $_isbn', textDirection: TextDirection.ltr, style: const TextStyle(color: _accent, fontSize: 12))),
                  for (final line in _lines)
                    Padding(
                      padding: const EdgeInsets.only(top: 6),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text('${line['text']}', maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontSize: 13)),
                        Wrap(spacing: 6, children: [
                          for (final f in _fields)
                            GestureDetector(
                              onTap: () => setState(() => line['field'] = line['field'] == f ? null : f),
                              child: Container(
                                margin: const EdgeInsets.only(top: 3),
                                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
                                decoration: BoxDecoration(color: line['field'] == f ? _accent : Colors.white12, borderRadius: BorderRadius.circular(20)),
                                child: Text(_fieldName(f), style: const TextStyle(color: Colors.white, fontSize: 11)),
                              ),
                            ),
                        ]),
                      ]),
                    ),
                ]),
        ),
      Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 12),
        child: Row(children: [
          if (widget.readText != null)
            OutlinedButton.icon(
              onPressed: _reading || _busy ? null : _readText,
              icon: _reading ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.text_snippet_outlined, size: 18),
              label: Text(context.l('استخراج النص', 'Read the text')),
              style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Colors.white24)),
            ),
          const Spacer(),
          FilledButton.icon(
            onPressed: _busy || _result == null ? null : _done,
            icon: const Icon(Icons.check_rounded),
            label: Text(context.l('اعتماد', 'Use it')),
            style: FilledButton.styleFrom(backgroundColor: _accent, foregroundColor: Colors.white, padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 12)),
          ),
        ]),
      ),
    ]);
  }
}

/// The photo with the frame over it, its handles, and the magnifier while one is dragged.
class _CropPainter extends CustomPainter {
  _CropPainter({required this.image, required this.rect, required this.quad, required this.valid, required this.corner, required this.side, required this.touch, required this.accent});

  final ui.Image image;
  final Rect rect;
  final List<Offset> quad;
  final bool valid;
  final int? corner;
  final int? side;
  final Offset? touch;
  final Color accent;

  static const _loupe = 132.0;

  @override
  void paint(Canvas canvas, Size size) {
    final src = Rect.fromLTWH(0, 0, image.width.toDouble(), image.height.toDouble());
    canvas.drawImageRect(image, src, rect, Paint()..filterQuality = FilterQuality.medium);
    if (quad.length != 4) return;

    final frame = Path()..addPolygon(quad, true);
    // Everything outside the frame is dimmed.
    canvas.drawPath(Path.combine(PathOperation.difference, Path()..addRect(rect), frame), Paint()..color = Colors.black54);
    final line = Paint()
      ..color = valid ? accent : Colors.redAccent
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.5
      ..strokeJoin = StrokeJoin.round;
    canvas.drawPath(frame, line);

    final white = Paint()..color = Colors.white;
    for (var i = 0; i < 4; i++) {
      final mid = (quad[i] + quad[(i + 1) % 4]) / 2;
      final r = RRect.fromRectAndRadius(Rect.fromCenter(center: mid, width: 18, height: 18), const Radius.circular(5));
      canvas.drawRRect(r, white);
      canvas.drawRRect(r, line);
    }
    for (var i = 0; i < 4; i++) {
      canvas.drawCircle(quad[i], 13, Paint()..color = (corner == i ? accent : Colors.white).withOpacity(0.3));
      canvas.drawCircle(
          quad[i],
          13,
          Paint()
            ..color = Colors.white
            ..style = PaintingStyle.stroke
            ..strokeWidth = 3);
      canvas.drawCircle(quad[i], 3, Paint()..color = accent);
    }

    // The magnifier: the exact spot, which the finger covers.
    final focus = corner != null ? quad[corner!] : (side != null ? (quad[side!] + quad[(side! + 1) % 4]) / 2 : null);
    if (focus == null) return;
    final nearLeft = touch != null && touch!.dx < _loupe + 40 && touch!.dy < _loupe + 40;
    final center = Offset(nearLeft ? size.width - _loupe / 2 - 12 : _loupe / 2 + 12, _loupe / 2 + 12);
    final circle = Rect.fromCircle(center: center, radius: _loupe / 2);
    final scale = rect.width / image.width; // screen pixels per photo pixel
    final zoom = math.max(scale * 2.5, 1.0);
    final span = _loupe / zoom; // photo pixels across the magnifier
    final fx = (focus.dx - rect.left) / scale;
    final fy = (focus.dy - rect.top) / scale;

    canvas.save();
    canvas.clipPath(Path()..addOval(circle));
    canvas.drawRect(circle, Paint()..color = const Color(0xFF111111));
    canvas.drawImageRect(image, Rect.fromCenter(center: Offset(fx, fy), width: span, height: span), circle, Paint()..filterQuality = FilterQuality.medium);
    Offset map(Offset p) => center + Offset(((p.dx - rect.left) / scale - fx) * zoom, ((p.dy - rect.top) / scale - fy) * zoom);
    canvas.drawPath(
        Path()..addPolygon([for (final p in quad) map(p)], true),
        Paint()
          ..color = accent
          ..style = PaintingStyle.stroke
          ..strokeWidth = 2);
    final cross = Paint()
      ..color = Colors.white
      ..strokeWidth = 1.2;
    canvas.drawLine(center - const Offset(12, 0), center + const Offset(12, 0), cross);
    canvas.drawLine(center - const Offset(0, 12), center + const Offset(0, 12), cross);
    canvas.restore();
    canvas.drawCircle(
        center,
        _loupe / 2,
        Paint()
          ..color = Colors.white
          ..style = PaintingStyle.stroke
          ..strokeWidth = 3);
  }

  @override
  bool shouldRepaint(covariant _CropPainter old) => true;
}
