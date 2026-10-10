enum CommunicationStyle { professional, friendly, formal, casual }

enum AssistantLanguage { indonesian, english }

class AiSettings {
  const AiSettings({
    this.enabled = false,
    this.assistantName = 'Assistant WABiz',
    this.communicationStyle = CommunicationStyle.friendly,
    this.language = AssistantLanguage.indonesian,
    this.additionalInstructions = '',
    this.preventHallucination = true,
    this.useInstitutionKnowledge = true,
    this.replyInSelectedLanguage = true,
    this.fallbackToAdmin = true,
  });

  final bool enabled;
  final String assistantName;
  final CommunicationStyle communicationStyle;
  final AssistantLanguage language;
  final String additionalInstructions;
  final bool preventHallucination;
  final bool useInstitutionKnowledge;
  final bool replyInSelectedLanguage;
  final bool fallbackToAdmin;

  AiSettings copyWith({
    bool? enabled,
    String? assistantName,
    CommunicationStyle? communicationStyle,
    AssistantLanguage? language,
    String? additionalInstructions,
    bool? preventHallucination,
    bool? useInstitutionKnowledge,
    bool? replyInSelectedLanguage,
    bool? fallbackToAdmin,
  }) => AiSettings(
    enabled: enabled ?? this.enabled,
    assistantName: assistantName ?? this.assistantName,
    communicationStyle: communicationStyle ?? this.communicationStyle,
    language: language ?? this.language,
    additionalInstructions:
        additionalInstructions ?? this.additionalInstructions,
    preventHallucination: preventHallucination ?? this.preventHallucination,
    useInstitutionKnowledge:
        useInstitutionKnowledge ?? this.useInstitutionKnowledge,
    replyInSelectedLanguage:
        replyInSelectedLanguage ?? this.replyInSelectedLanguage,
    fallbackToAdmin: fallbackToAdmin ?? this.fallbackToAdmin,
  );
}
