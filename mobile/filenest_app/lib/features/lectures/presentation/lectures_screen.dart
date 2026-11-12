import 'package:flutter/material.dart';

import '../../../core/models/file_item.dart';
import '../../../core/network/api_client.dart';
import '../../../core/widgets/authenticated_thumbnail.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/ui_kit.dart';
import '../../media_player/presentation/media_player_screen.dart';
import '../../site/site_api.dart';

/// «المحاضرات المرئية», as on the site: every video the user may watch, by folder or as one
/// list, with search. Kept on the device like the other site pages, so it opens offline too.
class LecturesScreen extends StatefulWidget {
  const LecturesScreen({super.key});

  @override
  State<LecturesScreen> createState() => _LecturesScreenState();
}

class _LecturesScreenState extends State<LecturesScreen> {
  List<Map<String, dynamic>> _folders = [];
  Map<int, FileItem> _videos = {};
  List<FileItem> _ordered = [];
  String? _error;
  bool _loading = true;
  String _q = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final d = await SiteApi.instance.lectures();
      final videos = (d['videos'] as List? ?? [])
          .map((v) => FileItem.fromJson(Map<String, dynamic>.from(v as Map)))
          .toList();
      if (!mounted) return;
      setState(() {
        _ordered = videos;
        _videos = {for (final v in videos) v.id: v};
        _folders = (d['folders'] as List? ?? []).map((f) => Map<String, dynamic>.from(f as Map)).toList();
        _loading = false;
      });
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = ApiClient.messageFor(e);
          _loading = false;
        });
      }
    }
  }

  List<FileItem> _videosOf(Map<String, dynamic> folder) =>
      (folder['video_ids'] as List? ?? []).map((id) => _videos[(id as num).toInt()]).whereType<FileItem>().toList();

  bool _match(String text) => _q.isEmpty || text.toLowerCase().contains(_q.toLowerCase());

  void _play(List<FileItem> list, int index) {
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => MediaPlayerScreen(playlist: list, initialIndex: index)));
  }

  String _folderName(BuildContext context, Map<String, dynamic> f) =>
      (f['name'] as String?) ?? context.l('خارج المجلدات', 'Not in a folder');

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: Text(context.l('المحاضرات المرئية', 'Video lectures')),
          bottom: PreferredSize(
            preferredSize: const Size.fromHeight(112),
            child: Column(children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                child: TextField(
                  onChanged: (v) => setState(() => _q = v.trim()),
                  decoration: InputDecoration(
                    hintText: context.l('ابحث عن محاضرة أو مادة', 'Search lectures or subjects'),
                    prefixIcon: const Icon(Icons.search_rounded),
                    isDense: true,
                  ),
                ),
              ),
              TabBar(tabs: [
                Tab(text: context.l('حسب المجلد', 'By folder')),
                Tab(text: context.l('كل المقاطع', 'All videos')),
              ]),
            ]),
          ),
        ),
        body: _loading
            ? const Center(child: CircularProgressIndicator())
            : _error != null
                ? EmptyState(icon: Icons.cloud_off_rounded, message: _error!, actionLabel: context.l('إعادة المحاولة', 'Retry'), onAction: _load)
                : TabBarView(children: [_buildFolders(context), _buildAll(context)]),
      ),
    );
  }

  Widget _buildFolders(BuildContext context) {
    final folders = _folders.where((f) {
      final path = ((f['path'] as List?) ?? []).join(' ');
      return _match('${_folderName(context, f)} $path') || _videosOf(f).any((v) => _match(v.name));
    }).toList();
    if (folders.isEmpty) {
      return EmptyState(icon: Icons.video_library_outlined, message: context.l('لا توجد محاضرات مرئية متاحة لك بعد.', 'No video lectures available to you yet.'));
    }
    return RefreshIndicator(
      onRefresh: _load,
      child: LayoutBuilder(builder: (context, c) {
        final columns = (c.maxWidth / 320).floor().clamp(1, 4);
        return GridView.builder(
          padding: const EdgeInsets.all(14),
          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: columns,
            mainAxisSpacing: 14,
            crossAxisSpacing: 14,
            childAspectRatio: 1.35,
          ),
          itemCount: folders.length,
          itemBuilder: (context, i) => _FolderCard(
            name: _folderName(context, folders[i]),
            path: ((folders[i]['path'] as List?) ?? []).cast<String>(),
            count: (folders[i]['count'] as num?)?.toInt() ?? 0,
            cover: _videosOf(folders[i]).firstWhere((v) => v.thumbnailUrl != null, orElse: () => _videosOf(folders[i]).first),
            onTap: () => Navigator.of(context).push(MaterialPageRoute(
              builder: (_) => _FolderVideosScreen(title: _folderName(context, folders[i]), videos: _videosOf(folders[i])),
            )),
          ),
        );
      }),
    );
  }

  Widget _buildAll(BuildContext context) {
    final list = _ordered.where((v) => _match(v.name)).toList();
    if (list.isEmpty) {
      return EmptyState(icon: Icons.search_off_rounded, message: context.l('لا نتائج مطابقة', 'No matches'));
    }
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.separated(
        padding: const EdgeInsets.all(14),
        itemCount: list.length,
        separatorBuilder: (_, __) => const SizedBox(height: 10),
        itemBuilder: (context, i) => _VideoRow(
          video: list[i],
          onTap: () {
            final folder = _folders.firstWhere(
              (f) => (f['video_ids'] as List? ?? []).contains(list[i].id),
              orElse: () => {'video_ids': [list[i].id]},
            );
            final siblings = _videosOf(folder);
            _play(siblings, siblings.indexWhere((v) => v.id == list[i].id).clamp(0, siblings.length - 1));
          },
        ),
      ),
    );
  }
}

