import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Persists small UI preferences (locale, theme mode) — reuses the same
/// secure-storage backend as TokenStorage rather than adding a new
/// shared_preferences dependency for two string values.
class PreferencesStorage {
  PreferencesStorage._();
  static final PreferencesStorage instance = PreferencesStorage._();

  final _storage = const FlutterSecureStorage();
  static const _localeKey = 'filenest_locale';
  static const _themeModeKey = 'filenest_theme_mode';

  Future<String?> readLocale() => _storage.read(key: _localeKey);

  Future<void> saveLocale(String languageCode) => _storage.write(key: _localeKey, value: languageCode);

  Future<String?> readThemeMode() => _storage.read(key: _themeModeKey);

  Future<void> saveThemeMode(String mode) => _storage.write(key: _themeModeKey, value: mode);
}
