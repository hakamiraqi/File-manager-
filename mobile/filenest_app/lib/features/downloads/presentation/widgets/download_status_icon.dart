import 'package:flutter/material.dart';

import '../../../../core/files/file_opener.dart';
import '../../data/offline_download_service.dart';
import 'package:provider/provider.dart';

import '../../../../app/theme/app_colors.dart';
import '../../../../core/i18n/app_strings.dart';
import '../../../../core/models/file_item.dart';
import '../../state/downloads_provider.dart';

/// The trailing control on a file row that drives the offline-download
/// feature end to end: an outlined download icon when the file hasn't
/// been saved yet, a determinate progress ring with a cancel (×) button
/// while it's downloading, and a filled "available offline" icon once
/// done — tapping that last state opens the local copy directly, with no
/// network call at all.
class DownloadStatusIcon extends StatelessWidget {
  const DownloadStatusIcon({super.key, required this.file});

  final FileItem file;

  @override
  Widget build(BuildContext context) {
    return Consumer<DownloadsProvider>(
      builder: (context, downloads, _) {
        final progress = downloads.progressFor(file.id);

        if (progress != null) {
          return SizedBox(
            width: 40,
            height: 40,
            child: Stack(
              alignment: Alignment.center,
              children: [
                CircularProgressIndicator(value: progress == 0 ? null : progress, strokeWidth: 2.5),
                IconButton(
                  icon: const Icon(Icons.close_rounded, size: 14),
                  tooltip: context.tr('downloads.cancel_download'),
                  padding: EdgeInsets.zero,
                  constraints: const BoxConstraints(),
                  onPressed: () => downloads.cancelDownload(file.id),
                ),
              ],
            ),
          );
        }

        final isDownloaded = downloads.isDownloaded(file.id);

        return IconButton(
          icon: Icon(
            isDownloaded ? Icons.offline_pin_rounded : Icons.download_for_offline_outlined,
            color: isDownloaded ? AppColors.success : null,
          ),
          tooltip: isDownloaded ? context.tr('downloads.available_offline_tap') : context.tr('downloads.download_for_offline'),
          onPressed: () {
            final record = isDownloaded ? OfflineDownloadService.instance.recordFor(file.id) : null;
            record != null ? openLocalFile(context, record.localPath, file.name, fileId: file.id) : downloads.download(file);
          },
        );
      },
    );
  }
}
