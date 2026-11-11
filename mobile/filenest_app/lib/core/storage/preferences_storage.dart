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

  // Offline reading (2026-11-11, by request), both on unless the user turns them off:
  // a file opened in the app stays saved for reading without internet, and the library
  // map is kept on the phone and refreshed when the internet is back.
  static const _keepOpenedKey = 'filenest_keep_opened_files';
  static const _offlineMapKey = 'filenest_offline_library_map';

  Future<bool> readKeepOpenedFiles() async => (await _storage.read(key: _keepOpenedKey)) != '0';
  Future<void> saveKeepOpenedFiles(bool on) => _storage.write(key: _keepOpenedKey, value: on ? '1' : '0');
  Future<bool> readOfflineMap() async => (await _storage.read(key: _offlineMapKey)) != '0';
  Future<void> saveOfflineMap(bool on) => _storage.write(key: _offlineMapKey, value: on ? '1' : '0');
}
