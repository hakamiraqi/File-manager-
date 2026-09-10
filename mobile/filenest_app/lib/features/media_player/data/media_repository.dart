import 'dart:io';

import 'package:dio/dio.dart';

import '../../../core/network/api_client.dart';
import '../models/quiz_report.dart';
import '../models/subtitle_cue.dart';
import '../models/subtitle_track.dart';
import '../models/video_comment.dart';
import '../models/video_question.dart';
import 'webvtt_parser.dart';

/// Subtitle/quiz/comment listing and fetching for the in-app media player —
/// the mobile counterpart of the web player's subtitle menu, question
/// overlay, and comments panel (backend API 23.12/23.13).
class MediaRepository {
  final _dio = ApiClient.instance.dio;

  Future<List<SubtitleTrack>> subtitlesFor(int fileId) async {
    final response = await _dio.get('/files/$fileId/subtitles');
    return (response.data['subtitles'] as List)
        .map((e) => SubtitleTrack.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<List<SubtitleCue>> loadCues(int subtitleId) async {
    final response = await _dio.get<String>(
      '/subtitles/$subtitleId',
      options: Options(responseType: ResponseType.plain),
    );
    return WebVttParser.parse(response.data ?? '');
  }

  /// Raw JSON of `{questions, score}` exactly as the backend returns it —
  /// callers turn `questions` into [VideoQuestion] objects. Kept raw here
  /// (rather than parsed) so OfflineDownloadService can cache the same
  /// payload to disk verbatim for offline playback.
  Future<Map<String, dynamic>> questionsPayloadFor(int fileId) async {
    final response = await _dio.get('/files/$fileId/questions');
    return response.data as Map<String, dynamic>;
  }

  Future<List<VideoQuestion>> questionsFor(int fileId) async {
    final payload = await questionsPayloadFor(fileId);
    return (payload['questions'] as List)
        .map((e) => VideoQuestion.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<QuizAnswerResult> answerQuestion(int questionId, int choiceId) async {
    final response = await _dio.post('/questions/$questionId/answer', data: {'choice_id': choiceId});
    return QuizAnswerResult.fromJson(response.data as Map<String, dynamic>);
  }

  Future<void> createQuestion(
    int fileId, {
    required int timestampSeconds,
    required String question,
    required List<String> choices,
    required int correctIndex,
  }) {
    return _dio.post('/files/$fileId/questions', data: {
      'timestamp_seconds': timestampSeconds,
      'question': question,
      'choices': choices,
      'correct_index': correctIndex,
    });
  }

  Future<void> updateQuestion(
    int questionId, {
    required int timestampSeconds,
    required String question,
    required List<String> choices,
    required int correctIndex,
  }) {
    return _dio.put('/questions/$questionId', data: {
      'timestamp_seconds': timestampSeconds,
      'question': question,
      'choices': choices,
      'correct_index': correctIndex,
    });
  }

  Future<void> deleteQuestion(int questionId) => _dio.delete('/questions/$questionId');

  /// Uploads a JSON/CSV file to bulk-create questions — invalid rows are
  /// skipped server-side and reported back in [QuizImportResult.errors]
  /// rather than failing the whole batch.
  Future<QuizImportResult> importQuestions(int fileId, File file) async {
    final formData = FormData.fromMap({
      'file': await MultipartFile.fromFile(file.path, filename: file.uri.pathSegments.last),
    });
    final response = await _dio.post('/files/$fileId/questions/import', data: formData);
    return QuizImportResult.fromJson(response.data as Map<String, dynamic>);
  }

  /// Raw CSV text of the import template — callers write it to a local
  /// file and open it (see MediaPlayerScreen) since there is no browser
  /// download prompt on mobile.
  Future<String> importTemplateCsv() async {
    final response = await _dio.get<String>(
      '/questions/import/template',
      options: Options(responseType: ResponseType.plain),
    );
    return response.data ?? '';
  }

  Future<QuizReport> report(int fileId) async {
    final response = await _dio.get('/files/$fileId/questions/report');
    return QuizReport.fromJson(response.data as Map<String, dynamic>);
  }

  Future<List<VideoComment>> commentsFor(int fileId) async {
    final response = await _dio.get('/files/$fileId/comments');
    return (response.data['comments'] as List)
        .map((e) => VideoComment.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<VideoComment> postComment(int fileId, String body, {int? timestampSeconds}) async {
    final response = await _dio.post('/files/$fileId/comments', data: {
      'body': body,
      if (timestampSeconds != null) 'timestamp_seconds': timestampSeconds,
    });
    return VideoComment.fromJson(response.data['comment'] as Map<String, dynamic>);
  }

  Future<void> deleteComment(int commentId) => _dio.delete('/comments/$commentId');
}
