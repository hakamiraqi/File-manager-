import '../../../core/models/user.dart';
import '../../../core/network/api_client.dart';
import '../../../core/storage/token_storage.dart';

/// Account-management calls — distinct from AuthService, which only
/// handles establishing/ending a session (login/register/logout/2FA).
class ProfileService {
  final _dio = ApiClient.instance.dio;

  Future<FileNestUser> updateProfile({String? name, String? email}) async {
    final response = await _dio.put('/profile', data: {
      if (name != null) 'name': name,
      if (email != null) 'email': email,
    });
    return FileNestUser.fromJson(response.data['user'] as Map<String, dynamic>);
  }

  Future<FileNestUser> updatePreferences({required String locale}) async {
    final response = await _dio.patch('/profile/preferences', data: {'locale': locale});
    return FileNestUser.fromJson(response.data['user'] as Map<String, dynamic>);
  }

  Future<void> updatePassword({
    required String currentPassword,
    required String password,
  }) async {
    await _dio.put('/password', data: {
      'current_password': currentPassword,
      'password': password,
      'password_confirmation': password,
    });
  }

  /// Deletes the account server-side, then clears the locally stored
  /// token. Only clears it on success — a wrong password must leave the
  /// existing session intact instead of logging the user out locally
  /// while their (undeleted) account still exists server-side.
  Future<void> deleteAccount(String password) async {
    await _dio.delete('/profile', data: {'password': password});
    await TokenStorage.instance.clear();
  }
}
