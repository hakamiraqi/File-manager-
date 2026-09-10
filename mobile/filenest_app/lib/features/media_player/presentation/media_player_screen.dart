import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:video_player/video_player.dart';

import '../../../core/models/file_item.dart';
import '../../../core/network/api_client.dart';
import '../../../core/storage/token_storage.dart';
import '../data/media_repository.dart';
import '../models/subtitle_cue.dart';
import '../models/subtitle_track.dart';

/// In-app video/audio player: streaming playback with a quality selector
/// (when the backend has transcoded alternate resolutions), subtitles
/// (WebVTT, rendered as a synced overlay since video_player has no
/// built-in caption renderer), a queue of sibling media files with
/// next/previous and autoplay-next, and the usual transport controls
/// (seek, speed, play/pause) — the mobile counterpart of the web
/// dashboard's Plyr/wavesurfer.js player (main README section 26).
class MediaPlayerScreen extends StatefulWidget {
  const MediaPlayerScreen({super.key, required this.playlist, required this.initialIndex});

  /// Sibling files of the same kind (all video, or all audio) from the
  /// current folder — the "queue". A single file with no siblings is
  /// just a playlist of length 1.
  final List<FileItem> playlist;
  final int initialIndex;

  @override
  State<MediaPlayerScreen> createState() => _MediaPlayerScreenState();
}

class _MediaPlayerScreenState extends State<MediaPlayerScreen> {
  final _mediaRepository = MediaRepository();

  VideoPlayerController? _controller;
  bool _isInitializing = true;
  String? _error;
  bool _endHandled = false;

  late int _currentIndex = widget.initialIndex;
  int? _selectedQualityHeight;
  double _playbackSpeed = 1.0;
  bool _autoplayNext = true;
  bool _isFullscreen = false;

  List<SubtitleTrack> _subtitleTracks = [];
  SubtitleTrack? _selectedSubtitleTrack;
  List<SubtitleCue> _cues = [];
  String? _currentSubtitleText;

  FileItem get _currentFile => widget.playlist[_currentIndex];
  bool get _hasPlaylist => widget.playlist.length > 1;

  @override
  void initState() {
    super.initState();
    _loadFileAt(_currentIndex);
  }

  @override
  void dispose() {
    _restoreSystemChrome();
    _controller?.removeListener(_onControllerUpdate);
    _controller?.dispose();
    super.dispose();
  }

  Future<void> _loadFileAt(int index, {bool autoplay = true}) async {
    final file = widget.playlist[index];

    setState(() {
      _currentIndex = index;
      _selectedQualityHeight = file.videoQualities.isNotEmpty ? file.videoQualities.first.height : null;
      _selectedSubtitleTrack = null;
      _subtitleTracks = [];
      _cues = [];
      _currentSubtitleText = null;
      _endHandled = false;
    });

    if (file.isVideo) {
      _mediaRepository.subtitlesFor(file.id).then((tracks) {
        if (mounted && file.id == _currentFile.id) setState(() => _subtitleTracks = tracks);
      }).catchError((_) {});
    }

    final source = file.videoQualities.isNotEmpty ? file.videoQualities.first.src : file.previewUrl;
    await _initializePlayer(source, autoplay: autoplay);
  }

  Future<void> _initializePlayer(String url, {Duration? seekTo, bool autoplay = true}) async {
    setState(() {
      _isInitializing = true;
      _error = null;
    });

    final oldController = _controller;
    _controller = null;
    oldController?.removeListener(_onControllerUpdate);
    await oldController?.dispose();

    final token = await TokenStorage.instance.read();
    final controller = VideoPlayerController.networkUrl(
      Uri.parse(url),
      httpHeaders: token != null ? {'Authorization': 'Bearer $token'} : {},
    );

    try {
      await controller.initialize();
      if (seekTo != null) await controller.seekTo(seekTo);
      await controller.setPlaybackSpeed(_playbackSpeed);
      controller.addListener(_onControllerUpdate);
      if (autoplay) await controller.play();

      if (!mounted) {
        await controller.dispose();
        return;
      }
      setState(() {
        _controller = controller;
        _isInitializing = false;
      });
    } catch (_) {
      await controller.dispose();
      if (mounted) {
        setState(() {
          _error = 'تعذّر تشغيل هذا الملف.';
          _isInitializing = false;
        });
      }
    }
  }

