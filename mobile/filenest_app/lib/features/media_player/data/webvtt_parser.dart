import '../models/subtitle_cue.dart';

/// A small, dependency-free WebVTT parser — just enough to render
/// subtitles as an overlay `Text` synced to playback position. Not a
/// general-purpose WebVTT implementation (no styling cues, regions, or
/// karaoke timestamps), which this app's subtitle files never contain
/// anyway since the backend always produces them itself (SrtToVttConverter).
class WebVttParser {
  static final _timingPattern = RegExp(
    r'(\d{2}:)?(\d{2}):(\d{2})[.,](\d{3})\s*-->\s*(\d{2}:)?(\d{2}):(\d{2})[.,](\d{3})',
  );

  static List<SubtitleCue> parse(String content) {
    final normalized = content.replaceAll('\r\n', '\n').replaceAll('\r', '\n');
    final blocks = normalized.split(RegExp(r'\n\s*\n'));
    final cues = <SubtitleCue>[];

    for (final block in blocks) {
      final lines = block.split('\n').where((l) => l.trim().isNotEmpty).toList();
      if (lines.isEmpty) continue;

      final timingIndex = lines.indexWhere((l) => _timingPattern.hasMatch(l));
      if (timingIndex == -1) continue; // header line ("WEBVTT") or a stray cue-id with no timing

      final match = _timingPattern.firstMatch(lines[timingIndex])!;
      final start = _durationFrom(match, 1);
      final end = _durationFrom(match, 5);
      final text = lines.sublist(timingIndex + 1).join('\n').trim();

      if (text.isNotEmpty) {
        cues.add(SubtitleCue(start: start, end: end, text: text));
      }
    }

    return cues;
  }

  /// [groupOffset] is 1 for the start time's capture groups, 5 for the
  /// end time's — the hours group ([groupOffset]) is optional (WebVTT
  /// allows MM:SS.mmm for durations under an hour).
  static Duration _durationFrom(RegExpMatch match, int groupOffset) {
    final hours = match.group(groupOffset)?.replaceAll(':', '');
    final minutes = match.group(groupOffset + 1)!;
    final seconds = match.group(groupOffset + 2)!;
    final millis = match.group(groupOffset + 3)!;

    return Duration(
      hours: hours != null ? int.parse(hours) : 0,
      minutes: int.parse(minutes),
      seconds: int.parse(seconds),
      milliseconds: int.parse(millis),
    );
  }
}
