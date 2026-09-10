import '../../../core/models/user.dart';

/// Either a completed login (token already stored, [user] populated) or a
/// pending one that still needs a 2FA code/recovery code before a token is
/// issued (see AuthService.verifyTwoFactorChallenge).
class LoginResult {
  final bool twoFactorRequired;
  final FileNestUser? user;
  final String? twoFactorToken;

  LoginResult.completed(FileNestUser this.user)
      : twoFactorRequired = false,
        twoFactorToken = null;

  LoginResult.twoFactorRequired(String this.twoFactorToken)
      : twoFactorRequired = true,
        user = null;
}
