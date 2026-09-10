import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:video_player/video_player.dart';

import '../../../core/models/file_item.dart';
import '../../../core/network/api_client.dart';
import '../../../core/storage/token_storage.dart';
import '../../downloads/data/offline_download_service.dart';
import '../../downloads/models/offline_file.dart';
import '../data/media_repository.dart';
import '../models/subtitle_cue.dart';
import '../models/subtitle_track.dart';
import '../models/video_comment.dart';
import '../models/video_question.dart';

/// In-app video/audio player: streaming playback with a quality selector
/// (when the backend has transcoded alternate resolutions), subtitles
/// (WebVTT, rendered as a synced overlay since video_player has no
/// built-in caption renderer), a queue of sibling media files with
/// next/previous and autoplay-next, the usual transport controls (seek,
/// speed, play/pause), interactive-video questions that pause playback
/// and score the viewer, and a comments panel — the mobile counterpart of
/// the web dashboard's Plyr/wavesurfer.js player (main README sections 26
/// and 27).
///
/// When [offlineRecord] is set, video plays from the local downloaded
/// file instead of streaming, and the quiz runs entirely from the cached
/// questions in that record (see OfflineDownloadService) — no network
/// access at all. Subtitles, quality switching, and comments all require
/// the server and are simply unavailable in that mode.
class MediaPlayerScreen extends StatefulWidget {
  const MediaPlayerScreen({super.key, required this.playlist, required this.initialIndex, this.offlineRecord});

  /// Sibling files of the same kind (all video, or all audio) from the
  /// current folder — the "queue". A single file with no siblings is
  /// just a playlist of length 1. Ignored (playlist of one) when
  /// [offlineRecord] is set.
  final List<FileItem> playlist;
  final int initialIndex;
  final OfflineFileRecord? offlineRecord;

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

  // Interactive video: questions that pause playback at a timestamp.
  List<VideoQuestion> _questions = [];
  QuizScore _score = const QuizScore(answered: 0, correct: 0, total: 0);
  VideoQuestion? _activeQuestion;
  int? _selectedChoiceId;
  QuizAnswerResult? _answerFeedback;
  bool _answering = false;

  // Comments (online only — see _isOffline).
  List<VideoComment> _comments = [];
  final _commentController = TextEditingController();
  bool _anchorCommentToTime = false;
  bool _postingComment = false;
  bool _showComments = false;

