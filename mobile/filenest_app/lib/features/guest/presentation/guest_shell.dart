import 'package:flutter/material.dart';

import '../../../core/widgets/ui_kit.dart';
import '../../contact/presentation/contact_screen.dart';
import '../../library/presentation/library_screen.dart';
import '../../theses/presentation/theses_screen.dart';

/// The visitors' app: the parts of the site that are open without an account
/// (library, theses, contact us) as bottom tabs, plus a way back to sign in.
class GuestShell extends StatefulWidget {
  const GuestShell({super.key, required this.site, this.initial = 'library'});

  /// /public/site: which sections are on.
  final Map<String, dynamic> site;
  final String initial;

  @override
  State<GuestShell> createState() => _GuestShellState();
}

class _GuestShellState extends State<GuestShell> {
  late final List<String> _tabs = [
    if (widget.site['library'] == true) 'library',
    if (widget.site['theses'] == true) 'theses',
    if (widget.site['contact'] == true) 'contact',
  ];
  late int _index = _tabs.indexOf(widget.initial).clamp(0, _tabs.isEmpty ? 0 : _tabs.length - 1);
  late final Set<int> _visited = {_index};

  Widget _screen(String key) => switch (key) {
        'library' => const LibraryScreen(guest: true),
        'theses' => ThesesScreen(contactEnabled: widget.site['contact'] == true),
        _ => const ContactScreen(),
      };

  @override
  Widget build(BuildContext context) {
    if (_tabs.isEmpty) {
      return Scaffold(appBar: AppBar(), body: Center(child: Text(context.l('واجهة الزوار مغلقة حالياً.', 'The visitors\' area is closed.'))));
    }
    return Scaffold(
      body: IndexedStack(
        index: _index,
        children: [for (var i = 0; i < _tabs.length; i++) _visited.contains(i) ? _screen(_tabs[i]) : const SizedBox.shrink()],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
        onDestinationSelected: (i) {
          if (i == _tabs.length) {
            Navigator.of(context).pop();
            return;
          }
          setState(() {
            _index = i;
            _visited.add(i);
          });
        },
        destinations: [
          for (final t in _tabs)
            switch (t) {
              'library' => NavigationDestination(
                  icon: const Icon(Icons.local_library_outlined),
                  selectedIcon: const Icon(Icons.local_library_rounded),
                  label: context.l('المكتبة', 'Library'),
                ),
              'theses' => NavigationDestination(
                  icon: const Icon(Icons.school_outlined),
                  selectedIcon: const Icon(Icons.school_rounded),
                  label: context.l('الأطاريح', 'Theses'),
                ),
              _ => NavigationDestination(
                  icon: const Icon(Icons.forum_outlined),
                  selectedIcon: const Icon(Icons.forum_rounded),
                  label: context.l('تواصل معنا', 'Contact'),
                ),
            },
          NavigationDestination(icon: const Icon(Icons.login_rounded), label: context.l('دخول', 'Sign in')),
        ],
      ),
    );
  }
}
