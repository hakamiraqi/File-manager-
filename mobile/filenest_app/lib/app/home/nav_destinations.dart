import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/i18n/app_strings.dart';
import '../../features/account/presentation/account_screen.dart';
import '../../features/admin/presentation/admin_home_screen.dart';
import '../../features/app_settings/presentation/app_settings_screen.dart';
import '../../features/auth/state/auth_provider.dart';
import '../../features/contact/presentation/contact_screen.dart';
import '../../features/lectures/presentation/lectures_screen.dart';
import '../../features/library/presentation/library_map_screen.dart';
import '../../features/library/presentation/my_loans_screen.dart';
import '../../features/playlists/presentation/playlists_screen.dart';
import '../../features/saved/presentation/saved_screen.dart';
import '../../core/widgets/ui_kit.dart';
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
        label: context.tr('nav.admin'),
        onTap: () => push(const AdminHomeScreen()),
      ),
    NavDestinationItem(
      icon: Icons.bookmark_border_rounded,
      label: context.l('المحفوظات والتنزيلات', 'Saved & downloads'),
      onTap: () => push(const SavedScreen()),
    ),
    NavDestinationItem(
      icon: Icons.video_library_outlined,
      label: context.l('المحاضرات المرئية', 'Video lectures'),
      onTap: () => push(const LecturesScreen()),
    ),
    NavDestinationItem(
      icon: Icons.map_outlined,
      label: context.l('خريطة المكتبة', 'Library map'),
      onTap: () => push(const LibraryMapScreen()),
    ),
    NavDestinationItem(
      icon: Icons.bookmark_added_outlined,
      label: context.l('استعاراتي', 'My loans'),
      onTap: () => push(const MyLoansScreen()),
    ),
    NavDestinationItem(
      icon: Icons.forum_outlined,
      label: context.l('تواصل معنا', 'Contact us'),
      onTap: () => push(const ContactScreen()),
    ),
    NavDestinationItem(
      icon: Icons.queue_music_rounded,
      label: context.tr('nav.playlists'),
      onTap: () => push(const PlaylistsScreen()),
    ),
    NavDestinationItem(
      icon: Icons.shield_outlined,
      label: context.tr('nav.security'),
      onTap: () => push(const SecurityScreen()),
    ),
    NavDestinationItem(
      icon: Icons.person_outline_rounded,
      label: context.tr('nav.account'),
      onTap: () => push(const AccountScreen()),
    ),
    NavDestinationItem(
      icon: Icons.delete_outline_rounded,
      label: context.tr('nav.trash'),
      onTap: () => push(const TrashScreen()),
    ),
    NavDestinationItem(
      icon: Icons.language_rounded,
      label: context.tr('nav.app_settings'),
      onTap: () => push(const AppSettingsScreen()),
    ),
    NavDestinationItem(
      icon: Icons.dns_outlined,
      label: context.tr('nav.server_settings'),
      onTap: () => push(const ServerSettingsScreen()),
    ),
    NavDestinationItem(
      icon: Icons.logout_rounded,
      label: context.tr('nav.logout'),
      isDestructive: true,
      onTap: () => context.read<AuthProvider>().logout(),
    ),
  ];
}
