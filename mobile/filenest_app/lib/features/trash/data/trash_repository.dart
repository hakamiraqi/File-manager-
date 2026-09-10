import 'package:dio/dio.dart';

import '../../../core/models/file_item.dart';
import '../../../core/models/folder_item.dart';
import '../../../core/network/api_client.dart';

/// The `/trash` API group (backend README section 23.5).
class TrashRepository {
  final Dio _dio = ApiClient.instance.dio;

  Future<List<FileItem>> trashedFiles() async {
    final response = await _dio.get('/trash');
    return (response.data['files'] as List)
        .map((e) => FileItem.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<List<FolderItem>> trashedFolders() async {
    final response = await _dio.get('/trash');
    return (response.data['folders'] as List)
        .map((e) => FolderItem.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<void> restore({required String type, required int id}) async {
    await _dio.post('/trash/restore', data: {'type': type, 'id': id});
  }

  Future<void> forceDelete({required String type, required int id}) async {
    await _dio.delete('/trash/$type/$id');
  }

  Future<void> empty() async {
    await _dio.delete('/trash');
  }
}
