import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';

import '../../../../app/theme/app_colors.dart';
import '../../../../core/widgets/empty_state.dart';
import '../../../../core/widgets/ui_kit.dart';
import '../../data/library_manage_api.dart';
import '../library_widgets.dart';
import 'manage_widgets.dart';

/// «الكتب والنسخ» for library staff: search, add and edit books (with ISBN lookup and a
/// cover picture), their copies (shelf, status, barcode, RFID), and printing their labels.
class BooksManageScreen extends StatefulWidget {
  const BooksManageScreen({super.key});

  @override
  State<BooksManageScreen> createState() => _BooksManageScreenState();
}

class _BooksManageScreenState extends State<BooksManageScreen> {
  final _search = TextEditingController();
  final _scroll = ScrollController();
  final _paged = Paged<Json>();
  List<Json> _sections = [];
  List<String> _shelfCodes = [];
  int? _section;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(() {
      if (_scroll.position.pixels > _scroll.position.maxScrollExtent - 300 && _paged.hasMore && !_paged.loading) _load();
    });
    _load(reset: true);
  }

  @override
  void dispose() {
    _search.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _load({bool reset = false}) async {
    if (reset) _paged.reset();
    setState(() => _paged.loading = true);
    try {
      final r = await LibraryManageApi.instance.books(q: _search.text.trim(), section: _section, page: _paged.page + 1);
      final books = Map<String, dynamic>.from(r['books'] as Map);
      _paged.items.addAll((books['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)));
      _paged.page = (books['current_page'] as num).toInt();
      _paged.lastPage = (books['last_page'] as num).toInt();
      _sections = ((r['sections'] as List?) ?? []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      _shelfCodes = ((r['shelfCodes'] as List?) ?? []).map((e) => '$e').toList();
    } catch (e) {
      _paged.error = ApiMessage.of(e);
    }
    if (mounted) setState(() => _paged.loading = false);
  }

  Future<void> _open([Json? book]) async {
    final changed = await Navigator.of(context).push<bool>(MaterialPageRoute(
      builder: (_) => BookEditScreen(book: book, sections: _sections, shelfCodes: _shelfCodes),
    ));
    if (changed == true) _load(reset: true);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(context.l('الكتب والنسخ', 'Books and copies'))),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _open(),
        icon: const Icon(Icons.add_rounded),
        label: Text(context.l('كتاب جديد', 'New book')),
      ),
      body: RefreshIndicator(
        onRefresh: () => _load(reset: true),
        child: ListView(controller: _scroll, padding: const EdgeInsets.only(bottom: 96), children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 8, 12, 4),
            child: SearchField(
              controller: _search,
              hint: context.l('العنوان أو المؤلف أو ISBN أو الباركود', 'Title, author, ISBN or barcode'),
              onSubmitted: (_) => _load(reset: true),
              onClear: () => _load(reset: true),
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12),
            child: SectionPicker(
              sections: _sections,
              value: _section,
              allLabel: context.l('كل الأقسام', 'All sections'),
              onChanged: (v) {
                _section = v;
                _load(reset: true);
              },
            ),
          ),
          if (_paged.items.isEmpty && (_paged.loading || _paged.error != null))
            SizedBox(height: 300, child: LoadingOrError(error: _paged.error, onRetry: () => _load(reset: true)))
          else if (_paged.items.isEmpty)
            SizedBox(height: 300, child: EmptyState(icon: Icons.menu_book_outlined, message: context.l('لا توجد كتب', 'No books')))
          else
            GroupCard(children: [
              for (final b in _paged.items)
                ListTile(
                  leading: BookCover(title: b['title'] ?? '', url: b['cover_url'] as String?, width: 40, radius: 6),
                  title: Text(b['title'] ?? '', maxLines: 2, overflow: TextOverflow.ellipsis),
                  subtitle: Text(
                    [
                      if ((b['authors'] ?? '').toString().isNotEmpty) b['authors'],
                      (b['section'] as Map?)?['name'],
                      context.l('${b['available_count'] ?? 0} متاح من ${b['copies_count'] ?? 0}', '${b['available_count'] ?? 0} of ${b['copies_count'] ?? 0} available'),
                    ].whereType<Object>().join(' · '),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                  onTap: () => _open(b),
                ),
            ]),
          if (_paged.loading && _paged.items.isNotEmpty) const Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator())),
        ]),
      ),
    );
  }
}

