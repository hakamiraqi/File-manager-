import 'package:flutter/material.dart';

import '../../../core/network/api_client.dart';

import '../../../app/theme/app_colors.dart';
import '../../../core/models/file_item.dart';
import '../../../core/models/folder_item.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/ui_kit.dart';
import '../../files/presentation/browse_screen.dart';
import '../../files/presentation/widgets/item_tiles.dart';
import '../../media_player/presentation/media_player_screen.dart';
import '../../site/site_api.dart';

/// «المحفوظات والتنزيلات»: what I saved for later and what I downloaded, as on the site.
class SavedScreen extends StatefulWidget {
  const SavedScreen({super.key, this.initialTab = 0});

  final int initialTab;

  @override
  State<SavedScreen> createState() => _SavedScreenState();
}

class _SavedScreenState extends State<SavedScreen> {
  Map<String, dynamic>? _data;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final d = await SiteApi.instance.saved();
      if (mounted) setState(() => _data = d);
    } catch (e) {
      if (mounted) setState(() => _error = ApiClient.messageFor(e));
    }
  }

  void _open(String type, Map<String, dynamic> item) {
    if (type == 'folder') {
      Navigator.of(context).push(MaterialPageRoute(builder: (_) => BrowseScreen(folder: FolderItem.fromJson(item))));
      return;
    }
    final file = FileItem.fromJson(item);
    if (file.isPlayableMedia) {
      Navigator.of(context).push(MaterialPageRoute(builder: (_) => MediaPlayerScreen(playlist: [file], initialIndex: 0)));
    } else {
      Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => BrowseScreen(
          folder: file.folderId == null ? null : FolderItem(id: file.folderId!, name: '', parentId: null, color: null, isPublic: false),
        ),
      ));
    }
  }

  Future<void> _unsave(String type, int id) async {
    try {
      await SiteApi.instance.toggleSaved(type, id);
      if (mounted) context.toast(context.l('أُزيل من المحفوظات.', 'Removed from saved.'));
      _load();
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  Future<void> _forget([int? id]) async {
    if (id == null) {
      final ok = await showDialog<bool>(
        context: context,
        builder: (_) => AlertDialog(
          title: Text(context.l('مسح سجل التنزيلات؟', 'Clear download history?')),
          content: Text(context.l('الملفات نفسها لا تُحذف، فقط تختفي من هذه القائمة.', 'The files stay; they only leave this list.')),
          actions: [
            TextButton(onPressed: () => Navigator.of(context).pop(false), child: Text(context.l('إلغاء', 'Cancel'))),
            TextButton(onPressed: () => Navigator.of(context).pop(true), child: Text(context.l('مسح', 'Clear'))),
          ],
        ),
      );
      if (ok != true) return;
    }
    try {
      await SiteApi.instance.forgetDownload(id);
      _load();
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  void _menu(String type, Map<String, dynamic> item, {required bool download}) {
    showModalBottomSheet<void>(
      context: context,
      useRootNavigator: true,
      builder: (sheet) => SafeArea(
        child: Wrap(children: [
          ListTile(
            leading: const Icon(Icons.open_in_new_rounded),
            title: Text(context.l('فتح', 'Open')),
            onTap: () {
              Navigator.of(sheet).pop();
              _open(type, item);
            },
          ),
          if (!download)
            ListTile(
              leading: const Icon(Icons.bookmark_remove_outlined, color: AppColors.danger),
              title: Text(context.l('إزالة من المحفوظات', 'Remove from saved'), style: const TextStyle(color: AppColors.danger)),
              onTap: () {
                Navigator.of(sheet).pop();
                _unsave(type, item['id'] as int);
              },
            )
          else
            ListTile(
              leading: const Icon(Icons.history_toggle_off_rounded, color: AppColors.danger),
              title: Text(context.l('إزالة من سجل التنزيلات', 'Remove from history'), style: const TextStyle(color: AppColors.danger)),
              onTap: () {
                Navigator.of(sheet).pop();
                _forget(item['id'] as int);
              },
            ),
        ]),
      ),
    );
  }

  Widget _list(List<Map> rows, {required bool download}) {
    if (rows.isEmpty) {
      return RefreshIndicator(
        onRefresh: _load,
        child: ListView(children: [
          const SizedBox(height: 80),
          EmptyState(
            icon: download ? Icons.download_done_rounded : Icons.bookmark_border_rounded,
            message: download
                ? context.l('لم تنزّل ملفات بعد.', 'No downloads yet.')
                : context.l('احفظ أي ملف أو مجلد من قائمته ليظهر هنا.', 'Save any file or folder from its menu to see it here.'),
          ),
        ]),
      );
    }
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.only(bottom: 24), children: [
        if (download)
          Align(
            alignment: AlignmentDirectional.centerEnd,
            child: TextButton.icon(
              onPressed: () => _forget(),
              icon: const Icon(Icons.delete_sweep_outlined),
              label: Text(context.l('مسح السجل', 'Clear history')),
            ),
          ),
        for (final r in rows)
          Builder(builder: (_) {
            final type = r['type'] as String;
            final item = Map<String, dynamic>.from(r['item'] as Map);
            if (type == 'folder') {
              return FolderTile(
                folder: FolderItem.fromJson(item),
                onTap: () => _open(type, item),
                onMenu: () => _menu(type, item, download: download),
                onLongPress: () => _menu(type, item, download: download),
              );
            }
            return FileTile(
              file: FileItem.fromJson(item),
              onTap: () => _open(type, item),
              onMenu: () => _menu(type, item, download: download),
              onLongPress: () => _menu(type, item, download: download),
            );
          }),
      ]),
    );
  }

  @override
  Widget build(BuildContext context) {
    final d = _data;
    return DefaultTabController(
      length: 2,
      initialIndex: widget.initialTab,
      child: Scaffold(
        appBar: AppBar(
          title: Text(context.l('المحفوظات والتنزيلات', 'Saved & downloads')),
          bottom: TabBar(tabs: [
            Tab(icon: const Icon(Icons.bookmark_rounded), text: context.l('المحفوظات', 'Saved')),
            Tab(icon: const Icon(Icons.download_done_rounded), text: context.l('سجل التنزيلات', 'Downloaded')),
          ]),
        ),
        body: d == null
            ? LoadingOrError(error: _error, onRetry: _load)
            : TabBarView(children: [
                _list((d['saved'] as List).cast<Map>(), download: false),
                _list((d['downloads'] as List).cast<Map>(), download: true),
              ]),
      ),
    );
  }
}
