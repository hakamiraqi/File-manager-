import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../app/state/theme_provider.dart';
import '../../../core/i18n/app_strings.dart';
import '../../../core/i18n/locale_provider.dart';

/// Lets the user switch the app's display language and force light/dark
/// instead of following the OS — the mobile counterpart of the web app's
/// Profile → "تفضيلات الواجهة" section and its theme toggle.
class AppSettingsScreen extends StatelessWidget {
  const AppSettingsScreen({super.key});

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
            onChanged: (value) => context.read<LocaleProvider>().setLocale(value!),
          ),
          RadioListTile<String>(
            title: Text(context.tr('settings.language_en')),
            value: 'en',
            groupValue: localeProvider.locale.languageCode,
            onChanged: (value) => context.read<LocaleProvider>().setLocale(value!),
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
