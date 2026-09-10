import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/utils/formatters.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/file_icons.dart';
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

  @override
  Widget build(BuildContext context) {
    final downloads = context.watch<DownloadsProvider>();

    if (downloads.loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (downloads.items.isEmpty) {
      return ListView(
        children: const [
          SizedBox(height: 80),
          EmptyState(
            icon: Icons.download_for_offline_outlined,
            message:
                'لا توجد ملفات محمّلة للعمل بدون إنترنت بعد.\nاضغط على أيقونة التنزيل بجانب أي ملف في "ملفاتي" لحفظه هنا.',
          ),
        ],
      );
    }

    return ListView.builder(
      itemCount: downloads.items.length,
      itemBuilder: (context, index) => _OfflineFileTile(record: downloads.items[index]),
    );
  }
}

class _OfflineFileTile extends StatelessWidget {
  const _OfflineFileTile({required this.record});

  final OfflineFileRecord record;

  @override
  Widget build(BuildContext context) {
    final downloads = context.read<DownloadsProvider>();
    final extension = record.name.contains('.') ? record.name.split('.').last : null;

    return ListTile(
      leading: Icon(fileIconForExtension(extension), color: Colors.grey.shade700),
      title: Text(record.name),
      subtitle: Text('${formatBytes(record.size)} · ${formatDate(record.downloadedAt)}'),
      onTap: () => downloads.openOffline(record.fileId),
      trailing: IconButton(
        icon: const Icon(Icons.delete_outline_rounded),
        tooltip: 'حذف النسخة المحلية',
        onPressed: () => downloads.delete(record.fileId),
      ),
    );
  }
}
