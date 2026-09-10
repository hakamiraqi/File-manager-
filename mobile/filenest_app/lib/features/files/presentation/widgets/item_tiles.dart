import 'package:flutter/material.dart';

import '../../../../core/models/file_item.dart';
import '../../../../core/models/folder_item.dart';
import '../../../../core/widgets/authenticated_thumbnail.dart';
import '../../../../core/widgets/file_icons.dart';
import '../../../downloads/presentation/widgets/download_status_icon.dart';

class FolderTile extends StatelessWidget {
  final FolderItem folder;
  final VoidCallback onTap;
  final VoidCallback onMenu;
  final VoidCallback onLongPress;
  final bool selectionMode;
  final bool selected;

  const FolderTile({
    super.key,
    required this.folder,
    required this.onTap,
    required this.onMenu,
    required this.onLongPress,
    this.selectionMode = false,
    this.selected = false,
  });

  @override
  Widget build(BuildContext context) {
    final labels = <String>[
      if (folder.folderType == 'thesis') 'أطروحة',
      if (folder.isPublic) 'مجلد عام',
    ];

    return ListTile(
      leading: selectionMode
          ? Checkbox(value: selected, onChanged: (_) => onTap())
          : Icon(Icons.folder_rounded, color: _colorFor(folder.color)),
      title: Text(folder.name),
      subtitle: labels.isEmpty ? null : Text(labels.join(' · ')),
      trailing: selectionMode ? null : IconButton(icon: const Icon(Icons.more_vert), onPressed: onMenu),
      selected: selected,
      onTap: onTap,
      onLongPress: onLongPress,
    );
  }

  Color _colorFor(String? name) {
    switch (name) {
      case 'red':
        return Colors.red;
      case 'green':
        return Colors.green;
      case 'amber':
        return Colors.amber;
      case 'purple':
        return Colors.purple;
      default:
        return const Color(0xFF2563EB);
    }
  }
}

class FileTile extends StatelessWidget {
  final FileItem file;
  final VoidCallback onTap;
  final VoidCallback onMenu;
  final VoidCallback onLongPress;
  final bool selectionMode;
  final bool selected;

  const FileTile({
    super.key,
    required this.file,
    required this.onTap,
    required this.onMenu,
    required this.onLongPress,
    this.selectionMode = false,
    this.selected = false,
  });

  @override
  Widget build(BuildContext context) {
    return ListTile(
      leading: selectionMode
          ? Checkbox(value: selected, onChanged: (_) => onTap())
          : (file.hasThumbnail && file.thumbnailUrl != null)
              ? AuthenticatedThumbnail(url: file.thumbnailUrl!, fallbackIcon: fileIconForExtension(file.extension))
              : Icon(fileIconForExtension(file.extension), color: Colors.grey.shade700),
      title: Text(file.name),
      subtitle: Text(
        file.contentSnippet ?? file.humanSize,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
      ),
      trailing: selectionMode
          ? null
          : Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                DownloadStatusIcon(file: file),
                IconButton(icon: const Icon(Icons.more_vert), onPressed: onMenu),
              ],
            ),
      selected: selected,
      onTap: onTap,
      onLongPress: onLongPress,
    );
  }
}
