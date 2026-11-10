import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';

import '../../../../app/theme/app_colors.dart';
import '../../../../core/widgets/empty_state.dart';
import '../../../../core/widgets/ui_kit.dart';
import '../../data/library_manage_api.dart';
import 'manage_widgets.dart';

/// «الأقسام»: the catalogue's sections and sub-sections, with their place and cover picture.
class SectionsScreen extends StatefulWidget {
  const SectionsScreen({super.key});

  @override
  State<SectionsScreen> createState() => _SectionsScreenState();
}

class _SectionsScreenState extends State<SectionsScreen> {
  List<Json>? _sections;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final s = await LibraryManageApi.instance.sections();
      if (mounted) setState(() => _sections = s);
    } catch (e) {
      if (mounted) setState(() => _error = ApiMessage.of(e));
    }
  }

  int _depth(Json s) => '${s['path'] ?? ''}'.split(' / ').length - 1;

  /// Ids of a section and everything under it (it cannot move inside itself).
  Set<int> _subtree(int id) {
    final out = {id};
    var grew = true;
    while (grew) {
      grew = false;
      for (final s in _sections ?? <Json>[]) {
        final p = (s['parent_id'] as num?)?.toInt();
        if (p != null && out.contains(p) && out.add((s['id'] as num).toInt())) grew = true;
      }
    }
    return out;
  }

  Future<void> _edit([Json? section, int? parent]) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useRootNavigator: true,
      builder: (_) => _SectionSheet(
        section: section,
        parent: parent,
        sections: _sections ?? [],
        exclude: section == null ? const {} : _subtree((section['id'] as num).toInt()),
      ),
    );
    if (saved == true) _load();
  }

  Future<void> _delete(Json s) async {
    if (!await confirmAction(context, context.l('حذف القسم «${s['name']}»؟ تنتقل كتبه وأقسامه الفرعية إلى القسم الأعلى.', 'Delete «${s['name']}»? Its books and sub-sections move up.'))) return;
    try {
      final msg = await LibraryManageApi.instance.deleteSection((s['id'] as num).toInt());
      if (mounted) context.toast(msg);
      _load();
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final list = _sections;
    return Scaffold(
      appBar: AppBar(title: Text(context.l('الأقسام', 'Sections'))),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _edit(),
        icon: const Icon(Icons.add_rounded),
        label: Text(context.l('قسم جديد', 'New section')),
      ),
      body: list == null
          ? LoadingOrError(error: _error, onRetry: _load)
          : list.isEmpty
              ? EmptyState(icon: Icons.account_tree_outlined, message: context.l('لا توجد أقسام بعد', 'No sections yet'))
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(padding: const EdgeInsets.only(bottom: 96), children: [
                    GroupCard(children: [
                      for (final s in list)
                        ListTile(
                          contentPadding: EdgeInsetsDirectional.only(start: 14.0 + _depth(s) * 22, end: 4),
                          leading: Icon(_depth(s) == 0 ? Icons.folder_rounded : Icons.subdirectory_arrow_left_rounded, color: AppColors.primary),
                          title: Text('${s['name']}'),
                          subtitle: Text([
                            context.l('${s['books_count'] ?? 0} كتاب', '${s['books_count'] ?? 0} books'),
                            if ((s['location'] ?? '').toString().isNotEmpty) s['location'],
                          ].join(' · ')),
                          onTap: () => _edit(s),
                          trailing: PopupMenuButton<String>(
                            onSelected: (v) => v == 'child' ? _edit(null, (s['id'] as num).toInt()) : _delete(s),
                            itemBuilder: (_) => [
                              PopupMenuItem(value: 'child', child: Text(context.l('قسم فرعي هنا', 'Sub-section here'))),
                              PopupMenuItem(value: 'delete', child: Text(context.l('حذف', 'Delete'), style: const TextStyle(color: AppColors.danger))),
                            ],
                          ),
                        ),
                    ]),
                  ]),
                ),
    );
  }
}

class _SectionSheet extends StatefulWidget {
  const _SectionSheet({this.section, this.parent, required this.sections, required this.exclude});

  final Json? section;
  final int? parent;
  final List<Json> sections;
  final Set<int> exclude;

  @override
  State<_SectionSheet> createState() => _SectionSheetState();
}

class _SectionSheetState extends State<_SectionSheet> {
  late final _name = TextEditingController(text: '${widget.section?['name'] ?? ''}');
  late final _location = TextEditingController(text: '${widget.section?['location'] ?? ''}');
  late final _description = TextEditingController(text: '${widget.section?['description'] ?? ''}');
  late final _sort = TextEditingController(text: '${widget.section?['sort'] ?? 0}');
  late int? _parent = (widget.section?['parent_id'] as num?)?.toInt() ?? widget.parent;
  String? _cover;
  bool _removeCover = false;
  bool _busy = false;

  @override
  void dispose() {
    _name.dispose();
    _location.dispose();
    _description.dispose();
    _sort.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty) return;
    setState(() => _busy = true);
    try {
      final msg = await LibraryManageApi.instance.saveSection({
        'name': _name.text.trim(),
        'parent_id': _parent,
        'location': _location.text.trim(),
        'description': _description.text.trim(),
        'sort': int.tryParse(_sort.text.trim()) ?? 0,
      }, id: (widget.section?['id'] as num?)?.toInt(), cover: _cover, removeCover: _removeCover);
      if (!mounted) return;
      context.toast(msg);
      Navigator.pop(context, true);
    } catch (e) {
      if (mounted) context.toastError(e);
    }
    if (mounted) setState(() => _busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final hasCover = widget.section?['cover_url'] != null && !_removeCover;
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(widget.section == null ? context.l('قسم جديد', 'New section') : context.l('تعديل القسم', 'Edit section'),
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
          const SizedBox(height: 12),
          TextField(controller: _name, autofocus: widget.section == null, decoration: InputDecoration(labelText: context.l('الاسم *', 'Name *'))),
          const SizedBox(height: 8),
          SectionPicker(
            sections: widget.sections,
            value: _parent,
            exclude: widget.exclude,
            allLabel: context.l('قسم رئيسي', 'Top level'),
            label: context.l('داخل القسم', 'Inside'),
            onChanged: (v) => setState(() => _parent = v),
          ),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(child: TextField(controller: _location, decoration: InputDecoration(labelText: context.l('المكان (رف / قاعة)', 'Place (shelf / hall)')))),
            const SizedBox(width: 10),
            SizedBox(width: 80, child: TextField(controller: _sort, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: context.l('الترتيب', 'Order')))),
          ]),
          const SizedBox(height: 8),
          TextField(controller: _description, minLines: 2, maxLines: 5, decoration: InputDecoration(labelText: context.l('الوصف', 'Description'))),
          const SizedBox(height: 8),
          Wrap(spacing: 8, children: [
            OutlinedButton.icon(
              onPressed: () async {
                final r = await FilePicker.platform.pickFiles(type: FileType.image);
                final p = r?.files.single.path;
                if (p != null) setState(() => _cover = p);
              },
              icon: const Icon(Icons.image_outlined),
              label: Text(_cover != null ? context.l('اختيرت صورة', 'Picture chosen') : context.l('صورة القسم', 'Cover picture')),
            ),
            if (hasCover && _cover == null)
              TextButton(onPressed: () => setState(() => _removeCover = true), child: Text(context.l('إزالة الصورة', 'Remove picture'))),
          ]),
          const SizedBox(height: 16),
          FilledButton(onPressed: _busy ? null : _save, child: Text(context.l('حفظ', 'Save'))),
        ]),
      ),
    );
  }
}
