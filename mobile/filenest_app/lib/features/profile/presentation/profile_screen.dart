import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../app/theme/app_colors.dart';
import '../../../core/i18n/app_strings.dart';
import '../../../core/widgets/ui_kit.dart';
import '../../account/presentation/account_screen.dart';
import '../../admin/presentation/admin_home_screen.dart';
import '../../app_settings/presentation/app_settings_screen.dart';
import '../../auth/state/auth_provider.dart';
import '../../contact/presentation/contact_screen.dart';
import '../../contact/presentation/inbox_screen.dart';
import '../../downloads/presentation/downloads_screen.dart';
import '../../library/presentation/library_desk_screen.dart';
import '../../library/presentation/my_loans_screen.dart';
import '../../playlists/presentation/playlists_screen.dart';
import '../../saved/presentation/saved_screen.dart';
import '../../security/presentation/security_screen.dart';
import '../../server_settings/presentation/server_settings_screen.dart';
import '../../shares/presentation/shares_screen.dart';
import '../../site/badges_provider.dart';
import '../../site/site_api.dart';
import '../../trash/presentation/trash_screen.dart';

/// «حسابي»: the settings-style hub (like Telegram's Settings tab) with the
/// profile picture on top and everything else grouped below.
class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  bool _uploading = false;

  void _push(Widget screen) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));

  Future<void> _changePhoto({required bool hasPhoto}) async {
    final action = await showModalBottomSheet<String>(
      context: context,
      useRootNavigator: true,
      builder: (sheet) => SafeArea(
        child: Wrap(children: [
          ListTile(
            leading: const Icon(Icons.photo_library_outlined),
            title: Text(context.l('اختيار صورة', 'Choose a photo')),
            onTap: () => Navigator.of(sheet).pop('pick'),
          ),
          if (hasPhoto)
            ListTile(
              leading: const Icon(Icons.delete_outline_rounded, color: AppColors.danger),
              title: Text(context.l('حذف الصورة', 'Remove photo'), style: const TextStyle(color: AppColors.danger)),
              onTap: () => Navigator.of(sheet).pop('remove'),
            ),
        ]),
      ),
    );
    if (action == null || !mounted) return;
    setState(() => _uploading = true);
    try {
      if (action == 'pick') {
        final picked = await FilePicker.platform.pickFiles(type: FileType.image);
        final path = picked?.files.single.path;
        if (path == null) return;
        await SiteApi.instance.uploadAvatar(path);
      } else {
        await SiteApi.instance.deleteAvatar();
      }
      if (mounted) await context.read<AuthProvider>().refreshProfile();
    } catch (e) {
      if (mounted) context.toastError(e);
    } finally {
      if (mounted) setState(() => _uploading = false);
    }
  }

  Future<void> _logout() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text(context.l('تسجيل الخروج؟', 'Log out?')),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: Text(context.l('إلغاء', 'Cancel'))),
          TextButton(onPressed: () => Navigator.of(context).pop(true), child: Text(context.l('خروج', 'Log out'), style: const TextStyle(color: AppColors.danger))),
        ],
      ),
    );
    if (ok == true && mounted) context.read<AuthProvider>().logout();
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().user;
    final badges = context.watch<BadgesProvider>();
    if (user == null) return const SizedBox.shrink();
    final scheme = Theme.of(context).colorScheme;
    final usage = user.storageUsagePercent;

    return Scaffold(
      appBar: AppBar(title: Text(context.l('حسابي', 'Me'))),
      body: ListView(padding: const EdgeInsets.only(bottom: 32), children: [
        const SizedBox(height: 8),
        Center(
          child: Stack(children: [
            GestureDetector(
              onTap: _uploading ? null : () => _changePhoto(hasPhoto: (user.avatarUrl ?? '').isNotEmpty),
              child: Avatar(name: user.name, url: user.avatarUrl, radius: 48),
            ),
            PositionedDirectional(
              end: 0,
              bottom: 0,
              child: Material(
                color: AppColors.primary,
                shape: const CircleBorder(side: BorderSide(color: Colors.white, width: 2)),
                child: InkWell(
                  customBorder: const CircleBorder(),
                  onTap: _uploading ? null : () => _changePhoto(hasPhoto: (user.avatarUrl ?? '').isNotEmpty),
                  child: Padding(
                    padding: const EdgeInsets.all(7),
                    child: _uploading
                        ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                        : const Icon(Icons.photo_camera_rounded, size: 16, color: Colors.white),
                  ),
                ),
              ),
            ),
          ]),
        ),
        const SizedBox(height: 10),
        Text(user.name, textAlign: TextAlign.center, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700)),
        Text(user.email, textAlign: TextAlign.center, style: TextStyle(color: scheme.onSurfaceVariant)),
        Padding(
          padding: const EdgeInsets.fromLTRB(48, 12, 48, 0),
          child: Column(children: [
            ClipRRect(
              borderRadius: BorderRadius.circular(4),
              child: LinearProgressIndicator(
                value: (usage / 100).clamp(0.0, 1.0),
                minHeight: 6,
                backgroundColor: scheme.surfaceContainerHighest,
                color: usage > 90 ? AppColors.danger : AppColors.primary,
              ),
            ),
            const SizedBox(height: 4),
            Text(context.tr('storage.usage_percent', {'percent': usage.toStringAsFixed(1)}), style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant)),
          ]),
        ),
        GroupCard(title: context.l('ملفاتي', 'My files'), children: [
          NavRow(icon: Icons.bookmark_rounded, color: const Color(0xFFF5A623), label: context.l('المحفوظات والتنزيلات', 'Saved & downloads'), onTap: () => _push(const SavedScreen())),
          NavRow(
            icon: Icons.download_for_offline_rounded,
            color: const Color(0xFF00897B),
            label: context.l('الملفات بدون إنترنت', 'Offline files'),
            onTap: () => _push(Scaffold(appBar: AppBar(title: Text(context.tr('nav.downloads'))), body: const DownloadsScreen())),
          ),
          NavRow(icon: Icons.link_rounded, color: AppColors.primary, label: context.tr('nav.shares'), onTap: () => _push(const SharesScreen())),
          NavRow(icon: Icons.queue_music_rounded, color: const Color(0xFFEE7AAE), label: context.tr('nav.playlists'), onTap: () => _push(const PlaylistsScreen())),
          NavRow(icon: Icons.delete_rounded, color: Colors.blueGrey, label: context.tr('nav.trash'), onTap: () => _push(const TrashScreen())),
        ]),
        GroupCard(title: context.l('المكتبة والتواصل', 'Library & contact'), children: [
          NavRow(icon: Icons.bookmark_added_rounded, color: const Color(0xFF7E57C2), label: context.l('استعاراتي', 'My loans'), onTap: () => _push(const MyLoansScreen())),
          if (user.manageLibrary)
            NavRow(icon: Icons.qr_code_scanner_rounded, color: const Color(0xFF5C6BC0), label: context.l('مكتب الإعارة', 'Lending desk'), onTap: () => _push(const LibraryDeskScreen())),
          NavRow(icon: Icons.forum_rounded, color: const Color(0xFF26A69A), label: context.l('تواصل معنا', 'Contact us'), onTap: () => _push(const ContactScreen())),
          if (user.manageContact)
            NavRow(
              icon: Icons.inbox_rounded,
              color: const Color(0xFFEF6C00),
              label: context.l('رسائل التواصل', 'Contact inbox'),
              badge: badges.contactNew,
              onTap: () => _push(const InboxScreen()),
            ),
        ]),
        GroupCard(title: context.l('الإعدادات', 'Settings'), children: [
          if (user.isAdmin)
            NavRow(icon: Icons.admin_panel_settings_rounded, color: const Color(0xFFE53935), label: context.tr('nav.admin'), onTap: () => _push(const AdminHomeScreen())),
          NavRow(icon: Icons.person_rounded, color: const Color(0xFF42A5F5), label: context.tr('nav.account'), onTap: () => _push(const AccountScreen())),
          NavRow(
            icon: Icons.shield_rounded,
            color: const Color(0xFF66BB6A),
            label: context.tr('nav.security'),
            subtitle: user.twoFactorEnabled ? context.l('التحقق بخطوتين مفعّل', 'Two-factor on') : null,
            onTap: () => _push(const SecurityScreen()),
          ),
          NavRow(icon: Icons.palette_rounded, color: const Color(0xFFAB47BC), label: context.tr('nav.app_settings'), onTap: () => _push(const AppSettingsScreen())),
          NavRow(icon: Icons.dns_rounded, color: const Color(0xFF78909C), label: context.tr('nav.server_settings'), onTap: () => _push(const ServerSettingsScreen())),
        ]),
        GroupCard(children: [
          NavRow(icon: Icons.logout_rounded, color: AppColors.danger, label: context.tr('nav.logout'), destructive: true, onTap: _logout),
        ]),
      ]),
    );
  }
}
