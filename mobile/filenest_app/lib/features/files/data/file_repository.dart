import 'dart:io';

import 'package:dio/dio.dart';

import '../../../core/models/file_item.dart';
import '../../../core/models/folder_item.dart';
import '../../../core/network/api_client.dart';

class BrowseResult {
  final FolderItem? currentFolder;
  final List<FolderItem> breadcrumbs;
  final List<FolderItem> folders;
  final List<FileItem> files;

  BrowseResult({
    required this.currentFolder,
    required this.breadcrumbs,
    required this.folders,
    required this.files,
  });
}

/// Browsing, folder/file CRUD, and chunked upload/download — everything
/// under the `/browse`, `/folders`, `/files` and `/uploads` API groups
/// (backend README section 23.3/23.4). Trash and sharing live in their
/// own repositories (features/trash, features/shares) even though the
/// backend groups them under the same controller family, so each feature
/// folder only imports what it actually needs.
class FileRepository {
  final Dio _dio = ApiClient.instance.dio;

  Future<BrowseResult> browse({int? folderId, String query = ''}) async {
    final response = await _dio.get('/browse', queryParameters: {
      if (folderId != null) 'folder': folderId,
      if (query.isNotEmpty) 'q': query,
    });

    final data = response.data as Map<String, dynamic>;

    return BrowseResult(
      currentFolder: data['current_folder'] == null
          ? null
          : FolderItem.fromJson(data['current_folder']),
      breadcrumbs: (data['breadcrumbs'] as List)
          .map((e) => FolderItem.fromJson(e as Map<String, dynamic>))
          .toList(),
      folders: (data['folders'] as List)
          .map((e) => FolderItem.fromJson(e as Map<String, dynamic>))
          .toList(),
      files: (data['files'] as List)
          .map((e) => FileItem.fromJson(e as Map<String, dynamic>))
          .toList(),
    );
  }

  Future<FolderItem> createFolder({required String name, int? parentId}) async {
    final response = await _dio.post('/folders', data: {
      'name': name,
      if (parentId != null) 'parent_id': parentId,
    });
    return FolderItem.fromJson(response.data['folder']);
  }

  Future<void> renameFolder(int folderId, String name) async {
    await _dio.put('/folders/$folderId', data: {'name': name});
  }

  Future<void> moveFolder(int folderId, int? parentId) async {
    await _dio.put('/folders/$folderId', data: {'parent_id': parentId});
  }

  /// Updates a folder's free-form bibliographic info (researcher,
  /// supervisor, thesis title, publisher, digital library, publication
  /// year) — shown/edited on the folder's own screen. Any field omitted
  /// from [fields] is left untouched server-side.
  Future<FolderItem> updateFolderInfo(int folderId, Map<String, String?> fields) async {
    final response = await _dio.put('/folders/$folderId', data: fields);
    return FolderItem.fromJson(response.data['folder']);
  }

  Future<void> deleteFolder(int folderId) async {
    await _dio.delete('/folders/$folderId');
  }

  Future<void> renameFile(int fileId, String name) async {
    await _dio.put('/files/$fileId', data: {'name': name});
  }

  Future<void> moveFile(int fileId, int? folderId) async {
    await _dio.put('/files/$fileId', data: {'folder_id': folderId});
  }

  Future<void> deleteFile(int fileId) async {
    await _dio.delete('/files/$fileId');
  }

  Future<void> copyFile(int fileId, {int? folderId}) async {
    await _dio.post('/files/$fileId/copy', data: {
      if (folderId != null) 'folder_id': folderId,
    });
  }

  /// Downloads a file server-side from an external URL straight into the
  /// user's drive (SSRF-guarded on the backend) — same feature as the web
  /// dashboard's "upload from URL". Only works when an admin has enabled
  /// it in Settings; the caller doesn't need to know that in advance and
  /// can just surface whatever error comes back if it's off.
  Future<void> uploadFromUrl({required String url, int? folderId}) async {
    await _dio.post('/uploads/remote', data: {
      'url': url,
      if (folderId != null) 'folder_id': folderId,
    });
  }