  void _onControllerUpdate() {
    final controller = _controller;
    if (controller == null) return;
    final value = controller.value;

    // Subtitle overlay: whichever cue's range contains the current
    // position, or none.
    if (_cues.isNotEmpty) {
      final match = _cues.where((c) => c.contains(value.position));
      final text = match.isEmpty ? null : match.first.text;
      if (text != _currentSubtitleText) {
        setState(() => _currentSubtitleText = text);
      }
    }

    // Autoplay-next: fire once per playback reaching its end.
    if (!_endHandled &&
        value.duration > Duration.zero &&
        value.position >= value.duration - const Duration(milliseconds: 300)) {
      _endHandled = true;
      if (_autoplayNext && _hasPlaylist && _currentIndex < widget.playlist.length - 1) {
        _loadFileAt(_currentIndex + 1);
      }
    }
  }

  void _playPrevious() {
    if (_currentIndex > 0) _loadFileAt(_currentIndex - 1);
  }

  void _playNext() {
    if (_currentIndex < widget.playlist.length - 1) _loadFileAt(_currentIndex + 1);
  }

  Future<void> _togglePlayPause() async {
    final controller = _controller;
    if (controller == null) return;
    if (controller.value.isPlaying) {
      await controller.pause();
    } else {
      await controller.play();
    }
    setState(() {});
  }

  Future<void> _setSpeed(double speed) async {
    setState(() => _playbackSpeed = speed);
    await _controller?.setPlaybackSpeed(speed);
  }

  Future<void> _switchQuality(VideoQualityOption quality) async {
    final position = _controller?.value.position ?? Duration.zero;
    final wasPlaying = _controller?.value.isPlaying ?? true;
    setState(() => _selectedQualityHeight = quality.height);
    await _initializePlayer(quality.src, seekTo: position, autoplay: wasPlaying);
  }

  Future<void> _selectSubtitleTrack(SubtitleTrack? track) async {
    setState(() {
      _selectedSubtitleTrack = track;
      _cues = [];
      _currentSubtitleText = null;
    });
    if (track == null) return;

    try {
      final cues = await _mediaRepository.loadCues(track.id);
      if (mounted && _selectedSubtitleTrack?.id == track.id) {
        setState(() => _cues = cues);
      }
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
    }
  }

  Future<void> _toggleFullscreen() async {
    setState(() => _isFullscreen = !_isFullscreen);
    if (_isFullscreen) {
      await SystemChrome.setPreferredOrientations([DeviceOrientation.landscapeLeft, DeviceOrientation.landscapeRight]);
      await SystemChrome.setEnabledSystemUIMode(SystemUiMode.immersiveSticky);
    } else {
      await _restoreSystemChrome();
    }
  }

  Future<void> _restoreSystemChrome() async {
    await SystemChrome.setPreferredOrientations(DeviceOrientation.values);
    await SystemChrome.setEnabledSystemUIMode(SystemUiMode.edgeToEdge);
  }

  String _formatDuration(Duration d) {
    final minutes = d.inMinutes.remainder(60).toString().padLeft(2, '0');
    final seconds = d.inSeconds.remainder(60).toString().padLeft(2, '0');
    return d.inHours > 0 ? '${d.inHours}:$minutes:$seconds' : '$minutes:$seconds';
  }

  @override
  Widget build(BuildContext context) {
    final body = SafeArea(
      top: !_isFullscreen,
      bottom: !_isFullscreen,
      child: Column(
        children: [
          Expanded(child: Center(child: _buildMediaArea())),
          if (!_isFullscreen || !_currentFile.isVideo) ...[
            _buildTransportControls(),
            if (_hasPlaylist) _buildPlaylist(),
          ],
        ],
      ),
    );

    if (_isFullscreen) {
      return Scaffold(backgroundColor: Colors.black, body: body);
    }

    return Scaffold(
      appBar: AppBar(title: Text(_currentFile.name, overflow: TextOverflow.ellipsis)),
      body: body,
    );
  }