/// One book: its details, cover and copies. Returns true when something changed.
class BookEditScreen extends StatefulWidget {
  const BookEditScreen({super.key, this.book, required this.sections, required this.shelfCodes});

  final Json? book;
  final List<Json> sections;
  final List<String> shelfCodes;

  @override
  State<BookEditScreen> createState() => _BookEditScreenState();
}

class _BookEditScreenState extends State<BookEditScreen> {
  static const _fields = ['title', 'subtitle', 'authors', 'isbn', 'publisher', 'year', 'language', 'edition', 'pages', 'description'];

  late final Map<String, TextEditingController> _c = {
    for (final f in _fields) f: TextEditingController(text: widget.book?[f]?.toString() ?? ''),
  };
  final _copiesCount = TextEditingController(text: '1');
  final _shelf = TextEditingController();
  late int? _section = (widget.book?['section_id'] as num?)?.toInt();
  late bool _referenceOnly = widget.book?['reference_only'] == true;
  late List<Json> _copies = ((widget.book?['copies'] as List?) ?? []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
  String? _cover;
  String? _coverUrl;
  bool _removeCover = false;
  bool _busy = false;
  bool _changed = false;

  bool get _isNew => widget.book == null;
  int? get _id => (widget.book?['id'] as num?)?.toInt();

  @override
  void dispose() {
    for (final c in _c.values) {
      c.dispose();
    }
    _copiesCount.dispose();
    _shelf.dispose();
    super.dispose();
  }

  Future<void> _lookupIsbn() async {
    final isbn = _c['isbn']!.text.trim();
    if (isbn.isEmpty) return;
    setState(() => _busy = true);
    try {
      final r = await LibraryManageApi.instance.isbn(isbn);
      for (final f in ['title', 'subtitle', 'authors', 'publisher', 'year', 'pages']) {
        if (r[f] != null && '${r[f]}'.isNotEmpty) _c[f]!.text = '${r[f]}';
      }
      _coverUrl = r['cover_url'] as String?;
      if (mounted) context.toast(context.l('عُبّئت البيانات من ISBN.', 'Filled in from the ISBN.'));
    } catch (e) {
      if (mounted) context.toastError(e);
    }
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _pickCover() async {
    final r = await FilePicker.platform.pickFiles(type: FileType.image);
    final path = r?.files.single.path;
    if (path != null) setState(() {
      _cover = path;
      _removeCover = false;
    });
  }

  Future<void> _save() async {
    if (_c['title']!.text.trim().isEmpty) {
      context.toast(context.l('اكتب عنوان الكتاب.', 'Enter the title.'), error: true);
      return;
    }
    setState(() => _busy = true);
    try {
      final data = <String, dynamic>{
        for (final f in _fields) f: _c[f]!.text.trim(),
        'section_id': _section,
        'reference_only': _referenceOnly,
        if (_coverUrl != null && _cover == null) 'cover_url': _coverUrl,
        if (_removeCover) 'remove_cover': true,
        if (_isNew) 'copies': int.tryParse(_copiesCount.text.trim()) ?? 1,
        if (_isNew) 'shelf': _shelf.text.trim(),
      };
      final msg = await LibraryManageApi.instance.saveBook(data, id: _id, cover: _cover);
      if (!mounted) return;
      context.toast(msg);
      Navigator.of(context).pop(true);
    } catch (e) {
      if (mounted) context.toastError(e);
    }
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _delete() async {
    if (!await confirmAction(context, context.l('حذف هذا الكتاب ونسخه؟', 'Delete this book and its copies?'))) return;
    try {
      final msg = await LibraryManageApi.instance.deleteBook(_id!);
      if (!mounted) return;
      context.toast(msg);
      Navigator.of(context).pop(true);
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  /// Copies change on the server at once; reload them from the books list.
  Future<void> _reloadCopies() async {
    _changed = true;
    try {
      final r = await LibraryManageApi.instance.books(q: widget.book?['title']?.toString());
      final list = ((r['books'] as Map)['data'] as List).map((e) => Map<String, dynamic>.from(e as Map));
      final me = list.where((b) => b['id'] == widget.book?['id']).firstOrNull;
      if (me != null && mounted) {
        setState(() => _copies = ((me['copies'] as List?) ?? []).map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {}
  }

  Future<void> _addCopies() async {
    final r = await showDialog<(int, String)>(context: context, builder: (_) => _AddCopiesDialog(shelfCodes: widget.shelfCodes));
    if (r == null) return;
    try {
      final msg = await LibraryManageApi.instance.addCopies(_id!, r.$1, shelf: r.$2.isEmpty ? null : r.$2);
      if (mounted) context.toast(msg);
      await _reloadCopies();
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  Future<void> _editCopy(Json copy) async {
    final r = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      useRootNavigator: true,
      builder: (_) => _CopySheet(copy: copy, shelfCodes: widget.shelfCodes),
    );
    if (r == 'saved' || r == 'deleted') await _reloadCopies();
  }

  Future<void> _printLabels() async {
    try {
      await LibraryManageApi.instance.openInBrowser('labels', books: [_id!]);
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  Widget _field(String key, String ar, String en, {TextInputType? type, int lines = 1}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: TextField(
          controller: _c[key],
          keyboardType: type,
          minLines: lines,
          maxLines: lines == 1 ? 1 : lines + 3,
          decoration: InputDecoration(labelText: context.l(ar, en)),
        ),
      );

  @override
  Widget build(BuildContext context) {
    final existingCover = widget.book?['cover_url'] as String?;
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) Navigator.of(context).pop(_changed);
      },
      child: Scaffold(
        appBar: AppBar(
          title: Text(_isNew ? context.l('كتاب جديد', 'New book') : context.l('تعديل الكتاب', 'Edit book')),
          actions: [
            if (!_isNew) IconButton(tooltip: context.l('حذف', 'Delete'), icon: const Icon(Icons.delete_outline_rounded), onPressed: _delete),
            IconButton(
              tooltip: context.l('حفظ', 'Save'),
              icon: _busy ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.check_rounded),
              onPressed: _busy ? null : _save,
            ),
          ],
        ),
        body: ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 32), children: [
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            GestureDetector(
              onTap: _pickCover,
              child: Stack(children: [
                _cover != null
                    ? ClipRRect(borderRadius: BorderRadius.circular(8), child: SizedBox(width: 90, child: AspectRatio(aspectRatio: 2 / 3, child: LocalImage(path: _cover!))))
                    : BookCover(title: _c['title']!.text, url: _removeCover ? null : (_coverUrl ?? existingCover), width: 90, radius: 8),
                const PositionedDirectional(
                  bottom: 4,
                  end: 4,
                  child: CircleAvatar(radius: 14, backgroundColor: Colors.black54, child: Icon(Icons.photo_camera_outlined, size: 16, color: Colors.white)),
                ),
              ]),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(children: [
                _field('title', 'العنوان *', 'Title *'),
                Row(children: [
                  Expanded(child: _field('isbn', 'ISBN', 'ISBN', type: TextInputType.number)),
                  IconButton(tooltip: context.l('جلب البيانات', 'Look up'), icon: const Icon(Icons.travel_explore_rounded), onPressed: _busy ? null : _lookupIsbn),
                ]),
              ]),
            ),
          ]),
          if ((existingCover != null || _cover != null) && !_removeCover)
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: TextButton.icon(
                onPressed: () => setState(() {
                  _cover = null;
                  _coverUrl = null;
                  _removeCover = true;
                }),
                icon: const Icon(Icons.hide_image_outlined, size: 18),
                label: Text(context.l('إزالة الغلاف', 'Remove cover')),
              ),
            ),
          _field('subtitle', 'العنوان الفرعي', 'Subtitle'),
          _field('authors', 'المؤلفون', 'Authors'),
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 6),
            child: SectionPicker(
              sections: widget.sections,
              value: _section,
              allLabel: context.l('بدون قسم', 'No section'),
              label: context.l('القسم', 'Section'),
              onChanged: (v) => setState(() => _section = v),
            ),
          ),
          Row(children: [
            Expanded(child: _field('publisher', 'الناشر', 'Publisher')),
            const SizedBox(width: 10),
            SizedBox(width: 90, child: _field('year', 'السنة', 'Year', type: TextInputType.number)),
          ]),
          Row(children: [
            Expanded(child: _field('edition', 'الطبعة', 'Edition')),
            const SizedBox(width: 10),
            Expanded(child: _field('language', 'اللغة', 'Language')),
            const SizedBox(width: 10),
            SizedBox(width: 90, child: _field('pages', 'الصفحات', 'Pages', type: TextInputType.number)),
          ]),
          _field('description', 'الوصف', 'Description', lines: 3),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            value: _referenceOnly,
            onChanged: (v) => setState(() => _referenceOnly = v),
            title: Text(context.l('للقراءة داخل المكتبة فقط', 'In-library reading only')),
            subtitle: Text(context.l('لا يُعار خارج المكتبة', 'Not lent out')),
          ),
          if (_isNew) ...[
            const Divider(height: 28),
            Row(children: [
              SizedBox(
                width: 110,
                child: TextField(controller: _copiesCount, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: context.l('عدد النسخ', 'Copies'))),
              ),
              const SizedBox(width: 10),
              Expanded(child: ShelfField(controller: _shelf, codes: widget.shelfCodes)),
            ]),
          ] else ...[
            const SizedBox(height: 8),
            Row(children: [
              Text(context.l('النسخ (${_copies.length})', 'Copies (${_copies.length})'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
              const Spacer(),
              TextButton.icon(onPressed: _printLabels, icon: const Icon(Icons.print_outlined, size: 18), label: Text(context.l('ملصقات', 'Labels'))),
              TextButton.icon(onPressed: _addCopies, icon: const Icon(Icons.add_rounded, size: 18), label: Text(context.l('نسخ', 'Copies'))),
            ]),
            Material(
              color: Theme.of(context).colorScheme.surface,
              borderRadius: BorderRadius.circular(14),
              clipBehavior: Clip.antiAlias,
              child: Column(children: [
                for (final c in _copies)
                  ListTile(
                    dense: true,
                    leading: Icon(Icons.qr_code_rounded, color: copyStatusColor(c['status'] as String? ?? '')),
                    title: Text('${c['barcode'] ?? ''}', textDirection: TextDirection.ltr, textAlign: TextAlign.start),
                    subtitle: Text([
                      copyStatusLabel(context, c['status'] as String? ?? ''),
                      if ((c['shelf'] ?? '').toString().isNotEmpty) context.l('الرف ${c['shelf']}', 'Shelf ${c['shelf']}'),
                      if ((c['rfid_tag'] ?? '').toString().isNotEmpty) 'RFID',
                    ].join(' · ')),
                    onTap: () => _editCopy(c),
                  ),
                if (_copies.isEmpty) Padding(padding: const EdgeInsets.all(16), child: Text(context.l('لا توجد نسخ.', 'No copies.'))),
              ]),
            ),
          ],
        ]),
      ),
    );
  }
}

