import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../../app/theme/app_colors.dart';
import '../../../core/widgets/ui_kit.dart';
import '../data/library_map_cache.dart';
import 'library_map_screen.dart';

/// «خريطة المكتبة» drawn by the app itself (2026-11-11, by request): the plan is kept on the
/// device, so it opens without internet, and is refreshed whenever the internet is there (the
/// reader is told when the library changed it). The 3D map stays one tap away when online.
class LibraryPlanScreen extends StatefulWidget {
  const LibraryPlanScreen({super.key, this.bookId, this.bookTitle, this.spotCode, this.spotLevel});

  final int? bookId;
  final String? bookTitle;

  /// The bookcase to show (the book's «map_spot»).
  final String? spotCode;
  final int? spotLevel;

  @override
  State<LibraryPlanScreen> createState() => _LibraryPlanScreenState();
}

class _LibraryPlanScreenState extends State<LibraryPlanScreen> {
  final _cache = LibraryMapCache.instance;
  final _zoom = TransformationController();
  Map<String, dynamic>? _data;
  bool _offline = false;
  bool _loading = true;
  int _storey = 0;
  Timer? _retry;
  Size? _fitted;

  @override
  void initState() {
    super.initState();
    _start();
  }

  @override
  void dispose() {
    _retry?.cancel();
    _zoom.dispose();
    super.dispose();
  }

  Future<void> _start() async {
    final saved = await _cache.saved();
    if (saved != null && mounted) _show(saved);
    await _refresh(first: saved == null);
  }

