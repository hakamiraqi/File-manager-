import '../../../core/models/file_item.dart';

/// One file inside a [Playlist], in play order.
class PlaylistEntry {
  final int id;
  final int order;
  final FileItem file;

  PlaylistEntry({required this.id, required this.order, required this.file});

  factory PlaylistEntry.fromJson(Map<String, dynamic> json) {
    return PlaylistEntry(
      id: json['id'] as int,
      order: (json['order'] as num?)?.toInt() ?? 0,
      file: FileItem.fromJson(json['file'] as Map<String, dynamic>),
    );
  }
}

/// A named, user-curated, ordered playlist of video/audio files — see the
/// backend's Playlist model and `/playlists` API group.
class Playlist {
  final int id;
  final String name;
  final int? itemsCount;
  final List<PlaylistEntry> items;

  Playlist({required this.id, required this.name, required this.itemsCount, required this.items});

  factory Playlist.fromJson(Map<String, dynamic> json) {
    return Playlist(
      id: json['id'] as int,
      name: json['name'] as String,
      itemsCount: (json['items_count'] as num?)?.toInt(),
      items: (json['items'] as List?)
              ?.map((e) => PlaylistEntry.fromJson(e as Map<String, dynamic>))
              .toList() ??
          const [],
    );
  }
}

/// One row returned by the `/playlists/picker` endpoint — used only by the
/// "add to playlist" bottom sheet, so it doesn't need the full item list.
class PlaylistPickerEntry {
  final int id;
  final String name;
  final int itemsCount;
  final bool containsFile;

  PlaylistPickerEntry({
    required this.id,
    required this.name,
    required this.itemsCount,
    required this.containsFile,
  });

  factory PlaylistPickerEntry.fromJson(Map<String, dynamic> json) {
    return PlaylistPickerEntry(
      id: json['id'] as int,
      name: json['name'] as String,
      itemsCount: (json['items_count'] as num?)?.toInt() ?? 0,
      containsFile: json['contains_file'] as bool? ?? false,
    );
  }
}