class _FolderVideosScreen extends StatelessWidget {
  const _FolderVideosScreen({required this.title, required this.videos});

  final String title;
  final List<FileItem> videos;

  void _play(BuildContext context, int index) => Navigator.of(context)
      .push(MaterialPageRoute(builder: (_) => MediaPlayerScreen(playlist: videos, initialIndex: index)));

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(title, overflow: TextOverflow.ellipsis)),
      floatingActionButton: videos.isEmpty
          ? null
          : FloatingActionButton.extended(
              onPressed: () => _play(context, 0),
              icon: const Icon(Icons.play_arrow_rounded),
              label: Text(context.l('تشغيل الكل', 'Play all')),
            ),
      body: ListView.separated(
        padding: const EdgeInsets.fromLTRB(14, 14, 14, 90),
        itemCount: videos.length,
        separatorBuilder: (_, __) => const SizedBox(height: 10),
        itemBuilder: (context, i) => _VideoRow(video: videos[i], number: i + 1, onTap: () => _play(context, i)),
      ),
    );
  }
}

class _FolderCard extends StatelessWidget {
  const _FolderCard({required this.name, required this.path, required this.count, required this.cover, required this.onTap});

  final String name;
  final List<String> path;
  final int count;
  final FileItem cover;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Material(
      color: scheme.surfaceContainerLow,
      borderRadius: BorderRadius.circular(18),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Expanded(
            child: Stack(fit: StackFit.expand, children: [
              DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(colors: [scheme.primary, Colors.black87], begin: Alignment.topLeft, end: Alignment.bottomRight),
                ),
                child: const Icon(Icons.folder_rounded, color: Colors.white38, size: 46),
              ),
              if (cover.thumbnailUrl != null) _Cover(url: cover.thumbnailUrl!),
              const DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(begin: Alignment.bottomCenter, end: Alignment.center, colors: [Colors.black87, Colors.transparent]),
                ),
              ),
              PositionedDirectional(
                end: 8,
                bottom: 8,
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(color: Colors.black.withValues(alpha: 0.7), borderRadius: BorderRadius.circular(8)),
                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                    const Icon(Icons.play_arrow_rounded, color: Colors.white, size: 15),
                    const SizedBox(width: 3),
                    Text(context.l('$count مقطع', '$count videos'),
                        style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w700)),
                  ]),
                ),
              ),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 8, 12, 10),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              if (path.isNotEmpty)
                Text(path.join(' › '),
                    maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 11, color: scheme.onSurfaceVariant)),
              Text(name, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700)),
            ]),
          ),
        ]),
      ),
    );
  }
}

/// A square thumbnail filling a wide frame (cropped, centred).
class _Cover extends StatelessWidget {
  const _Cover({required this.url});

  final String url;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(builder: (context, c) {
      final side = c.maxWidth > c.maxHeight ? c.maxWidth : c.maxHeight;
      return ClipRect(
        child: OverflowBox(
          maxWidth: side,
          maxHeight: side,
          child: AuthenticatedThumbnail(url: url, fallbackIcon: Icons.movie_outlined, size: side),
        ),
      );
    });
  }
}

class _VideoRow extends StatelessWidget {
  const _VideoRow({required this.video, required this.onTap, this.number});

  final FileItem video;
  final VoidCallback onTap;
  final int? number;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Material(
      color: scheme.surfaceContainerLow,
      borderRadius: BorderRadius.circular(14),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(8),
          child: Row(children: [
            ClipRRect(
              borderRadius: BorderRadius.circular(10),
              child: SizedBox(
                width: 120,
                height: 68,
                child: Stack(fit: StackFit.expand, children: [
                  const ColoredBox(color: Colors.black87, child: Icon(Icons.movie_outlined, color: Colors.white38)),
                  if (video.thumbnailUrl != null) _Cover(url: video.thumbnailUrl!),
                  const Center(child: Icon(Icons.play_circle_fill_rounded, color: Colors.white70, size: 28)),
                  if (number != null)
                    PositionedDirectional(
                      start: 4,
                      top: 4,
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                        decoration: BoxDecoration(color: Colors.black.withValues(alpha: 0.7), borderRadius: BorderRadius.circular(6)),
                        child: Text('$number', style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700)),
                      ),
                    ),
                ]),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(video.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600, height: 1.3)),
                const SizedBox(height: 4),
                Text(video.humanSize, style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant)),
              ]),
            ),
          ]),
        ),
      ),
    );
  }
}
