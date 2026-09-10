import 'package:flutter/material.dart';

import '../../features/downloads/presentation/downloads_screen.dart';
import '../../features/files/presentation/browse_screen.dart';
import '../../features/shares/presentation/shares_screen.dart';
import 'app_drawer.dart';
import 'more_destinations_sheet.dart';

/// The app's root screen after login: a bottom [NavigationBar] for the
/// three primary destinations (tabs, as requested) plus a sidebar
/// [AppDrawer] for everything secondary (security, account, trash, server
/// settings, logout). All three tab bodies stay mounted via [IndexedStack]
/// so switching tabs never re-fetches or loses scroll position — the same
/// "tabs keep their state" behaviour Telegram's own chat/contacts/settings
/// tabs have.
class HomeShell extends StatefulWidget {
  const HomeShell({super.key});

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  int _tabIndex = 0;

  static const _titles = ['ملفاتي', 'التنزيلات', 'المشاركة'];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(_titles[_tabIndex]),
        actions: [
          IconButton(
            icon: const Icon(Icons.more_vert_rounded),
            tooltip: 'المزيد',
            onPressed: () => MoreDestinationsSheet.show(context),
          ),
        ],
      ),
      drawer: const AppDrawer(),
      body: IndexedStack(
        index: _tabIndex,
        children: const [
          BrowseScreen(embedded: true),
          DownloadsScreen(),
          SharesScreen(embedded: true),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tabIndex,
        onDestinationSelected: (index) => setState(() => _tabIndex = index),
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.folder_outlined),
            selectedIcon: Icon(Icons.folder_rounded),
            label: 'ملفاتي',
          ),
          NavigationDestination(
            icon: Icon(Icons.download_for_offline_outlined),
            selectedIcon: Icon(Icons.download_for_offline_rounded),
            label: 'التنزيلات',
          ),
          NavigationDestination(
            icon: Icon(Icons.link_outlined),
            selectedIcon: Icon(Icons.link_rounded),
            label: 'المشاركة',
          ),
        ],
      ),
    );
  }
}
