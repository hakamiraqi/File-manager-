import 'package:dio/dio.dart';

import '../../../core/network/api_client.dart';
import '../models/subtitle_cue.dart';
import '../models/subtitle_track.dart';
import 'webvtt_parser.dart';

/// Subtitle listing/fetching for the in-app media player — the mobile
/// counterpart of the web player's subtitle menu (backend API 23.12).
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
}
