import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../app/theme/app_colors.dart';
import '../../../core/models/folder_item.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/ui_kit.dart';
import '../../contact/presentation/contact_screen.dart';
import '../../contact/presentation/inbox_screen.dart';
import '../../files/presentation/browse_screen.dart';
import '../../library/presentation/library_desk_screen.dart';
import '../../library/presentation/my_loans_screen.dart';
import '../../site/badges_provider.dart';
import '../../site/site_api.dart';

const _categoryStyle = <String, (IconData, Color)>{
  'library': (Icons.local_library_rounded, Color(0xFF7E57C2)),
  'files': (Icons.folder_rounded, Color(0xFF2AABEE)),
  'account': (Icons.person_rounded, Color(0xFF00897B)),
  'admin': (Icons.admin_panel_settings_rounded, Color(0xFFEF6C00)),
};

/// «الإشعارات»: everything the site would show in its bell, with filters.
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key, this.embedded = false});

  /// Inside the home tabs (no back button).
  final bool embedded;

  @override
  State<NotificationsScreen> createState() => NotificationsScreenState();
}

class NotificationsScreenState extends State<NotificationsScreen> {
  final _paged = Paged<Map<String, dynamic>>();
  String _filter = 'all';
  int _unread = 0;

  @override
  void initState() {
    super.initState();
    reload();
  }

  Future<void> reload() => _load(reset: true);

  Future<void> _load({bool reset = false}) async {
    if (_paged.loading || (!reset && !_paged.hasMore)) return;
    if (reset) _paged.reset();
    setState(() => _paged.loading = true);
    try {
      final r = await SiteApi.instance.notifications(filter: _filter, page: _paged.page + 1);
      _paged.items.addAll((r['data'] as List).cast<Map<String, dynamic>>());
      _paged.page = (r['meta']['current_page'] as num).toInt();
      _paged.lastPage = (r['meta']['last_page'] as num).toInt();
      _unread = (r['unread'] as num?)?.toInt() ?? 0;
      if (mounted) context.read<BadgesProvider>().setNotifications(_unread);
    } catch (e) {
      _paged.error = e.toString();
    } finally {
      if (mounted) setState(() => _paged.loading = false);
    }
  }

