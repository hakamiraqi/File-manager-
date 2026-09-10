/// One selectable choice of an interactive-video question. [isCorrect] is
/// only ever non-null for a viewer with the manage_questions capability —
/// matching the backend's own answer-key-hiding rule (README 27.6) — so a
/// cached offline copy carries the same visibility a regular viewer would
/// have seen online.
class VideoQuestionChoice {
  final int id;
  final String label;
  final bool? isCorrect;

  const VideoQuestionChoice({required this.id, required this.label, this.isCorrect});

  factory VideoQuestionChoice.fromJson(Map<String, dynamic> json) {
    return VideoQuestionChoice(
      id: json['id'] as int,
      label: json['label'] as String,
      isCorrect: json['is_correct'] as bool?,
    );
  }

  Map<String, dynamic> toJson() => {'id': id, 'label': label, 'is_correct': isCorrect};
}

/// A single timestamped question on a video, plus the current user's own
/// answer state — mirrors the JSON shape of `GET /files/{id}/questions`
/// (backend README 23.13) exactly, so the same model serializes straight
/// into the offline-downloads manifest for quiz caching.
class VideoQuestion {
  final int id;
  final int timestampSeconds;
  final String question;
  final List<VideoQuestionChoice> choices;
  int? answeredChoiceId;
  bool? answeredCorrectly;

  /// True once an answer has been recorded locally while offline but not
  /// yet confirmed by the server — the score badge and correctness reveal
  /// stay provisional until sync succeeds.
  bool pendingSync;

  VideoQuestion({
    required this.id,
    required this.timestampSeconds,
    required this.question,
    required this.choices,
    this.answeredChoiceId,
    this.answeredCorrectly,
    this.pendingSync = false,
  });

  bool get isAnswered => answeredChoiceId != null;

  factory VideoQuestion.fromJson(Map<String, dynamic> json) {
    return VideoQuestion(
      id: json['id'] as int,
      timestampSeconds: (json['timestamp_seconds'] as num).toInt(),
      question: json['question'] as String,
      choices: (json['choices'] as List)
          .map((e) => VideoQuestionChoice.fromJson(e as Map<String, dynamic>))
          .toList(),
      answeredChoiceId: json['answered_choice_id'] as int?,
      answeredCorrectly: json['answered_correctly'] as bool?,
      pendingSync: json['pending_sync'] as bool? ?? false,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'timestamp_seconds': timestampSeconds,
        'question': question,
        'choices': choices.map((c) => c.toJson()).toList(),
        'answered_choice_id': answeredChoiceId,
        'answered_correctly': answeredCorrectly,
        'pending_sync': pendingSync,
      };
}

class QuizScore {
  final int answered;
  final int correct;
  final int total;

  const QuizScore({required this.answered, required this.correct, required this.total});

  factory QuizScore.fromJson(Map<String, dynamic> json) {
    return QuizScore(
      answered: json['answered'] as int? ?? 0,
      correct: json['correct'] as int? ?? 0,
      total: json['total'] as int? ?? 0,
    );
  }

  static QuizScore fromQuestions(List<VideoQuestion> questions) {
    final answered = questions.where((q) => q.isAnswered).toList();
    return QuizScore(
      answered: answered.length,
      correct: answered.where((q) => q.answeredCorrectly == true).length,
      total: questions.length,
    );
  }
}

/// Result of `POST /questions/{id}/answer`.
class QuizAnswerResult {
  final bool isCorrect;
  final int? correctChoiceId;
  final QuizScore score;

  const QuizAnswerResult({required this.isCorrect, required this.correctChoiceId, required this.score});

  factory QuizAnswerResult.fromJson(Map<String, dynamic> json) {
    return QuizAnswerResult(
      isCorrect: json['is_correct'] as bool,
      correctChoiceId: json['correct_choice_id'] as int?,
      score: QuizScore.fromJson(json['score'] as Map<String, dynamic>),
    );
  }
}
