import 'package:flutter/material.dart';

import '../../core/storage/preferences_storage.dart';

/// The user's chosen theme (light/dark/system), persisted across launches.
/// AppTheme.light()/dark() already existed from earlier work — this only
/// adds the missing piece: letting the user override the OS setting.
class ThemeProvider extends ChangeNotifier {
  ThemeMode _mode = ThemeMode.system;
  ThemeMode get mode => _mode;

  Future<void> bootstrap() async {
    final saved = await PreferencesStorage.instance.readThemeMode();
    switch (saved) {
      case 'light':
        _mode = ThemeMode.light;
        notifyListeners();
      case 'dark':
        _mode = ThemeMode.dark;
        notifyListeners();
      default:
        break;
    }
  }

  Future<void> setMode(ThemeMode mode) async {
    if (_mode == mode) return;
    _mode = mode;
    notifyListeners();
    await PreferencesStorage.instance.saveThemeMode(mode.name);
  }
}
