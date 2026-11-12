import 'dart:io';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:pdfrx/pdfrx.dart';
import 'package:provider/provider.dart';
import 'package:share_plus/share_plus.dart';

import '../../../core/files/file_opener.dart';
import '../../../core/widgets/ui_kit.dart';
import '../../downloads/state/downloads_provider.dart';
import '../data/local_pdf_tools.dart';

/// A file chosen for a tool: from the device or from the app's offline downloads.
class _Src {
  const _Src(this.path, this.name);
  final String path;
  final String name;
}

String _base(String name) => name.toLowerCase().endsWith('.pdf') ? name.substring(0, name.length - 4) : name.replaceAll(RegExp(r'\.[^.]+$'), '');

/// «أدوات PDF على الجهاز» (2026-11-12): work without internet. [onOpenServerTools] opens the
/// full server tools (compress, Word/Excel, watermark…) when there is a connection.
class LocalPdfToolsScreen extends StatefulWidget {
  const LocalPdfToolsScreen({super.key, this.onOpenServerTools});

  final VoidCallback? onOpenServerTools;

  @override
  State<LocalPdfToolsScreen> createState() => _LocalPdfToolsScreenState();
}

class _LocalPdfToolsScreenState extends State<LocalPdfToolsScreen> {
  List<File> _results = [];
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _loadResults();
  }

  Future<void> _loadResults() async {
    final r = await LocalPdfTools.results();
    if (mounted) setState(() => _results = r);
  }

  // ---------------------------------------------------------------- picking

  Future<List<_Src>> _pick({required bool images, required bool multiple}) async {
    final where = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (c) => SafeArea(
        child: Wrap(children: [
          ListTile(
            leading: const Icon(Icons.phone_android_rounded),
            title: Text(c.l('من الجهاز', 'From this device')),
            onTap: () => Navigator.pop(c, 'device'),
          ),
          ListTile(
            leading: const Icon(Icons.download_done_rounded),
            title: Text(c.l('من التنزيلات (بدون إنترنت)', 'From downloads (offline)')),
            onTap: () => Navigator.pop(c, 'downloads'),
          ),
        ]),
      ),
    );
    if (where == null || !mounted) return [];
    if (where == 'device') {
      final r = await FilePicker.platform.pickFiles(
        allowMultiple: multiple,
        type: images ? FileType.image : FileType.custom,
        allowedExtensions: images ? null : ['pdf'],
      );
      return [for (final f in r?.files ?? <PlatformFile>[]) if (f.path != null) _Src(f.path!, f.name)];
    }
    final downloads = context.read<DownloadsProvider>();
    await downloads.load();
    final exts = images ? const ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'] : const ['pdf'];
    final items = downloads.items.where((d) => exts.contains(d.name.split('.').last.toLowerCase()) && File(d.localPath).existsSync()).toList();
    if (!mounted) return [];
    if (items.isEmpty) {
      context.toast(context.l('لا توجد ملفات مناسبة في التنزيلات.', 'No suitable files in downloads.'));
      return [];
    }
    final chosen = <int>{};
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => AlertDialog(
          title: Text(c.l('اختر من التنزيلات', 'Choose from downloads')),
          content: SizedBox(
            width: double.maxFinite,
            child: ListView(
              shrinkWrap: true,
              children: [
                for (var i = 0; i < items.length; i++)
                  multiple
                      ? CheckboxListTile(
                          value: chosen.contains(i),
                          title: Text(items[i].name, maxLines: 2, overflow: TextOverflow.ellipsis),
                          onChanged: (v) => set(() => v == true ? chosen.add(i) : chosen.remove(i)),
                        )
                      : ListTile(
                          title: Text(items[i].name, maxLines: 2, overflow: TextOverflow.ellipsis),
                          onTap: () {
                            chosen.add(i);
                            Navigator.pop(c, true);
                          },
                        ),
              ],
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: Text(c.l('إلغاء', 'Cancel'))),
            if (multiple) FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(c.l('تم', 'Done'))),
          ],
        ),
      ),
    );
    if (ok != true) return [];
    return [for (final i in chosen.toList()..sort()) _Src(items[i].localPath, items[i].name)];
  }

  // ---------------------------------------------------------------- running

  Future<void> _run(Future<List<File>> Function() job) async {
    setState(() => _busy = true);
    try {
      final made = await job();
      await _loadResults();
      if (!mounted) return;
      context.toast(made.length == 1
          ? context.l('حُفظ: ${made.first.uri.pathSegments.last}', 'Saved: ${made.first.uri.pathSegments.last}')
          : context.l('حُفظ ${made.length} ملفات.', 'Saved ${made.length} files.'));
      if (made.length == 1) await openLocalFile(context, made.first.path, made.first.uri.pathSegments.last);
    } catch (e) {
      if (mounted) context.toast(e is LocalPdfException ? e.message : context.l('تعذّر تنفيذ العملية.', 'The operation failed.'), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _merge() async {
    final list = await Navigator.of(context).push<List<_Src>>(MaterialPageRoute(
      builder: (_) => _OrderedFilesScreen(title: context.l('دمج ملفات PDF', 'Merge PDFs'), images: false, pick: _pick),
    ));
    if (list == null || list.length < 2) return;
    await _run(() async => [await LocalPdfTools.merge([for (final s in list) s.path], '${_base(list.first.name)} (مدمج)')]);
  }

  Future<void> _images() async {
    final list = await Navigator.of(context).push<List<_Src>>(MaterialPageRoute(
      builder: (_) => _OrderedFilesScreen(title: context.l('صور إلى PDF', 'Images to PDF'), images: true, pick: _pick),
    ));
    if (list == null || list.isEmpty) return;
    await _run(() async => [await LocalPdfTools.imagesToPdf([for (final s in list) s.path], list.length == 1 ? _base(list.first.name) : '${_base(list.first.name)} (صور)')]);
  }

  Future<void> _organize() async {
    final src = await _pick(images: false, multiple: false);
    if (src.isEmpty || !mounted) return;
    final result = await Navigator.of(context).push<_Organized>(MaterialPageRoute(builder: (_) => _OrganizeScreen(source: src.first, pick: _pick)));
    if (result == null) return;
    await _run(() async => [await LocalPdfTools.assemble(result.sources, result.pages, '${_base(src.first.name)} (منظّم)')]);
  }

  Future<void> _split() async {
    final src = await _pick(images: false, multiple: false);
    if (src.isEmpty || !mounted) return;
    final s = src.first;
    final count = await LocalPdfTools.pageCount(s.path);
    if (!mounted) return;
    final ctrl = TextEditingController();
    var each = false;
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => AlertDialog(
          title: Text(c.l('تقسيم واستخراج صفحات', 'Split / extract pages')),
          content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(c.l('عدد الصفحات: $count', 'Pages: $count'), style: Theme.of(c).textTheme.bodySmall),
            const SizedBox(height: 8),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              value: each,
              title: Text(c.l('كل صفحة ملف', 'Each page a file')),
              onChanged: (v) => set(() => each = v),
            ),
            if (!each)
              TextField(
                controller: ctrl,
                textDirection: TextDirection.ltr,
                decoration: InputDecoration(
                  labelText: c.l('الصفحات (كل نطاق ملف)', 'Pages (each range a file)'),
                  hintText: '1-3, 5, 8-z',
                  helperText: c.l('z = آخر صفحة', 'z = last page'),
                ),
              ),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: Text(c.l('إلغاء', 'Cancel'))),
            FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(c.l('تنفيذ', 'Run'))),
          ],
        ),
      ),
    );
    if (ok != true) return;
    await _run(() async {
      if (each && count > 300) throw const LocalPdfException('الملف أكثر من 300 صفحة؛ اختر نطاقات أكبر.');
      final ranges = each ? [for (var p = 1; p <= count; p++) [p]] : LocalPdfTools.parseRanges(ctrl.text, count);
      final made = <File>[];
      for (final r in ranges) {
        final label = r.length == 1 ? '${r.first}' : '${r.first}-${r.last}';
        made.add(await LocalPdfTools.assemble([s.path], [for (final p in r) PageRef(0, p)], '${_base(s.name)} (صفحات $label)'));
      }
      return made;
    });
  }

  Future<void> _removeBlank() async {
    final src = await _pick(images: false, multiple: false);
    if (src.isEmpty || !mounted) return;
    final s = src.first;
    await _run(() async => [(await LocalPdfTools.removeBlank(s.path, '${_base(s.name)} (بدون الصفحات الفارغة)')).file]);
  }

  Future<void> _rotate() async {
    final src = await _pick(images: false, multiple: false);
    if (src.isEmpty || !mounted) return;
    final s = src.first;
    var turns = 1;
    var which = 'all';
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => AlertDialog(
          title: Text(c.l('تدوير الصفحات', 'Rotate pages')),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            SegmentedButton<int>(
              segments: [
                ButtonSegment(value: 1, label: Text(c.l('90° يميناً', '90° right'))),
                const ButtonSegment(value: 2, label: Text('180°')),
                ButtonSegment(value: 3, label: Text(c.l('90° يساراً', '90° left'))),
              ],
              selected: {turns},
              onSelectionChanged: (v) => set(() => turns = v.first),
            ),
            const SizedBox(height: 12),
            SegmentedButton<String>(
              segments: [
                ButtonSegment(value: 'all', label: Text(c.l('الكل', 'All'))),
                ButtonSegment(value: 'odd', label: Text(c.l('الفردية', 'Odd'))),
                ButtonSegment(value: 'even', label: Text(c.l('الزوجية', 'Even'))),
              ],
              selected: {which},
              onSelectionChanged: (v) => set(() => which = v.first),
            ),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: Text(c.l('إلغاء', 'Cancel'))),
            FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(c.l('تنفيذ', 'Run'))),
          ],
        ),
      ),
    );
    if (ok != true) return;
    await _run(() async {
      final n = await LocalPdfTools.pageCount(s.path);
      final pages = [
        for (var p = 1; p <= n; p++)
          PageRef(0, p, (which == 'all' || (which == 'odd' && p.isOdd) || (which == 'even' && p.isEven)) ? turns : 0),
      ];
      return [await LocalPdfTools.assemble([s.path], pages, '${_base(s.name)} (مُدوّر)')];
    });
  }

  // ---------------------------------------------------------------- UI

  @override
  Widget build(BuildContext context) {
    final tools = [
      (Icons.merge_type_rounded, const Color(0xFFE11D48), context.l('دمج', 'Merge'), context.l('عدة ملفات PDF في ملف واحد', 'Several PDFs into one'), _merge),
      (Icons.grid_view_rounded, const Color(0xFFF59E0B), context.l('تنظيم الصفحات', 'Organise pages'), context.l('ترتيب، تدوير، حذف، إدراج من ملف', 'Reorder, rotate, delete, insert'), _organize),
      (Icons.content_cut_rounded, const Color(0xFFF97316), context.l('تقسيم واستخراج', 'Split & extract'), context.l('صفحات محددة أو كل صفحة ملف', 'Chosen pages or each page'), _split),
      (Icons.rotate_right_rounded, const Color(0xFFEAB308), context.l('تدوير', 'Rotate'), context.l('كل الصفحات أو الفردية أو الزوجية', 'All, odd or even pages'), _rotate),
      (Icons.layers_clear_outlined, const Color(0xFF78716C), context.l('حذف الصفحات الفارغة', 'Remove blank pages'), context.l('يجد الصفحات البيضاء ويحذفها', 'Finds white pages and removes them'), _removeBlank),
      (Icons.add_photo_alternate_outlined, const Color(0xFF65A30D), context.l('صور إلى PDF', 'Images to PDF'), context.l('صور الهاتف والمسح في ملف واحد', 'Photos and scans in one file'), _images),
    ];
    return Scaffold(
      appBar: AppBar(title: Text(context.l('أدوات PDF', 'PDF tools'))),
      body: Stack(children: [
        RefreshIndicator(
          onRefresh: _loadResults,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(color: Colors.green.withOpacity(0.1), borderRadius: BorderRadius.circular(14)),
                child: Row(children: [
                  const Icon(Icons.wifi_off_rounded, color: Colors.green),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(context.l('تعمل على جهازك بدون إنترنت، والملفات لا تغادر هاتفك.', 'These run on your device without internet; files never leave it.')),
                  ),
                ]),
              ),
              const SizedBox(height: 16),
              GridView.count(
                crossAxisCount: MediaQuery.of(context).size.width > 600 ? 3 : 2,
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                mainAxisSpacing: 12,
                crossAxisSpacing: 12,
                childAspectRatio: 1.15,
                children: [
                  for (final t in tools)
                    Material(
                      color: Theme.of(context).colorScheme.surfaceContainerHighest.withOpacity(0.5),
                      borderRadius: BorderRadius.circular(18),
                      child: InkWell(
                        borderRadius: BorderRadius.circular(18),
                        onTap: _busy ? null : t.$5,
                        child: Padding(
                          padding: const EdgeInsets.all(14),
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Container(
                              width: 42,
                              height: 42,
                              decoration: BoxDecoration(color: t.$2, borderRadius: BorderRadius.circular(12)),
                              child: Icon(t.$1, color: Colors.white),
                            ),
                            const Spacer(),
                            Text(t.$3, style: const TextStyle(fontWeight: FontWeight.w700)),
                            const SizedBox(height: 2),
                            Text(t.$4, maxLines: 2, overflow: TextOverflow.ellipsis, style: Theme.of(context).textTheme.bodySmall),
                          ]),
                        ),
                      ),
                    ),
                ],
              ),
              if (widget.onOpenServerTools != null) ...[
                const SizedBox(height: 12),
                ListTile(
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14), side: BorderSide(color: Theme.of(context).dividerColor)),
                  leading: const Icon(Icons.cloud_outlined),
                  title: Text(context.l('أدوات الخادم الكاملة', 'All server tools')),
                  subtitle: Text(context.l('ضغط، Word وExcel، علامة مائية، حماية… (تحتاج اتصالاً)', 'Compress, Word & Excel, watermark, protect… (needs a connection)')),
                  trailing: const Icon(Icons.chevron_left_rounded),
                  onTap: widget.onOpenServerTools,
                ),
              ],
              const SizedBox(height: 20),
              Text(context.l('ما أنشأته على هذا الجهاز', 'Made on this device'), style: Theme.of(context).textTheme.titleSmall),
              const SizedBox(height: 6),
              if (_results.isEmpty)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 20),
                  child: Text(context.l('لا ملفات بعد.', 'Nothing yet.'), textAlign: TextAlign.center, style: Theme.of(context).textTheme.bodySmall),
                ),
              for (final f in _results)
                Card(
                  child: ListTile(
                    leading: const Icon(Icons.picture_as_pdf_rounded, color: Color(0xFFDC2626)),
                    title: Text(f.uri.pathSegments.last, maxLines: 2, overflow: TextOverflow.ellipsis),
                    subtitle: Text(_size(f.lengthSync())),
                    onTap: () => openLocalFile(context, f.path, f.uri.pathSegments.last),
                    trailing: PopupMenuButton<String>(
                      onSelected: (v) async {
                        if (v == 'share') {
                          await Share.shareXFiles([XFile(f.path)]);
                        } else if (v == 'delete') {
                          await f.delete();
                          await _loadResults();
                        }
                      },
                      itemBuilder: (c) => [
                        PopupMenuItem(value: 'share', child: Text(c.l('مشاركة أو حفظ', 'Share or save'))),
                        PopupMenuItem(value: 'delete', child: Text(c.l('حذف', 'Delete'))),
                      ],
                    ),
                  ),
                ),
            ],
          ),
        ),
        if (_busy)
          Container(
            color: Colors.black38,
            alignment: Alignment.center,
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(20),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5)),
                  const SizedBox(width: 14),
                  Text(context.l('جارٍ التنفيذ…', 'Working…')),
                ]),
              ),
            ),
          ),
      ]),
    );
  }

  static String _size(int b) => b >= 1048576 ? '${(b / 1048576).toStringAsFixed(1)} MB' : '${(b / 1024).ceil()} KB';
}

