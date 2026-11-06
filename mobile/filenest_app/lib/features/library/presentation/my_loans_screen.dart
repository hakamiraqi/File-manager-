import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../app/theme/app_colors.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/ui_kit.dart';
import '../../site/badges_provider.dart';
import '../../site/site_api.dart';
import 'book_screen.dart';
import 'library_widgets.dart';

/// «استعاراتي»: books I have now, my holds, history and fines.
class MyLoansScreen extends StatefulWidget {
  const MyLoansScreen({super.key});

  @override
  State<MyLoansScreen> createState() => _MyLoansScreenState();
}

class _MyLoansScreenState extends State<MyLoansScreen> {
  Map<String, dynamic>? _data;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final d = await SiteApi.instance.myLoans();
      if (mounted) {
        setState(() => _data = d);
        context.read<BadgesProvider>().refresh();
      }
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    }
  }

  Future<void> _renew(int id) async {
    try {
      final r = await SiteApi.instance.renew(id);
      if (mounted) context.toast(r['message'] ?? '');
      _load();
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  Future<void> _cancelHold(int id) async {
    try {
      await SiteApi.instance.cancelHold(id);
      _load();
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _data;
    return DefaultTabController(
      length: 3,
      child: Scaffold(
        appBar: AppBar(
          title: Text(context.l('استعاراتي', 'My loans')),
          bottom: d == null
              ? null
              : TabBar(tabs: [
                  Tab(text: context.l('الحالية (${(d['active'] as List).length})', 'Current (${(d['active'] as List).length})')),
                  Tab(text: context.l('الحجوزات (${(d['holds'] as List).length})', 'Holds (${(d['holds'] as List).length})')),
                  Tab(text: context.l('السجل', 'History')),
                ]),
        ),
        body: d == null
            ? LoadingOrError(error: _error, onRetry: _load)
            : Column(children: [
                if (d['member'] != null || ((d['fines_due'] as num?) ?? 0) > 0)
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
                    child: Row(children: [
                      if (d['member'] != null)
                        Expanded(
                          child: Text(context.l('بطاقة القارئ: ${d['member']['card_number']}', 'Card: ${d['member']['card_number']}'),
                              style: TextStyle(color: Theme.of(context).colorScheme.onSurfaceVariant)),
                        ),
                      if (((d['fines_due'] as num?) ?? 0) > 0)
                        Pill(text: context.l('غرامات: ${d['fines_due']} د.ع', 'Fines: ${d['fines_due']} IQD'), color: AppColors.danger, icon: Icons.payments_outlined),
                    ]),
                  ),
                Expanded(
                  child: TabBarView(children: [
                    _list((d['active'] as List).cast<Map<String, dynamic>>(), active: true),
                    _holds((d['holds'] as List).cast<Map<String, dynamic>>()),
                    _list((d['history'] as List).cast<Map<String, dynamic>>(), active: false),
                  ]),
                ),
              ]),
      ),
    );
  }

  Widget _list(List<Map<String, dynamic>> loans, {required bool active}) {
    if (loans.isEmpty) {
      return RefreshIndicator(
        onRefresh: _load,
        child: ListView(children: [
          const SizedBox(height: 80),
          EmptyState(icon: Icons.menu_book_outlined, message: active ? context.l('ما عندك كتب مُعارة الآن.', 'No books on loan.') : context.l('لا يوجد سجل بعد.', 'No history yet.')),
        ]),
      );
    }
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.separated(
        padding: const EdgeInsets.all(12),
        itemCount: loans.length,
        separatorBuilder: (_, __) => const SizedBox(height: 10),
        itemBuilder: (_, i) {
          final l = loans[i];
          final book = Map<String, dynamic>.from(l['book'] as Map);
          final overdue = l['is_overdue'] == true;
          final due = DateTime.tryParse(l['due_at'] ?? '')?.toLocal();
          final daysLeft = due == null ? null : due.difference(DateTime.now()).inDays;
          return Material(
            color: Theme.of(context).colorScheme.surface,
            borderRadius: BorderRadius.circular(16),
            child: InkWell(
              borderRadius: BorderRadius.circular(16),
              onTap: book['id'] == null ? null : () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => BookScreen(bookId: book['id'] as int))),
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  BookCover(title: book['title'] ?? '', url: book['cover_url'] as String?, width: 56, radius: 8),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(book['title'] ?? '', maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600)),
                      const SizedBox(height: 4),
                      if (active)
                        Pill(
                          text: overdue
                              ? context.l('متأخر ${l['days_overdue']} يوم', '${l['days_overdue']} days overdue')
                              : context.l('يُرجع ${shortDate(l['due_at'])}${daysLeft != null ? ' (باقي $daysLeft يوم)' : ''}', 'Due ${shortDate(l['due_at'])}'),
                          color: overdue ? AppColors.danger : (daysLeft != null && daysLeft <= 2 ? AppColors.warning : AppColors.success),
                          icon: overdue ? Icons.warning_amber_rounded : Icons.event_rounded,
                        )
                      else
                        Text(context.l('أُرجع ${shortDate(l['returned_at'])}', 'Returned ${shortDate(l['returned_at'])}'),
                            style: TextStyle(fontSize: 12.5, color: Theme.of(context).colorScheme.onSurfaceVariant)),
                      if (((l['fine'] as num?) ?? 0) > 0 && l['fine_paid'] != true && l['fine_waived'] != true)
                        Padding(
                          padding: const EdgeInsets.only(top: 4),
                          child: Text(context.l('غرامة: ${l['fine']} د.ع', 'Fine: ${l['fine']} IQD'), style: const TextStyle(color: AppColors.danger, fontSize: 12.5)),
                        ),
                      if (active && !overdue)
                        Align(
                          alignment: AlignmentDirectional.centerEnd,
                          child: TextButton.icon(
                            onPressed: () => _renew(l['id'] as int),
                            icon: const Icon(Icons.autorenew_rounded, size: 18),
                            label: Text(context.l('تجديد', 'Renew')),
                          ),
                        ),
                    ]),
                  ),
                ]),
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _holds(List<Map<String, dynamic>> holds) {
    if (holds.isEmpty) {
      return ListView(children: [
        const SizedBox(height: 80),
        EmptyState(icon: Icons.bookmark_border_rounded, message: context.l('لا توجد حجوزات.', 'No holds.')),
      ]);
    }
    return ListView(
      padding: const EdgeInsets.all(12),
      children: holds.map((h) {
        final book = Map<String, dynamic>.from(h['book'] as Map);
        final ready = h['status'] == 'ready';
        return Card(
          margin: const EdgeInsets.only(bottom: 10),
          child: ListTile(
            contentPadding: const EdgeInsets.all(10),
            leading: BookCover(title: book['title'] ?? '', url: book['cover_url'] as String?, width: 40, radius: 6),
            title: Text(book['title'] ?? '', maxLines: 2, overflow: TextOverflow.ellipsis),
            subtitle: Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Align(
                alignment: AlignmentDirectional.centerStart,
                child: Pill(
                  text: ready ? context.l('جاهز للاستلام حتى ${shortDate(h['expires_at'])}', 'Ready until ${shortDate(h['expires_at'])}') : context.l('بالانتظار', 'Waiting'),
                  color: ready ? AppColors.success : AppColors.primary,
                ),
              ),
            ),
            trailing: IconButton(
              tooltip: context.l('إلغاء الحجز', 'Cancel hold'),
              icon: const Icon(Icons.close_rounded),
              onPressed: () => _cancelHold(h['id'] as int),
            ),
          ),
        );
      }).toList(),
    );
  }
}