  Future<void> _refresh({bool first = false}) async {
    try {
      final changed = await _cache.refresh();
      final data = await _cache.saved();
      if (!mounted) return;
      _retry?.cancel();
      _retry = null;
      if (data != null && (changed || _data == null)) _show(data);
      setState(() {
        _offline = false;
        _loading = false;
      });
      if (changed && !first) context.toast(context.l('حُدّثت خريطة المكتبة.', 'The library map was updated.'));
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _offline = true;
        _loading = false;
      });
      // Try again while the map is open: it updates by itself when the internet is back.
      _retry ??= Timer.periodic(const Duration(seconds: 30), (_) => _refresh());
    }
  }

  void _show(Map<String, dynamic> data) {
    setState(() {
      _data = data;
      final t = _target(data);
      if (t != null) _storey = ((t['storey'] as num?) ?? 0).toInt();
    });
  }

  List<Map<String, dynamic>> _shelves(Map<String, dynamic> d) =>
      ((d['shelves'] as List?) ?? []).map((e) => Map<String, dynamic>.from(e as Map)).toList();

  /// The book's bookcase: given, or found in the saved books.
  String? _spotCode(Map<String, dynamic> d) {
    if (widget.spotCode != null) return widget.spotCode;
    for (final b in (d['books'] as List? ?? [])) {
      if (b is Map && b['id'] == widget.bookId) {
        final spots = b['spots'] as List? ?? [];
        if (spots.isNotEmpty) return '${(spots.first as Map)['code']}';
      }
    }
    return null;
  }

  Map<String, dynamic>? _target(Map<String, dynamic> d) {
    final code = _spotCode(d)?.trim().toLowerCase();
    if (code == null || code.isEmpty) return null;
    for (final s in _shelves(d)) {
      if ('${s['code']}'.toLowerCase() == code) return s;
    }
    return null;
  }

  void _tapShelf(Map<String, dynamic> s, Map<String, dynamic> stock) {
    final raw = stock['${s['id']}'];
    final n = raw is Map ? raw['n'] : raw;
    showModalBottomSheet<void>(
      context: context,
      useRootNavigator: true,
      builder: (_) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 20),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(
              [s['code'], if ((s['name'] ?? '').toString().isNotEmpty) s['name']].join(' — '),
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 8),
            if (s['section'] != null) Text(context.l('القسم: ${s['section']}', 'Section: ${s['section']}')),
            if (s['kind'] == 'shelf') ...[
              Text(context.l('الرفوف: ${s['levels'] ?? '—'}', 'Levels: ${s['levels'] ?? '—'}')),
              Text(context.l('النسخ عليه: ${n ?? 0}', 'Copies on it: ${n ?? 0}')),
            ],
          ]),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final d = _data;
    final floor = d == null ? null : Map<String, dynamic>.from(d['floor'] as Map);
    final storeys = ((floor?['storeys'] as List?) ?? []).map((e) => '$e').toList();
    final target = d == null ? null : _target(d);

    return Scaffold(
      appBar: AppBar(
        title: Text(widget.bookTitle ?? context.l('خريطة المكتبة', 'Library map'), maxLines: 1, overflow: TextOverflow.ellipsis),
        actions: [
          if (!_offline)
            TextButton.icon(
              onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => LibraryMapScreen(bookId: widget.bookId))),
              icon: const Icon(Icons.view_in_ar_rounded, size: 20),
              label: Text(context.l('ثلاثي الأبعاد', '3D')),
            ),
        ],
      ),
      body: d == null
          ? Center(
              child: _loading
                  ? const CircularProgressIndicator()
                  : Padding(
                      padding: const EdgeInsets.all(24),
                      child: Text(
                        context.l('تحتاج الخريطة اتصالاً بالإنترنت أول مرة، ثم تعمل بدونه.', 'The map needs the internet the first time, then works without it.'),
                        textAlign: TextAlign.center,
                      ),
                    ),
            )
          : Column(children: [
              if (_offline)
                Container(
                  width: double.infinity,
                  color: AppColors.warning.withOpacity(0.15),
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                  child: Text(context.l('بدون إنترنت: الخريطة المحفوظة على الجهاز.', 'Offline: the map saved on this device.'), style: const TextStyle(fontSize: 12.5)),
                ),
              if (target != null)
                ListTile(
                  dense: true,
                  leading: const Icon(Icons.place_rounded, color: Color(0xFFF59E0B)),
                  title: Text(context.l('الرف ${target['code']}${widget.spotLevel != null ? ' — الطبقة ${widget.spotLevel}' : ''}',
                      'Bookcase ${target['code']}${widget.spotLevel != null ? ' — level ${widget.spotLevel}' : ''}')),
                ),
              if (storeys.length > 1)
                SizedBox(
                  height: 44,
                  child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 8), children: [
                    for (var i = 0; i < storeys.length; i++)
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
                        child: ChoiceChip(label: Text(storeys[i]), selected: _storey == i, onSelected: (_) => setState(() => _storey = i)),
                      ),
                  ]),
                ),
              Expanded(child: _plan(d, floor!, target)),
            ]),
    );
  }

  Widget _plan(Map<String, dynamic> d, Map<String, dynamic> floor, Map<String, dynamic>? target) {
    final width = ((floor['width'] as num?) ?? 20).toDouble();
    final depth = ((floor['depth'] as num?) ?? 14).toDouble();
    final items = _shelves(d).where((s) => ((s['storey'] as num?) ?? 0).toInt() == _storey).toList();
    final stock = Map<String, dynamic>.from((d['stock'] is Map ? d['stock'] : const {}) as Map);
    final entrance = floor['entrance'] is Map ? Map<String, dynamic>.from(floor['entrance'] as Map) : null;

    return LayoutBuilder(builder: (context, box) {
      final scale = math.min((box.maxWidth - 24) / width, (box.maxHeight - 24) / depth);
      final size = Size(width * scale, depth * scale);
      if (_fitted != box.biggest) {
        _fitted = box.biggest;
        // A book's bookcase starts zoomed in on it.
        if (target != null && ((target['storey'] as num?) ?? 0).toInt() == _storey) {
          const z = 2.2;
          final tx = (target['x'] as num).toDouble() * scale + 12;
          final ty = (target['y'] as num).toDouble() * scale + 12;
          WidgetsBinding.instance.addPostFrameCallback((_) {
            if (!mounted) return;
            _zoom.value = Matrix4.identity()
              ..translate(box.maxWidth / 2 - tx * z, box.maxHeight / 2 - ty * z)
              ..scale(z);
          });
        }
      }
      return InteractiveViewer(
        transformationController: _zoom,
        constrained: false,
        minScale: 0.6,
        maxScale: 10,
        boundaryMargin: const EdgeInsets.all(200),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: GestureDetector(
            onTapUp: (e) {
              final p = Offset(e.localPosition.dx / scale, e.localPosition.dy / scale);
              for (final s in items.reversed) {
                if (_PlanPainter.isHall(s)) continue;
                if (_PlanPainter.contains(s, p)) {
                  _tapShelf(s, stock);
                  return;
                }
              }
            },
            child: CustomPaint(
              size: size,
              painter: _PlanPainter(
                items: items,
                scale: scale,
                floorSize: Size(width, depth),
                target: target != null && ((target['storey'] as num?) ?? 0).toInt() == _storey ? target : null,
                entrance: _storey == 0 && entrance != null ? Offset((entrance['x'] as num).toDouble(), (entrance['y'] as num).toDouble()) : null,
                dark: Theme.of(context).brightness == Brightness.dark,
              ),
            ),
          ),
        ),
      );
    });
  }
}

