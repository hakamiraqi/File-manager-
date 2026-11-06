import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../app/theme/app_colors.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/ui_kit.dart';
import '../../site/badges_provider.dart';
import '../../site/site_api.dart';
import 'contact_screen.dart';

/// Staff inbox of «تواصل معنا»: new and in-progress messages first, reply,
/// change the status or delete.
class InboxScreen extends StatefulWidget {
  const InboxScreen({super.key, this.openId});

  final int? openId;

  @override
  State<InboxScreen> createState() => _InboxScreenState();
}

class _InboxScreenState extends State<InboxScreen> {
  final _paged = Paged<Map<String, dynamic>>();
  final _search = TextEditingController();
  String _status = 'open';
  String? _kind;
  Map<String, dynamic> _counts = {};
  Map<String, dynamic> _kinds = {};
  Map<String, dynamic> _statuses = {};
  int? _expanded;

  @override
  void initState() {
    super.initState();
    _expanded = widget.openId;
    _load(reset: true);
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load({bool reset = false}) async {
    if (_paged.loading || (!reset && !_paged.hasMore)) return;
    if (reset) _paged.reset();
    setState(() => _paged.loading = true);
    try {
      final r = await SiteApi.instance.inbox(status: _status, kind: _kind, q: _search.text.trim(), page: _paged.page + 1);
      _paged.items.addAll((r['data'] as List).cast<Map<String, dynamic>>());
      _paged.page = (r['meta']['current_page'] as num).toInt();
      _paged.lastPage = (r['meta']['last_page'] as num).toInt();
      _counts = Map<String, dynamic>.from(r['counts'] as Map);
      _kinds = Map<String, dynamic>.from(r['kinds'] as Map);
      _statuses = Map<String, dynamic>.from(r['statuses'] as Map);
    } catch (e) {
      _paged.error = e.toString();
    } finally {
      if (mounted) setState(() => _paged.loading = false);
    }
  }

  Future<void> _update(Map<String, dynamic> m, String status, String? reply) async {
    try {
      final r = await SiteApi.instance.updateInbox(m['id'] as int, status: status, reply: reply);
      if (mounted) {
        context.toast(r['message'] ?? context.l('تم الحفظ.', 'Saved.'));
        context.read<BadgesProvider>().refresh();
      }
      _load(reset: true);
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  Future<void> _delete(Map<String, dynamic> m) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text(context.l('حذف الرسالة؟', 'Delete message?')),
        content: Text(m['subject'] ?? ''),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: Text(context.l('إلغاء', 'Cancel'))),
          TextButton(onPressed: () => Navigator.of(context).pop(true), child: Text(context.l('حذف', 'Delete'), style: const TextStyle(color: AppColors.danger))),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await SiteApi.instance.deleteInbox(m['id'] as int);
      if (mounted) context.read<BadgesProvider>().refresh();
      _load(reset: true);
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(context.l('رسائل التواصل', 'Contact inbox'))),
      body: Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 6, 12, 6),
          child: SearchField(controller: _search, hint: context.l('ابحث بالموضوع أو الاسم أو البريد', 'Search subject, name or e-mail'), onSubmitted: (_) => _load(reset: true)),
        ),
        FilterStrip(
          items: {
            'open': context.l('المفتوحة ${_counts['open'] ?? ''}', 'Open ${_counts['open'] ?? ''}'),
            'new': context.l('جديد ${_counts['new'] ?? ''}', 'New ${_counts['new'] ?? ''}'),
            'in_progress': context.l('قيد المتابعة ${_counts['in_progress'] ?? ''}', 'In progress ${_counts['in_progress'] ?? ''}'),
            'closed': context.l('مغلق ${_counts['closed'] ?? ''}', 'Closed ${_counts['closed'] ?? ''}'),
            'all': context.l('الكل ${_counts['all'] ?? ''}', 'All ${_counts['all'] ?? ''}'),
          },
          selected: _status,
          onSelected: (k) {
            _status = k;
            _load(reset: true);
          },
        ),
        if (_kinds.isNotEmpty)
          SizedBox(
            height: 40,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12),
              children: [
                for (final e in {null: context.l('كل الأنواع', 'All types'), ..._kinds}.entries)
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 3),
                    child: FilterChip(
                      avatar: e.key == null ? null : Icon(contactKindIcons[e.key] ?? Icons.mail_outline_rounded, size: 16),
                      label: Text('${e.value}', style: const TextStyle(fontSize: 12.5)),
                      selected: _kind == e.key,
                      showCheckmark: false,
                      onSelected: (_) {
                        _kind = e.key;
                        _load(reset: true);
                      },
                    ),
                  ),
              ],
            ),
          ),
        Expanded(
          child: NotificationListener<ScrollNotification>(
            onNotification: (n) {
              if (n.metrics.pixels > n.metrics.maxScrollExtent - 300) _load();
              return false;
            },
            child: RefreshIndicator(
              onRefresh: () => _load(reset: true),
              child: _paged.items.isEmpty
                  ? ListView(children: [
                      const SizedBox(height: 60),
                      if (_paged.loading)
                        const Center(child: CircularProgressIndicator())
                      else
                        EmptyState(icon: Icons.mark_email_read_outlined, message: _paged.error ?? context.l('لا توجد رسائل هنا.', 'No messages here.')),
                    ])
                  : ListView.builder(
                      padding: const EdgeInsets.fromLTRB(12, 6, 12, 24),
                      itemCount: _paged.items.length,
                      itemBuilder: (_, i) => _MessageCard(
                        key: ValueKey(_paged.items[i]['id']),
                        message: _paged.items[i],
                        kinds: _kinds,
                        statuses: _statuses,
                        expanded: _expanded == _paged.items[i]['id'],
                        onToggle: () => setState(() => _expanded = _expanded == _paged.items[i]['id'] ? null : _paged.items[i]['id'] as int),
                        onSave: (status, reply) => _update(_paged.items[i], status, reply),
                        onDelete: () => _delete(_paged.items[i]),
                      ),
                    ),
            ),
          ),
        ),
      ]),
    );
  }
}

