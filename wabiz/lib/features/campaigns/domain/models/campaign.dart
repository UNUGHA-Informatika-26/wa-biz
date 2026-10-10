enum CampaignStatus {
  draft,
  scheduled,
  processing,
  completed,
  failed,
  partial,
  cancelled,
}

class Campaign {
  const Campaign({
    required this.id,
    required this.name,
    required this.message,
    required this.status,
    required this.recipientCount,
    this.sentCount = 0,
    this.failedCount = 0,
    this.unknownCount = 0,
    this.suppressedCount = 0,
    this.scheduledAt,
    this.connectionName,
    this.connectionId,
  });
  final int id;
  final String name;
  final String message;
  final CampaignStatus status;
  final int recipientCount;
  final int sentCount;
  final int failedCount;
  final int unknownCount;
  final int suppressedCount;
  final DateTime? scheduledAt;
  final String? connectionName;
  final int? connectionId;

  factory Campaign.fromJson(Map<String, dynamic> json) => Campaign(
    id: _int(json['id']),
    name: json['name'] as String? ?? '',
    message: json['message'] as String? ?? '',
    status: CampaignStatus.values.firstWhere(
      (s) => s.name == json['status'],
      orElse: () => CampaignStatus.failed,
    ),
    recipientCount: _int(json['total_recipients'] ?? json['recipients_count']),
    sentCount: _int(json['sent_count']),
    failedCount: _int(json['failed_count']),
    unknownCount: _int(json['unknown_count']),
    suppressedCount: _int(json['suppressed_count']),
    scheduledAt: DateTime.tryParse('${json['scheduled_at'] ?? ''}'),
    connectionName: (json['connection'] as Map?)?['display_name'] as String?,
    connectionId: _int(json['whatsapp_connection_id']),
  );
}

int _int(Object? value) =>
    value is num ? value.toInt() : int.tryParse('$value') ?? 0;