class _PlanPainter extends CustomPainter {
  _PlanPainter({required this.items, required this.scale, required this.floorSize, this.target, this.entrance, required this.dark});

  final List<Map<String, dynamic>> items;
  final double scale;
  final Size floorSize;
  final Map<String, dynamic>? target;
  final Offset? entrance;
  final bool dark;

  static const _sectionColors = [0xFF2563EB, 0xFF059669, 0xFFD97706, 0xFFDB2777, 0xFF7C3AED, 0xFF0891B2, 0xFFDC2626, 0xFF65A30D, 0xFFEA580C, 0xFF4F46E5];

  static double _n(Object? v, [double d = 0]) => v is num ? v.toDouble() : double.tryParse('$v') ?? d;

  static bool isHall(Map<String, dynamic> s) => (s['kind'] == 'hall' || s['kind'] == 'corridor') && ((s['points'] as List?)?.length ?? 0) >= 3;

  /// A shape's corners on the floor (its points turned by its rotation, around its x, y).
  static List<Offset> outline(Map<String, dynamic> s) {
    final a = _n(s['rotation']) * math.pi / 180;
    final c = math.cos(a), sn = math.sin(a);
    final x = _n(s['x']), y = _n(s['y']);
    return ((s['points'] as List?) ?? []).map((p) {
      final px = _n((p as List)[0]), py = _n(p[1]);
      return Offset(x + px * c - py * sn, y + px * sn + py * c);
    }).toList();
  }

  static bool contains(Map<String, dynamic> s, Offset p) {
    final a = -_n(s['rotation']) * math.pi / 180;
    final dx = p.dx - _n(s['x']), dy = p.dy - _n(s['y']);
    final lx = dx * math.cos(a) - dy * math.sin(a), ly = dx * math.sin(a) + dy * math.cos(a);
    final w = math.max(_n(s['width'], 1), 0.6) / 2, h = math.max(_n(s['depth'], 0.5), 0.6) / 2;
    return lx.abs() <= w && ly.abs() <= h;
  }

  Color _hex(String? v, Color fallback) {
    if (v == null || !v.startsWith('#') || (v.length != 7 && v.length != 4)) return fallback;
    var h = v.substring(1);
    if (h.length == 3) h = h.split('').map((c) => '$c$c').join();
    return Color(int.parse('FF$h', radix: 16));
  }

  Color _fill(Map<String, dynamic> s) {
    switch (s['kind']) {
      case 'shelf':
        final id = (s['section_id'] as num?)?.toInt();
        return _hex(s['color'] as String?, id == null ? const Color(0xFF64748B) : Color(_sectionColors[(id * 7) % _sectionColors.length]));
      case 'stairs':
        return const Color(0xFFEDE9FE);
      case 'door':
        return const Color(0xFF10B981);
      case 'gate':
        return const Color(0xFFDC2626);
      case 'desk':
        return const Color(0xFF0F766E);
      case 'qr':
        return const Color(0xFF111827);
      case 'obstacle':
        return _hex(s['color'] as String?, const Color(0xFF6B7280));
      case 'wall':
        return const Color(0xFF475569);
      default:
        return const Color(0xFFA16207);
    }
  }

