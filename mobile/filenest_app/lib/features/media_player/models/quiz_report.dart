/// Aggregate results for one video's quiz — mirrors
/// `GET /files/{id}/questions/report` (backend README 23.13/27.4): overall
/// respondent count/average, a per-question/per-choice breakdown, and a
/// per-user score table.
class QuizReportSummary {
  final int totalQuestions;
  final int respondents;
  final double? averagePercent;

  const QuizReportSummary({required this.totalQuestions, required this.respondents, required this.averagePercent});

  factory QuizReportSummary.fromJson(Map<String, dynamic> json) {
    return QuizReportSummary(
      totalQuestions: json['total_questions'] as int,
      respondents: json['respondents'] as int,
      averagePercent: (json['average_percent'] as num?)?.toDouble(),
    );
  }
}

class QuizReportChoice {
  final int id;
  final String label;
  final bool isCorrect;
  final int count;
  final double? percent;

  const QuizReportChoice({
    required this.id,
    required this.label,
    required this.isCorrect,
    required this.count,
    required this.percent,
  });

  factory QuizReportChoice.fromJson(Map<String, dynamic> json) {
    return QuizReportChoice(
      id: json['id'] as int,
      label: json['label'] as String,
      isCorrect: json['is_correct'] as bool,
      count: json['count'] as int,
      percent: (json['percent'] as num?)?.toDouble(),
    );
  }
}

class QuizReportQuestion {
  final int id;
  final int timestampSeconds;
  final String question;
  final int totalAnswers;
  final int correctCount;
  final double? correctRate;
  final List<QuizReportChoice> choices;

  const QuizReportQuestion({
    required this.id,
    required this.timestampSeconds,
    required this.question,
    required this.totalAnswers,
    required this.correctCount,
    required this.correctRate,
    required this.choices,
  });

  factory QuizReportQuestion.fromJson(Map<String, dynamic> json) {
    return QuizReportQuestion(
      id: json['id'] as int,
      timestampSeconds: (json['timestamp_seconds'] as num).toInt(),
      question: json['question'] as String,
      totalAnswers: json['total_answers'] as int,
      correctCount: json['correct_count'] as int,
      correctRate: (json['correct_rate'] as num?)?.toDouble(),
      choices: (json['choices'] as List).map((e) => QuizReportChoice.fromJson(e as Map<String, dynamic>)).toList(),
    );
  }
}

class QuizReportUser {
  final int userId;
  final String? name;
  final String? email;
  final int answered;
  final int correct;
  final int totalQuestions;
  final double? percent;

  const QuizReportUser({
    required this.userId,
    required this.name,
    required this.email,
    required this.answered,
    required this.correct,
    required this.totalQuestions,
    required this.percent,
  });

  factory QuizReportUser.fromJson(Map<String, dynamic> json) {
    return QuizReportUser(
      userId: json['user_id'] as int,
      name: json['name'] as String?,
      email: json['email'] as String?,
      answered: json['answered'] as int,
      correct: json['correct'] as int,
      totalQuestions: json['total_questions'] as int,
      percent: (json['percent'] as num?)?.toDouble(),
    );
  }
}

class QuizReport {
  final QuizReportSummary summary;
  final List<QuizReportQuestion> questions;
  final List<QuizReportUser> users;

  const QuizReport({required this.summary, required this.questions, required this.users});

  factory QuizReport.fromJson(Map<String, dynamic> json) {
    return QuizReport(
      summary: QuizReportSummary.fromJson(json['summary'] as Map<String, dynamic>),
      questions: (json['questions'] as List).map((e) => QuizReportQuestion.fromJson(e as Map<String, dynamic>)).toList(),
      users: (json['users'] as List).map((e) => QuizReportUser.fromJson(e as Map<String, dynamic>)).toList(),
    );
  }
}

/// Result of `POST /files/{id}/questions/import`.
class QuizImportResult {
  final int created;
  final List<String> errors;

  const QuizImportResult({required this.created, required this.errors});

  factory QuizImportResult.fromJson(Map<String, dynamic> json) {
    return QuizImportResult(
      created: json['created'] as int,
      errors: (json['errors'] as List).map((e) => e as String).toList(),
    );
  }
}
