import 'dart:io';

import '../../../core/models/user.dart';
import '../../../core/network/api_client.dart';
import '../../../core/storage/token_storage.dart';
import '../models/login_result.dart';

class AuthService {
  final _dio = ApiClient.instance.dio;

  Future<FileNestUser> register({
    required String name,
    required String email,
    required String password,
  }) async {
    final response = await _dio.post('/auth/register', data: {
      'name': name,
      'email': email,
      'password': password,
      'password_confirmation': password,
      'device_name': await _deviceName(),
    });

    await TokenStorage.instance.save(response.data['token'] as String);
    return FileNestUser.fromJson(response.data['user'] as Map<String, dynamic>);
  }

  /// Returns a completed [LoginResult] with the token already stored, or a
  /// pending one (2FA enabled) that verifyTwoFactorChallenge() must be
  /// called with next — no token exists yet in that case.
  Future<LoginResult> login({
    required String email,
    required String password,
  }) async {
    final response = await _dio.post('/auth/login', data: {
      'email': email,
      'password': password,
      'device_name': await _deviceName(),
    });

    if (response.data['two_factor_required'] == true) {
      return LoginResult.twoFactorRequired(response.data['two_factor_token'] as String);
    }

    await TokenStorage.instance.save(response.data['token'] as String);
    return LoginResult.completed(FileNestUser.fromJson(response.data['user'] as Map<String, dynamic>));
  }

  /// Completes a login that returned twoFactorRequired, with either a TOTP
  /// [code] or a single-use [recoveryCode].
  Future<FileNestUser> verifyTwoFactorChallenge({
    required String twoFactorToken,
    String? code,
    String? recoveryCode,
  }) async {
    final response = await _dio.post('/auth/two-factor-challenge', data: {
      'two_factor_token': twoFactorToken,
      if (code != null) 'code': code,
      if (recoveryCode != null) 'recovery_code': recoveryCode,
    });

    await TokenStorage.instance.save(response.data['token'] as String);
    return FileNestUser.fromJson(response.data['user'] as Map<String, dynamic>);
  }

  Future<void> logout() async {
    try {
      await _dio.post('/auth/logout');
    } finally {
      await TokenStorage.instance.clear();
    }
  }

  Future<FileNestUser> me() async {
    final response = await _dio.get('/auth/me');
    return FileNestUser.fromJson(response.data['user'] as Map<String, dynamic>);
  }

  Future<bool> hasStoredToken() async => (await TokenStorage.instance.read()) != null;

  /// Sends the account's password-reset email — same Laravel password
  /// broker the web "forgot password" form uses. The emailed link opens
  /// the web reset page by default (works fine from a phone's browser);
  /// resetPassword() below exists for a client that wants to handle the
  /// emailed token itself instead.
  Future<void> sendPasswordResetLink(String email) async {
    await _dio.post('/password/forgot-password', data: {'email': email});
  }

  /// Completes a reset with the token from the emailed link, pasted or
  /// deep-linked back into the app.
  Future<void> resetPassword({
    required String email,
    required String token,
    required String password,
  }) async {
    await _dio.post('/password/reset', data: {
      'email': email,
      'token': token,
      'password': password,
      'password_confirmation': password,
    });
  }

  Future<String> _deviceName() async {
    try {
      return '${Platform.operatingSystem}-flutter-app';
    } catch (_) {
      return 'flutter-app';
    }
  }
}
