import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../../../core/models/user.dart';
import '../../../core/network/server_config_service.dart';
import '../../account/data/profile_service.dart';
import '../data/auth_service.dart';
import '../models/login_result.dart';

enum AuthStatus { unknown, authenticated, unauthenticated }

class AuthProvider extends ChangeNotifier {
  final _authService = AuthService();
  final _profileService = ProfileService();

  AuthStatus status = AuthStatus.unknown;
  FileNestUser? user;

  Future<void> bootstrap() async {
    // Decide local-network vs. hosted server *before* the first API call
    // (including the "am I still logged in?" check right below), so a
    // phone that's on the same LAN as the server never touches the
    // internet at all when the mode is auto or local-only.
    await ServerConfigService.instance.resolveAndApply();

    if (!await _authService.hasStoredToken()) {
      status = AuthStatus.unauthenticated;
      notifyListeners();
      return;
    }

    try {
      // Not long to wait without internet: the saved session opens instead.
      user = await _authService.me().timeout(const Duration(seconds: 8));
      status = AuthStatus.authenticated;
      offline = false;
    } catch (e) {
      // No internet (or the server is down): open with the saved session instead of the
      // login screen. Only the server saying the sign-in is no longer valid signs out.
      final rejected = e is DioException && (e.response?.statusCode == 401 || e.response?.statusCode == 403);
      final saved = rejected ? null : await _authService.cachedUser();
      if (saved != null) {
        user = saved;
        status = AuthStatus.authenticated;
        offline = true;
        _retryWhenOnline();
      } else {
        status = AuthStatus.unauthenticated;
      }
    }
    notifyListeners();
  }

  /// Opened with the saved session (no internet): true until the server answers again.
  bool offline = false;
  Timer? _retry;

  void _retryWhenOnline() {
    _retry?.cancel();
    _retry = Timer.periodic(const Duration(seconds: 30), (_) async {
      try {
        await ServerConfigService.instance.resolveAndApply();
        user = await _authService.me();
        offline = false;
        _retry?.cancel();
        notifyListeners();
      } catch (e) {
        if (e is DioException && e.response?.statusCode == 401) {
          _retry?.cancel();
          await logout();
        }
      }
    });
  }

  /// Returns the raw result so the caller can navigate to the 2FA
  /// challenge screen when required — only a completed login updates this
  /// provider's authenticated state.
  Future<LoginResult> login(String email, String password) async {
    final result = await _authService.login(email: email, password: password);

    if (!result.twoFactorRequired) {
      user = result.user;
      status = AuthStatus.authenticated;
      notifyListeners();
    }

    return result;
  }

  Future<void> completeTwoFactorLogin({
    required String twoFactorToken,
    String? code,
    String? recoveryCode,
  }) async {
    user = await _authService.verifyTwoFactorChallenge(
      twoFactorToken: twoFactorToken,
      code: code,
      recoveryCode: recoveryCode,
    );
    status = AuthStatus.authenticated;
    notifyListeners();
  }

  Future<void> register(String name, String email, String password) async {
    user = await _authService.register(name: name, email: email, password: password);
    status = AuthStatus.authenticated;
    notifyListeners();
  }

  Future<void> refreshProfile() async {
    user = await _authService.me();
    notifyListeners();
  }

  Future<void> logout() async {
    _retry?.cancel();
    await _authService.forgetUser();
    try {
      await _authService.logout();
    } catch (_) {}
    user = null;
    status = AuthStatus.unauthenticated;
    notifyListeners();
  }

  /// Throws on a wrong password without changing any local state — only
  /// a confirmed server-side deletion signs the app out.
  Future<void> deleteAccount(String password) async {
    await _profileService.deleteAccount(password);
    user = null;
    status = AuthStatus.unauthenticated;
    notifyListeners();
  }
}
