import 'package:flutter/material.dart';

import '../../../../app/theme/app_colors.dart';
import '../../../../core/widgets/empty_state.dart';
import '../../../../core/widgets/ui_kit.dart';
import '../../data/library_manage_api.dart';
import 'manage_widgets.dart';

String memberTypeLabel(BuildContext context, String? type) => switch (type) {
      'teacher' => context.l('تدريسي', 'Teacher'),
      'staff' => context.l('موظف', 'Staff'),
      _ => context.l('طالب', 'Student'),
    };

/// «القرّاء»: readers with or without a site account, their card numbers, and printing cards.
class MembersScreen extends StatefulWidget {
  const MembersScreen({super.key});

  @override
  State<MembersScreen> createState() => _MembersScreenState();
}

class _MembersScreenState extends State<MembersScreen> {
  final _search = TextEditingController();
  final _scroll = ScrollController();
  final _paged = Paged<Json>();
  final Set<int> _picked = {};

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
      final r = await LibraryManageApi.instance.members(q: _search.text.trim(), page: _paged.page + 1);
      final m = Map<String, dynamic>.from(r['members'] as Map);
      _paged.items.addAll((m['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)));
      _paged.page = (m['current_page'] as num).toInt();
      _paged.lastPage = (m['last_page'] as num).toInt();
    } catch (e) {
      _paged.error = ApiMessage.of(e);
    }
    if (mounted) setState(() => _paged.loading = false);
  }

  Future<void> _edit([Json? member]) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useRootNavigator: true,
      builder: (_) => _MemberSheet(member: member),
    );
    if (saved == true) _load(reset: true);
  }

  Future<void> _printCards() async {
    try {
      await LibraryManageApi.instance.openInBrowser('cards', ids: _picked.toList());
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(_picked.isEmpty ? context.l('القرّاء', 'Readers') : context.l('اختير ${_picked.length}', '${_picked.length} selected')),
        leading: _picked.isEmpty ? null : IconButton(icon: const Icon(Icons.close_rounded), onPressed: () => setState(_picked.clear)),
        actions: [
          IconButton(
            tooltip: _picked.isEmpty ? context.l('طباعة كل البطاقات', 'Print all cards') : context.l('طباعة بطاقات المختارين', 'Print selected cards'),
            icon: const Icon(Icons.print_outlined),
            onPressed: _printCards,
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _edit(),
        icon: const Icon(Icons.person_add_alt_1_rounded),
        label: Text(context.l('قارئ جديد', 'New reader')),
      ),
      body: RefreshIndicator(
        onRefresh: () => _load(reset: true),
        child: ListView(controller: _scroll, padding: const EdgeInsets.only(bottom: 96), children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
            child: SearchField(
              controller: _search,
              hint: context.l('الاسم أو رقم البطاقة أو الهاتف', 'Name, card number or phone'),
              onSubmitted: (_) => _load(reset: true),
              onClear: () => _load(reset: true),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 6, 20, 0),
            child: Text(context.l('اضغط مطولاً لاختيار قرّاء لطباعة بطاقاتهم.', 'Long-press to pick readers for printing cards.'),
                style: TextStyle(fontSize: 12, color: Theme.of(context).colorScheme.onSurfaceVariant)),
          ),
          if (_paged.items.isEmpty && (_paged.loading || _paged.error != null))
            SizedBox(height: 300, child: LoadingOrError(error: _paged.error, onRetry: () => _load(reset: true)))
          else if (_paged.items.isEmpty)
            SizedBox(height: 300, child: EmptyState(icon: Icons.badge_outlined, message: context.l('لا يوجد قرّاء', 'No readers')))
          else
            GroupCard(children: [
              for (final m in _paged.items)
                Builder(builder: (context) {
                  final id = (m['id'] as num).toInt();
                  final on = _picked.contains(id);
                  void toggle() => setState(() => on ? _picked.remove(id) : _picked.add(id));
                  return ListTile(
                    selected: on,
                    leading: on ? const CircleAvatar(backgroundColor: AppColors.primary, child: Icon(Icons.check_rounded, color: Colors.white)) : Avatar(name: '${m['name'] ?? ''}'),
                    title: Text('${m['name'] ?? ''}', style: TextStyle(color: m['active'] == false ? Colors.grey : null)),
                    subtitle: Text([
                      m['card_number'],
                      memberTypeLabel(context, m['type'] as String?),
                      if ((m['stage'] ?? '').toString().isNotEmpty) context.l('المرحلة ${m['stage']}', 'Stage ${m['stage']}'),
                      if (((m['active_loans_count'] as num?) ?? 0) > 0) context.l('معه ${m['active_loans_count']} كتاب', '${m['active_loans_count']} on loan'),
                      if (m['active'] == false) context.l('موقوف', 'Inactive'),
                    ].whereType<Object>().join(' · ')),
                    onTap: _picked.isEmpty ? () => _edit(m) : toggle,
                    onLongPress: toggle,
                  );
                }),
            ]),
          if (_paged.loading && _paged.items.isNotEmpty) const Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator())),
        ]),
      ),
    );
  }
}

