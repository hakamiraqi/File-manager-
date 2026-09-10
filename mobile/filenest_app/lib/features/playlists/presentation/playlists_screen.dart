import 'package:flutter/material.dart';

import '../../../core/i18n/app_strings.dart';
import '../../../core/network/api_client.dart';
import '../../../core/widgets/authenticated_thumbnail.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/file_icons.dart';
import '../data/playlist_repository.dart';
import '../models/playlist.dart';
import 'playlist_detail_screen.dart';

/// Lists every named playlist the user has created — the mobile counterpart
/// of the web app's Playlists/Index.vue.
class PlaylistsScreen extends StatefulWidget {
  const PlaylistsScreen({super.key});

  @override
  State<PlaylistsScreen> createState() => _PlaylistsScreenState();
}

class _PlaylistsScreenState extends State<PlaylistsScreen> {
  final _repository = PlaylistRepository();
  List<Playlist> _playlists = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _isLoading = true);
    try {
      final playlists = await _repository.playlists();
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

  Future<void> _createPlaylist() async {
    final controller = TextEditingController();
    final name = await showDialog<String>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(context.tr('playlist.new')),
        content: TextField(controller: controller, autofocus: true, decoration: InputDecoration(labelText: context.tr('playlist.name_label'))),
        actions: [
          TextButton(onPressed: () => Navigator.of(dialogContext).pop(), child: Text(context.tr('action.cancel'))),
          FilledButton(onPressed: () => Navigator.of(dialogContext).pop(controller.text), child: Text(context.tr('action.create'))),
        ],
      ),
    );
    if (name == null || name.trim().isEmpty) return;

    try {
      final playlist = await _repository.create(name: name.trim());
      _load();
      if (mounted) _openPlaylist(playlist);
    } catch (e) {
      _showError(e);
    }
  }

  void _openPlaylist(Playlist playlist) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => PlaylistDetailScreen(playlistId: playlist.id)));
    _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(context.tr('playlist.title'))),
      floatingActionButton: FloatingActionButton(onPressed: _createPlaylist, child: const Icon(Icons.add_rounded)),
      body: RefreshIndicator(onRefresh: _load, child: _buildBody()),
    );
  }

  Widget _buildBody() {
    if (_isLoading) return const Center(child: CircularProgressIndicator());

    if (_playlists.isEmpty) {
      return ListView(
        children: [
          const SizedBox(height: 80),
          EmptyState(
            icon: Icons.queue_music_rounded,
            message: context.tr('playlist.empty'),
          ),
        ],
      );
    }

    return ListView.builder(
      itemCount: _playlists.length,
      itemBuilder: (context, index) {
        final playlist = _playlists[index];
        final firstFile = playlist.items.isNotEmpty ? playlist.items.first.file : null;

        return ListTile(
          leading: (firstFile != null && firstFile.hasThumbnail && firstFile.thumbnailUrl != null)
              ? AuthenticatedThumbnail(url: firstFile.thumbnailUrl!, fallbackIcon: Icons.queue_music_rounded)
              : Icon(firstFile != null ? fileIconForExtension(firstFile.extension) : Icons.queue_music_rounded),
          title: Text(playlist.name),
          subtitle: Text(context.tr('playlist.items_count', {'count': playlist.itemsCount ?? playlist.items.length})),
          onTap: () => _openPlaylist(playlist),
        );
      },
    );
  }
}