  /// Uploads a local file via the same chunked protocol the web dashboard
  /// uses. Calling this again for the same (name, size, folder) — after a
  /// dropped connection, an app restart, or an explicit retry — resumes
  /// rather than restarts: the server recognises the still-in-progress
  /// session and reports which chunks it already has in `received_chunks`,
  /// so only the missing ones are re-sent. A failed attempt is therefore
  /// never auto-cancelled here; only cancelUpload() below deletes the
  /// session and its temporary chunks for good.
  Future<FileItem> uploadFile({
    required File file,
    required String fileName,
    int? folderId,
    void Function(double progress)? onProgress,
  }) async {
    final size = await file.length();

    final init = await _dio.post('/uploads/init', data: {
      'name': fileName,
      'size': size,
      if (folderId != null) 'folder_id': folderId,
    });

    final identifier = init.data['identifier'] as String;
    final chunkSize = init.data['chunk_size'] as int;
    final totalChunks = init.data['total_chunks'] as int;
    final receivedChunks = Set<int>.from(init.data['received_chunks'] as List? ?? []);

    onProgress?.call(receivedChunks.length / totalChunks);

    final raf = await file.open();
    try {
      for (var index = 0; index < totalChunks; index++) {
        if (receivedChunks.contains(index)) continue;

        await raf.setPosition(index * chunkSize);
        final bytes = await raf.read(chunkSize);
        final formData = FormData.fromMap({
          'identifier': identifier,
          'index': index,
          'chunk': MultipartFile.fromBytes(bytes, filename: 'chunk-$index'),
        });

        await _dio.post('/uploads/chunk', data: formData);
        receivedChunks.add(index);
        onProgress?.call(receivedChunks.length / totalChunks);
      }
    } finally {
      await raf.close();
    }

    final finalize = await _dio.post('/uploads/finalize', data: {'identifier': identifier});
    return FileItem.fromJson(finalize.data['file']);
  }

  /// Permanently abandons an in-progress upload — unlike a failed
  /// uploadFile() call, this deletes the session and its temporary chunks
  /// server-side, so a later call for the same file starts over from
  /// scratch instead of resuming.
  Future<void> cancelUpload(String identifier) async {
    await _dio.post('/uploads/cancel', data: {'identifier': identifier});
  }

  /// Downloads [file]'s bytes to [destinationPath], reporting progress via
  /// [onProgress] (0.0-1.0) — used both for the quick ephemeral preview
  /// download and, with a persistent destination, by the offline-downloads
  /// feature (see features/downloads).
  Future<String> downloadFile(
    FileItem file,
    String destinationPath, {
    void Function(double progress)? onProgress,
    CancelToken? cancelToken,
  }) async {
    await _dio.download(
      file.downloadUrl,
      destinationPath,
      cancelToken: cancelToken,
      onReceiveProgress: (received, total) {
        if (total > 0) onProgress?.call(received / total);
      },
    );
    return destinationPath;
  }

  /// The full text DocumentTextExtractor pulled from this document — an
  /// on-demand call (mirrors the web dashboard's preview panel) rather
  /// than part of every browse() response, since it can be large.
  Future<String?> fileContent(int fileId) async {
    final response = await _dio.get('/files/$fileId/content');
    return response.data['content'] as String?;
  }

  Future<List<FolderItem>> folderTree() async {
    final response = await _dio.get('/folders/tree');
    return (response.data as List)
        .map((e) => FolderItem.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  /// Downloads several files/folders at once as a single ZIP archive,
  /// mirroring the web dashboard's bulk-download button. The `[]` in the
  /// query keys is deliberate — Laravel only parses repeated query keys as
  /// an array with that bracket suffix (`file_ids[]=1&file_ids[]=2`), and
  /// spelling it out here doesn't depend on which list-serialization style
  /// happens to be Dio's default.
  Future<String> downloadZip({
    required List<int> fileIds,
    required List<int> folderIds,
    required String destinationPath,
  }) async {
    await _dio.download(
      '/download-zip',
      destinationPath,
      queryParameters: {
        'file_ids[]': fileIds,
        'folder_ids[]': folderIds,
      },
    );
    return destinationPath;
  }
}
