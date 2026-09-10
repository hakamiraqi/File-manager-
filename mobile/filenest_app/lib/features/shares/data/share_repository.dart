import 'package:dio/dio.dart';

import '../../../core/network/api_client.dart';
import '../models/share_item.dart';

/// The `/shares` API group (backend README section 23.5).
class ShareRepository {
  final Dio _dio = ApiClient.instance.dio;

  Future<List<ShareItem>> shares() async {
    final response = await _dio.get('/shares');
    return (response.data['shares'] as List)
        .map((e) => ShareItem.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<ShareItem> createShare({
    required String shareableType,
    required int shareableId,
    String? password,
    DateTime? expiresAt,
    bool oneTimeDownload = false,
  }) async {
    final response = await _dio.post('/shares', data: {
      'shareable_type': shareableType,
      'shareable_id': shareableId,
      if (password != null && password.isNotEmpty) 'password': password,
      if (expiresAt != null) 'expires_at': expiresAt.toIso8601String(),
      'one_time_download': oneTimeDownload,
    });
    return ShareItem.fromJson(response.data['share']);
  }

  Future<void> revoke(int shareId) async {
    await _dio.delete('/shares/$shareId');
  }
}
