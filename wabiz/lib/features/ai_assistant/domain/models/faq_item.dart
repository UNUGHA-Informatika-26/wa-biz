class FaqItem {
  const FaqItem({required this.question, required this.answer});

  final String question;
  final String answer;

  FaqItem copyWith({String? question, String? answer}) => FaqItem(
    question: question ?? this.question,
    answer: answer ?? this.answer,
  );
}
