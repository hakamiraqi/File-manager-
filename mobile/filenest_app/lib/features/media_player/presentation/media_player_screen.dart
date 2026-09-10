import 'dart:io';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';
import 'package:provider/provider.dart';
import 'package:video_player/video_player.dart';

import '../../../core/i18n/app_strings.dart';
import '../../../core/models/file_item.dart';
import '../../../core/network/api_client.dart';
import '../../../core/storage/token_storage.dart';
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
  }

  @override
  void dispose() {
    _restoreSystemChrome();
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
            if (_currentFile.isVideo && !_isOffline && _canManageQuestions) _buildQuestionManagerToggle(),
            if (_currentFile.isVideo && !_isOffline && _canManageQuestions && _showQuestionManager) _buildQuestionManagerPanel(),
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
                    context.tr('preview.your_score', {'correct': _score.correct, 'total': _score.total}) +
                        (_isOffline && _questions.any((q) => q.pendingSync) ? ' ${context.tr('player.pending_sync')}' : ''),
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

  Widget _buildQuestionManagerToggle() {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      child: TextButton.icon(
        onPressed: () => setState(() {
          _showQuestionManager = !_showQuestionManager;
          if (_showQuestionManager) _resetQuestionForm();
        }),
        icon: Icon(_showQuestionManager ? Icons.expand_less_rounded : Icons.quiz_outlined),
        label: Text(context.tr('preview.interactive_questions', {'count': _questions.length})),
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
              decoration: BoxDecoration(color: Colors.grey.shade100, borderRadius: BorderRadius.circular(8)),
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
                                  color: choice.isCorrect ? Colors.green.shade700 : Colors.grey.shade700,
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
                                  backgroundColor: Colors.grey.shade300,
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

  Widget _buildCommentsToggle() {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      child: TextButton.icon(
        onPressed: () => setState(() => _showComments = !_showComments),
        icon: Icon(_showComments ? Icons.expand_less_rounded : Icons.comment_outlined),
        label: Text(context.tr('preview.comments_title', {'count': _comments.length})),
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
              tooltip: context.tr('preview.speed'),
              initialValue: _playbackSpeed,
              onSelected: _setSpeed,
              itemBuilder: (context) => const [0.5, 0.75, 1.0, 1.25, 1.5, 2.0]
                  .map((speed) => PopupMenuItem(value: speed, child: Text('${speed}x')))
                  .toList(),
            ),
            if (_currentFile.videoQualities.length > 1)
              PopupMenuButton<VideoQualityOption>(
                icon: const Icon(Icons.high_quality_outlined),
                tooltip: context.tr('player.quality'),
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
                tooltip: context.tr('preview.subtitles_title'),
                onSelected: _selectSubtitleTrack,
                itemBuilder: (context) => [
                  PopupMenuItem(value: null, child: Text(context.tr('player.no_subtitles'))),
                  ..._subtitleTracks.map((t) => PopupMenuItem(value: t, child: Text(t.label))),
                ],
              ),
            if (_currentFile.isVideo)
              IconButton(
                icon: Icon(_isFullscreen ? Icons.fullscreen_exit_rounded : Icons.fullscreen_rounded),
                tooltip: context.tr('player.fullscreen'),
                onPressed: _toggleFullscreen,
              ),
            if (_hasPlaylist)
              IconButton(
                icon: Icon(
                  Icons.repeat_one_rounded,
                  color: _autoplayNext ? Theme.of(context).colorScheme.primary : Colors.grey,
                ),
                tooltip: context.tr('preview.autoplay_next'),
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
