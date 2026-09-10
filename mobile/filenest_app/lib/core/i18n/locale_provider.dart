import 'package:flutter/material.dart';

import '../storage/preferences_storage.dart';

const List<Locale> supportedAppLocales = [Locale('ar'), Locale('en')];

/// The user's chosen display language, persisted across launches.
/// Defaults to Arabic (this app's primary language) rather than the
/// device locale, matching the web app's own default.
class LocaleProvider extends ChangeNotifier {
  /// Mirrors the current locale outside the widget tree, for the rare
  /// caller (e.g. ApiClient.messageFor) that has no BuildContext to read
  /// context.tr() from.
  static String currentLanguageCode = 'ar';

  Locale _locale = const Locale('ar');
  Locale get locale => _locale;

  Future<void> bootstrap() async {
    final saved = await PreferencesStorage.instance.readLocale();
    if (saved != null && supportedAppLocales.any((l) => l.languageCode == saved)) {
      _locale = Locale(saved);
      currentLanguageCode = saved;
      notifyListeners();
    }
  }

  Future<void> setLocale(String languageCode) async {
    if (_locale.languageCode == languageCode) return;
    _locale = Locale(languageCode);
    currentLanguageCode = languageCode;
    notifyListeners();
    await PreferencesStorage.instance.saveLocale(languageCode);
  }
}
