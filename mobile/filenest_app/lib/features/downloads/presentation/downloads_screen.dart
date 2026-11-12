import 'dart:io';
import '../../../core/widgets/ui_kit.dart';
import '../../pdf_tools/presentation/local_pdf_tools_screen.dart';

import 'package:flutter/material.dart';

import '../../../core/files/file_opener.dart';
import 'package:provider/provider.dart';

import '../../../core/i18n/app_strings.dart';
import '../../../core/models/file_item.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/file_icons.dart';
import '../../media_player/presentation/media_player_screen.dart';
import '../data/offline_download_service.dart';
import '../models/offline_file.dart';
import '../state/downloads_provider.dart';

/// The "التنزيلات" tab: every file saved for offline use, each openable
/// with zero network access. Always used embedded inside HomeShell, which
/// supplies the surrounding AppBar/title — this widget only ever renders
/// its list body.
class DownloadsScreen extends StatefulWidget {
  const DownloadsScreen({super.key});

  @override
  State<DownloadsScreen> createState() => _DownloadsScreenState();
}

class _DownloadsScreenState extends State<DownloadsScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => context.read<DownloadsProvider>().load());
  }

  /// The on-device PDF tools work offline, so they are offered here too (2026-11-12).
  Widget _pdfToolsTile(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 0),
        child: Material(
          color: Theme.of(context).colorScheme.primary.withOpacity(0.08),
          borderRadius: BorderRadius.circular(14),
          child: ListTile(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
            leading: const Icon(Icons.picture_as_pdf_rounded),
            title: Text(context.l('أدوات PDF بدون إنترنت', 'PDF tools, offline')),
            subtitle: Text(context.l('دمج، تنظيم، تقسيم، تدوير، صور إلى PDF', 'Merge, organise, split, rotate, images to PDF')),
            trailing: const Icon(Icons.chevron_left_rounded),
            onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const LocalPdfToolsScreen())),
          ),
        ),
      );

  @override
  Widget build(BuildContext context) {
    final downloads = context.watch<DownloadsProvider>();

    if (downloads.loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (downloads.items.isEmpty) {
      return ListView(
        children: [
          _pdfToolsTile(context),
          const SizedBox(height: 60),
          EmptyState(
            icon: Icons.download_for_offline_outlined,
            message: context.tr('downloads.empty'),
          ),
        ],
      );
    }

    return Column(
      children: [
        _pdfToolsTile(context),
        if (downloads.pendingAnswerCount > 0)
          Container(
            width: double.infinity,
            color: Theme.of(context).colorScheme.primaryContainer,
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    downloads.syncing
                        ? context.tr('downloads.syncing')
                        : context.tr('downloads.pending_answers', {'count': downloads.pendingAnswerCount}),
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ),
                if (!downloads.syncing)
                  TextButton(onPressed: () => downloads.syncPendingAnswers(), child: Text(context.tr('downloads.sync_now'))),
              ],
            ),
          ),
        Expanded(
          child: ListView.builder(
            itemCount: downloads.items.length,
            itemBuilder: (context, index) => _OfflineFileTile(record: downloads.items[index]),
          ),
        ),
      ],
    );
  }
}

class _OfflineFileTile extends StatelessWidget {
  const _OfflineFileTile({required this.record});

  final OfflineFileRecord record;

  void _open(BuildContext context) {
    if (!record.isVideo) {
      openLocalFile(context, record.localPath, record.name);
      return;
    }

    final file = FileItem.fromOfflineName(id: record.fileId, name: record.name, mimeType: record.mimeType, size: record.size);
    Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => MediaPlayerScreen(playlist: [file], initialIndex: 0, offlineRecord: record)),
    );
  }

  Widget _icon(String? extension) => Container(
        color: Colors.grey.withOpacity(0.12),
        child: Icon(fileIconForExtension(extension), color: Colors.grey.shade700),
      );

  @override
  Widget build(BuildContext context) {
    final downloads = context.read<DownloadsProvider>();
    final extension = record.name.contains('.') ? record.name.split('.').last : null;

    return ListTile(
      leading: SizedBox(
        width: 48,
        height: 48,
        child: ClipRRect(
          borderRadius: BorderRadius.circular(8),
          child: (record.mimeType ?? '').startsWith('image/')
              ? Image.file(File(record.localPath), fit: BoxFit.cover, cacheWidth: 144, errorBuilder: (_, __, ___) => _icon(extension))
              : FutureBuilder<File?>(
                  future: OfflineDownloadService.instance.thumbnailFor(record),
                  builder: (_, snap) => snap.data == null
                      ? _icon(extension)
                      : Image.file(snap.data!, fit: BoxFit.cover, cacheWidth: 144, errorBuilder: (_, __, ___) => _icon(extension)),
                ),
        ),
      ),
      title: Text(record.name),
      subtitle: Text(
        '${formatBytes(record.size)} · ${formatDate(record.downloadedAt)}'
        '${record.hasQuiz ? ' · ${context.tr('downloads.quiz_available_offline')}' : ''}',
      ),
      onTap: () => _open(context),
      trailing: IconButton(
        icon: const Icon(Icons.delete_outline_rounded),
        tooltip: context.tr('downloads.delete_local_copy'),
        onPressed: () => downloads.delete(record.fileId),
      ),
    );
  }
}
