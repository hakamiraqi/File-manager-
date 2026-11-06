import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../app/theme/app_colors.dart';
import '../../../core/widgets/ui_kit.dart';
import '../../auth/state/auth_provider.dart';
import '../../site/site_api.dart';

const contactKindIcons = <String, IconData>{
  'inquiry': Icons.help_outline_rounded,
  'book_request': Icons.menu_book_rounded,
  'thesis_request': Icons.school_rounded,
  'suggestion': Icons.lightbulb_outline_rounded,
  'complaint': Icons.report_gmailerrorred_rounded,
  'other': Icons.more_horiz_rounded,
};

Color contactStatusColor(String? status) => switch (status) {
      'new' => AppColors.primary,
      'in_progress' => AppColors.warning,
      _ => Colors.grey,
    };

/// «تواصل معنا»: send an inquiry or a request (a book or thesis too), and see
/// the replies to what I sent before.
class ContactScreen extends StatefulWidget {
  const ContactScreen({super.key, this.aboutType, this.aboutId, this.aboutTitle, this.kind});

  final String? aboutType;
  final int? aboutId;
  final String? aboutTitle;
  final String? kind;

  @override
  State<ContactScreen> createState() => _ContactScreenState();
}

class _ContactScreenState extends State<ContactScreen> {
  final _form = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _subject = TextEditingController();
  final _message = TextEditingController();
  Map<String, dynamic>? _info;
  String? _error;
  late String _kind = widget.kind ?? 'inquiry';
  bool _sending = false;

  @override
  void initState() {
    super.initState();
    if (widget.aboutTitle != null) {
      _subject.text = widget.aboutType == 'thesis' ? 'طلب: ${widget.aboutTitle}' : 'طلب كتاب: ${widget.aboutTitle}';
    }
    _load();
  }

  @override
  void dispose() {
    for (final c in [_name, _email, _phone, _subject, _message]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final d = await SiteApi.instance.contactInfo();
      if (mounted) setState(() => _info = d);
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    }
  }

