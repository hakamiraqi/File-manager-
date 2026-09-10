import 'package:dio/dio.dart';

import '../../../core/network/api_client.dart';
import '../models/playlist.dart';

/// The `/playlists` API group — named, ordered video/audio playlists,
/// mirroring the web app's Playlists feature.
class PlaylistRepository {
  final Dio _dio = ApiClient.instance.dio;

  Future<List<Playlist>> playlists() async {
    final response = await _dio.get('/playlists');
    return (response.data['playlists'] as List)
        .map((e) => Playlist.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<List<PlaylistPickerEntry>> picker({int? fileId}) async {
    final response = await _dio.get('/playlists/picker', queryParameters: {
      if (fileId != null) 'file_id': fileId,
    });
    return (response.data['playlists'] as List)
        .map((e) => PlaylistPickerEntry.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<Playlist> create({required String name, int? fileId}) async {
    final response = await _dio.post('/playlists', data: {
      'name': name,
      if (fileId != null) 'file_id': fileId,
    });
    return Playlist.fromJson(response.data['playlist']);
  }

  Future<Playlist> show(int playlistId) async {
    final response = await _dio.get('/playlists/$playlistId');
    return Playlist.fromJson(response.data['playlist']);
  }

  Future<Playlist> rename(int playlistId, String name) async {
    final response = await _dio.put('/playlists/$playlistId', data: {'name': name});
    return Playlist.fromJson(response.data['playlist']);
  }

  Future<void> destroy(int playlistId) async {
    await _dio.delete('/playlists/$playlistId');
  }

  Future<void> addItem(int playlistId, int fileId) async {
    await _dio.post('/playlists/$playlistId/items', data: {'file_id': fileId});
  }

  Future<void> removeItem(int playlistId, int itemId) async {
    await _dio.delete('/playlists/$playlistId/items/$itemId');
  }

  Future<Playlist> reorder(int playlistId, List<int> itemIdsInOrder) async {
    final response = await _dio.put('/playlists/$playlistId/reorder', data: {'order': itemIdsInOrder});
    return Playlist.fromJson(response.data['playlist']);
  }
}