class _AddCopiesDialog extends StatefulWidget {
  const _AddCopiesDialog({required this.shelfCodes});
  final List<String> shelfCodes;

  @override
  State<_AddCopiesDialog> createState() => _AddCopiesDialogState();
}

class _AddCopiesDialogState extends State<_AddCopiesDialog> {
  final _count = TextEditingController(text: '1');
  final _shelf = TextEditingController();

  @override
  void dispose() {
    _count.dispose();
    _shelf.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(context.l('إضافة نسخ', 'Add copies')),
      content: Column(mainAxisSize: MainAxisSize.min, children: [
        TextField(controller: _count, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: context.l('العدد', 'Count'))),
        const SizedBox(height: 8),
        ShelfField(controller: _shelf, codes: widget.shelfCodes),
      ]),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: Text(context.l('إلغاء', 'Cancel'))),
        FilledButton(
          onPressed: () {
            final n = int.tryParse(_count.text.trim()) ?? 0;
            if (n < 1 || n > 100) return;
            Navigator.pop(context, (n, _shelf.text.trim()));
          },
          child: Text(context.l('إضافة', 'Add')),
        ),
      ],
    );
  }
}

/// One copy: barcode, shelf, status (a lent or reserved copy changes only at the desk),
/// RFID tag, condition and notes.
class _CopySheet extends StatefulWidget {
  const _CopySheet({required this.copy, required this.shelfCodes});
  final Json copy;
  final List<String> shelfCodes;