class _MessageCard extends StatefulWidget {
  const _MessageCard({
    super.key,
    required this.message,
    required this.kinds,
    required this.statuses,
    required this.expanded,
    required this.onToggle,
    required this.onSave,
    required this.onDelete,
  });

  final Map<String, dynamic> message;
  final Map<String, dynamic> kinds;
  final Map<String, dynamic> statuses;
  final bool expanded;
  final VoidCallback onToggle;
  final void Function(String status, String? reply) onSave;
  final VoidCallback onDelete;

  @override
  State<_MessageCard> createState() => _MessageCardState();
}

class _MessageCardState extends State<_MessageCard> {
  late final _reply = TextEditingController(text: widget.message['reply'] ?? '');
  late String _status = widget.message['status'] as String? ?? 'new';

  @override
  void dispose() {
    _reply.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final m = widget.message;
    final scheme = Theme.of(context).colorScheme;
    final user = m['user'] as Map?;
    final contacts = [m['email'], m['phone']].where((v) => v != null && '$v'.isNotEmpty).join(' · ');

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: widget.onToggle,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Avatar(name: m['name'] ?? '', url: user?['avatar_url'] as String?, radius: 20),
              const SizedBox(width: 10),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    Expanded(child: Text(m['name'] ?? '', style: const TextStyle(fontWeight: FontWeight.w600))),
                    Text(timeAgo(context, m['created_at'] as String?), style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant)),
                  ]),
                  Text(m['subject'] ?? '', maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: m['status'] == 'new' ? FontWeight.w700 : FontWeight.w500)),
                  const SizedBox(height: 4),
                  Wrap(spacing: 6, runSpacing: 4, children: [
                    Pill(text: '${widget.kinds[m['kind']] ?? m['kind']}', color: const Color(0xFF7E57C2), icon: contactKindIcons[m['kind']]),
                    Pill(text: '${widget.statuses[m['status']] ?? m['status']}', color: contactStatusColor(m['status'] as String?)),
                    if ((m['reply'] ?? '').toString().isNotEmpty) Pill(text: context.l('تم الرد', 'Replied'), color: AppColors.success, icon: Icons.reply_rounded),
                  ]),
                ]),
              ),
            ]),
            if (widget.expanded) ...[
              const Divider(height: 22),
              if ((m['about_title'] ?? '').toString().isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Row(children: [
                    Icon(m['about_type'] == 'thesis' ? Icons.school_rounded : Icons.menu_book_rounded, size: 18, color: AppColors.primary),
                    const SizedBox(width: 6),
                    Expanded(child: Text(m['about_title'], style: const TextStyle(fontWeight: FontWeight.w600))),
                  ]),
                ),
              SelectableText(m['message'] ?? '', style: const TextStyle(height: 1.6)),
              if (contacts.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: SelectableText(contacts, style: TextStyle(color: scheme.onSurfaceVariant, fontSize: 12.5)),
                ),
              if (m['handler'] != null)
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Text(context.l('تابعها: ${m['handler']}', 'Handled by ${m['handler']}'), style: TextStyle(color: scheme.onSurfaceVariant, fontSize: 12.5)),
                ),
              const SizedBox(height: 12),
              TextField(
                controller: _reply,
                minLines: 3,
                maxLines: 8,
                decoration: InputDecoration(hintText: context.l('اكتب الرد (يصل للمرسل بالبريد والإشعار)', 'Reply (sent by e-mail and notification)')),
              ),
              const SizedBox(height: 10),
              SegmentedButton<String>(
                segments: widget.statuses.entries.map((e) => ButtonSegment(value: e.key, label: Text('${e.value}', style: const TextStyle(fontSize: 12)))).toList(),
                selected: {_status},
                showSelectedIcon: false,
                onSelectionChanged: (v) => setState(() => _status = v.first),
              ),
              const SizedBox(height: 8),
              Row(children: [
                IconButton(
                  tooltip: context.l('حذف', 'Delete'),
                  onPressed: widget.onDelete,
                  icon: const Icon(Icons.delete_outline_rounded, color: AppColors.danger),
                ),
                const Spacer(),
                FilledButton.icon(
                  style: FilledButton.styleFrom(minimumSize: const Size(0, 44)),
                  onPressed: () => widget.onSave(_status, _reply.text.trim().isEmpty ? null : _reply.text.trim()),
                  icon: const Icon(Icons.send_rounded, size: 18),
                  label: Text(context.l('حفظ وإرسال', 'Save & send')),
                ),
              ]),
            ],
          ]),
        ),
      ),
    );
  }
}
