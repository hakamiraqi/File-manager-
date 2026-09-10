import 'dart:io';

import 'package:dio/dio.dart';

import '../../../core/network/api_client.dart';
import '../models/activity_log_entry.dart';
import '../models/admin_user.dart';
import '../models/analytics_data.dart';
import '../models/dashboard_data.dart';
import '../models/ip_rule.dart';

/// Every call the admin panel needs, under `/admin/...` (backend README
/// section on the admin REST API) — one repository per admin domain would
/// be more files for very little gain here, since each endpoint is a thin
/// one-shot request with no shared state, unlike e.g. MediaRepository's
/// quiz/comments which share loading logic.
class AdminRepository {
  final _dio = ApiClient.instance.dio;

  Future<DashboardData> dashboard() async {
    final response = await _dio.get('/admin/dashboard');
    return DashboardData.fromJson(response.data as Map<String, dynamic>);
  }

  // ---- Users ----

  Future<AdminUserPage> users({String query = '', int page = 1}) async {
    final response = await _dio.get('/admin/users', queryParameters: {
      if (query.isNotEmpty) 'q': query,
      'page': page,
    });
    return AdminUserPage.fromJson(response.data as Map<String, dynamic>);
  }

  Future<AdminUser> createUser(Map<String, dynamic> data) async {
    final response = await _dio.post('/admin/users', data: data);
    return AdminUser.fromJson(response.data['user'] as Map<String, dynamic>);
  }

  Future<AdminUser> updateUser(int id, Map<String, dynamic> data) async {
    final response = await _dio.put('/admin/users/$id', data: data);
    return AdminUser.fromJson(response.data['user'] as Map<String, dynamic>);
  }

  Future<void> deleteUser(int id) => _dio.delete('/admin/users/$id');

  // ---- Settings ----

  Future<Map<String, dynamic>> settingsPayload() async {
    final response = await _dio.get('/admin/settings');
    return response.data as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> updateSettings(Map<String, dynamic> data) async {
    final response = await _dio.post('/admin/settings', data: data);
    return (response.data as Map<String, dynamic>)['settings'] as Map<String, dynamic>;
  }

  /// Same endpoint as [updateSettings], but as multipart form data so an
  /// optional new logo/header image can ride along — mirrors Settings.vue
  /// posting `logo`/`header_image` file inputs. Unlike a JSON body (where a
  /// real `true`/`false` survives), every multipart field is stringified,
  /// and Laravel's `boolean` rule only accepts "1"/"0" (not "true"/"false")
  /// as strings — so bool values are normalized before building FormData.
  Future<Map<String, dynamic>> updateSettingsWithFiles(
    Map<String, dynamic> data, {
    File? logo,
    File? headerImage,
  }) async {
    final normalized = data.map((key, value) => MapEntry(key, value is bool ? (value ? '1' : '0') : value));

    final formData = FormData.fromMap({
      ...normalized,
      if (logo != null) 'logo': await MultipartFile.fromFile(logo.path, filename: logo.uri.pathSegments.last),
      if (headerImage != null)
        'header_image': await MultipartFile.fromFile(headerImage.path, filename: headerImage.uri.pathSegments.last),
    });
    final response = await _dio.post('/admin/settings', data: formData);
    return (response.data as Map<String, dynamic>)['settings'] as Map<String, dynamic>;
  }

  // ---- Permissions ----

  Future<Map<String, dynamic>> permissionsPayload() async {
    final response = await _dio.get('/admin/permissions');
    return response.data as Map<String, dynamic>;
  }

  Future<void> updatePermissions(Map<String, Map<String, bool>> matrix) {
    return _dio.post('/admin/permissions', data: {'matrix': matrix});
  }

  // ---- Security ----

  Future<Map<String, dynamic>> securityPayload() async {
    final response = await _dio.get('/admin/security');
    return response.data as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> updateSecurity(Map<String, dynamic> data) async {
    final response = await _dio.post('/admin/security', data: data);
    return (response.data as Map<String, dynamic>)['settings'] as Map<String, dynamic>;
  }

  Future<IpRule> addIpRule({required String type, required String ipOrCidr, String? note}) async {
    final response = await _dio.post('/admin/security/ip-rules', data: {
      'type': type,
      'ip_or_cidr': ipOrCidr,
      if (note != null && note.isNotEmpty) 'note': note,
    });
    return IpRule.fromJson(response.data['ip_rule'] as Map<String, dynamic>);
  }

  Future<void> deleteIpRule(int id) => _dio.delete('/admin/security/ip-rules/$id');

  // ---- Mail ----

  Future<Map<String, dynamic>> mailSettings() async {
    final response = await _dio.get('/admin/mail');
    return (response.data as Map<String, dynamic>)['settings'] as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> updateMailSettings(Map<String, dynamic> data) async {
    final response = await _dio.post('/admin/mail', data: data);
    return (response.data as Map<String, dynamic>)['settings'] as Map<String, dynamic>;
  }

  Future<void> sendTestMail(String email) => _dio.post('/admin/mail/test', data: {'email': email});

  // ---- Translations ----

  Future<Map<String, dynamic>> translations(String locale) async {
    final response = await _dio.get('/admin/translations', queryParameters: {'locale': locale});
    return response.data as Map<String, dynamic>;
  }

  Future<void> saveTranslation({required String locale, required String key, required String value}) {
    return _dio.post('/admin/translations', data: {'locale': locale, 'key': key, 'value': value});
  }

  Future<void> resetTranslation({required String locale, required String key}) {
    return _dio.delete('/admin/translations', data: {'locale': locale, 'key': key});
  }

  // ---- Activity log ----

  Future<ActivityLogPage> activityLog({int page = 1}) async {
    final response = await _dio.get('/admin/activity', queryParameters: {'page': page});
    return ActivityLogPage.fromJson(response.data as Map<String, dynamic>);
  }

  // ---- Analytics ----

  Future<AnalyticsData> analytics({int days = 30}) async {
    final response = await _dio.get('/admin/analytics', queryParameters: {'days': days});
    return AnalyticsData.fromJson(response.data as Map<String, dynamic>);
  }
}