// ------------------------------------------------------------------ ordered list of files

class _OrderedFilesScreen extends StatefulWidget {
  const _OrderedFilesScreen({required this.title, required this.images, required this.pick});

  final String title;
  final bool images;
  final Future<List<_Src>> Function({required bool images, required bool multiple}) pick;

  @override
  State<_OrderedFilesScreen> createState() => _OrderedFilesScreenState();
}

class _OrderedFilesScreenState extends State<_OrderedFilesScreen> {
  final List<_Src> _files = [];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _add());
  }

  Future<void> _add() async {
    final more = await widget.pick(images: widget.images, multiple: true);
    if (mounted) setState(() => _files.addAll(more));
  }

  @override
  Widget build(BuildContext context) {
    final min = widget.images ? 1 : 2;
    return Scaffold(
      appBar: AppBar(title: Text(widget.title), actions: [IconButton(icon: const Icon(Icons.add_rounded), onPressed: _add)]),
      body: _files.isEmpty
          ? Center(child: TextButton.icon(onPressed: _add, icon: const Icon(Icons.add_rounded), label: Text(context.l('اختر الملفات', 'Choose files'))))
          : ReorderableListView.builder(
              padding: const EdgeInsets.fromLTRB(8, 8, 8, 100),
              itemCount: _files.length,
              onReorder: (a, b) => setState(() {
                if (b > a) b--;
                _files.insert(b, _files.removeAt(a));
              }),
              itemBuilder: (c, i) => ListTile(
                key: ValueKey('${_files[i].path}#$i'),
                leading: widget.images
                    ? ClipRRect(borderRadius: BorderRadius.circular(6), child: Image.file(File(_files[i].path), width: 44, height: 44, fit: BoxFit.cover, cacheWidth: 120))
                    : CircleAvatar(child: Text('${i + 1}')),
                title: Text(_files[i].name, maxLines: 2, overflow: TextOverflow.ellipsis),
                trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                  IconButton(icon: const Icon(Icons.close_rounded), onPressed: () => setState(() => _files.removeAt(i))),
                  ReorderableDragStartListener(index: i, child: const Icon(Icons.drag_handle_rounded)),
                ]),
              ),
            ),
      floatingActionButton: _files.length >= min
          ? FloatingActionButton.extended(
              onPressed: () => Navigator.pop(context, List<_Src>.from(_files)),
              icon: const Icon(Icons.check_rounded),
              label: Text(widget.images ? context.l('إنشاء PDF', 'Make PDF') : context.l('دمج', 'Merge')),
            )
          : null,
    );
  }
}

