import 'dart:collection';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:open_filex/open_filex.dart';
import 'package:share_plus/share_plus.dart';

import '../widgets/ui_kit.dart';

/// «قارئ PDF» (2026-11-11, by request): reads a PDF inside the app, page after page, without
/// another app (Android draws the pages with PdfRenderer, iPhone with PDFKit). Pages are drawn
/// as they come into view and the few far away are let go, so a big thesis stays light.
class PdfReaderScreen extends StatefulWidget {
  const PdfReaderScreen({super.key, required this.path, required this.title});

  final String path;
  final String title;

  /// Phones read here; the Windows app hands the file to the computer's own PDF reader.
  static bool get supported => Platform.isAndroid || Platform.isIOS;

  @override
  State<PdfReaderScreen> createState() => _PdfReaderScreenState();
}

class _PdfReaderScreenState extends State<PdfReaderScreen> {
  static const _channel = MethodChannel('filenest/pdf');
  static const _keep = 14;

  final _scroll = ScrollController();
  final _zoom = TransformationController();
  final _images = LinkedHashMap<int, Uint8List>();
  final Map<int, Future<Uint8List?>> _pending = {};

  List<Size> _sizes = [];
  String? _error;
  int _current = 1;
  bool _zoomed = false;
  int _renderWidth = 1080;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_onScroll);
    _zoom.addListener(() {
      final z = _zoom.value.getMaxScaleOnAxis() > 1.01;
      if (z != _zoomed) setState(() => _zoomed = z);
    });
    _open();
  }

  @override
  void dispose() {
    _channel.invokeMethod('close');
    _scroll.dispose();
    _zoom.dispose();
    super.dispose();
  }

  Future<void> _open() async {
    try {
      final head = await File(widget.path).openRead(0, 1024).fold<List<int>>([], (a, b) => a..addAll(b));
      if (!String.fromCharCodes(head).contains('%PDF')) {
        setState(() => _error = context.l('الملف المنزّل ليس PDF صالحاً (قد يكون التنزيل لم يكتمل). احذفه وأعد تنزيله.',
            'The downloaded file is not a valid PDF (the download may be incomplete). Delete it and download again.'));
        return;
      }
      final r = await _channel.invokeMapMethod<String, dynamic>('open', {'path': widget.path});
      final sizes = ((r?['sizes'] as List?) ?? [])
          .map((e) => Size(((e as List)[0] as num).toDouble(), (e[1] as num).toDouble()))
          .toList();
      if (!mounted) return;
      if (sizes.isEmpty) {
        setState(() => _error = context.l('الملف لا يحتوي صفحات.', 'The file has no pages.'));
        return;
      }
      setState(() => _sizes = sizes);
    } on PlatformException catch (e) {
      if (!mounted) return;
      setState(() => _error = e.code == 'password'
          ? context.l('الملف محمي بكلمة سر، افتحه ببرنامج آخر.', 'The file is password-protected; open it in another app.')
          : context.l('تعذّر عرض الملف هنا: ${e.message ?? ''}', 'Could not show the file here: ${e.message ?? ''}'));
    } catch (e) {
      if (mounted) setState(() => _error = '$e');
    }
  }

  Future<Uint8List?> _page(int index) {
    final have = _images[index];
    if (have != null) return Future.value(have);
    return _pending[index] ??= _channel.invokeMethod<Uint8List>('render', {'page': index, 'width': _renderWidth}).then((bytes) {
      _pending.remove(index);
      if (bytes != null) {
        _images[index] = bytes;
        while (_images.length > _keep) {
          _images.remove(_images.keys.first);
        }
      }
      return bytes;
    }).catchError((_) {
      _pending.remove(index);
      return null;
    });
  }

  /// Top of each page in the list, for the page counter and «go to page».
  double _top(int index, double width) {
    var y = 8.0;
    for (var i = 0; i < index; i++) {
      y += width * _sizes[i].height / _sizes[i].width + 8;
    }
    return y;
  }

  void _onScroll() {
    if (_sizes.isEmpty || !_scroll.hasClients) return;
    final width = context.size?.width ?? 400;
    final mid = _scroll.offset + _scroll.position.viewportDimension / 2;
    var y = 8.0;
    var n = 1;
    for (var i = 0; i < _sizes.length; i++) {
      final h = (width - 16) * _sizes[i].height / _sizes[i].width + 8;
      if (y + h > mid) {
        n = i + 1;
        break;
      }
      y += h;
      n = i + 1;
    }
    if (n != _current) setState(() => _current = n);
  }

  Future<void> _goTo() async {
    final c = TextEditingController();
    final n = await showDialog<int>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text(context.l('اذهب إلى صفحة', 'Go to page')),
        content: TextField(
          controller: c,
          autofocus: true,
          keyboardType: TextInputType.number,
          decoration: InputDecoration(hintText: '1 – ${_sizes.length}'),
          onSubmitted: (v) => Navigator.pop(context, int.tryParse(v)),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: Text(context.l('إلغاء', 'Cancel'))),
          FilledButton(onPressed: () => Navigator.pop(context, int.tryParse(c.text)), child: Text(context.l('اذهب', 'Go'))),
        ],
      ),
    );
    c.dispose();
    if (n == null || n < 1 || n > _sizes.length || !_scroll.hasClients) return;
    final width = (context.size?.width ?? 400) - 16;
    _scroll.jumpTo(_top(n - 1, width).clamp(0, _scroll.position.maxScrollExtent));
  }

  Future<void> _openElsewhere() async {
    final r = await OpenFilex.open(widget.path);
    if (r.type != ResultType.done && mounted) {
      context.toast(context.l('لا يوجد برنامج لفتح الملف على هذا الجهاز.', 'No app on this device can open the file.'), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Theme.of(context).brightness == Brightness.dark ? const Color(0xFF111111) : const Color(0xFFE5E7EB),
      appBar: AppBar(
        title: Text(widget.title, maxLines: 1, overflow: TextOverflow.ellipsis),
        actions: [
          if (_sizes.isNotEmpty) IconButton(tooltip: context.l('اذهب إلى صفحة', 'Go to page'), icon: const Icon(Icons.format_list_numbered_rounded), onPressed: _goTo),
          PopupMenuButton<String>(
            onSelected: (v) => v == 'share' ? Share.shareXFiles([XFile(widget.path)]) : _openElsewhere(),
            itemBuilder: (_) => [
              PopupMenuItem(value: 'open', child: Text(context.l('فتح ببرنامج آخر', 'Open in another app'))),
              PopupMenuItem(value: 'share', child: Text(context.l('مشاركة الملف', 'Share the file'))),
            ],
          ),
        ],
      ),
      body: _error != null
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  const Icon(Icons.picture_as_pdf_outlined, size: 56, color: Colors.grey),
                  const SizedBox(height: 12),
                  Text(_error!, textAlign: TextAlign.center),
                  const SizedBox(height: 16),
                  OutlinedButton.icon(onPressed: _openElsewhere, icon: const Icon(Icons.open_in_new_rounded), label: Text(context.l('فتح ببرنامج آخر', 'Open in another app'))),
                ]),
              ),
            )
          : _sizes.isEmpty
              ? const Center(child: CircularProgressIndicator())
              : LayoutBuilder(builder: (context, box) {
                  final width = box.maxWidth - 16;
                  _renderWidth = (width * MediaQuery.of(context).devicePixelRatio).clamp(600, 1600).round();
                  return Stack(children: [
                    InteractiveViewer(
                      transformationController: _zoom,
                      minScale: 1,
                      maxScale: 5,
                      panEnabled: _zoomed,
                      child: ListView.builder(
                        controller: _scroll,
                        physics: _zoomed ? const NeverScrollableScrollPhysics() : null,
                        padding: const EdgeInsets.fromLTRB(8, 8, 8, 64),
                        itemCount: _sizes.length,
                        itemBuilder: (context, i) => Padding(
                          padding: const EdgeInsets.only(bottom: 8),
                          child: AspectRatio(
                            aspectRatio: _sizes[i].width / _sizes[i].height,
                            child: DecoratedBox(
                              decoration: const BoxDecoration(color: Colors.white, boxShadow: [BoxShadow(color: Colors.black12, blurRadius: 4)]),
                              child: FutureBuilder<Uint8List?>(
                                future: _page(i),
                                initialData: _images[i],
                                builder: (context, snap) => snap.data == null
                                    ? Center(child: Text('${i + 1}', style: const TextStyle(color: Colors.black26, fontSize: 28)))
                                    : Image.memory(snap.data!, fit: BoxFit.fill, gaplessPlayback: true),
                              ),
                            ),
                          ),
                        ),
                      ),
                    ),
                    Positioned(
                      bottom: 14,
                      left: 0,
                      right: 0,
                      child: Center(
                        child: GestureDetector(
                          onTap: _goTo,
                          child: Container(
                            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                            decoration: BoxDecoration(color: Colors.black.withOpacity(0.6), borderRadius: BorderRadius.circular(20)),
                            child: Text('$_current / ${_sizes.length}', textDirection: TextDirection.ltr, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600)),
                          ),
                        ),
                      ),
                    ),
                  ]);
                }),
    );
  }
}
