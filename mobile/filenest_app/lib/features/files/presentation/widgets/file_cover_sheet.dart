import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../../../../core/models/file_item.dart';
import '../../../../core/network/api_client.dart';
import '../../../../core/scan/cover_scanner_screen.dart';
import '../../../../core/widgets/ui_kit.dart';

/// «غلاف الكتاب…» on one file (2026-11-14), as on the website: search the cover online and
/// pick it, photograph or upload a picture (through the scanner), use a page of the PDF,
/// or remove the cover. Returns true when the cover changed.
Future<bool> showFileCoverSheet(BuildContext context, FileItem file) async {
  final changed = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
    builder: (_) => FractionallySizedBox(heightFactor: 0.85, child: _FileCoverSheet(file: file)),
  );
  return changed == true;
}

class _FileCoverSheet extends StatefulWidget {
  const _FileCoverSheet({required this.file});
  final FileItem file;

  @override
  State<_FileCoverSheet> createState() => _FileCoverSheetState();
}

class _FileCoverSheetState extends State<_FileCoverSheet> {
  Dio get _dio => ApiClient.instance.dio;
  final _query = TextEditingController();
  List<Map<String, dynamic>> _results = [];
  bool _searching = true;
  bool _busy = false;
  String? _message;

  String get _base => '/files/${widget.file.id}/cover';
  bool get _isPdf => widget.file.name.toLowerCase().endsWith('.pdf');

  @override
  void initState() {
    super.initState();
    _search();
  }

  @override
  void dispose() {
    _query.dispose();
    super.dispose();
  }

  Future<void> _search() async {
    setState(() {
      _searching = true;
      _message = null;
    });
    try {
      final q = _query.text.trim();
      final r = await _dio.get('$_base/search', queryParameters: {if (q.isNotEmpty) 'q': q});
      final data = Map<String, dynamic>.from(r.data as Map);
      if (!mounted) return;
      setState(() {
        _results = ((data['results'] as List?) ?? []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
        if (_query.text.isEmpty) _query.text = '${data['query'] ?? ''}';
        if (_results.isEmpty) _message = context.l('لا توجد نتائج. جرّب عنواناً آخر أو رقم ISBN، أو استعمل صورة.', 'No results. Try another title or an ISBN, or use a picture.');
      });
    } catch (e) {
      if (mounted) setState(() => _message = ApiClient.messageFor(e));
    }
    if (mounted) setState(() => _searching = false);
  }

  /// Sends one change and closes on success.
  Future<void> _apply(Future<Response<dynamic>> Function() send, String done) async {
    setState(() => _busy = true);
    try {
      await send();
      if (!mounted) return;
      context.toast(done);
      Navigator.of(context).pop(true);
    } catch (e) {
      if (mounted) {
        context.toastError(e);
        setState(() => _busy = false);
      }
    }
  }

  Future<void> _pick(Map<String, dynamic> hit) => _apply(
        () => _dio.post(_base, data: {'url': hit['url'], 'source': hit['source'], if (hit['isbn'] != null) 'isbn': hit['isbn']}),
        context.l('تم تعيين الغلاف.', 'Cover set.'),
      );

  Future<void> _photo() async {
    final r = await scanCover(context);
    if (r == null || !mounted) return;
    await _apply(
      () async => _dio.post(_base, data: FormData.fromMap({'image': await MultipartFile.fromFile(r.path)})),
      context.l('تم تعيين الغلاف.', 'Cover set.'),
    );
  }

  Future<void> _page() async {
    final c = TextEditingController(text: '1');
    final n = await showDialog<int>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(ctx.l('صفحة من الملف', 'A page of the file')),
        content: TextField(controller: c, keyboardType: TextInputType.number, autofocus: true, decoration: InputDecoration(labelText: ctx.l('رقم الصفحة', 'Page number'))),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: Text(ctx.l('إلغاء', 'Cancel'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, int.tryParse(c.text.trim())), child: Text(ctx.l('استعمال', 'Use'))),
        ],
      ),
    );
    if (n == null || n < 1 || !mounted) return;
    await _apply(() => _dio.post(_base, data: {'page': n}), context.l('تم تعيين الغلاف.', 'Cover set.'));
  }

  Future<void> _remove() => _apply(() => _dio.delete(_base), context.l('أزيل الغلاف.', 'Cover removed.'));

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 14, 16, 4),
          child: Row(children: [
            Expanded(child: Text(context.l('غلاف الكتاب', 'Book cover'), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700))),
            if (_busy) const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)),
          ]),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 0, 12, 6),
          child: Wrap(spacing: 8, children: [
            ActionChip(avatar: const Icon(Icons.photo_camera_outlined, size: 18), label: Text(context.l('تصوير أو صورة', 'Photo or picture')), onPressed: _busy ? null : _photo),
            if (_isPdf) ActionChip(avatar: const Icon(Icons.find_in_page_outlined, size: 18), label: Text(context.l('صفحة من الملف', 'A page of the file')), onPressed: _busy ? null : _page),
            ActionChip(avatar: const Icon(Icons.hide_image_outlined, size: 18), label: Text(context.l('إزالة الغلاف', 'Remove cover')), onPressed: _busy ? null : _remove),
          ]),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: TextField(
            controller: _query,
            textInputAction: TextInputAction.search,
            onSubmitted: (_) => _search(),
            decoration: InputDecoration(
              isDense: true,
              labelText: context.l('ابحث بالعنوان أو ISBN', 'Search by title or ISBN'),
              suffixIcon: IconButton(icon: const Icon(Icons.search_rounded), onPressed: _searching ? null : _search),
            ),
          ),
        ),
        Expanded(
          child: _searching
              ? const Center(child: CircularProgressIndicator())
              : _results.isEmpty
                  ? Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(_message ?? '', textAlign: TextAlign.center)))
                  : GridView.builder(
                      padding: const EdgeInsets.all(12),
                      gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(maxCrossAxisExtent: 130, childAspectRatio: 0.52, crossAxisSpacing: 10, mainAxisSpacing: 10),
                      itemCount: _results.length,
                      itemBuilder: (context, i) {
                        final hit = _results[i];
                        return InkWell(
                          onTap: _busy ? null : () => _pick(hit),
                          borderRadius: BorderRadius.circular(8),
                          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                            Expanded(
                              child: ClipRRect(
                                borderRadius: BorderRadius.circular(8),
                                child: Image.network('${hit['thumb'] ?? hit['url']}', fit: BoxFit.cover, errorBuilder: (_, __, ___) => const ColoredBox(color: Colors.black12, child: Icon(Icons.menu_book_outlined))),
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text('${hit['title'] ?? ''}', maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 11.5, height: 1.2)),
                            Text('${hit['source'] == 'google' ? 'Google Books' : 'Open Library'}${'${hit['year'] ?? ''}'.isEmpty ? '' : ' · ${hit['year']}'}', maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 10, color: Colors.grey)),
                          ]),
                        );
                      },
                    ),
        ),
      ]),
    );
  }
}