// ------------------------------------------------------------------ organise pages

class _Organized {
  const _Organized(this.sources, this.pages);
  final List<String> sources;
  final List<PageRef> pages;
}

class _Item {
  _Item(this.ref, this.key);
  PageRef ref;
  final int key;
}

class _OrganizeScreen extends StatefulWidget {
  const _OrganizeScreen({required this.source, required this.pick});

  final _Src source;
  final Future<List<_Src>> Function({required bool images, required bool multiple}) pick;

  @override
  State<_OrganizeScreen> createState() => _OrganizeScreenState();
}

class _OrganizeScreenState extends State<_OrganizeScreen> {
  final List<PdfDocument> _docs = [];
  final List<String> _paths = [];
  final List<_Item> _items = [];
  final List<_Item> _removed = [];
  int _keys = 0;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _open(widget.source.path);
  }

  Future<void> _open(String path, {int? at}) async {
    try {
      final doc = await PdfDocument.openFile(path);
      _docs.add(doc);
      _paths.add(path);
      final src = _docs.length - 1;
      final added = [for (var p = 1; p <= doc.pages.length; p++) _Item(PageRef(src, p), _keys++)];
      setState(() {
        _items.insertAll(at ?? _items.length, added);
        _loading = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _loading = false);
      context.toast(context.l('تعذّر فتح الملف (قد يكون محمياً بكلمة مرور).', 'Could not open the file (it may be password-protected).'), error: true);
    }
  }

  @override
  void dispose() {
    for (final d in _docs) {
      d.dispose();
    }
    super.dispose();
  }

  Future<void> _insert() async {
    final more = await widget.pick(images: false, multiple: false);
    if (more.isNotEmpty) await _open(more.first.path);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(context.l('تنظيم الصفحات', 'Organise pages')),
        actions: [
          IconButton(tooltip: context.l('إدراج صفحات من ملف', 'Insert pages from a file'), icon: const Icon(Icons.note_add_outlined), onPressed: _insert),
          if (_removed.isNotEmpty)
            IconButton(
              tooltip: context.l('استرجاع آخر صفحة محذوفة', 'Restore the last deleted page'),
              icon: const Icon(Icons.undo_rounded),
              onPressed: () => setState(() => _items.add(_removed.removeLast())),
            ),
          IconButton(
            tooltip: context.l('عكس الترتيب', 'Reverse order'),
            icon: const Icon(Icons.swap_vert_rounded),
            onPressed: () => setState(() {
              final r = _items.reversed.toList();
              _items
                ..clear()
                ..addAll(r);
            }),
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ReorderableListView.builder(
              padding: const EdgeInsets.fromLTRB(8, 8, 8, 100),
              itemCount: _items.length,
              onReorder: (a, b) => setState(() {
                if (b > a) b--;
                _items.insert(b, _items.removeAt(a));
              }),
              header: Padding(
                padding: const EdgeInsets.all(8),
                child: Text(
                  context.l('اسحب الصفحة من المقبض لترتيبها. القلب للتدوير والسلة للحذف.', 'Drag a page by its handle. Rotate or delete with the buttons.'),
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ),
              itemBuilder: (c, i) {
                final it = _items[i];
                return Card(
                  key: ValueKey(it.key),
                  child: Padding(
                    padding: const EdgeInsets.all(8),
                    child: Row(children: [
                      SizedBox(
                        width: 70,
                        height: 92,
                        child: Center(
                          child: RotatedBox(
                            quarterTurns: it.ref.quarterTurns,
                            child: PdfPageView(document: _docs[it.ref.source], pageNumber: it.ref.page),
                          ),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text('${i + 1}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                          Text(
                            it.ref.source == 0
                                ? context.l('الصفحة ${it.ref.page} من الملف', 'Page ${it.ref.page} of the file')
                                : context.l('الصفحة ${it.ref.page} من الملف المُدرج ${it.ref.source}', 'Page ${it.ref.page} of inserted file ${it.ref.source}'),
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ]),
                      ),
                      IconButton(icon: const Icon(Icons.rotate_right_rounded), onPressed: () => setState(() => it.ref = it.ref.turned())),
                      IconButton(
                        icon: const Icon(Icons.delete_outline_rounded),
                        onPressed: () => setState(() => _removed.add(_items.removeAt(i))),
                      ),
                      ReorderableDragStartListener(index: i, child: const Padding(padding: EdgeInsets.all(8), child: Icon(Icons.drag_handle_rounded))),
                    ]),
                  ),
                );
              },
            ),
      floatingActionButton: _items.isEmpty
          ? null
          : FloatingActionButton.extended(
              onPressed: () => Navigator.pop(context, _Organized(List<String>.from(_paths), [for (final it in _items) it.ref])),
              icon: const Icon(Icons.check_rounded),
              label: Text(context.l('حفظ نسخة', 'Save a copy')),
            ),
    );
  }
}
