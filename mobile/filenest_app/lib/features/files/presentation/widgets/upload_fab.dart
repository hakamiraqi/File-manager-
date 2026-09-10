import 'package:flutter/material.dart';

import '../../../../core/i18n/app_strings.dart';

/// A minimal "speed dial" (new folder / upload folder / upload file / upload
/// from URL) without pulling in an extra package just for this.
class UploadFab extends StatefulWidget {
  const UploadFab({
    super.key,
    required this.onNewFolder,
    required this.onUpload,
    required this.onUploadFolder,
    required this.onUploadFromUrl,
  });

  final VoidCallback onNewFolder;
  final VoidCallback onUpload;
  final VoidCallback onUploadFolder;
  final VoidCallback onUploadFromUrl;

  @override
  State<UploadFab> createState() => _UploadFabState();
}

class _UploadFabState extends State<UploadFab> {
  bool _open = false;

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (_open) ...[
          FloatingActionButton.extended(
            heroTag: 'new-folder',
            onPressed: () {
              setState(() => _open = false);
              widget.onNewFolder();
            },
            icon: const Icon(Icons.create_new_folder_outlined),
            label: Text(context.tr('folder.new')),
          ),
          const SizedBox(height: 12),
          FloatingActionButton.extended(
            heroTag: 'upload-folder',
            onPressed: () {
              setState(() => _open = false);
              widget.onUploadFolder();
            },
            icon: const Icon(Icons.drive_folder_upload_outlined),
            label: Text(context.tr('action.upload_folder')),
          ),
          const SizedBox(height: 12),
          FloatingActionButton.extended(
            heroTag: 'upload-url',
            onPressed: () {
              setState(() => _open = false);
              widget.onUploadFromUrl();
            },
            icon: const Icon(Icons.link_rounded),
            label: Text(context.tr('action.upload_remote')),
          ),
          const SizedBox(height: 12),
          FloatingActionButton.extended(
            heroTag: 'upload',
            onPressed: () {
              setState(() => _open = false);
              widget.onUpload();
            },
            icon: const Icon(Icons.upload_file_rounded),
            label: Text(context.tr('action.upload')),
          ),
          const SizedBox(height: 12),
        ],
        FloatingActionButton(
          heroTag: 'main',
          onPressed: () => setState(() => _open = !_open),
          child: Icon(_open ? Icons.close_rounded : Icons.add_rounded),
        ),
      ],
    );
  }
}