  Future<void> _send() async {
    if (!_form.currentState!.validate()) return;
    setState(() => _sending = true);
    try {
      final r = await SiteApi.instance.sendContact({
        'kind': _kind,
        'name': _name.text.trim(),
        'email': _email.text.trim(),
        'phone': _phone.text.trim(),
        'subject': _subject.text.trim(),
        'message': _message.text.trim(),
        if (widget.aboutType != null) 'about_type': widget.aboutType,
        if (widget.aboutId != null) 'about_id': widget.aboutId,
      });
      if (!mounted) return;
      _message.clear();
      await showDialog<void>(
        context: context,
        builder: (_) => AlertDialog(
          icon: const Icon(Icons.check_circle_rounded, color: AppColors.success, size: 44),
          content: Text(r['message'] ?? '', textAlign: TextAlign.center),
          actions: [TextButton(onPressed: () => Navigator.of(context).pop(), child: Text(context.l('حسناً', 'OK')))],
        ),
      );
      _load();
    } catch (e) {
      if (mounted) context.toastError(e);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final info = _info;
    final signedIn = context.watch<AuthProvider>().user != null;
    if (info == null) {
      return Scaffold(appBar: AppBar(title: Text(context.l('تواصل معنا', 'Contact us'))), body: LoadingOrError(error: _error, onRetry: _load));
    }
    final kinds = Map<String, dynamic>.from(info['kinds'] as Map);
    final statuses = Map<String, dynamic>.from(info['statuses'] as Map);
    final mine = (info['mine'] as List).cast<Map>();

    return DefaultTabController(
      length: signedIn ? 2 : 1,
      child: Scaffold(
        appBar: AppBar(
          title: Text(context.l('تواصل معنا', 'Contact us')),
          bottom: signedIn
              ? TabBar(tabs: [
                  Tab(text: context.l('رسالة جديدة', 'New message')),
                  Tab(text: context.l('رسائلي (${mine.length})', 'My messages (${mine.length})')),
                ])
              : null,
        ),
        body: TabBarView(children: [
          Form(
            key: _form,
            child: ListView(padding: const EdgeInsets.only(bottom: 32), children: [
              if (widget.aboutTitle != null)
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
                  child: Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(color: AppColors.primary.withOpacity(0.1), borderRadius: BorderRadius.circular(14)),
                    child: Row(children: [
                      Icon(widget.aboutType == 'thesis' ? Icons.school_rounded : Icons.menu_book_rounded, color: AppColors.primary),
                      const SizedBox(width: 10),
                      Expanded(child: Text(widget.aboutTitle!, style: const TextStyle(fontWeight: FontWeight.w600))),
                    ]),
                  ),
                ),
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 14, 16, 4),
                child: Text(context.l('نوع الرسالة', 'Type'), style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: Theme.of(context).colorScheme.primary)),
              ),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 12),
                child: Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: kinds.entries.map((e) {
                    final on = e.key == _kind;
                    return ChoiceChip(
                      avatar: Icon(contactKindIcons[e.key] ?? Icons.mail_outline_rounded, size: 18, color: on ? Colors.white : null),
                      label: Text('${e.value}'),
                      selected: on,
                      showCheckmark: false,
                      selectedColor: AppColors.primary,
                      labelStyle: TextStyle(color: on ? Colors.white : null),
                      onSelected: (_) => setState(() => _kind = e.key),
                    );
                  }).toList(),
                ),
              ),
              if (!signedIn)
                GroupCard(
                  title: context.l('بياناتك حتى نرد عليك', 'How we reach you'),
                  footer: context.l('اكتب البريد أو رقم الهاتف على الأقل.', 'Give an e-mail or a phone number.'),
                  children: [
                    _field(_name, context.l('الاسم', 'Name'), Icons.person_outline_rounded, required: true),
                    _field(_email, context.l('البريد الإلكتروني', 'E-mail'), Icons.alternate_email_rounded, keyboard: TextInputType.emailAddress),
                    _field(_phone, context.l('رقم الهاتف', 'Phone'), Icons.phone_outlined, keyboard: TextInputType.phone),
                  ],
                ),
              GroupCard(
                title: context.l('الرسالة', 'Message'),
                children: [
                  _field(_subject, context.l('الموضوع', 'Subject'), Icons.subject_rounded, required: true),
                  _field(_message, context.l('اكتب رسالتك...', 'Your message...'), Icons.notes_rounded, required: true, lines: 6),
                ],
              ),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: FilledButton.icon(
                  onPressed: _sending ? null : _send,
                  icon: _sending
                      ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                      : const Icon(Icons.send_rounded),
                  label: Text(context.l('إرسال', 'Send')),
                ),
              ),
            ]),
          ),
          if (signedIn)
            RefreshIndicator(
              onRefresh: _load,
              child: mine.isEmpty
                  ? ListView(children: [
                      const SizedBox(height: 80),
                      Center(child: Text(context.l('لم ترسل رسائل بعد.', 'No messages yet.'))),
                    ])
                  : ListView(
                      padding: const EdgeInsets.all(12),
                      children: mine.map((m) {
                        final reply = (m['reply'] ?? '').toString();
                        return Card(
                          margin: const EdgeInsets.only(bottom: 10),
                          child: Padding(
                            padding: const EdgeInsets.all(12),
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Row(children: [
                                Icon(contactKindIcons[m['kind']] ?? Icons.mail_outline_rounded, size: 18, color: AppColors.primary),
                                const SizedBox(width: 6),
                                Expanded(child: Text(m['subject'] ?? '', style: const TextStyle(fontWeight: FontWeight.w600))),
                                Pill(text: '${statuses[m['status']] ?? m['status']}', color: contactStatusColor(m['status'] as String?)),
                              ]),
                              const SizedBox(height: 6),
                              Text(m['message'] ?? '', maxLines: 4, overflow: TextOverflow.ellipsis),
                              const SizedBox(height: 4),
                              Text('#${m['id']} · ${timeAgo(context, m['created_at'] as String?)}',
                                  style: TextStyle(fontSize: 12, color: Theme.of(context).colorScheme.onSurfaceVariant)),
                              if (reply.isNotEmpty)
                                Container(
                                  width: double.infinity,
                                  margin: const EdgeInsets.only(top: 10),
                                  padding: const EdgeInsets.all(10),
                                  decoration: BoxDecoration(
                                    color: AppColors.success.withOpacity(0.1),
                                    borderRadius: BorderRadius.circular(12),
                                  ),
                                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                    Text(context.l('الرد', 'Reply'), style: const TextStyle(fontWeight: FontWeight.w700, color: AppColors.success, fontSize: 12.5)),
                                    const SizedBox(height: 4),
                                    Text(reply),
                                  ]),
                                ),
                            ]),
                          ),
                        );
                      }).toList(),
                    ),
            ),
        ]),
      ),
    );
  }

  Widget _field(TextEditingController c, String label, IconData icon, {bool required = false, int lines = 1, TextInputType? keyboard}) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      child: TextFormField(
        controller: c,
        minLines: lines,
        maxLines: lines == 1 ? 1 : 12,
        keyboardType: keyboard,
        decoration: InputDecoration(
          hintText: label,
          prefixIcon: lines == 1 ? Icon(icon) : null,
          border: InputBorder.none,
          enabledBorder: InputBorder.none,
          focusedBorder: InputBorder.none,
          filled: false,
        ),
        validator: required ? (v) => (v == null || v.trim().isEmpty) ? context.l('مطلوب', 'Required') : null : null,
      ),
    );
  }
}
