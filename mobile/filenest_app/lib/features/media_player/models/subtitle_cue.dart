/// One WebVTT cue: a time range plus the text shown during it.
class SubtitleCue {
  final Duration start;
  final Duration end;
  final String text;

  const SubtitleCue({required this.start, required this.end, required this.text});

  bool contains(Duration position) => position >= start && position <= end;
}
