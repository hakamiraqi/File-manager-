import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math' as math;
import 'dart:ui' show FontFeature;

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';
import 'package:provider/provider.dart';
import 'package:screen_brightness/screen_brightness.dart';
import 'package:video_player/video_player.dart';
import 'package:volume_controller/volume_controller.dart';

import '../../../app/theme/app_theme.dart';
import '../../../core/i18n/app_strings.dart';
import '../../../core/models/file_item.dart';
import '../../../core/network/api_client.dart';
import '../../../core/storage/token_storage.dart';
import '../../../core/widgets/authenticated_thumbnail.dart';
import '../../auth/state/auth_provider.dart';
import '../../downloads/data/offline_download_service.dart';
import '../../downloads/models/offline_file.dart';
import '../data/media_repository.dart';
import '../models/quiz_report.dart';
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

  // Manual rotation for videos uploaded in the wrong orientation (portrait
  // recordings that play sideways) — 0..3, each step is a 90° clockwise turn.
  // Resets per file (see _loadFile) rather than persisting, since it's a
  // one-off viewing fix, not a property of the file itself.
  int _rotationQuarterTurns = 0;

  // YouTube-style gesture controls: vertical drag on the left/right half of
  // the video adjusts brightness/volume, double-tap seeks ±10s. Overlay
  // values are 0..1 (brightness/volume) shown briefly while dragging/after a
  // seek, then auto-hidden — mirrors how YouTube's own player gives instant
  // feedback without permanent on-screen sliders cluttering the video.
  double _brightness = 0.5;
  double _volume = 0.5;
  double? _dragStartValue;
  bool _showBrightnessOverlay = false;
  bool _showVolumeOverlay = false;
  String? _seekFeedback;
  int _seekSide = 1;
  Timer? _overlayHideTimer;

  // Overlay controls: shown on tap, hidden 3s later while playing.
  bool _controlsVisible = true;
  Timer? _controlsHideTimer;
  double? _scrubMs; // while the timeline is being dragged
  bool _boostingSpeed = false; // long-press = 2× while held

  // Autoplay-next waits a few seconds with a cancellable card.
  int? _upNextSeconds;
  Timer? _upNextTimer;

  // Carry on where this video was left last time.
  int? _resumedFrom;
  Timer? _resumeTimer;
  Timer? _positionSaveTimer;

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

  // Question authoring (manage_questions capability, online only) — the
  // mobile counterpart of PreviewModal.vue's question-manager panel.
  bool _showQuestionManager = false;
  int? _editingQuestionId;
  int _formTimestamp = 0;
  final _timestampController = TextEditingController();
  final _questionTextController = TextEditingController();
  List<TextEditingController> _choiceControllers = [TextEditingController(), TextEditingController()];
  int _correctIndex = 0;
  bool _savingQuestion = false;
  String? _questionFormError;

  bool _importing = false;
  String? _importMessage;
  String? _importError;

  bool _showReport = false;
  bool _loadingReport = false;
  QuizReport? _report;
  String? _reportError;

  FileItem get _currentFile => widget.playlist[_currentIndex];
  bool get _hasPlaylist => !_isOffline && widget.playlist.length > 1;
  bool get _isOffline => widget.offlineRecord != null;
  bool get _canManageQuestions => context.read<AuthProvider>().user?.permissions.manageQuestions ?? false;
  bool get _canComment => context.read<AuthProvider>().user?.permissions.comment ?? false;

  @override
  void initState() {
    super.initState();
    _loadFileAt(_currentIndex);
    _initGestureControls();
    _positionSaveTimer = Timer.periodic(const Duration(seconds: 10), (_) {
      if (_controller?.value.isPlaying ?? false) _rememberPosition();
    });
  }

  Future<void> _initGestureControls() async {
    try {
      _brightness = await ScreenBrightness().current;
    } catch (_) {
      // Some platforms (desktop, some emulators) don't expose brightness —
      // keep the 0.5 default rather than blocking playback on this.
    }
    VolumeController().showSystemUI = false;
    VolumeController().getVolume().then((v) {
      if (mounted) setState(() => _volume = v);
    });
  }

  @override
  void dispose() {
    _rememberPosition();
    _restoreSystemChrome();
    _overlayHideTimer?.cancel();
    _controlsHideTimer?.cancel();
    _upNextTimer?.cancel();
    _resumeTimer?.cancel();
    _positionSaveTimer?.cancel();
    ScreenBrightness().resetScreenBrightness().catchError((_) {});
    _controller?.removeListener(_onControllerUpdate);
    _controller?.dispose();
    _commentController.dispose();
    _timestampController.dispose();
    _questionTextController.dispose();
    for (final controller in _choiceControllers) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _loadFileAt(int index, {bool autoplay = true}) async {
    final file = widget.playlist[index];
    _rememberPosition();
    _upNextTimer?.cancel();
    _resumeTimer?.cancel();

    setState(() {
      _currentIndex = index;
      _upNextSeconds = null;
      _resumedFrom = null;
      _scrubMs = null;
      _controlsVisible = true;
      _rotationQuarterTurns = 0;
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
      _showQuestionManager = false;
      _showReport = false;
      _report = null;
      _reportError = null;
      _importMessage = null;
      _importError = null;
      _resetQuestionForm();
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
    } else {
      final source = file.videoQualities.isNotEmpty ? file.videoQualities.first.src : file.previewUrl;
      await _initializePlayer(source, autoplay: autoplay);
    }
    if (!mounted) return;
    await _offerResume(file);
    _showControls();
  }

  void _rememberPosition() {
    final controller = _controller;
    if (controller == null || !controller.value.isInitialized || _isInitializing) return;
    final position = controller.value.position.inSeconds;
    final duration = controller.value.duration.inSeconds;
    final nearlyDone = duration > 0 && position >= duration - 15;
    _PlaybackMemory.put(_currentFile.id, (position < 10 || nearlyDone) ? null : position);
  }

  Future<void> _offerResume(FileItem file) async {
    final saved = await _PlaybackMemory.get(file.id);
    final controller = _controller;
    if (saved == null || controller == null || !mounted || file.id != _currentFile.id) return;
    if (saved >= controller.value.duration.inSeconds - 15) return;
    await controller.seekTo(Duration(seconds: saved));
    if (!mounted) return;
    setState(() => _resumedFrom = saved);
    _resumeTimer?.cancel();
    _resumeTimer = Timer(const Duration(seconds: 7), () {
      if (mounted) setState(() => _resumedFrom = null);
    });
  }

  void _showControls({bool autoHide = true}) {
    _controlsHideTimer?.cancel();
    if (!_controlsVisible && mounted) setState(() => _controlsVisible = true);
    if (!autoHide) return;
    _controlsHideTimer = Timer(const Duration(seconds: 3), () {
      if (!mounted) return;
      final playing = _controller?.value.isPlaying ?? false;
      if (playing && _currentFile.isVideo && _scrubMs == null) setState(() => _controlsVisible = false);
    });
  }

  void _toggleControls() {
    if (_controlsVisible) {
      _controlsHideTimer?.cancel();
      setState(() => _controlsVisible = false);
    } else {
      _showControls();
    }
  }

  void _startUpNextCountdown() {
    _upNextTimer?.cancel();
    setState(() {
      _upNextSeconds = 5;
      _controlsVisible = true;
    });
    _upNextTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) {
        timer.cancel();
        return;
      }
      final left = (_upNextSeconds ?? 0) - 1;
      if (left <= 0) {
        timer.cancel();
        _playNext();
      } else {
        setState(() => _upNextSeconds = left);
      }
    });
  }

  void _cancelUpNext() {
    _upNextTimer?.cancel();
    setState(() => _upNextSeconds = null);
  }

  void _startSpeedBoost() {
    if (_controller?.value.isPlaying != true) return;
    HapticFeedback.selectionClick();
    setState(() => _boostingSpeed = true);
    _controller?.setPlaybackSpeed(2.0);
  }

  void _stopSpeedBoost() {
    if (!_boostingSpeed) return;
    setState(() => _boostingSpeed = false);
    _controller?.setPlaybackSpeed(_playbackSpeed);
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
          _error = isLocalFile ? context.tr('player.offline_playback_error') : context.tr('player.playback_error');
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
      _PlaybackMemory.put(_currentFile.id, null);
      if (_autoplayNext && _hasPlaylist && _currentIndex < widget.playlist.length - 1) {
        _startUpNextCountdown();
      } else {
        _showControls(autoHide: false);
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

  /// Clears the add/edit form back to "new question at the current
  /// playback position" — a plain mutation (no setState of its own) so
  /// callers can bundle it into their own setState alongside other state.
  void _resetQuestionForm() {
    _editingQuestionId = null;
    _formTimestamp = _controller?.value.position.inSeconds ?? 0;
    _timestampController.text = '$_formTimestamp';
    _questionTextController.clear();
    for (final controller in _choiceControllers) {
      controller.dispose();
    }
    _choiceControllers = [TextEditingController(), TextEditingController()];
    _correctIndex = 0;
    _questionFormError = null;
  }

  void _editQuestion(VideoQuestion question) {
    setState(() {
      _editingQuestionId = question.id;
      _formTimestamp = question.timestampSeconds;
      _timestampController.text = '$_formTimestamp';
      _questionTextController.text = question.question;
      for (final controller in _choiceControllers) {
        controller.dispose();
      }
      _choiceControllers = question.choices.map((c) => TextEditingController(text: c.label)).toList();
      final correctIndex = question.choices.indexWhere((c) => c.isCorrect == true);
      _correctIndex = correctIndex >= 0 ? correctIndex : 0;
      _questionFormError = null;
    });
  }

  void _addChoiceField() {
    if (_choiceControllers.length >= 6) return;
    setState(() => _choiceControllers.add(TextEditingController()));
  }

  void _removeChoiceField(int index) {
    if (_choiceControllers.length <= 2) return;
    setState(() {
      _choiceControllers.removeAt(index).dispose();
      if (_correctIndex >= _choiceControllers.length) _correctIndex = _choiceControllers.length - 1;
    });
  }

  Future<void> _submitQuestionForm() async {
    if (_savingQuestion) return;
    setState(() {
      _savingQuestion = true;
      _questionFormError = null;
    });

    final choices = _choiceControllers.map((c) => c.text.trim()).toList();

    try {
      if (_editingQuestionId != null) {
        await _mediaRepository.updateQuestion(
          _editingQuestionId!,
          timestampSeconds: _formTimestamp,
          question: _questionTextController.text.trim(),
          choices: choices,
          correctIndex: _correctIndex,
        );
      } else {
        await _mediaRepository.createQuestion(
          _currentFile.id,
          timestampSeconds: _formTimestamp,
          question: _questionTextController.text.trim(),
          choices: choices,
          correctIndex: _correctIndex,
        );
      }
      if (!mounted) return;
      setState(_resetQuestionForm);
      await _loadQuestions();
    } catch (e) {
      if (mounted) setState(() => _questionFormError = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _savingQuestion = false);
    }
  }

  Future<void> _deleteManagedQuestion(VideoQuestion question) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text(context.tr('player.delete_question_title')),
        content: Text(context.tr('player.confirm_delete_question', {'question': question.question})),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: Text(context.tr('action.cancel'))),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () => Navigator.of(context).pop(true),
            child: Text(context.tr('action.delete')),
          ),
        ],
      ),
    );
    if (confirmed != true) return;

    try {
      await _mediaRepository.deleteQuestion(question.id);
      await _loadQuestions();
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
    }
  }

  Future<void> _pickAndImportQuestions() async {
    final result = await FilePicker.platform.pickFiles(
      type: FileType.custom,
      allowedExtensions: ['json', 'csv', 'txt'],
    );
    if (result == null || result.files.single.path == null) return;

    setState(() {
      _importing = true;
      _importMessage = null;
      _importError = null;
    });

    try {
      final outcome = await _mediaRepository.importQuestions(_currentFile.id, File(result.files.single.path!));
      if (!mounted) return;
      setState(() {
        _importMessage = outcome.errors.isEmpty
            ? context.tr('player.import_success', {'count': outcome.created})
            : context.tr('player.import_success_with_errors', {'count': outcome.created, 'errors': outcome.errors.length});
      });
      await _loadQuestions();
    } catch (e) {
      if (mounted) setState(() => _importError = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _importing = false);
    }
  }

  /// Writes the template CSV to a temp file and hands it to the OS so the
  /// user can open/save it with whichever app they prefer — there is no
  /// browser download prompt to fall back on here like on the web.
  Future<void> _downloadImportTemplate() async {
    try {
      final csv = await _mediaRepository.importTemplateCsv();
      final dir = await getTemporaryDirectory();
      final file = File('${dir.path}/video-questions-template.csv');
      await file.writeAsString(csv);
      await OpenFilex.open(file.path);
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
    }
  }

  Future<void> _toggleReport() async {
    setState(() => _showReport = !_showReport);
    if (!_showReport || _report != null) return;

    setState(() {
      _loadingReport = true;
      _reportError = null;
    });
    try {
      final report = await _mediaRepository.report(_currentFile.id);
      if (mounted) setState(() => _report = report);
    } catch (e) {
      if (mounted) setState(() => _reportError = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _loadingReport = false);
    }
  }

  String _percentLabel(double? value) => value == null ? '—' : '${(value * 100).round()}%';

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
    final value = controller.value;
    if (value.isPlaying) {
      await controller.pause();
    } else {
      if (_upNextSeconds != null) _cancelUpNext();
      if (value.duration > Duration.zero && value.position >= value.duration - const Duration(milliseconds: 300)) {
        await controller.seekTo(Duration.zero);
        _endHandled = false;
      }
      await controller.play();
    }
    if (!mounted) return;
    setState(() {});
    _showControls();
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

  void _rotateVideo() {
    setState(() => _rotationQuarterTurns = (_rotationQuarterTurns + 1) % 4);
  }

  void _scheduleOverlayHide() {
    _overlayHideTimer?.cancel();
    _overlayHideTimer = Timer(const Duration(milliseconds: 700), () {
      if (!mounted) return;
      setState(() {
        _showBrightnessOverlay = false;
        _showVolumeOverlay = false;
        _seekFeedback = null;
      });
    });
  }

  void _onBrightnessDragStart(DragStartDetails details) {
    _dragStartValue = _brightness;
  }

  void _onBrightnessDragUpdate(DragUpdateDetails details, double areaHeight) {
    final start = _dragStartValue ?? _brightness;
    final delta = -details.delta.dy / areaHeight * 2.5;
    final next = (start + delta).clamp(0.0, 1.0);
    _dragStartValue = next;
    setState(() {
      _brightness = next;
      _showBrightnessOverlay = true;
    });
    ScreenBrightness().setScreenBrightness(next).catchError((_) {});
  }

  void _onVolumeDragStart(DragStartDetails details) {
    _dragStartValue = _volume;
  }

  void _onVolumeDragUpdate(DragUpdateDetails details, double areaHeight) {
    final start = _dragStartValue ?? _volume;
    final delta = -details.delta.dy / areaHeight * 2.5;
    final next = (start + delta).clamp(0.0, 1.0);
    _dragStartValue = next;
    setState(() {
      _volume = next;
      _showVolumeOverlay = true;
    });
    VolumeController().setVolume(next);
  }

  void _onGestureDragEnd(DragEndDetails details) {
    _dragStartValue = null;
    _scheduleOverlayHide();
  }

  void _seekRelative(int seconds) {
    final controller = _controller;
    if (controller == null) return;
    final duration = controller.value.duration;
    var target = controller.value.position + Duration(seconds: seconds);
    if (target < Duration.zero) target = Duration.zero;
    if (target > duration) target = duration;
    controller.seekTo(target);
    _seekSide = seconds < 0 ? -1 : 1;
    if (target < duration) _endHandled = false;
    setState(() => _seekFeedback = seconds > 0 ? '+$seconds${context.tr('player.seconds_short')}' : '$seconds${context.tr('player.seconds_short')}');
    _scheduleOverlayHide();
  }

  String _formatDuration(Duration d) {
    final minutes = d.inMinutes.remainder(60).toString().padLeft(2, '0');
    final seconds = d.inSeconds.remainder(60).toString().padLeft(2, '0');
    return d.inHours > 0 ? '${d.inHours}:$minutes:$seconds' : '$minutes:$seconds';
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
                        : Text(context.tr('preview.submit_answer')),
                  )
                else ...[
                  Text(
                    _answerFeedback!.correctChoiceId == null
                        ? context.tr('player.answer_recorded_offline')
                        : (_answerFeedback!.isCorrect ? context.tr('preview.correct_answer') : context.tr('preview.wrong_answer')),
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: _answerFeedback!.correctChoiceId == null
                          ? Colors.white
                          : (_answerFeedback!.isCorrect ? Colors.greenAccent : Colors.redAccent),
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 10),
                  FilledButton(onPressed: _continueAfterQuestion, child: Text(context.tr('preview.continue_watching'))),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildQuestionManagerPanel() {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_questions.isNotEmpty)
            ..._questions.map((q) => ListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  title: Text(q.question, maxLines: 1, overflow: TextOverflow.ellipsis),
                  subtitle: Text(_formatTimestamp(q.timestampSeconds)),
                  trailing: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      IconButton(icon: const Icon(Icons.edit_outlined, size: 20), onPressed: () => _editQuestion(q)),
                      IconButton(
                        icon: const Icon(Icons.delete_outline_rounded, size: 20, color: Colors.red),
                        onPressed: () => _deleteManagedQuestion(q),
                      ),
                    ],
                  ),
                )),
          const Divider(),
          Wrap(
            spacing: 8,
            runSpacing: 4,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              OutlinedButton.icon(
                onPressed: _importing ? null : _pickAndImportQuestions,
                icon: _importing
                    ? const SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2))
                    : const Icon(Icons.upload_file_outlined, size: 18),
                label: Text(context.tr('preview.import_button')),
              ),
              TextButton(onPressed: _downloadImportTemplate, child: Text(context.tr('preview.download_template'))),
            ],
          ),
          if (_importMessage != null)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(_importMessage!, style: const TextStyle(color: Colors.green, fontSize: 12)),
            ),
          if (_importError != null)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(_importError!, style: const TextStyle(color: Colors.red, fontSize: 12)),
            ),
          const Divider(),
          Text(
            _editingQuestionId != null ? context.tr('preview.edit_question_title') : context.tr('preview.new_question_title'),
            style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Text(context.tr('preview.appears_at_second'), style: const TextStyle(fontSize: 12)),
              const SizedBox(width: 8),
              SizedBox(
                width: 64,
                child: TextField(
                  controller: _timestampController,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(isDense: true),
                  onChanged: (v) => _formTimestamp = int.tryParse(v) ?? _formTimestamp,
                ),
              ),
              TextButton(
                onPressed: () => setState(() {
                  _formTimestamp = _controller?.value.position.inSeconds ?? 0;
                  _timestampController.text = '$_formTimestamp';
                }),
                child: Text(context.tr('preview.use_current_time'), style: const TextStyle(fontSize: 12)),
              ),
            ],
          ),
          TextField(
            controller: _questionTextController,
            maxLines: 2,
            decoration: InputDecoration(hintText: context.tr('preview.question_placeholder'), isDense: true),
          ),
          const SizedBox(height: 8),
          ..._choiceControllers.asMap().entries.map((entry) {
            final index = entry.key;
            return Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Row(
                children: [
                  Radio<int>(value: index, groupValue: _correctIndex, onChanged: (v) => setState(() => _correctIndex = v ?? 0)),
                  Expanded(
                    child: TextField(
                      controller: entry.value,
                      decoration: InputDecoration(hintText: context.tr('preview.choice_placeholder'), isDense: true),
                    ),
                  ),
                  if (_choiceControllers.length > 2)
                    IconButton(icon: const Icon(Icons.close_rounded, size: 18), onPressed: () => _removeChoiceField(index)),
                ],
              ),
            );
          }),
          if (_choiceControllers.length < 6)
            Align(
              alignment: Alignment.centerRight,
              child: TextButton.icon(
                onPressed: _addChoiceField,
                icon: const Icon(Icons.add_rounded, size: 16),
                label: Text(context.tr('preview.add_choice')),
              ),
            ),
          if (_questionFormError != null)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(_questionFormError!, style: const TextStyle(color: Colors.red, fontSize: 12)),
            ),
          const SizedBox(height: 8),
          Row(
            children: [
              FilledButton(
                onPressed: _savingQuestion ? null : _submitQuestionForm,
                child: Text(_editingQuestionId != null ? context.tr('preview.save_edit') : context.tr('preview.add_question')),
              ),
              if (_editingQuestionId != null)
                TextButton(onPressed: () => setState(_resetQuestionForm), child: Text(context.tr('preview.cancel_edit'))),
            ],
          ),
          const Divider(),
          TextButton.icon(
            onPressed: _toggleReport,
            icon: Icon(_showReport ? Icons.expand_less_rounded : Icons.bar_chart_rounded),
            label: Text(context.tr('preview.report_title')),
          ),
          if (_showReport) _buildReportSection(),
          const SizedBox(height: 8),
        ],
      ),
    );
  }

  Widget _buildReportSection() {
    if (_loadingReport) return const Padding(padding: EdgeInsets.all(12), child: Center(child: CircularProgressIndicator()));
    if (_reportError != null) return Text(_reportError!, style: const TextStyle(color: Colors.red, fontSize: 12));

    final report = _report;
    if (report == null) return const SizedBox.shrink();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 8),
          child: Text(
            context.tr('preview.report_respondents', {'count': report.summary.respondents}) +
                (report.summary.averagePercent != null
                    ? ' ${context.tr('preview.report_average', {'percent': _percentLabel(report.summary.averagePercent)})}'
                    : ''),
            style: const TextStyle(fontSize: 12, color: Colors.grey),
          ),
        ),
        ...report.questions.map((q) => Container(
              margin: const EdgeInsets.only(bottom: 8),
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(color: Colors.white.withValues(alpha: 0.05), borderRadius: BorderRadius.circular(8)),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${_formatTimestamp(q.timestampSeconds)} — ${q.question} ${context.tr('preview.report_answers_count', {'count': q.totalAnswers})}',
                    style: const TextStyle(fontSize: 12),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 4),
                  ...q.choices.map((choice) => Padding(
                        padding: const EdgeInsets.symmetric(vertical: 2),
                        child: Row(
                          children: [
                            SizedBox(
                              width: 70,
                              child: Text(
                                choice.label,
                                style: TextStyle(
                                  fontSize: 11,
                                  fontWeight: choice.isCorrect ? FontWeight.w600 : FontWeight.normal,
                                  color: choice.isCorrect ? Colors.greenAccent : Colors.white70,
                                ),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                            Expanded(
                              child: ClipRRect(
                                borderRadius: BorderRadius.circular(4),
                                child: LinearProgressIndicator(
                                  value: choice.percent ?? 0,
                                  minHeight: 8,
                                  backgroundColor: Colors.white12,
                                  valueColor: AlwaysStoppedAnimation(choice.isCorrect ? Colors.green : Colors.grey),
                                ),
                              ),
                            ),
                            SizedBox(width: 36, child: Text(_percentLabel(choice.percent), style: const TextStyle(fontSize: 11))),
                          ],
                        ),
                      )),
                ],
              ),
            )),
        if (report.users.isNotEmpty) ...[
          Text(context.tr('preview.report_by_user'), style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
          ...report.users.map((u) => Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Expanded(child: Text(u.name ?? u.email ?? '', style: const TextStyle(fontSize: 12), overflow: TextOverflow.ellipsis)),
                    Text('${u.correct}/${u.totalQuestions}', style: const TextStyle(fontSize: 11, color: Colors.grey)),
                    const SizedBox(width: 8),
                    Text(_percentLabel(u.percent), style: const TextStyle(fontSize: 12)),
                  ],
                ),
              )),
        ] else
          Text(context.tr('preview.report_no_answers'), style: const TextStyle(fontSize: 12, color: Colors.grey)),
      ],
    );
  }

  Widget _buildCommentsPanel() {
    return SizedBox(
      height: 220,
      child: Column(
        children: [
          Expanded(
            child: _comments.isEmpty
                ? Center(child: Text(context.tr('player.no_comments_yet'), style: const TextStyle(color: Colors.grey)))
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
          if (_canComment)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 8),
              child: Row(
                children: [
                  Checkbox(value: _anchorCommentToTime, onChanged: (v) => setState(() => _anchorCommentToTime = v ?? false)),
                  Text(context.tr('player.anchor_current_time'), style: const TextStyle(fontSize: 12)),
                  Expanded(
                    child: TextField(
                      controller: _commentController,
                      decoration: InputDecoration(hintText: context.tr('preview.comment_placeholder')),
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

  // ---------------------------------------------------------------------------
  // Layout: a dark "cinema" page. The stage (video or audio art) carries its
  // own overlay controls; details, actions and the queue scroll below it.
  // Media controls and the timeline stay left-to-right in Arabic too, like
  // every major player, so play/next/seek point the way the video moves.
  // ---------------------------------------------------------------------------

  static const _speeds = [0.5, 0.75, 1.0, 1.25, 1.5, 1.75, 2.0];
  static const _pageBackground = Color(0xFF0E1116);

  @override
  Widget build(BuildContext context) {
    return Theme(
      data: AppTheme.dark(),
      child: Builder(builder: (context) {
        final stage = _buildStage(context);
        final page = _isFullscreen
            ? Scaffold(backgroundColor: Colors.black, body: stage)
            : Scaffold(
                backgroundColor: _pageBackground,
                body: SafeArea(
                  bottom: false,
                  child: Column(children: [stage, Expanded(child: _buildDetails(context))]),
                ),
              );

        return PopScope(
          canPop: !_isFullscreen,
          onPopInvokedWithResult: (didPop, _) {
            if (!didPop && _isFullscreen) _toggleFullscreen();
          },
          child: CallbackShortcuts(
            bindings: {
              const SingleActivator(LogicalKeyboardKey.space): _togglePlayPause,
              const SingleActivator(LogicalKeyboardKey.keyK): _togglePlayPause,
              const SingleActivator(LogicalKeyboardKey.arrowLeft): () => _seekRelative(-10),
              const SingleActivator(LogicalKeyboardKey.arrowRight): () => _seekRelative(10),
              const SingleActivator(LogicalKeyboardKey.keyF): _toggleFullscreen,
              const SingleActivator(LogicalKeyboardKey.escape): () {
                if (_isFullscreen) _toggleFullscreen();
              },
            },
            child: Focus(autofocus: true, child: page),
          ),
        );
      }),
    );
  }

  bool get _isReady => _controller != null && !_isInitializing && _error == null;

  Widget _buildStage(BuildContext context) {
    final controller = _controller;
    final ready = _isReady;
    final videoRatio = ready && controller!.value.aspectRatio > 0 ? controller.value.aspectRatio : 16 / 9;

    Widget content;
    if (_error != null) {
      content = Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const Icon(Icons.error_outline_rounded, color: Colors.redAccent, size: 42),
            const SizedBox(height: 10),
            Text(_error!, textAlign: TextAlign.center, style: const TextStyle(color: Colors.white)),
          ]),
        ),
      );
    } else if (!ready) {
      content = const Center(child: CircularProgressIndicator(color: Colors.white));
    } else if (!_currentFile.isVideo) {
      content = _buildAudioArt(context);
    } else {
      content = Center(
        child: RotatedBox(
          quarterTurns: _rotationQuarterTurns,
          child: AspectRatio(aspectRatio: videoRatio, child: VideoPlayer(controller!)),
        ),
      );
    }

    final stage = ColoredBox(
      color: Colors.black,
      child: LayoutBuilder(builder: (context, constraints) {
        final areaHeight = constraints.maxHeight;
        return Stack(fit: StackFit.expand, children: [
          content,
          if (ready && _currentFile.isVideo) _buildGestureLayer(areaHeight),
          if (_currentSubtitleText != null)
            PositionedDirectional(
              start: 16,
              end: 16,
              bottom: _controlsVisible ? 78 : 22,
              child: Center(
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
                  decoration: BoxDecoration(color: Colors.black.withValues(alpha: 0.75), borderRadius: BorderRadius.circular(8)),
                  child: Text(
                    _currentSubtitleText!,
                    textAlign: TextAlign.center,
                    style: TextStyle(color: Colors.white, fontSize: _isFullscreen ? 19 : 15, height: 1.3),
                  ),
                ),
              ),
            ),
          if (_showBrightnessOverlay) _buildLevelPill(Icons.brightness_6_rounded, _brightness, left: true),
          if (_showVolumeOverlay)
            _buildLevelPill(_volume == 0 ? Icons.volume_off_rounded : Icons.volume_up_rounded, _volume, left: false),
          if (_seekFeedback != null) _buildSeekFeedback(_seekFeedback!),
          if (_boostingSpeed)
            Align(
              alignment: Alignment.topCenter,
              child: Padding(
                padding: const EdgeInsets.only(top: 14),
                child: _pill(Row(mainAxisSize: MainAxisSize.min, children: [
                  const Text('2×', style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
                  const SizedBox(width: 6),
                  const Icon(Icons.fast_forward_rounded, color: Colors.white, size: 18),
                ])),
              ),
            ),
          if (ready) _buildControlsOverlay(context) else Align(alignment: Alignment.topCenter, child: _buildTopBar(context)),
          if (_resumedFrom != null && _upNextSeconds == null) _buildResumePill(context),
          if (_upNextSeconds != null && _hasPlaylist && _currentIndex < widget.playlist.length - 1) _buildUpNextCard(context),
          if (_activeQuestion != null) Positioned.fill(child: _buildQuestionOverlay(_activeQuestion!)),
        ]);
      }),
    );

    if (_isFullscreen) return stage;

    final size = MediaQuery.of(context).size;
    final double shownRatio = _currentFile.isVideo
        ? (_rotationQuarterTurns.isOdd ? 1 / videoRatio : videoRatio).clamp(0.75, 2.4).toDouble()
        : 16 / 9;
    final double height = (size.width / shownRatio).clamp(200.0, math.max(200.0, size.height * 0.58)).toDouble();
    return SizedBox(width: double.infinity, height: height, child: stage);
  }

  Widget _buildAudioArt(BuildContext context) {
    final accent = Theme.of(context).colorScheme.primary;
    return DecoratedBox(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [accent.withValues(alpha: 0.55), const Color(0xFF14171D)],
        ),
      ),
      child: Center(
        child: Container(
          width: 92,
          height: 92,
          decoration: BoxDecoration(shape: BoxShape.circle, color: Colors.white.withValues(alpha: 0.12)),
          child: const Icon(Icons.graphic_eq_rounded, size: 50, color: Colors.white),
        ),
      ),
    );
  }

  /// Split touch zones, by physical side (forced LTR so Arabic doesn't swap
  /// them): tap shows/hides the controls, double-tap seeks ±10s, vertical
  /// drag sets brightness (left) or volume (right), long-press plays at 2×.
  Widget _buildGestureLayer(double areaHeight) {
    Widget zone(int side) => Expanded(
          child: GestureDetector(
            behavior: HitTestBehavior.translucent,
            onTap: _toggleControls,
            onDoubleTap: () => _seekRelative(side * 10),
            onLongPressStart: (_) => _startSpeedBoost(),
            onLongPressEnd: (_) => _stopSpeedBoost(),
            onVerticalDragStart: side < 0 ? _onBrightnessDragStart : _onVolumeDragStart,
            onVerticalDragUpdate: (d) => side < 0 ? _onBrightnessDragUpdate(d, areaHeight) : _onVolumeDragUpdate(d, areaHeight),
            onVerticalDragEnd: _onGestureDragEnd,
          ),
        );

    return Positioned.fill(
      child: Directionality(textDirection: TextDirection.ltr, child: Row(children: [zone(-1), zone(1)])),
    );
  }

  Widget _buildControlsOverlay(BuildContext context) {
    final visible = _controlsVisible || !_currentFile.isVideo;
    return Positioned.fill(
      child: IgnorePointer(
        ignoring: !visible,
        child: AnimatedOpacity(
          opacity: visible ? 1 : 0,
          duration: const Duration(milliseconds: 220),
          child: ValueListenableBuilder<VideoPlayerValue>(
            valueListenable: _controller!,
            builder: (context, value, _) => Stack(fit: StackFit.expand, children: [
              IgnorePointer(
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topCenter,
                      end: Alignment.bottomCenter,
                      colors: [
                        Colors.black.withValues(alpha: 0.6),
                        Colors.transparent,
                        Colors.black.withValues(alpha: _scrubMs != null ? 0.45 : 0.15),
                        Colors.black.withValues(alpha: 0.8),
                      ],
                      stops: const [0, 0.3, 0.6, 1],
                    ),
                  ),
                ),
              ),
              Column(children: [
                _buildTopBar(context),
                const Spacer(),
                _scrubMs != null ? _buildScrubTime(value) : _buildCenterControls(value),
                const Spacer(),
                _buildBottomBar(context, value),
              ]),
            ]),
          ),
        ),
      ),
    );
  }

  Widget _buildTopBar(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(4, _isFullscreen ? 10 : 2, 4, 0),
      child: Row(children: [
        IconButton(
          icon: const Icon(Icons.arrow_back_rounded, color: Colors.white),
          tooltip: context.tr('player.back'),
          onPressed: () => _isFullscreen ? _toggleFullscreen() : Navigator.of(context).maybePop(),
        ),
        Expanded(
          child: _isFullscreen
              ? _bidiText(context, _currentFile.name,
                  style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w600))
              : const SizedBox.shrink(),
        ),
        if (_isReady)
          IconButton(
            icon: const Icon(Icons.tune_rounded, color: Colors.white),
            tooltip: context.tr('player.settings'),
            onPressed: _openSettingsSheet,
          ),
      ]),
    );
  }

  Widget _buildCenterControls(VideoPlayerValue value) {
    final ended = value.duration > Duration.zero && value.position >= value.duration - const Duration(milliseconds: 300);
    final mainIcon = ended
        ? Icons.replay_rounded
        : (value.isPlaying ? Icons.pause_rounded : Icons.play_arrow_rounded);

    return Directionality(
      textDirection: TextDirection.ltr,
      child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
        if (_hasPlaylist) ...[
          _roundButton(Icons.skip_previous_rounded, _currentIndex > 0 ? _playPrevious : null,
              size: 28, tooltip: context.tr('player.previous')),
          const SizedBox(width: 10),
        ],
        _roundButton(Icons.replay_10_rounded, () => _seekRelative(-10), size: 30, tooltip: context.tr('player.back_10')),
        const SizedBox(width: 18),
        Material(
          color: Colors.white.withValues(alpha: 0.16),
          shape: const CircleBorder(),
          child: InkWell(
            customBorder: const CircleBorder(),
            onTap: _togglePlayPause,
            child: SizedBox(
              width: 70,
              height: 70,
              child: value.isBuffering && !value.isPlaying
                  ? const Padding(
                      padding: EdgeInsets.all(20),
                      child: CircularProgressIndicator(strokeWidth: 3, color: Colors.white),
                    )
                  : AnimatedSwitcher(
                      duration: const Duration(milliseconds: 160),
                      transitionBuilder: (child, anim) => ScaleTransition(scale: anim, child: child),
                      child: Icon(mainIcon, key: ValueKey(mainIcon), size: 44, color: Colors.white),
                    ),
            ),
          ),
        ),
        const SizedBox(width: 18),
        _roundButton(Icons.forward_10_rounded, () => _seekRelative(10), size: 30, tooltip: context.tr('player.forward_10')),
        if (_hasPlaylist) ...[
          const SizedBox(width: 10),
          _roundButton(Icons.skip_next_rounded, _currentIndex < widget.playlist.length - 1 ? _playNext : null,
              size: 28, tooltip: context.tr('player.next')),
        ],
      ]),
    );
  }

  Widget _buildScrubTime(VideoPlayerValue value) {
    return Directionality(
      textDirection: TextDirection.ltr,
      child: Text(
        '${_formatDuration(Duration(milliseconds: _scrubMs!.round()))} / ${_formatDuration(value.duration)}',
        style: const TextStyle(
          color: Colors.white,
          fontSize: 26,
          fontWeight: FontWeight.w700,
          fontFeatures: [FontFeature.tabularFigures()],
        ),
      ),
    );
  }

  Widget _roundButton(IconData icon, VoidCallback? onPressed, {double size = 26, String? tooltip}) {
    return IconButton(
      iconSize: size,
      tooltip: tooltip,
      color: Colors.white,
      disabledColor: Colors.white30,
      style: IconButton.styleFrom(backgroundColor: Colors.black.withValues(alpha: 0.25)),
      icon: Icon(icon),
      onPressed: onPressed == null
          ? null
          : () {
              onPressed();
              _showControls();
            },
    );
  }

  Widget _buildBottomBar(BuildContext context, VideoPlayerValue value) {
    final accent = Theme.of(context).colorScheme.primary;
    final durationMs = value.duration.inMilliseconds.toDouble();
    final maxMs = durationMs > 0 ? durationMs : 1.0;
    final positionMs = (_scrubMs ?? value.position.inMilliseconds.toDouble()).clamp(0.0, maxMs).toDouble();
    final bufferedMs = value.buffered.isEmpty
        ? 0.0
        : value.buffered.map((r) => r.end.inMilliseconds).reduce(math.max).toDouble().clamp(0.0, maxMs).toDouble();
    const timeStyle = TextStyle(color: Colors.white, fontSize: 12.5, fontFeatures: [FontFeature.tabularFigures()]);

    return Directionality(
      textDirection: TextDirection.ltr,
      child: Padding(
        padding: EdgeInsets.fromLTRB(6, 0, 6, _isFullscreen ? 14 : 2),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          SizedBox(
            height: 30,
            child: Stack(alignment: Alignment.center, children: [
              SliderTheme(
                data: SliderTheme.of(context).copyWith(
                  trackHeight: _scrubMs != null ? 5 : 3.5,
                  activeTrackColor: accent,
                  inactiveTrackColor: Colors.white24,
                  secondaryActiveTrackColor: Colors.white54,
                  thumbColor: accent,
                  overlayColor: accent.withValues(alpha: 0.22),
                  thumbShape: RoundSliderThumbShape(enabledThumbRadius: _scrubMs != null ? 9 : 6.5),
                  overlayShape: const RoundSliderOverlayShape(overlayRadius: 16),
                ),
                child: Slider(
                  value: positionMs,
                  max: maxMs,
                  secondaryTrackValue: bufferedMs,
                  onChangeStart: (v) {
                    _controlsHideTimer?.cancel();
                    setState(() => _scrubMs = v);
                  },
                  onChanged: (v) => setState(() => _scrubMs = v),
                  onChangeEnd: (v) {
                    _controller?.seekTo(Duration(milliseconds: v.round()));
                    setState(() => _scrubMs = null);
                    _showControls();
                  },
                ),
              ),
              // Where the interactive questions sit on the timeline.
              if (_questions.isNotEmpty && durationMs > 0)
                Positioned.fill(
                  child: IgnorePointer(
                    child: LayoutBuilder(builder: (context, c) {
                      const inset = 16.0;
                      final track = c.maxWidth - inset * 2;
                      return Stack(children: [
                        for (final q in _questions)
                          Positioned(
                            left: inset + track * ((q.timestampSeconds * 1000) / durationMs).clamp(0.0, 1.0).toDouble() - 3,
                            top: c.maxHeight / 2 - 3,
                            child: Container(
                              width: 6,
                              height: 6,
                              decoration: BoxDecoration(
                                shape: BoxShape.circle,
                                color: q.isAnswered ? Colors.white70 : Colors.amberAccent,
                              ),
                            ),
                          ),
                      ]);
                    }),
                  ),
                ),
            ]),
          ),
          Row(children: [
            const SizedBox(width: 10),
            Text('${_formatDuration(Duration(milliseconds: positionMs.round()))}  /  ${_formatDuration(value.duration)}',
                style: timeStyle),
            const Spacer(),
            if (_playbackSpeed != 1.0)
              TextButton(
                onPressed: _openSettingsSheet,
                style: TextButton.styleFrom(foregroundColor: Colors.white, minimumSize: const Size(40, 36)),
                child: Text(_speedLabel(_playbackSpeed), style: const TextStyle(fontWeight: FontWeight.w700)),
              ),
            if (_subtitleTracks.isNotEmpty)
              IconButton(
                color: Colors.white,
                tooltip: context.tr('preview.subtitles_title'),
                icon: Icon(_selectedSubtitleTrack != null ? Icons.closed_caption_rounded : Icons.closed_caption_off),
                onPressed: () => _selectSubtitleTrack(_selectedSubtitleTrack != null ? null : _subtitleTracks.first),
              ),
            if (_currentFile.isVideo)
              IconButton(
                color: Colors.white,
                tooltip: context.tr(_isFullscreen ? 'player.exit_fullscreen' : 'player.fullscreen'),
                icon: Icon(_isFullscreen ? Icons.fullscreen_exit_rounded : Icons.fullscreen_rounded),
                onPressed: _toggleFullscreen,
              ),
          ]),
        ]),
      ),
    );
  }

  Widget _pill(Widget child) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
      decoration: BoxDecoration(color: Colors.black.withValues(alpha: 0.72), borderRadius: BorderRadius.circular(20)),
      child: child,
    );
  }

  Widget _buildLevelPill(IconData icon, double value, {required bool left}) {
    return Directionality(
      textDirection: TextDirection.ltr,
      child: Align(
        alignment: left ? Alignment.centerLeft : Alignment.centerRight,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 22),
          child: Container(
            width: 46,
            height: 170,
            padding: const EdgeInsets.symmetric(vertical: 12),
            decoration: BoxDecoration(color: Colors.black.withValues(alpha: 0.7), borderRadius: BorderRadius.circular(23)),
            child: Column(children: [
              Icon(icon, color: Colors.white, size: 22),
              const SizedBox(height: 10),
              Expanded(
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(3),
                  child: Container(
                    width: 6,
                    color: Colors.white24,
                    alignment: Alignment.bottomCenter,
                    child: FractionallySizedBox(heightFactor: value.clamp(0.0, 1.0).toDouble(), child: Container(color: Colors.white)),
                  ),
                ),
              ),
              const SizedBox(height: 6),
              Text('${(value * 100).round()}', style: const TextStyle(color: Colors.white, fontSize: 11)),
            ]),
          ),
        ),
      ),
    );
  }

  Widget _buildSeekFeedback(String text) {
    final forward = _seekSide > 0;
    return Directionality(
      textDirection: TextDirection.ltr,
      child: Align(
        alignment: forward ? Alignment.centerRight : Alignment.centerLeft,
        child: FractionallySizedBox(
          widthFactor: 0.38,
          heightFactor: 1,
          child: Container(
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.10),
              borderRadius: forward
                  ? const BorderRadius.horizontal(left: Radius.circular(400))
                  : const BorderRadius.horizontal(right: Radius.circular(400)),
            ),
            child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
              Icon(forward ? Icons.fast_forward_rounded : Icons.fast_rewind_rounded, color: Colors.white, size: 34),
              const SizedBox(height: 4),
              Text(text, style: const TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.w700)),
            ]),
          ),
        ),
      ),
    );
  }

  Widget _buildResumePill(BuildContext context) {
    final accent = Theme.of(context).colorScheme.primary;
    return PositionedDirectional(
      start: 12,
      bottom: _controlsVisible ? 80 : 16,
      child: Material(
        color: Colors.black.withValues(alpha: 0.8),
        borderRadius: BorderRadius.circular(20),
        child: InkWell(
          borderRadius: BorderRadius.circular(20),
          onTap: () {
            _controller?.seekTo(Duration.zero);
            setState(() => _resumedFrom = null);
          },
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
            child: Row(mainAxisSize: MainAxisSize.min, children: [
              const Icon(Icons.history_rounded, color: Colors.white70, size: 18),
              const SizedBox(width: 8),
              Text(context.tr('player.resumed_from', {'time': _formatDuration(Duration(seconds: _resumedFrom!))}),
                  style: const TextStyle(color: Colors.white, fontSize: 13)),
              const SizedBox(width: 10),
              Text(context.tr('player.start_over'),
                  style: TextStyle(color: accent, fontSize: 13, fontWeight: FontWeight.w700)),
            ]),
          ),
        ),
      ),
    );
  }

  Widget _buildUpNextCard(BuildContext context) {
    final next = widget.playlist[_currentIndex + 1];
    return PositionedDirectional(
      end: 12,
      bottom: 76,
      child: Container(
        width: 260,
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: const Color(0xEE14171D),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: Colors.white12),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          Row(children: [
            SizedBox(
              width: 22,
              height: 22,
              child: CircularProgressIndicator(
                value: (_upNextSeconds ?? 0) / 5,
                strokeWidth: 2.5,
                color: Theme.of(context).colorScheme.primary,
                backgroundColor: Colors.white12,
              ),
            ),
            const SizedBox(width: 8),
            Text(context.tr('player.up_next_in', {'seconds': _upNextSeconds}),
                style: const TextStyle(color: Colors.white70, fontSize: 12.5)),
          ]),
          const SizedBox(height: 8),
          _bidiText(context, next.name,
              maxLines: 2, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 14)),
          const SizedBox(height: 10),
          Row(children: [
            Expanded(
              child: OutlinedButton(
                onPressed: _cancelUpNext,
                style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Colors.white24)),
                child: Text(context.tr('action.cancel')),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: FilledButton.icon(
                onPressed: () {
                  _upNextTimer?.cancel();
                  _playNext();
                },
                icon: const Icon(Icons.play_arrow_rounded, size: 18),
                label: Text(context.tr('player.play_now')),
              ),
            ),
          ]),
        ]),
      ),
    );
  }

  Future<void> _openSettingsSheet() async {
    _controlsHideTimer?.cancel();
    await showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      backgroundColor: const Color(0xFF181C23),
      builder: (sheetContext) => Theme(
        data: AppTheme.dark(),
        child: StatefulBuilder(builder: (ctx, setSheet) {
          void apply(VoidCallback change) {
            change();
            setSheet(() {});
          }

          Widget label(IconData icon, String text) => Padding(
                padding: const EdgeInsets.only(top: 6, bottom: 10),
                child: Row(children: [
                  Icon(icon, size: 18, color: Colors.white70),
                  const SizedBox(width: 8),
                  Text(text, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700)),
                ]),
              );

          return SafeArea(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                label(Icons.speed_rounded, ctx.tr('preview.speed')),
                Wrap(spacing: 8, runSpacing: 8, children: [
                  for (final speed in _speeds)
                    ChoiceChip(
                      label: Text(speed == 1.0 ? ctx.tr('player.speed_normal') : _speedLabel(speed)),
                      selected: _playbackSpeed == speed,
                      onSelected: (_) => apply(() => _setSpeed(speed)),
                    ),
                ]),
                if (_currentFile.videoQualities.length > 1) ...[
                  const SizedBox(height: 18),
                  label(Icons.high_quality_outlined, ctx.tr('player.quality')),
                  Wrap(spacing: 8, runSpacing: 8, children: [
                    for (final q in _currentFile.videoQualities)
                      ChoiceChip(
                        label: Text('${q.height}p'),
                        selected: q.height == _selectedQualityHeight,
                        onSelected: (_) {
                          Navigator.of(sheetContext).pop();
                          _switchQuality(q);
                        },
                      ),
                  ]),
                ],
                if (_subtitleTracks.isNotEmpty) ...[
                  const SizedBox(height: 18),
                  label(Icons.closed_caption_outlined, ctx.tr('preview.subtitles_title')),
                  Wrap(spacing: 8, runSpacing: 8, children: [
                    ChoiceChip(
                      label: Text(ctx.tr('player.no_subtitles')),
                      selected: _selectedSubtitleTrack == null,
                      onSelected: (_) => apply(() => _selectSubtitleTrack(null)),
                    ),
                    for (final track in _subtitleTracks)
                      ChoiceChip(
                        label: Text(track.label),
                        selected: _selectedSubtitleTrack?.id == track.id,
                        onSelected: (_) => apply(() => _selectSubtitleTrack(track)),
                      ),
                  ]),
                ],
                const SizedBox(height: 10),
                if (_currentFile.isVideo)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: const Icon(Icons.rotate_90_degrees_cw_rounded, color: Colors.white70),
                    title: Text(ctx.tr('player.rotate'), style: const TextStyle(color: Colors.white)),
                    trailing: Text('${_rotationQuarterTurns * 90}°', style: const TextStyle(color: Colors.white54)),
                    onTap: () => apply(_rotateVideo),
                  ),
                if (_hasPlaylist)
                  SwitchListTile(
                    contentPadding: EdgeInsets.zero,
                    secondary: const Icon(Icons.playlist_play_rounded, color: Colors.white70),
                    title: Text(ctx.tr('preview.autoplay_next'), style: const TextStyle(color: Colors.white)),
                    value: _autoplayNext,
                    onChanged: (v) => apply(() => setState(() => _autoplayNext = v)),
                  ),
              ]),
            ),
          );
        }),
      ),
    );
    if (mounted) _showControls();
  }

  // ---- Below the stage: title, facts, actions, panels, queue ----

  Widget _buildDetails(BuildContext context) {
    final controller = _controller;
    final duration = controller?.value.duration ?? Duration.zero;
    final pendingSync = _isOffline && _questions.any((q) => q.pendingSync);

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
      children: [
        _bidiText(context, _currentFile.name,
            maxLines: 2, style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w700, height: 1.35)),
        const SizedBox(height: 10),
        Wrap(spacing: 8, runSpacing: 8, children: [
          if (_hasPlaylist)
            _metaChip(Icons.playlist_play_rounded,
                context.tr('player.position_in_queue', {'index': _currentIndex + 1, 'total': widget.playlist.length})),
          if (duration > Duration.zero) _metaChip(Icons.schedule_rounded, _formatDuration(duration)),
          if (_currentFile.humanSize.isNotEmpty) _metaChip(Icons.save_outlined, _currentFile.humanSize),
          if (_isOffline) _metaChip(Icons.offline_pin_rounded, context.tr('player.offline_copy'), color: Colors.greenAccent),
          if (_score.total > 0)
            _metaChip(
              Icons.emoji_events_outlined,
              context.tr('preview.your_score', {'correct': _score.correct, 'total': _score.total}) +
                  (pendingSync ? ' ${context.tr('player.pending_sync')}' : ''),
              color: Colors.amberAccent,
            ),
        ]),
        const SizedBox(height: 16),
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: Row(children: [
            if (_currentFile.isVideo && !_isOffline)
              _actionChip(
                context,
                Icons.forum_outlined,
                context.tr('preview.comments_title', {'count': _comments.length}),
                () => setState(() => _showComments = !_showComments),
                selected: _showComments,
              ),
            if (_currentFile.isVideo && !_isOffline && _canManageQuestions)
              _actionChip(
                context,
                Icons.quiz_outlined,
                context.tr('preview.interactive_questions', {'count': _questions.length}),
                () => setState(() {
                  _showQuestionManager = !_showQuestionManager;
                  if (_showQuestionManager) _resetQuestionForm();
                }),
                selected: _showQuestionManager,
              ),
            if (_isReady) _actionChip(context, Icons.tune_rounded, context.tr('player.settings'), _openSettingsSheet),
            if (_isReady && _currentFile.isVideo)
              _actionChip(context, Icons.fullscreen_rounded, context.tr('player.fullscreen'), _toggleFullscreen),
          ]),
        ),
        if (_showComments && _currentFile.isVideo && !_isOffline) ...[
          const SizedBox(height: 12),
          _panelCard(_buildCommentsPanel()),
        ],
        if (_showQuestionManager && _currentFile.isVideo && !_isOffline && _canManageQuestions) ...[
          const SizedBox(height: 12),
          _panelCard(_buildQuestionManagerPanel()),
        ],
        if (_hasPlaylist) ...[
          const SizedBox(height: 22),
          _buildQueue(context),
        ],
        if (_currentFile.isVideo) ...[
          const SizedBox(height: 18),
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            const Icon(Icons.touch_app_outlined, size: 16, color: Colors.white38),
            const SizedBox(width: 8),
            Expanded(
              child: Text(context.tr('player.gesture_tip'),
                  style: const TextStyle(color: Colors.white38, fontSize: 12, height: 1.5)),
            ),
          ]),
        ],
      ],
    );
  }

  Widget _metaChip(IconData icon, String text, {Color color = Colors.white70}) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(color: Colors.white.withValues(alpha: 0.06), borderRadius: BorderRadius.circular(20)),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(icon, size: 15, color: color),
        const SizedBox(width: 5),
        Text(text, style: TextStyle(color: color, fontSize: 12.5)),
      ]),
    );
  }

  Widget _actionChip(BuildContext context, IconData icon, String label, VoidCallback onTap, {bool selected = false}) {
    final accent = Theme.of(context).colorScheme.primary;
    return Padding(
      padding: const EdgeInsetsDirectional.only(end: 8),
      child: Material(
        color: selected ? accent.withValues(alpha: 0.2) : Colors.white.withValues(alpha: 0.07),
        shape: StadiumBorder(side: BorderSide(color: selected ? accent : Colors.white12)),
        child: InkWell(
          customBorder: const StadiumBorder(),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
            child: Row(mainAxisSize: MainAxisSize.min, children: [
              Icon(icon, size: 18, color: selected ? accent : Colors.white70),
              const SizedBox(width: 6),
              Text(label,
                  style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: selected ? accent : Colors.white)),
            ]),
          ),
        ),
      ),
    );
  }

  Widget _panelCard(Widget child) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 8),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.04),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.white10),
      ),
      child: child,
    );
  }

  Widget _buildQueue(BuildContext context) {
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Row(children: [
        const Icon(Icons.video_library_outlined, color: Colors.white, size: 20),
        const SizedBox(width: 8),
        Text(context.tr('player.up_next_title'),
            style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w700)),
        const SizedBox(width: 8),
        Directionality(
          textDirection: TextDirection.ltr,
          child: Text('${_currentIndex + 1}/${widget.playlist.length}', style: const TextStyle(color: Colors.white54)),
        ),
        const Spacer(),
        Flexible(
          child: Text(context.tr('preview.autoplay_next'),
              overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white70, fontSize: 12)),
        ),
        Switch(value: _autoplayNext, onChanged: (v) => setState(() => _autoplayNext = v)),
      ]),
      const SizedBox(height: 8),
      for (var i = 0; i < widget.playlist.length; i++) _buildQueueItem(context, i),
    ]);
  }

  Widget _buildQueueItem(BuildContext context, int index) {
    final file = widget.playlist[index];
    final current = index == _currentIndex;
    final accent = Theme.of(context).colorScheme.primary;
    final fallback = file.isVideo ? Icons.movie_outlined : Icons.music_note_rounded;

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Material(
        color: current ? accent.withValues(alpha: 0.15) : Colors.white.withValues(alpha: 0.04),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
          side: BorderSide(color: current ? accent.withValues(alpha: 0.6) : Colors.transparent),
        ),
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: current ? _togglePlayPause : () => _loadFileAt(index),
          child: Padding(
            padding: const EdgeInsets.all(8),
            child: Row(children: [
              SizedBox(
                width: 24,
                child: current
                    ? Icon(Icons.equalizer_rounded, color: accent, size: 20)
                    : Text('${index + 1}', textAlign: TextAlign.center, style: const TextStyle(color: Colors.white38)),
              ),
              const SizedBox(width: 6),
              ClipRRect(
                borderRadius: BorderRadius.circular(10),
                child: SizedBox(
                  width: 104,
                  height: 58,
                  child: Stack(fit: StackFit.expand, children: [
                    ColoredBox(color: Colors.white10, child: Icon(fallback, color: Colors.white38)),
                    if (file.hasThumbnail && file.thumbnailUrl != null)
                      // A square thumbnail, centred and cropped to the 16:9 frame.
                      OverflowBox(
                        maxWidth: 104,
                        maxHeight: 104,
                        child: AuthenticatedThumbnail(url: file.thumbnailUrl!, fallbackIcon: fallback, size: 104),
                      ),
                    if (current)
                      const ColoredBox(
                        color: Colors.black38,
                        child: Icon(Icons.play_circle_outline_rounded, color: Colors.white, size: 26),
                      ),
                  ]),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  _bidiText(context, file.name,
                      maxLines: 2,
                      style: TextStyle(
                        color: current ? accent : Colors.white,
                        fontSize: 14,
                        fontWeight: current ? FontWeight.w700 : FontWeight.w500,
                      )),
                  const SizedBox(height: 4),
                  Text(
                    current ? context.tr('player.now_playing') : file.humanSize,
                    style: TextStyle(color: current ? accent : Colors.white54, fontSize: 12),
                  ),
                ]),
              ),
            ]),
          ),
        ),
      ),
    );
  }

  /// File names mix English and Arabic; render each in its own direction so
  /// "2.2mm Drilling Guide.mp4" doesn't come out as "mm Drilling Guide.mp4 2.2",
  /// while keeping it aligned to the page's reading side.
  Widget _bidiText(BuildContext context, String text, {TextStyle? style, int maxLines = 1}) {
    return SizedBox(
      width: double.infinity,
      child: Text(
        text,
        textDirection: _directionOf(text),
        textAlign: Directionality.of(context) == TextDirection.rtl ? TextAlign.right : TextAlign.left,
        maxLines: maxLines,
        overflow: TextOverflow.ellipsis,
        style: style,
      ),
    );
  }

  static TextDirection? _directionOf(String text) {
    for (final rune in text.runes) {
      if ((rune >= 0x0590 && rune <= 0x08FF) || (rune >= 0xFB1D && rune <= 0xFDFF) || (rune >= 0xFE70 && rune <= 0xFEFF)) {
        return TextDirection.rtl;
      }
      if ((rune >= 0x41 && rune <= 0x5A) || (rune >= 0x61 && rune <= 0x7A) || (rune >= 0xC0 && rune <= 0x24F)) {
        return TextDirection.ltr;
      }
    }
    return null;
  }

  String _speedLabel(double speed) => '${speed == speed.roundToDouble() ? speed.toInt() : speed}×';
}

/// Where each video was left off, so reopening it carries on from there
/// (kept on the device only; a small JSON file in the app's support folder).
class _PlaybackMemory {
  static Map<String, int>? _cache;

  static Future<File> _file() async => File('${(await getApplicationSupportDirectory()).path}/playback_positions.json');

  static Future<Map<String, int>> _load() async {
    if (_cache != null) return _cache!;
    try {
      final file = await _file();
      if (await file.exists()) {
        final decoded = jsonDecode(await file.readAsString()) as Map<String, dynamic>;
        _cache = decoded.map((k, v) => MapEntry(k, (v as num).toInt()));
      }
    } catch (_) {
      // A damaged file just means starting from the beginning.
    }
    return _cache ??= {};
  }

  static Future<int?> get(int fileId) async => (await _load())['$fileId'];

  static Future<void> put(int fileId, int? seconds) async {
    final map = await _load();
    if (seconds == null) {
      if (map.remove('$fileId') == null) return;
    } else {
      map.remove('$fileId');
      map['$fileId'] = seconds;
      while (map.length > 300) {
        map.remove(map.keys.first);
      }
    }
    try {
      await (await _file()).writeAsString(jsonEncode(map));
    } catch (_) {}
  }
}