  Widget _buildMediaArea() {
    if (_error != null) {
      return Text(_error!, style: const TextStyle(color: Colors.red));
    }
    if (_isInitializing || _controller == null) {
      return const CircularProgressIndicator();
    }

    if (!_currentFile.isVideo) {
      return const Icon(Icons.music_note_rounded, size: 96, color: Colors.grey);
    }

    return Stack(
      alignment: Alignment.bottomCenter,
      children: [
        GestureDetector(
          onTap: _toggleFullscreen,
          child: AspectRatio(
            aspectRatio: _controller!.value.aspectRatio == 0 ? 16 / 9 : _controller!.value.aspectRatio,
            child: VideoPlayer(_controller!),
          ),
        ),
        if (_currentSubtitleText != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 16),
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
              decoration: BoxDecoration(color: Colors.black87, borderRadius: BorderRadius.circular(6)),
              child: Text(
                _currentSubtitleText!,
                textAlign: TextAlign.center,
                style: const TextStyle(color: Colors.white, fontSize: 15),
              ),
            ),
          ),
      ],
    );
  }

  Widget _buildTransportControls() {
    final controller = _controller;
    final position = controller?.value.position ?? Duration.zero;
    final duration = controller?.value.duration ?? Duration.zero;
    final isPlaying = controller?.value.isPlaying ?? false;

    final maxMs = duration.inMilliseconds > 0 ? duration.inMilliseconds.toDouble() : 1.0;
    final positionMs = position.inMilliseconds.toDouble().clamp(0.0, maxMs);

    return Column(
      children: [
        Slider(
          value: positionMs,
          max: maxMs,
          onChanged: controller == null
              ? null
              : (value) => controller.seekTo(Duration(milliseconds: value.toInt())),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(_formatDuration(position), style: const TextStyle(fontSize: 12)),
              Text(_formatDuration(duration), style: const TextStyle(fontSize: 12)),
            ],
          ),
        ),
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            if (_hasPlaylist)
              IconButton(icon: const Icon(Icons.skip_previous_rounded), onPressed: _currentIndex > 0 ? _playPrevious : null),
            IconButton(
              iconSize: 40,
              icon: Icon(isPlaying ? Icons.pause_circle_filled_rounded : Icons.play_circle_filled_rounded),
              onPressed: controller == null ? null : _togglePlayPause,
            ),
            if (_hasPlaylist)
              IconButton(
                icon: const Icon(Icons.skip_next_rounded),
                onPressed: _currentIndex < widget.playlist.length - 1 ? _playNext : null,
              ),
          ],
        ),
        Wrap(
          alignment: WrapAlignment.center,
          spacing: 4,
          children: [
            PopupMenuButton<double>(
              icon: const Icon(Icons.speed_rounded),
              tooltip: 'السرعة',
              initialValue: _playbackSpeed,
              onSelected: _setSpeed,
              itemBuilder: (context) => const [0.5, 0.75, 1.0, 1.25, 1.5, 2.0]
                  .map((speed) => PopupMenuItem(value: speed, child: Text('${speed}x')))
                  .toList(),
            ),
            if (_currentFile.videoQualities.length > 1)
              PopupMenuButton<VideoQualityOption>(
                icon: const Icon(Icons.high_quality_outlined),
                tooltip: 'الجودة',
                onSelected: _switchQuality,
                itemBuilder: (context) => _currentFile.videoQualities
                    .map((q) => PopupMenuItem(
                          value: q,
                          child: Text(
                            '${q.height}p',
                            style: TextStyle(
                              fontWeight: q.height == _selectedQualityHeight ? FontWeight.bold : FontWeight.normal,
                            ),
                          ),
                        ))
                    .toList(),
              ),
            if (_subtitleTracks.isNotEmpty)
              PopupMenuButton<SubtitleTrack?>(
                icon: Icon(Icons.subtitles_outlined, color: _selectedSubtitleTrack != null ? Theme.of(context).colorScheme.primary : null),
                tooltip: 'الترجمة',
                onSelected: _selectSubtitleTrack,
                itemBuilder: (context) => [
                  const PopupMenuItem(value: null, child: Text('بلا ترجمة')),
                  ..._subtitleTracks.map((t) => PopupMenuItem(value: t, child: Text(t.label))),
                ],
              ),
            if (_currentFile.isVideo)
              IconButton(
                icon: Icon(_isFullscreen ? Icons.fullscreen_exit_rounded : Icons.fullscreen_rounded),
                tooltip: 'ملء الشاشة',
                onPressed: _toggleFullscreen,
              ),
            if (_hasPlaylist)
              IconButton(
                icon: Icon(
                  Icons.repeat_one_rounded,
                  color: _autoplayNext ? Theme.of(context).colorScheme.primary : Colors.grey,
                ),
                tooltip: 'تشغيل تلقائي للتالي',
                onPressed: () => setState(() => _autoplayNext = !_autoplayNext),
              ),
          ],
        ),
      ],
    );
  }

  Widget _buildPlaylist() {
    return SizedBox(
      height: 140,
      child: ListView.builder(
        itemCount: widget.playlist.length,
        itemBuilder: (context, index) {
          final file = widget.playlist[index];
          final isCurrent = index == _currentIndex;
          return ListTile(
            dense: true,
            selected: isCurrent,
            leading: Icon(file.isVideo ? Icons.movie_rounded : Icons.music_note_rounded),
            title: Text(file.name, maxLines: 1, overflow: TextOverflow.ellipsis),
            onTap: isCurrent ? null : () => _loadFileAt(index),
          );
        },
      ),
    );
  }
}
