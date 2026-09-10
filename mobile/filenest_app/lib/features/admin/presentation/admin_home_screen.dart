import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/i18n/app_strings.dart';
import '../../auth/state/auth_provider.dart';
import 'activity_log_screen.dart';
import 'analytics_screen.dart';
import 'dashboard_screen.dart';
import 'mail_screen.dart';
import 'permissions_screen.dart';
import 'security_screen.dart';
import 'settings_screen.dart';
import 'translations_screen.dart';
import 'users_screen.dart';

class _AdminSection {
  const _AdminSection({required this.icon, required this.label, required this.builder, this.enabled = true});

  final IconData icon;
  final String label;
  final WidgetBuilder builder;
  final bool enabled;
}

/// The admin panel's own hub, reached only by an admin account — mirrors
/// AdminLayout's sidebar on the web (each item there is a full page here).
/// Every section beyond the dashboard is shown only if this admin's own
/// capability flags allow it, exactly like the web sidebar links only
/// appearing when `permissions.admin.*` is true.
class AdminHomeScreen extends StatelessWidget {
  const AdminHomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().user;
    final admin = user?.admin;

    if (admin == null) {
      return Scaffold(body: Center(child: Text(context.tr('admin.no_access'))));
    }

    final sections = <_AdminSection>[
      _AdminSection(icon: Icons.dashboard_outlined, label: context.tr('admin.nav.dashboard'), builder: (_) => const DashboardScreen()),
      _AdminSection(
        icon: Icons.people_outline_rounded,
        label: context.tr('admin.nav.users'),
        builder: (_) => const UsersScreen(),
        enabled: admin.users,
      ),
      _AdminSection(
        icon: Icons.tune_rounded,
        label: context.tr('admin.nav.settings'),
        builder: (_) => const AdminSettingsScreen(),
        enabled: admin.settingsPage,
      ),
      _AdminSection(
        icon: Icons.shield_outlined,
        label: context.tr('admin.nav.permissions'),
        builder: (_) => const PermissionsScreen(),
        enabled: admin.settings,
      ),
      _AdminSection(
        icon: Icons.lock_outline_rounded,
        label: context.tr('admin.nav.security'),
        builder: (_) => const AdminSecurityScreen(),
        enabled: admin.settings,
      ),
      _AdminSection(
        icon: Icons.mail_outline_rounded,
        label: context.tr('admin.nav.mail'),
        builder: (_) => const MailScreen(),
        enabled: admin.settings,
      ),
      _AdminSection(
        icon: Icons.translate_rounded,
        label: context.tr('admin.nav.translations'),
        builder: (_) => const TranslationsScreen(),
        enabled: admin.translations,
      ),
      _AdminSection(
        icon: Icons.history_rounded,
        label: context.tr('admin.nav.activity'),
        builder: (_) => const ActivityLogScreen(),
        enabled: admin.statistics,
      ),
      _AdminSection(
        icon: Icons.bar_chart_rounded,
        label: context.tr('admin.nav.analytics'),
        builder: (_) => const AnalyticsScreen(),
        enabled: admin.statistics,
      ),
    ].where((s) => s.enabled).toList();

    return Scaffold(
      appBar: AppBar(title: Text(context.tr('nav.admin'))),
      body: ListView.separated(
        padding: const EdgeInsets.symmetric(vertical: 8),
        itemCount: sections.length,
        separatorBuilder: (_, __) => const Divider(height: 1),
        itemBuilder: (context, index) {
          final section = sections[index];
          return ListTile(
            leading: Icon(section.icon),
            title: Text(section.label),
            trailing: const Icon(Icons.chevron_left_rounded),
            onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: section.builder)),
          );
        },
      ),
    );
  }
}