  @override
  void paint(Canvas canvas, Size size) {
    canvas.save();
    canvas.scale(scale);
    final line = 1 / scale;

    canvas.drawRect(Offset.zero & floorSize, Paint()..color = dark ? const Color(0xFF1F2937) : Colors.white);
    canvas.drawRect(Offset.zero & floorSize, Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = line
      ..color = const Color(0xFFCBD5E1));

    for (final h in items.where(isHall)) {
      final path = Path()..addPolygon(outline(h), true);
      canvas.drawPath(path, Paint()..color = h['kind'] == 'corridor' ? (dark ? const Color(0xFF334155) : const Color(0xFFF1F5F9)) : (dark ? const Color(0xFF374151) : const Color(0xFFFFFBEB)));
      canvas.drawPath(path, Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 0.12
        ..color = const Color(0xFF475569));
    }

    for (final s in items) {
      if (isHall(s)) continue;
      final kind = s['kind'];
      if (kind == 'obstacle' && ((s['points'] as List?)?.length ?? 0) >= 3) {
        canvas.drawPath(Path()..addPolygon(outline(s), true), Paint()..color = _fill(s).withOpacity(0.7));
        continue;
      }
      final w = _n(s['width'], 1), dp = _n(s['depth'], 0.5);
      canvas.save();
      canvas.translate(_n(s['x']), _n(s['y']));
      canvas.rotate(_n(s['rotation']) * math.pi / 180);
      if (kind == 'door') {
        canvas.drawRect(Rect.fromCenter(center: Offset.zero, width: w, height: 0.2), Paint()..color = _fill(s));
      } else {
        final r = RRect.fromRectAndRadius(Rect.fromCenter(center: Offset.zero, width: w, height: dp), const Radius.circular(0.04));
        canvas.drawRRect(r, Paint()..color = _fill(s));
        canvas.drawRRect(r, Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = kind == 'stairs' ? 0.06 : 0.03
          ..color = kind == 'stairs' ? const Color(0xFF7C3AED) : const Color(0x40000000));
        if (kind == 'stairs') {
          final p = Paint()
            ..color = const Color(0xFF7C3AED)
            ..strokeWidth = 0.03;
          for (var k = 1; k < 8; k++) {
            final yy = -dp / 2 + dp / 8 * k;
            canvas.drawLine(Offset(-w / 2, yy), Offset(w / 2, yy), p);
          }
        }
      }
      if (!const ['door', 'qr', 'wall', 'obstacle'].contains(kind) && '${s['code'] ?? ''}'.isNotEmpty) {
        var rot = _n(s['rotation']) % 360;
        if (rot < 0) rot += 360;
        if (rot > 90 && rot <= 270) canvas.rotate(math.pi);
        final fs = math.max(0.22, math.min(0.5, math.min(w / 2.6, dp * 1.4)));
        // Text is laid out in screen pixels (tiny font sizes scaled up draw badly).
        canvas.scale(1 / scale);
        final tp = TextPainter(
          text: TextSpan(
            text: '${s['code']}',
            style: TextStyle(fontSize: fs * scale, fontWeight: FontWeight.w800, color: kind == 'stairs' ? const Color(0xFF5B21B6) : Colors.white, height: 1),
          ),
          textDirection: TextDirection.ltr,
        )..layout();
        tp.paint(canvas, Offset(-tp.width / 2, -tp.height / 2));
      }
      canvas.restore();
    }

    final t = target;
    if (t != null) {
      final w = _n(t['width'], 1), dp = _n(t['depth'], 0.5);
      canvas.save();
      canvas.translate(_n(t['x']), _n(t['y']));
      canvas.rotate(_n(t['rotation']) * math.pi / 180);
      final r = RRect.fromRectAndRadius(Rect.fromCenter(center: Offset.zero, width: w + 0.3, height: dp + 0.3), const Radius.circular(0.1));
      canvas.drawRRect(r, Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 0.14
        ..color = const Color(0xFFF59E0B));
      canvas.restore();
      final c = Offset(_n(t['x']), _n(t['y']));
      canvas.drawCircle(c, 0.55, Paint()..color = const Color(0x55F59E0B));
    }

    final e = entrance;
    if (e != null) {
      canvas.drawCircle(e, 0.3, Paint()..color = const Color(0xFF10B981));
      canvas.drawCircle(e, 0.3, Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 0.06
        ..color = Colors.white);
    }
    canvas.restore();
  }

  @override
  bool shouldRepaint(covariant _PlanPainter old) =>
      old.items != items || old.scale != scale || old.target != target || old.dark != dark || old.entrance != entrance;
}
