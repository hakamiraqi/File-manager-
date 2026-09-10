/// An interactive-video answer recorded while offline, waiting to be
/// confirmed by `POST /questions/{id}/answer` (backend README 23.13) once
/// connectivity returns — see OfflineDownloadService.syncPendingAnswers.
class PendingQuizAnswer {
  final int fileId;
  final int questionId;
  final int choiceId;
  final DateTime answeredAt;

  const PendingQuizAnswer({
    required this.fileId,
    required this.questionId,
    required this.choiceId,
    required this.answeredAt,
  });

  factory PendingQuizAnswer.fromJson(Map<String, dynamic> json) {
    return PendingQuizAnswer(
      fileId: json['file_id'] as int,
      questionId: json['question_id'] as int,
      choiceId: json['choice_id'] as int,
      answeredAt: DateTime.parse(json['answered_at'] as String),
    );
  }

  Map<String, dynamic> toJson() => {
        'file_id': fileId,
        'question_id': questionId,
        'choice_id': choiceId,
        'answered_at': answeredAt.toIso8601String(),
      };
}
