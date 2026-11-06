import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/i18n/app_strings.dart';
import '../../core/widgets/ui_kit.dart';
import '../../features/files/presentation/browse_screen.dart';
import '../../features/library/presentation/library_screen.dart';
import '../../features/notifications/presentation/notifications_screen.dart';
import '../../features/profile/presentation/profile_screen.dart';
import '../../features/saved/presentation/saved_screen.dart';
import '../../features/site/badges_provider.dart';
import '../../features/theses/presentation/theses_screen.dart';
import 'app_drawer.dart';

/// The app's root screen after login, laid out like Telegram: five tabs at the
/// bottom (files, library, theses, notifications, me), each keeping its state.
/// Everything else lives in «حسابي» and in the drawer of the files tab.
class HomeShell extends StatefulWidget {
  const HomeShell({super.key});

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  int _tabIndex = 0;
  final _visited = <int>{0};
  final _notificationsKey = GlobalKey<NotificationsScreenState>();
  BadgesProvider? _badges;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _badges = context.read<BadgesProvider>()..start();
    });
  }

  @override
  void dispose() {
    _badges?.stop();
    super.dispose();
  }

  void _select(int index) {
    if (index == _tabIndex && index == 3) _notificationsKey.currentState?.reload();
    setState(() {
      _tabIndex = index;
      _visited.add(index);
    });
  }

  @override
  Widget build(BuildContext context) {
    final badges = context.watch<BadgesProvider>();

    // Tabs are built the first time they are opened, then kept alive.
    Widget lazy(int i, Widget Function() build) => _visited.contains(i) ? build() : const SizedBox.shrink();

    return Scaffold(
      body: IndexedStack(
        index: _tabIndex,
        children: [
          Scaffold(
            appBar: AppBar(
              title: Text(context.tr('nav.dashboard')),
              actions: [
                IconButton(
                  tooltip: context.l('المحفوظات والتنزيلات', 'Saved & downloads'),
                  icon: const Icon(Icons.bookmark_border_rounded),
                  onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const SavedScreen())),
                ),
              ],
            ),
            drawer: const AppDrawer(),
            body: const BrowseScreen(embedded: true),
          ),
          lazy(1, () => const LibraryScreen()),
          lazy(2, () => const ThesesScreen()),
          lazy(3, () => NotificationsScreen(key: _notificationsKey, embedded: true)),
          lazy(4, () => const ProfileScreen()),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tabIndex,
        onDestinationSelected: _select,
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
        destinations: [
          NavigationDestination(
            icon: const Icon(Icons.folder_outlined),
            selectedIcon: const Icon(Icons.folder_rounded),
            label: context.l('الملفات', 'Files'),
          ),
          NavigationDestination(
            icon: const Icon(Icons.local_library_outlined),
            selectedIcon: const Icon(Icons.local_library_rounded),
            label: context.l('المكتبة', 'Library'),
          ),
          NavigationDestination(
            icon: const Icon(Icons.school_outlined),
            selectedIcon: const Icon(Icons.school_rounded),
            label: context.l('الأطاريح', 'Theses'),
          ),
          NavigationDestination(
            icon: Badge(
              isLabelVisible: badges.notifications > 0,
              label: Text(badges.notifications > 99 ? '99+' : '${badges.notifications}'),
              child: const Icon(Icons.notifications_none_rounded),
            ),
            selectedIcon: Badge(
              isLabelVisible: badges.notifications > 0,
              label: Text(badges.notifications > 99 ? '99+' : '${badges.notifications}'),
              child: const Icon(Icons.notifications_rounded),
            ),
            label: context.l('الإشعارات', 'Alerts'),
          ),
          NavigationDestination(
            icon: Badge(
              isLabelVisible: badges.contactNew > 0,
              smallSize: 9,
              child: const Icon(Icons.account_circle_outlined),
            ),
            selectedIcon: const Icon(Icons.account_circle_rounded),
            label: context.l('حسابي', 'Me'),
          ),
        ],
      ),
    );
  }
}
