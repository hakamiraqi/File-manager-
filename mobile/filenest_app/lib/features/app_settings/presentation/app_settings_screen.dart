import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../app/state/theme_provider.dart';
import '../../../core/i18n/app_strings.dart';
import '../../../core/i18n/locale_provider.dart';
import '../../account/data/profile_service.dart';
import '../../auth/state/auth_provider.dart';

/// Lets the user switch the app's display language and force light/dark
/// instead of following the OS — the mobile counterpart of the web app's
/// Profile → "تفضيلات الواجهة" section and its theme toggle.
class AppSettingsScreen extends StatelessWidget {
  const AppSettingsScreen({super.key});

  /// The only language switch (2026-11-12; it was also under «الحساب»): changes the app at
  /// once and, when signed in, saves it on the account too, so emails and notifications follow.
  static Future<void> _setLanguage(BuildContext context, String code) async {
    final auth = context.read<AuthProvider>();
    await context.read<LocaleProvider>().setLocale(code);
    if (auth.user == null) return;
    try {
      await ProfileService().updatePreferences(locale: code);
      await auth.refreshProfile();
    } catch (_) {
      // Offline: the app still switches; the account catches up next time it's chosen.
    }
  }

  @override
  Widget build(BuildContext context) {
    final localeProvider = context.watch<LocaleProvider>();
    final themeProvider = context.watch<ThemeProvider>();

    return Scaffold(
      appBar: AppBar(title: Text(context.tr('settings.title'))),
      body: ListView(
        children: [
          _SectionHeader(title: context.tr('settings.language')),
          RadioListTile<String>(
            title: Text(context.tr('settings.language_ar')),
            value: 'ar',
            groupValue: localeProvider.locale.languageCode,
            onChanged: (value) => _setLanguage(context, value!),
          ),
          RadioListTile<String>(
            title: Text(context.tr('settings.language_en')),
            value: 'en',
            groupValue: localeProvider.locale.languageCode,
            onChanged: (value) => _setLanguage(context, value!),
          ),
          const Divider(height: 32),
          _SectionHeader(title: context.tr('settings.theme')),
          RadioListTile<ThemeMode>(
            title: Text(context.tr('settings.theme_light')),
            value: ThemeMode.light,
            groupValue: themeProvider.mode,
            onChanged: (value) => context.read<ThemeProvider>().setMode(value!),
          ),
          RadioListTile<ThemeMode>(
            title: Text(context.tr('settings.theme_dark')),
            value: ThemeMode.dark,
            groupValue: themeProvider.mode,
            onChanged: (value) => context.read<ThemeProvider>().setMode(value!),
          ),
          RadioListTile<ThemeMode>(
            title: Text(context.tr('settings.theme_system')),
            value: ThemeMode.system,
            groupValue: themeProvider.mode,
            onChanged: (value) => context.read<ThemeProvider>().setMode(value!),
          ),
        ],
      ),
    );
  }
}

class _SectionHeader extends StatelessWidget {
  const _SectionHeader({required this.title});

  final String title;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
      child: Text(
        title,
        style: Theme.of(context).textTheme.labelLarge?.copyWith(color: Theme.of(context).colorScheme.primary),
      ),
    );
  }
}