  @override
  State<_CopySheet> createState() => _CopySheetState();
}

class _CopySheetState extends State<_CopySheet> {
  late final _barcode = TextEditingController(text: '${widget.copy['barcode'] ?? ''}');
  late final _shelf = TextEditingController(text: '${widget.copy['shelf'] ?? ''}');
  late final _rfid = TextEditingController(text: '${widget.copy['rfid_tag'] ?? ''}');
  late final _notes = TextEditingController(text: '${widget.copy['notes'] ?? ''}');
  late String _status = widget.copy['status'] as String? ?? 'available';
  bool _busy = false;

  bool get _locked => _status == 'loaned' || _status == 'on_hold';

  @override
  void dispose() {
    _barcode.dispose();
    _shelf.dispose();
    _rfid.dispose();
    _notes.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      final msg = await LibraryManageApi.instance.updateCopy((widget.copy['id'] as num).toInt(), {
        'barcode': _barcode.text.trim(),
        'shelf': _shelf.text.trim(),
        'rfid_tag': _rfid.text.trim(),
        'notes': _notes.text.trim(),
        if (!_locked) 'status': _status,
      });
      if (!mounted) return;
      context.toast(msg);
      Navigator.pop(context, 'saved');
    } catch (e) {
      if (mounted) context.toastError(e);
    }
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _delete() async {
    if (!await confirmAction(context, context.l('حذف هذه النسخة؟', 'Delete this copy?'))) return;
    try {
      final msg = await LibraryManageApi.instance.deleteCopy((widget.copy['id'] as num).toInt());
      if (!mounted) return;
      context.toast(msg);
      Navigator.pop(context, 'deleted');
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(context.l('النسخة', 'Copy'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
          const SizedBox(height: 12),
          TextField(controller: _barcode, decoration: InputDecoration(labelText: context.l('الباركود', 'Barcode'))),
          const SizedBox(height: 8),
          ShelfField(controller: _shelf, codes: widget.shelfCodes),
          const SizedBox(height: 8),
          if (_locked)
            Text(context.l('الحالة: ${copyStatusLabel(context, _status)} — تتغير من مكتب الإعارة.', 'Status: ${copyStatusLabel(context, _status)} — changed at the desk.'))
          else
            DropdownButtonFormField<String>(
              value: _status,
              decoration: InputDecoration(labelText: context.l('الحالة', 'Status')),
              items: [
                for (final s in ['available', 'damaged', 'lost']) DropdownMenuItem(value: s, child: Text(copyStatusLabel(context, s))),
              ],
              onChanged: (v) => setState(() => _status = v ?? _status),
            ),
          const SizedBox(height: 8),
          TextField(controller: _rfid, decoration: InputDecoration(labelText: context.l('رمز RFID', 'RFID tag'))),
          const SizedBox(height: 8),
          TextField(controller: _notes, decoration: InputDecoration(labelText: context.l('ملاحظات', 'Notes'))),
          const SizedBox(height: 16),
          Row(children: [
            if (!_locked)
              TextButton.icon(
                onPressed: _busy ? null : _delete,
                icon: const Icon(Icons.delete_outline_rounded, color: AppColors.danger),
                label: Text(context.l('حذف', 'Delete'), style: const TextStyle(color: AppColors.danger)),
              ),
            const Spacer(),
            FilledButton(onPressed: _busy ? null : _save, child: Text(context.l('حفظ', 'Save'))),
          ]),
        ]),
      ),
    );
  }
}
