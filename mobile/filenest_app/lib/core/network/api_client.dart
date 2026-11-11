import 'dart:io';

import 'package:dio/dio.dart';

import '../config/api_config.dart';
import '../i18n/app_strings.dart';
import '../i18n/locale_provider.dart';
import '../storage/token_storage.dart';

/// Thin wrapper around a single Dio instance shared by every service.
/// Attaches the stored bearer token to every request and normalizes
/// FileNest's JSON error shape (`{message, errors}`) into a plain string.
class ApiClient {
  ApiClient._internal() {
    _dio = Dio(BaseOptions(
      baseUrl: ApiConfig.baseUrl,
      connectTimeout: const Duration(seconds: 20),
      receiveTimeout: const Duration(seconds: 60),
      headers: {'Accept': 'application/json'},
    ));

    _dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) async {
        final token = await TokenStorage.instance.read();
        if (token != null) {
          options.headers['Authorization'] = 'Bearer $token';
        }
        handler.next(options);
      },
    ));
  }

  static final ApiClient instance = ApiClient._internal();
  late final Dio _dio;

  Dio get dio => _dio;

  String get baseUrl => _dio.options.baseUrl;

  /// Repoints every future request at a different backend — used by
  /// ServerConfigService when switching between the local network and
  /// hosted server, or when the user edits either address.
  void updateBaseUrl(String baseUrl) {
    _dio.options.baseUrl = baseUrl;
  }

  static String messageFor(Object error) {
    if (error is DioException) {
      final data = error.response?.data;
      if (data is Map && data['message'] is String) {
        final errors = data['errors'];
        if (errors is Map && errors.isNotEmpty) {
          final first = errors.values.first;
          if (first is List && first.isNotEmpty) return first.first.toString();
        }
        return data['message'] as String;
      }
      if (error.type == DioExceptionType.connectionTimeout ||
          error.type == DioExceptionType.connectionError ||
          error.type == DioExceptionType.receiveTimeout ||
          error.error is SocketException) {
        return AppStrings.of(LocaleProvider.currentLanguageCode, 'error.connection_failed');
      }
    }
    return AppStrings.of(LocaleProvider.currentLanguageCode, 'error.unexpected');
  }
}
