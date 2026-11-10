import 'package:flutter/material.dart';

import '../../../../app/theme/app_colors.dart';
import '../../../../core/widgets/ui_kit.dart';
import '../../data/library_manage_api.dart';
import 'manage_widgets.dart';
import 'members_screen.dart' show memberTypeLabel;

/// «قواعد الإعارة والعطل»: loan days, how many books, renewals and the daily fine for each
/// reader type, the general rules, and the holidays that due dates skip.
class LibraryRulesScreen extends StatefulWidget {
  const LibraryRulesScreen({super.key});

  @override
  State<LibraryRulesScreen> createState() => _LibraryRulesScreenState();
}

class _LibraryRulesScreenState extends State<LibraryRulesScreen> {
  static const _types = ['student', 'teacher', 'staff'];
  static const _typeKeys = ['loan_days', 'max_loans', 'renewals', 'fine_per_day'];
  static const _generalKeys = ['hold_hours', 'remind_days_before', 'remind_every_days', 'lost_after_days'];

  final Map<String, TextEditingController> _c = {};
  List<Json> _holidays = [];
  bool _loaded = false;
  String? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in _c.values) {
      c.dispose();
    }
    super.dispose();
  }

  TextEditingController _ctl(String key) => _c.putIfAbsent(key, TextEditingController.new);

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final r = await LibraryManageApi.instance.settings();
      final rules = Map<String, dynamic>.from(r['rules'] as Map);
      final types = Map<String, dynamic>.from(rules['types'] as Map);
      for (final t in _types) {
        final set = Map<String, dynamic>.from((types[t] as Map?) ?? {});
        for (final k in _typeKeys) {
          _ctl('$t.$k').text = '${set[k] ?? 0}';
        }
      }
      final general = Map<String, dynamic>.from(rules['general'] as Map);
      for (final k in _generalKeys) {
        _ctl('general.$k').text = '${general[k] ?? 0}';
      }
      _holidays = ((r['holidays'] as List?) ?? []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      if (mounted) setState(() => _loaded = true);
    } catch (e) {
      if (mounted) setState(() => _error = ApiMessage.of(e));
    }
  }

  Future<void> _save() async {
    int v(String key) => int.tryParse(_ctl(key).text.trim()) ?? 0;
    setState(() => _busy = true);
    try {
      final msg = await LibraryManageApi.instance.saveRules({
        'types': {
          for (final t in _types) t: {for (final k in _typeKeys) k: v('$t.$k')},
        },
        'general': {for (final k in _generalKeys) k: v('general.$k')},
      });
      if (mounted) context.toast(msg);
    } catch (e) {
      if (mounted) context.toastError(e);
    }
    if (mounted) setState(() => _busy = false);
  }

  String _d(DateTime t) => '${t.year}-${t.month.toString().padLeft(2, '0')}-${t.day.toString().padLeft(2, '0')}';

  Future<void> _addHoliday() async {
    final range = await showDateRangePicker(context: context, firstDate: DateTime.now().subtract(const Duration(days: 30)), lastDate: DateTime.now().add(const Duration(days: 730)));
    if (range == null || !mounted) return;
    final name = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text(context.l('اسم العطلة', 'Holiday name')),
        content: TextField(controller: name, autofocus: true, decoration: InputDecoration(hintText: context.l('مثلاً: عطلة نصف السنة', 'e.g. mid-year break'))),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: Text(context.l('إلغاء', 'Cancel'))),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: Text(context.l('إضافة', 'Add'))),
        ],
      ),
    );
    final label = name.text.trim();
    name.dispose();
    if (ok != true) return;
    try {
      final msg = await LibraryManageApi.instance.addHoliday(_d(range.start), to: _d(range.end), name: label.isEmpty ? null : label);
      if (mounted) context.toast(msg);
      _load();
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  Future<void> _removeHoliday(Json h) async {
    try {
      await LibraryManageApi.instance.removeHoliday((h['id'] as num).toInt());
      setState(() => _holidays.remove(h));
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  Widget _num(String key, String label) => Expanded(
        child: TextField(
          controller: _ctl(key),
          keyboardType: TextInputType.number,
          textAlign: TextAlign.center,
          decoration: InputDecoration(labelText: label, isDense: true),
        ),
      );

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(context.l('قواعد الإعارة والعطل', 'Lending rules')),
        actions: [
          IconButton(
            tooltip: context.l('حفظ', 'Save'),
            icon: _busy ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.check_rounded),
            onPressed: !_loaded || _busy ? null : _save,
          ),
        ],
      ),
      body: !_loaded
          ? LoadingOrError(error: _error, onRetry: _load)
          : ListView(padding: const EdgeInsets.only(bottom: 32), children: [
              for (final t in _types)
                GroupCard(title: memberTypeLabel(context, t), children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(12, 12, 12, 12),
                    child: Row(children: [
                      _num('$t.loan_days', context.l('أيام الإعارة', 'Loan days')),
                      const SizedBox(width: 8),
                      _num('$t.max_loans', context.l('أقصى كتب', 'Max books')),
                      const SizedBox(width: 8),
                      _num('$t.renewals', context.l('التجديدات', 'Renewals')),
                      const SizedBox(width: 8),
                      _num('$t.fine_per_day', context.l('غرامة/يوم', 'Fine/day')),
                    ]),
                  ),
                ]),
              GroupCard(
                title: context.l('عامة', 'General'),
                footer: context.l('الحجز: ساعات بقاء الكتاب محجوزاً بعد إرجاعه. التذكير: قبل الموعد وكل كم يوم بعده. يُعدّ الكتاب مفقوداً بعد هذه الأيام من التأخير.',
                    'Hold: hours a returned book waits for the reader. Reminders: before the due date and every few days after. A copy counts as lost after this many days late.'),
                children: [
                  Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(children: [
                      Row(children: [
                        _num('general.hold_hours', context.l('ساعات الحجز', 'Hold hours')),
                        const SizedBox(width: 8),
                        _num('general.remind_days_before', context.l('تذكير قبل (يوم)', 'Remind before (days)')),
                      ]),
                      const SizedBox(height: 10),
                      Row(children: [
                        _num('general.remind_every_days', context.l('تذكير كل (يوم)', 'Remind every (days)')),
                        const SizedBox(width: 8),
                        _num('general.lost_after_days', context.l('مفقود بعد (يوم)', 'Lost after (days)')),
                      ]),
                    ]),
                  ),
                ],
              ),
              GroupCard(
                title: context.l('العطل', 'Holidays'),
                footer: context.l('لا يقع موعد الإرجاع في يوم عطلة ولا تُحسب فيه غرامة.', 'Due dates skip holidays and no fine is counted on them.'),
                children: [
                  for (final h in _holidays)
                    ListTile(
                      dense: true,
                      leading: const Icon(Icons.event_busy_rounded, color: AppColors.warning),
                      title: Text('${h['date']}'.split('T').first),
                      subtitle: (h['name'] ?? '').toString().isEmpty ? null : Text('${h['name']}'),
                      trailing: IconButton(icon: const Icon(Icons.close_rounded), onPressed: () => _removeHoliday(h)),
                    ),
                  ListTile(
                    leading: const Icon(Icons.add_rounded, color: AppColors.primary),
                    title: Text(context.l('إضافة عطلة', 'Add a holiday'), style: const TextStyle(color: AppColors.primary)),
                    onTap: _addHoliday,
                  ),
                ],
              ),
            ]),
    );
  }
}
