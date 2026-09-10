import 'package:flutter/material.dart';

import '../../../core/i18n/app_strings.dart';
import '../../../core/models/file_item.dart';
import '../../../core/models/folder_item.dart';
import '../../../core/network/api_client.dart';
import '../../../core/widgets/empty_state.dart';
import '../data/trash_repository.dart';

class TrashScreen extends StatefulWidget {
  const TrashScreen({super.key});

  @override
  State<TrashScreen> createState() => _TrashScreenState();
}

class _TrashScreenState extends State<TrashScreen> {
  final _trashRepository = TrashRepository();
  List<FileItem> _files = [];
  List<FolderItem> _folders = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _isLoading = true);
    try {
      final files = await _trashRepository.trashedFiles();
      final folders = await _trashRepository.trashedFolders();
      setState(() {
        _files = files;
        _folders = folders;
      });
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _restore(String type, int id) async {
    await _trashRepository.restore(type: type, id: id);
    _load();
  }

  Future<void> _forceDelete(String type, int id) async {
    await _trashRepository.forceDelete(type: type, id: id);
    _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(context.tr('nav.trash')),
        actions: [
          IconButton(
            icon: const Icon(Icons.delete_forever_outlined),
            tooltip: context.tr('trash.empty_action'),
            onPressed: () async {
              await _trashRepository.empty();
              _load();
            },
          ),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : (_files.isEmpty && _folders.isEmpty)
              ? EmptyState(icon: Icons.delete_outline_rounded, message: context.tr('empty.trash'))
              : ListView(
                  children: [
                    ..._folders.map((folder) => ListTile(
                          leading: const Icon(Icons.folder_rounded),
                          title: Text(folder.name),
                          trailing: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              IconButton(icon: const Icon(Icons.restore_rounded), onPressed: () => _restore('folder', folder.id)),
                              IconButton(icon: const Icon(Icons.delete_forever_outlined), onPressed: () => _forceDelete('folder', folder.id)),
                            ],
                          ),
                        )),
                    ..._files.map((file) => ListTile(
                          leading: const Icon(Icons.insert_drive_file_rounded),
                          title: Text(file.name),
                          trailing: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              IconButton(icon: const Icon(Icons.restore_rounded), onPressed: () => _restore('file', file.id)),
                              IconButton(icon: const Icon(Icons.delete_forever_outlined), onPressed: () => _forceDelete('file', file.id)),
                            ],
                          ),
                        )),
                  ],
                ),
    );
  }
}
