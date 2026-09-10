import 'package:dio/dio.dart';

import '../../../core/network/api_client.dart';
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
