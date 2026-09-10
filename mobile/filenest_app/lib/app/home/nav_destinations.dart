import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../features/account/presentation/account_screen.dart';
import '../../features/admin/presentation/admin_home_screen.dart';
import '../../features/auth/state/auth_provider.dart';
import '../../features/security/presentation/security_screen.dart';
import '../../features/server_settings/presentation/server_settings_screen.dart';
import '../../features/trash/presentation/trash_screen.dart';

/// One entry in the app's secondary navigation — the destinations that
/// live in the sidebar [AppDrawer] at the root, and in the "more" bottom
/// sheet everywhere else (see docs/ARCHITECTURE.md for why there are two
/// surfaces for the same list instead of one).
class NavDestinationItem {
  const NavDestinationItem({
    required this.icon,
    required this.label,
    required this.onTap,
    this.isDestructive = false,
  });

  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final bool isDestructive;
}

/// The single source of truth for what those secondary destinations are.
/// Add a new settings-like screen here once and it appears in both the
/// drawer and the "more" sheet automatically.
List<NavDestinationItem> buildSecondaryDestinations(BuildContext context) {
  void push(Widget screen) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
  final isAdmin = context.read<AuthProvider>().user?.isAdmin ?? false;

  return [
    if (isAdmin)
      NavDestinationItem(
        icon: Icons.admin_panel_settings_outlined,
        label: 'الإدارة',
        onTap: () => push(const AdminHomeScreen()),
      ),
    NavDestinationItem(
      icon: Icons.shield_outlined,
      label: 'الأمان',
      onTap: () => push(const SecurityScreen()),
    ),
    NavDestinationItem(
      icon: Icons.person_outline_rounded,
      label: 'الحساب',
      onTap: () => push(const AccountScreen()),
    ),
    NavDestinationItem(
      icon: Icons.delete_outline_rounded,
      label: 'سلة المحذوفات',
      onTap: () => push(const TrashScreen()),
    ),
    NavDestinationItem(
      icon: Icons.dns_outlined,
      label: 'إعدادات الخادم',
      onTap: () => push(const ServerSettingsScreen()),
    ),
    NavDestinationItem(
      icon: Icons.logout_rounded,
      label: 'تسجيل الخروج',
      isDestructive: true,
      onTap: () => context.read<AuthProvider>().logout(),
    ),
  ];
}