  Future<void> _readAll() async {
    try {
      await SiteApi.instance.readAllNotifications();
      for (final n in _paged.items) {
        n['read'] = true;
      }
      setState(() => _unread = 0);
      if (mounted) context.read<BadgesProvider>().setNotifications(0);
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  Future<void> _open(Map<String, dynamic> n) async {
    if (n['read'] != true) {
      n['read'] = true;
      setState(() => _unread = (_unread - 1).clamp(0, 1 << 30));
      context.read<BadgesProvider>().setNotifications(_unread);
      SiteApi.instance.readNotification(n['id'] as int).ignore();
    }
    final screen = screenForUrl(n['url'] as String?);
    if (screen != null) Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
  }

  Future<void> _delete(Map<String, dynamic> n) async {
    setState(() => _paged.items.remove(n));
    try {
      await SiteApi.instance.deleteNotification(n['id'] as int);
      if (mounted) context.read<BadgesProvider>().refresh();
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final body = Column(children: [
      const SizedBox(height: 6),
      FilterStrip(
        items: {
          'all': context.l('الكل', 'All'),
          'unread': context.l('غير المقروءة${_unread > 0 ? ' $_unread' : ''}', 'Unread${_unread > 0 ? ' $_unread' : ''}'),
          'library': context.l('المكتبة', 'Library'),
          'files': context.l('الملفات', 'Files'),
          'account': context.l('الحساب', 'Account'),
          'admin': context.l('الإدارة', 'Admin'),
        },
        selected: _filter,
        onSelected: (k) {
          _filter = k;
          _load(reset: true);
        },
      ),
      Expanded(
        child: NotificationListener<ScrollNotification>(
          onNotification: (s) {
            if (s.metrics.pixels > s.metrics.maxScrollExtent - 300) _load();
            return false;
          },
          child: RefreshIndicator(
            onRefresh: () => _load(reset: true),
            child: _paged.items.isEmpty
                ? ListView(children: [
                    const SizedBox(height: 80),
                    if (_paged.loading)
                      const Center(child: CircularProgressIndicator())
                    else
                      EmptyState(icon: Icons.notifications_none_rounded, message: _paged.error ?? context.l('لا توجد إشعارات.', 'No notifications.')),
                  ])
                : ListView.separated(
                    padding: const EdgeInsets.only(top: 6, bottom: 24),
                    itemCount: _paged.items.length,
                    separatorBuilder: (_, __) => const Divider(height: 1, indent: 72),
                    itemBuilder: (_, i) {
                      final n = _paged.items[i];
                      final style = _categoryStyle[n['category']] ?? (Icons.notifications_rounded, AppColors.primary);
                      final unread = n['read'] != true;
                      return Dismissible(
                        key: ValueKey('n${n['id']}'),
                        direction: DismissDirection.endToStart,
                        background: Container(
                          color: AppColors.danger,
                          alignment: AlignmentDirectional.centerEnd,
                          padding: const EdgeInsets.symmetric(horizontal: 20),
                          child: const Icon(Icons.delete_outline_rounded, color: Colors.white),
                        ),
                        onDismissed: (_) => _delete(n),
                        child: ListTile(
                          tileColor: unread ? AppColors.primary.withOpacity(0.06) : null,
                          contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
                          leading: CircleAvatar(radius: 22, backgroundColor: style.$2, child: Icon(style.$1, color: Colors.white, size: 22)),
                          title: Text(n['title'] ?? '', style: TextStyle(fontWeight: unread ? FontWeight.w700 : FontWeight.w500)),
                          subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            if ((n['body'] ?? '').toString().isNotEmpty) Text(n['body'], maxLines: 3, overflow: TextOverflow.ellipsis),
                            const SizedBox(height: 2),
                            Text(timeAgo(context, n['created_at'] as String?), style: const TextStyle(fontSize: 11.5)),
                          ]),
                          trailing: unread ? const CircleAvatar(radius: 5, backgroundColor: AppColors.primary) : null,
                          onTap: () => _open(n),
                        ),
                      );
                    },
                  ),
          ),
        ),
      ),
    ]);

    final actions = [
      if (_unread > 0)
        IconButton(tooltip: context.l('تحديد الكل كمقروء', 'Mark all read'), icon: const Icon(Icons.done_all_rounded), onPressed: _readAll),
    ];
    if (widget.embedded) return Scaffold(body: body, appBar: AppBar(title: Text(context.l('الإشعارات', 'Notifications')), actions: actions));
    return Scaffold(appBar: AppBar(title: Text(context.l('الإشعارات', 'Notifications')), actions: actions), body: body);
  }
}

/// Maps a site link from a notification to the matching app screen.
Widget? screenForUrl(String? url) {
  if (url == null || url.isEmpty) return null;
  final uri = Uri.tryParse(url);
  if (uri == null) return null;
  final path = uri.path;
  if (path.startsWith('/library/my-loans') || path.startsWith('/library/mine')) return const MyLoansScreen();
  if (path.startsWith('/library/manage') || path.startsWith('/library/counter')) return const LibraryDeskScreen();
  if (path.startsWith('/inbox') || path.startsWith('/contact/inbox')) {
    return InboxScreen(openId: int.tryParse(uri.queryParameters['open'] ?? ''));
  }
  if (path.startsWith('/contact')) return const ContactScreen();
  final folderId = int.tryParse(uri.queryParameters['folder'] ?? '') ??
      int.tryParse(RegExp(r'/folders/(\d+)').firstMatch(path)?.group(1) ?? '');
  if (folderId != null) {
    return BrowseScreen(folder: FolderItem(id: folderId, name: '', parentId: null, color: null, isPublic: false));
  }
  return null;
}
