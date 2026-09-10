import 'package:flutter/material.dart';

import '../../../../core/i18n/app_strings.dart';
import '../../../../core/models/file_item.dart';
import '../../../../core/network/api_client.dart';
import '../../data/playlist_repository.dart';
import '../../models/playlist.dart';

/// The "add to playlist" bottom sheet, opened from a file's action menu in
/// the browser — mirrors AddToPlaylistModal.vue on the web.
class AddToPlaylistSheet extends StatefulWidget {
  const AddToPlaylistSheet({super.key, required this.file});

  final FileItem file;

  static Future<void> show(BuildContext context, FileItem file) {
    return showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (context) => AddToPlaylistSheet(file: file),
    );
  }

  @override
  State<AddToPlaylistSheet> createState() => _AddToPlaylistSheetState();
}

class _AddToPlaylistSheetState extends State<AddToPlaylistSheet> {
  final _repository = PlaylistRepository();
  List<PlaylistPickerEntry> _playlists = [];
  bool _isLoading = true;
  int? _addingId;
  bool _creatingNew = false;
  final _newNameController = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _newNameController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => _isLoading = true);
    try {
      final playlists = await _repository.picker(fileId: widget.file.id);
      if (mounted) setState(() => _playlists = playlists);
    } catch (e) {
      if (mounted) _showError(e);
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  void _showError(Object e) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
  }

  Future<void> _addTo(PlaylistPickerEntry playlist) async {
    setState(() => _addingId = playlist.id);
    try {
      await _repository.addItem(playlist.id, widget.file.id);
      setState(() {
        final index = _playlists.indexWhere((p) => p.id == playlist.id);
        _playlists[index] = PlaylistPickerEntry(
          id: playlist.id,
          name: playlist.name,
          itemsCount: playlist.itemsCount + 1,
          containsFile: true,
        );
      });
    } catch (e) {
      _showError(e);
    } finally {
      if (mounted) setState(() => _addingId = null);
    }
  }

  Future<void> _createAndAdd() async {
    final name = _newNameController.text.trim();
    if (name.isEmpty) return;

    try {
      await _repository.create(name: name, fileId: widget.file.id);
      if (mounted) Navigator.of(context).pop();
    } catch (e) {
      _showError(e);
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
              child: Text(
                context.tr('playlist.add_to_title', {'name': widget.file.name}),
                style: Theme.of(context).textTheme.titleMedium,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
            ),
            Flexible(child: _buildList()),
            if (_creatingNew)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    TextField(
                      controller: _newNameController,
                      autofocus: true,
                      decoration: InputDecoration(labelText: context.tr('playlist.new_name_label')),
                    ),
                    const SizedBox(height: 8),
                    FilledButton(onPressed: _createAndAdd, child: Text(context.tr('playlist.create_and_add'))),
                  ],
                ),
              )
            else
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                child: OutlinedButton.icon(
                  onPressed: () => setState(() => _creatingNew = true),
                  icon: const Icon(Icons.add_rounded),
                  label: Text(context.tr('playlist.new')),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _buildList() {
    if (_isLoading) {
      return const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator()));
    }

    if (_playlists.isEmpty) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Center(child: Text(context.tr('playlist.none_yet'))),
      );
    }

    return ListView.builder(
      shrinkWrap: true,
      itemCount: _playlists.length,
      itemBuilder: (context, index) {
        final playlist = _playlists[index];
        return ListTile(
          title: Text(playlist.name),
          subtitle: Text(context.tr('playlist.items_count', {'count': playlist.itemsCount})),
          trailing: _addingId == playlist.id
              ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
              : Icon(
                  playlist.containsFile ? Icons.check_circle_rounded : Icons.add_circle_outline_rounded,
                  color: playlist.containsFile ? Colors.green : null,
                ),
          onTap: playlist.containsFile || _addingId != null ? null : () => _addTo(playlist),
        );
      },
    );
  }
}