  FileItem get _currentFile => widget.playlist[_currentIndex];
  bool get _hasPlaylist => !_isOffline && widget.playlist.length > 1;
  bool get _isOffline => widget.offlineRecord != null;

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
    _commentController.dispose();
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
      _questions = [];
      _score = const QuizScore(answered: 0, correct: 0, total: 0);
      _activeQuestion = null;
      _answerFeedback = null;
      _comments = [];
      _showComments = false;
    });

    if (file.isVideo) {
      if (_isOffline) {
        _loadQuestionsFromOfflineCache();
      } else {
        _mediaRepository.subtitlesFor(file.id).then((tracks) {
          if (mounted && file.id == _currentFile.id) setState(() => _subtitleTracks = tracks);
        }).catchError((_) {});
        _loadQuestions();
        _loadComments();
      }
    }

    if (_isOffline) {
      await _initializePlayer(widget.offlineRecord!.localPath, autoplay: autoplay, isLocalFile: true);
      return;
    }

    final source = file.videoQualities.isNotEmpty ? file.videoQualities.first.src : file.previewUrl;
    await _initializePlayer(source, autoplay: autoplay);
  }

  Future<void> _initializePlayer(String url, {Duration? seekTo, bool autoplay = true, bool isLocalFile = false}) async {
    setState(() {
      _isInitializing = true;
      _error = null;
    });

    final oldController = _controller;
    _controller = null;
    oldController?.removeListener(_onControllerUpdate);
    await oldController?.dispose();

    VideoPlayerController controller;
    if (isLocalFile) {
      controller = VideoPlayerController.file(File(url));
    } else {
      final token = await TokenStorage.instance.read();
      controller = VideoPlayerController.networkUrl(
        Uri.parse(url),
        httpHeaders: token != null ? {'Authorization': 'Bearer $token'} : {},
      );
    }

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
          _error = isLocalFile ? 'تعذّر تشغيل النسخة المحلية من هذا الملف.' : 'تعذّر تشغيل هذا الملف.';
          _isInitializing = false;
        });
      }
    }
  }

  Future<void> _loadQuestions() async {
    try {
      final questions = await _mediaRepository.questionsFor(_currentFile.id);
      if (mounted && _currentFile.isVideo) {
        setState(() {
          _questions = questions;
          _score = QuizScore.fromQuestions(questions);
        });
      }
    } catch (_) {
      // Feature disabled, or this viewer can't access it — no quiz, same
      // as the web player's behavior when GET .../questions 404s/403s.
    }
  }

  void _loadQuestionsFromOfflineCache() {
    final cached = widget.offlineRecord?.quizQuestions;
    if (cached == null || cached.isEmpty) return;

    final questions = cached.map((q) => VideoQuestion.fromJson(q)).toList();
    setState(() {
      _questions = questions;
      _score = QuizScore.fromQuestions(questions);
    });
  }

  Future<void> _loadComments() async {
    try {
      final comments = await _mediaRepository.commentsFor(_currentFile.id);
      if (mounted && _currentFile.isVideo) setState(() => _comments = comments);
    } catch (_) {
      // No comment capability, or a transient error — the panel just
      // stays empty rather than blocking playback.
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

    _enforceQuestionGate(controller, value.position);
  }

  /// Checked on every position update — a question at or before the
  /// current time that hasn't been answered yet pauses playback and snaps
  /// the position back to it, exactly like the web player's
  /// enforceQuestionGate() (main README 27.3): a question can never be
  /// skipped by seeking forward past it.
  void _enforceQuestionGate(VideoPlayerController controller, Duration position) {
    if (_activeQuestion != null || _questions.isEmpty) return;

    VideoQuestion? blocking;
    for (final question in _questions) {
      if (!question.isAnswered && position.inMilliseconds >= (question.timestampSeconds * 1000) - 400) {
        blocking = question;
        break;
      }
    }
    if (blocking == null) return;

    controller.pause();
    final target = Duration(seconds: blocking.timestampSeconds);
    if ((position - target).abs() > const Duration(seconds: 1)) {
      controller.seekTo(target);
    }
    setState(() {
      _activeQuestion = blocking;
      _selectedChoiceId = null;
      _answerFeedback = null;
    });
  }

  Color? _choiceColor(BuildContext context, VideoQuestionChoice choice) {
    if (_answerFeedback == null) {
      return _selectedChoiceId == choice.id ? Theme.of(context).colorScheme.primaryContainer : null;
    }
    if (choice.id == _answerFeedback!.correctChoiceId) return Colors.green.withOpacity(0.18);
    if (choice.id == _selectedChoiceId) return Colors.red.withOpacity(0.18);
    return null;
  }

  Future<void> _submitAnswer() async {
    final question = _activeQuestion;
    final choiceId = _selectedChoiceId;
    if (question == null || choiceId == null || _answering) return;

    setState(() => _answering = true);

    if (_isOffline) {
      final choice = question.choices.firstWhere((c) => c.id == choiceId);
      await OfflineDownloadService.instance.recordOfflineAnswer(
        fileId: _currentFile.id,
        questionId: question.id,
        choiceId: choiceId,
        isCorrectIfKnown: choice.isCorrect,
      );

      question.answeredChoiceId = choiceId;
      question.answeredCorrectly = choice.isCorrect;
      question.pendingSync = choice.isCorrect == null;

      if (!mounted) return;
      setState(() {
        _score = QuizScore.fromQuestions(_questions);
        _answerFeedback = QuizAnswerResult(
          isCorrect: choice.isCorrect ?? false,
          correctChoiceId: choice.isCorrect == true ? choice.id : null,
          score: _score,
        );
        _answering = false;
      });
      return;
    }

    try {
      final result = await _mediaRepository.answerQuestion(question.id, choiceId);
      question.answeredChoiceId = choiceId;
      question.answeredCorrectly = result.isCorrect;

      if (!mounted) return;
      setState(() {
        _score = result.score;
        _answerFeedback = result;
        _answering = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() => _answering = false);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
    }
  }

  void _continueAfterQuestion() {
    setState(() {
      _activeQuestion = null;
      _selectedChoiceId = null;
      _answerFeedback = null;
    });
    _controller?.play();
  }

  void _seekTo(int seconds) {
    _controller?.seekTo(Duration(seconds: seconds));
  }

  Future<void> _postComment() async {
    final body = _commentController.text.trim();
    if (body.isEmpty || _postingComment) return;

    setState(() => _postingComment = true);
    try {
      final timestamp = _anchorCommentToTime ? _controller?.value.position.inSeconds : null;
      final comment = await _mediaRepository.postComment(_currentFile.id, body, timestampSeconds: timestamp);
      if (!mounted) return;
      setState(() {
        _comments = [..._comments, comment];
        _commentController.clear();
      });
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
    } finally {
      if (mounted) setState(() => _postingComment = false);
    }
  }

  Future<void> _deleteComment(VideoComment comment) async {
    try {
      await _mediaRepository.deleteComment(comment.id);
      if (mounted) setState(() => _comments = _comments.where((c) => c.id != comment.id).toList());
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
    }
  }

  String _formatTimestamp(int seconds) {
    final m = seconds ~/ 60;
    final s = (seconds % 60).toString().padLeft(2, '0');
    return '$m:$s';
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
            if (_currentFile.isVideo && !_isOffline) _buildCommentsToggle(),
            if (_currentFile.isVideo && !_isOffline && _showComments) _buildCommentsPanel(),
          ],
        ],
      ),
    );

    if (_isFullscreen) {
      return Scaffold(backgroundColor: Colors.black, body: body);
    }

    return Scaffold(
      appBar: AppBar(
        title: Text(_currentFile.name, overflow: TextOverflow.ellipsis),
        bottom: _score.total > 0
            ? PreferredSize(
                preferredSize: const Size.fromHeight(28),
                child: Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: Text(
                    'درجتك: ${_score.correct} من ${_score.total}'
                    '${_isOffline && _questions.any((q) => q.pendingSync) ? ' (بانتظار المزامنة)' : ''}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ),
              )
            : null,
      ),
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
        if (_activeQuestion != null) Positioned.fill(child: _buildQuestionOverlay(_activeQuestion!)),
      ],
    );
  }

  Widget _buildQuestionOverlay(VideoQuestion question) {
    return Container(
      color: Colors.black87,
      padding: const EdgeInsets.all(20),
      child: Center(
        child: SingleChildScrollView(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  question.question,
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.w600),
                ),
                const SizedBox(height: 16),
                ...question.choices.map((choice) => Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: Material(
                        color: _choiceColor(context, choice) ?? Colors.white10,
                        borderRadius: BorderRadius.circular(10),
                        child: InkWell(
                          borderRadius: BorderRadius.circular(10),
                          onTap: _answerFeedback == null ? () => setState(() => _selectedChoiceId = choice.id) : null,
                          child: Padding(
                            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                            child: Row(
                              children: [
                                Icon(
                                  _selectedChoiceId == choice.id ? Icons.radio_button_checked : Icons.radio_button_off,
                                  color: Colors.white70,
                                  size: 18,
                                ),
                                const SizedBox(width: 10),
                                Expanded(child: Text(choice.label, style: const TextStyle(color: Colors.white))),
                              ],
                            ),
                          ),
                        ),
                      ),
                    )),
                const SizedBox(height: 8),
                if (_answerFeedback == null)
                  FilledButton(
                    onPressed: _selectedChoiceId == null || _answering ? null : _submitAnswer,
                    child: _answering
                        ? const SizedBox(
                            width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                        : const Text('إرسال الإجابة'),
                  )
                else ...[
                  Text(
                    _answerFeedback!.correctChoiceId == null
                        ? 'تم تسجيل إجابتك — ستظهر النتيجة عند الاتصال بالإنترنت.'
                        : (_answerFeedback!.isCorrect ? 'إجابة صحيحة!' : 'إجابة غير صحيحة.'),
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: _answerFeedback!.correctChoiceId == null
                          ? Colors.white
                          : (_answerFeedback!.isCorrect ? Colors.greenAccent : Colors.redAccent),
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 10),
                  FilledButton(onPressed: _continueAfterQuestion, child: const Text('متابعة المشاهدة')),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildCommentsToggle() {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      child: TextButton.icon(
        onPressed: () => setState(() => _showComments = !_showComments),
        icon: Icon(_showComments ? Icons.expand_less_rounded : Icons.comment_outlined),
        label: Text('التعليقات (${_comments.length})'),
      ),
    );
  }

  Widget _buildCommentsPanel() {
    return SizedBox(
      height: 220,
      child: Column(
        children: [
          Expanded(
            child: _comments.isEmpty
                ? const Center(child: Text('لا توجد تعليقات بعد.', style: TextStyle(color: Colors.grey)))
                : ListView.builder(
                    itemCount: _comments.length,
                    itemBuilder: (context, index) {
                      final comment = _comments[index];
                      return ListTile(
                        dense: true,
                        title: Text(comment.userName ?? ''),
                        subtitle: Text(comment.body),
                        leading: comment.timestampSeconds != null
                            ? TextButton(
                                onPressed: () => _seekTo(comment.timestampSeconds!),
                                child: Text(_formatTimestamp(comment.timestampSeconds!)),
                              )
                            : null,
                        trailing: IconButton(
                          icon: const Icon(Icons.delete_outline_rounded, size: 20),
                          onPressed: () => _deleteComment(comment),
                        ),
                      );
                    },
                  ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 8),
            child: Row(
              children: [
                Checkbox(value: _anchorCommentToTime, onChanged: (v) => setState(() => _anchorCommentToTime = v ?? false)),
                const Text('ربط بالوقت الحالي', style: TextStyle(fontSize: 12)),
                Expanded(
                  child: TextField(
                    controller: _commentController,
                    decoration: const InputDecoration(hintText: 'أضف تعليقاً...'),
                    onSubmitted: (_) => _postComment(),
                  ),
                ),
                IconButton(
                  icon: _postingComment
                      ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                      : const Icon(Icons.send_rounded),
                  onPressed: _postingComment ? null : _postComment,
                ),
              ],
            ),
          ),
        ],
      ),
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
