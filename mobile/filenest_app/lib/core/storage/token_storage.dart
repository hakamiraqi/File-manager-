import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Wraps the platform keystore/keychain so the Sanctum bearer token never
/// touches plain SharedPreferences.
class TokenStorage {
  TokenStorage._();
  static final TokenStorage instance = TokenStorage._();

  final _storage = const FlutterSecureStorage();
  static const _tokenKey = 'filenest_api_token';

  Future<void> save(String token) => _storage.write(key: _tokenKey, value: token);

  Future<String?> read() => _storage.read(key: _tokenKey);

  Future<void> clear() => _storage.delete(key: _tokenKey);
}
