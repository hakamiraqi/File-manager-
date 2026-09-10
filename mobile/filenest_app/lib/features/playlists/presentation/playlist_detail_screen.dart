import 'package:flutter/material.dart';

import '../../../core/i18n/app_strings.dart';
import '../../../core/network/api_client.dart';
import '../../../core/widgets/authenticated_thumbnail.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/file_icons.dart';
import '../../media_player/presentation/media_player_screen.dart';
import '../data/playlist_repository.dart';
import '../models/playlist.dart';

/// One playlist's ordered items — drag to reorder, tap to play from that
/// point, or "play all" from the top. Mirrors Playlists/Show.vue on the web.
class PlaylistDetailScreen extends StatefulWidget {
  const PlaylistDetailScreen({super.key, required this.playlistId});

  final int playlistId;

  @override
  State<PlaylistDetailScreen> createState() => _PlaylistDetailScreenState();
}

class _PlaylistDetailScreenState extends State<PlaylistDetailScreen> {
  final _repository = PlaylistRepository();
  Playlist? _playlist;
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _isLoading = true);
    try {
      final playlist = await _repository.show(widget.playlistId);
      if (mounted) setState(() => _playlist = playlist);
    } catch (e) {
      if (mounted) _showError(e);
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  void _showError(Object e) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
  }

  void _play(int index) {
    final playlist = _playlist;
    if (playlist == null || playlist.items.isEmpty) return;

    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => MediaPlayerScreen(
          playlist: playlist.items.map((e) => e.file).toList(),
          initialIndex: index,
        ),
      ),
    );
  }

  Future<void> _rename() async {
    final playlist = _playlist;
    if (playlist == null) return;

    final controller = TextEditingController(text: playlist.name);
    final name = await showDialog<String>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(context.tr('playlist.rename')),
        content: TextField(controller: controller, autofocus: true),
        actions: [
          TextButton(onPressed: () => Navigator.of(dialogContext).pop(), child: Text(context.tr('action.cancel'))),
          FilledButton(onPressed: () => Navigator.of(dialogContext).pop(controller.text), child: Text(context.tr('action.save'))),
        ],
      ),
    );
    if (name == null || name.trim().isEmpty || name == playlist.name) return;

    try {
      await _repository.rename(playlist.id, name.trim());
      _load();
    } catch (e) {
      _showError(e);
    }
  }

  Future<void> _delete() async {
    final playlist = _playlist;
    if (playlist == null) return;

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(context.tr('playlist.delete_title')),
        content: Text(context.tr('playlist.confirm_delete', {'name': playlist.name})),
        actions: [
          TextButton(onPressed: () => Navigator.of(dialogContext).pop(false), child: Text(context.tr('action.cancel'))),
          FilledButton(onPressed: () => Navigator.of(dialogContext).pop(true), child: Text(context.tr('action.delete'))),
        ],
      ),
    );
    if (confirmed != true) return;

    try {
      await _repository.destroy(playlist.id);
      if (mounted) Navigator.of(context).pop();
    } catch (e) {
      _showError(e);
    }
  }

  Future<void> _removeItem(PlaylistEntry item) async {
    final playlist = _playlist;
    if (playlist == null) return;

    setState(() => playlist.items.removeWhere((e) => e.id == item.id));
    try {
      await _repository.removeItem(playlist.id, item.id);
    } catch (e) {
      _showError(e);
      _load();
    }
  }

  Future<void> _onReorder(int oldIndex, int newIndex) async {
    final playlist = _playlist;
    if (playlist == null) return;

    setState(() {
      if (newIndex > oldIndex) newIndex -= 1;
      final item = playlist.items.removeAt(oldIndex);
      playlist.items.insert(newIndex, item);
    });

    try {
      await _repository.reorder(playlist.id, playlist.items.map((e) => e.id).toList());
    } catch (e) {
      _showError(e);
      _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    final playlist = _playlist;

    return Scaffold(
      appBar: AppBar(
        title: Text(playlist?.name ?? context.tr('playlist.title')),
        actions: [
          IconButton(icon: const Icon(Icons.drive_file_rename_outline_rounded), onPressed: playlist == null ? null : _rename),
          IconButton(icon: const Icon(Icons.delete_outline_rounded), onPressed: playlist == null ? null : _delete),
        ],
      ),
      floatingActionButton: (playlist != null && playlist.items.isNotEmpty)
          ? FloatingActionButton.extended(
              onPressed: () => _play(0),
              icon: const Icon(Icons.play_arrow_rounded),
              label: Text(context.tr('playlist.play_all')),
            )
          : null,
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    if (_isLoading) return const Center(child: CircularProgressIndicator());

    final playlist = _playlist;
    if (playlist == null) {
      return EmptyState(icon: Icons.error_outline_rounded, message: context.tr('playlist.load_error'));
    }

    if (playlist.items.isEmpty) {
      return EmptyState(
        icon: Icons.queue_music_rounded,
        message: context.tr('playlist.item_empty'),
      );
    }

    return ReorderableListView.builder(
      itemCount: playlist.items.length,
      onReorder: _onReorder,
      itemBuilder: (context, index) {
        final item = playlist.items[index];
        final file = item.file;

        return ListTile(
          key: ValueKey(item.id),
          leading: (file.hasThumbnail && file.thumbnailUrl != null)
              ? AuthenticatedThumbnail(url: file.thumbnailUrl!, fallbackIcon: fileIconForExtension(file.extension))
              : Icon(fileIconForExtension(file.extension)),
          title: Text(file.name, maxLines: 1, overflow: TextOverflow.ellipsis),
          subtitle: Text(file.humanSize),
          onTap: () => _play(index),
          trailing: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              IconButton(icon: const Icon(Icons.close_rounded), onPressed: () => _removeItem(item)),
              const Icon(Icons.drag_handle_rounded),
            ],
          ),
        );
      },
    );
  }
}
