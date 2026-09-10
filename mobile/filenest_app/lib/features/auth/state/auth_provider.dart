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
      user = await _authService.me();
      status = AuthStatus.authenticated;
    } catch (_) {
      status = AuthStatus.unauthenticated;
    }
    notifyListeners();
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
    await _authService.logout();
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