class _MemberSheet extends StatefulWidget {
  const _MemberSheet({this.member});
  final Json? member;

  @override
  State<_MemberSheet> createState() => _MemberSheetState();
}

class _MemberSheetState extends State<_MemberSheet> {
  late final _name = TextEditingController(text: '${widget.member?['name'] ?? ''}');
  late final _card = TextEditingController(text: '${widget.member?['card_number'] ?? ''}');
  late final _stage = TextEditingController(text: '${widget.member?['stage'] ?? ''}');
  late final _phone = TextEditingController(text: '${widget.member?['phone'] ?? ''}');
  late final _email = TextEditingController(text: '${widget.member?['email'] ?? ''}');
  late final _notes = TextEditingController(text: '${widget.member?['notes'] ?? ''}');
  late String _type = widget.member?['type'] as String? ?? 'student';
  late bool _active = widget.member?['active'] != false;
  bool _busy = false;

  int? get _id => (widget.member?['id'] as num?)?.toInt();

  @override
  void dispose() {
    for (final c in [_name, _card, _stage, _phone, _email, _notes]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty) return;
    setState(() => _busy = true);
    try {
      final msg = await LibraryManageApi.instance.saveMember({
        'name': _name.text.trim(),
        'card_number': _card.text.trim(),
        'type': _type,
        'stage': _stage.text.trim(),
        'phone': _phone.text.trim(),
        'email': _email.text.trim(),
        'notes': _notes.text.trim(),
        'active': _active,
      }, id: _id);
      if (!mounted) return;
      context.toast(msg);
      Navigator.pop(context, true);
    } catch (e) {
      if (mounted) context.toastError(e);
    }
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _delete() async {
    if (!await confirmAction(context, context.l('حذف هذا القارئ؟', 'Delete this reader?'))) return;
    try {
      final msg = await LibraryManageApi.instance.deleteMember(_id!);
      if (!mounted) return;
      context.toast(msg);
      Navigator.pop(context, true);
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  Future<void> _printCard() async {
    try {
      await LibraryManageApi.instance.openInBrowser('cards', ids: [_id!]);
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
          Text(_id == null ? context.l('قارئ جديد', 'New reader') : context.l('تعديل القارئ', 'Edit reader'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
          const SizedBox(height: 12),
          TextField(controller: _name, autofocus: _id == null, decoration: InputDecoration(labelText: context.l('الاسم *', 'Name *'))),
          const SizedBox(height: 8),
          TextField(
            controller: _card,
            decoration: InputDecoration(labelText: context.l('رقم البطاقة', 'Card number'), helperText: _id == null ? context.l('اتركه فارغاً ليُولّد تلقائياً', 'Leave empty to generate one') : null),
          ),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(
              child: DropdownButtonFormField<String>(
                value: _type,
                decoration: InputDecoration(labelText: context.l('النوع', 'Type')),
                items: [for (final t in ['student', 'teacher', 'staff']) DropdownMenuItem(value: t, child: Text(memberTypeLabel(context, t)))],
                onChanged: (v) => setState(() => _type = v ?? _type),
              ),
            ),
            const SizedBox(width: 10),
            SizedBox(width: 100, child: TextField(controller: _stage, decoration: InputDecoration(labelText: context.l('المرحلة', 'Stage')))),
          ]),
          const SizedBox(height: 8),
          TextField(controller: _phone, keyboardType: TextInputType.phone, decoration: InputDecoration(labelText: context.l('الهاتف', 'Phone'))),
          const SizedBox(height: 8),
          TextField(controller: _email, keyboardType: TextInputType.emailAddress, decoration: InputDecoration(labelText: context.l('البريد', 'Email'))),
          const SizedBox(height: 8),
          TextField(controller: _notes, decoration: InputDecoration(labelText: context.l('ملاحظات', 'Notes'))),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            value: _active,
            onChanged: (v) => setState(() => _active = v),
            title: Text(context.l('فعّال (يستطيع الاستعارة)', 'Active (may borrow)')),
          ),
          const SizedBox(height: 8),
          Row(children: [
            if (_id != null) ...[
              IconButton(tooltip: context.l('حذف', 'Delete'), onPressed: _delete, icon: const Icon(Icons.delete_outline_rounded, color: AppColors.danger)),
              IconButton(tooltip: context.l('طباعة البطاقة', 'Print card'), onPressed: _printCard, icon: const Icon(Icons.print_outlined)),
            ],
            const Spacer(),
            FilledButton(onPressed: _busy ? null : _save, child: Text(context.l('حفظ', 'Save'))),
          ]),
        ]),
      ),
    );
  }
}
