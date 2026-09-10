import '../../../core/network/api_client.dart';

class TwoFactorSetup {
  final String secret;
  final String qrCodeUri;

  TwoFactorSetup({required this.secret, required this.qrCodeUri});
}

/// Enable/confirm/disable/regenerate for the authenticated user's own 2FA —
/// distinct from AuthService.verifyTwoFactorChallenge, which handles the
/// mid-login step for an account that already has 2FA confirmed.
///
/// Every call here requires the account's current password (the API's
/// stateless equivalent of the web "confirm password" gate) — a stolen
/// bearer token alone must not be enough to take over 2FA.
class TwoFactorService {
  final _dio = ApiClient.instance.dio;

  Future<TwoFactorSetup> enable(String password) async {
    final response = await _dio.post('/user/two-factor-authentication', data: {'password': password});
    return TwoFactorSetup(
      secret: response.data['secret'] as String,
      qrCodeUri: response.data['qr_code_uri'] as String,
    );
  }

  Future<List<String>> confirm({required String code, required String password}) async {
    final response = await _dio.post('/user/confirmed-two-factor-authentication', data: {
      'code': code,
      'password': password,
    });
    return List<String>.from(response.data['recovery_codes'] as List);
  }

  Future<void> disable(String password) async {
    await _dio.delete('/user/two-factor-authentication', data: {'password': password});
  }

  Future<List<String>> regenerateRecoveryCodes(String password) async {
    final response = await _dio.post('/user/two-factor-recovery-codes', data: {'password': password});
    return List<String>.from(response.data['recovery_codes'] as List);
  }
}
