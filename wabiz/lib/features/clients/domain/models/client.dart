class Client {
  const Client({
    required this.id,
    required this.name,
    required this.phoneNumber,
    required this.email,
    required this.group,
    required this.status,
    required this.notes,
    required this.createdAt,
    required this.updatedAt,
    this.whatsappConsentStatus = 'unknown',
    this.whatsappConsentRecordedAt,
  });

  static const List<String> statuses = ['customer', 'prospect', 'vip', 'new'];

  final int id;
  final String name;
  final String phoneNumber;
  final String? email;
  final String? group;
  final String status;
  final String notes;
  final DateTime? createdAt;
  final DateTime? updatedAt;
  final String whatsappConsentStatus;
  final DateTime? whatsappConsentRecordedAt;

  String get statusLabel => switch (status) {
    'customer' => 'Pelanggan',
    'prospect' => 'Prospek',
    'vip' => 'VIP',
    'new' => 'Baru',
    _ => status,
  };

  factory Client.fromJson(Map<String, dynamic> json) {
    final Object? rawId = json['id'];
    final int? id = rawId is int ? rawId : null;
    final String? name = _requiredString(json, 'name');
    final String? phoneNumber = _requiredString(json, 'phone_number');
    final String? status = _requiredString(json, 'status');
    if (id == null || id < 1 || name == null || phoneNumber == null || status == null) {
      throw const FormatException('Data client dari server tidak valid.');
    }
    if (!statuses.contains(status)) {
      throw const FormatException('Status client dari server tidak valid.');
    }

    return Client(
      id: id,
      name: name,
      phoneNumber: phoneNumber,
      email: _nullableString(json, 'email'),
      group: _nullableString(json, 'group'),
      status: status,
      notes: _nullableString(json, 'notes') ?? '',
      createdAt: _dateTime(json, 'created_at'),
      updatedAt: _dateTime(json, 'updated_at'),
      whatsappConsentStatus: _consentStatus(json['whatsapp_consent_status']),
      whatsappConsentRecordedAt: json.containsKey('whatsapp_consent_recorded_at')
          ? _dateTime(json, 'whatsapp_consent_recorded_at')
          : null,
    );
  }

  Map<String, dynamic> toApiPayload() => {
    'name': name,
    'phone_number': phoneNumber,
    'email': email,
    'group': group,
    'status': status,
    'notes': notes,
  };

  Client copyWith({
    String? name,
    String? phoneNumber,
    String? email,
    String? group,
    String? status,
    String? notes,
    DateTime? createdAt,
    DateTime? updatedAt,
    String? whatsappConsentStatus,
    DateTime? whatsappConsentRecordedAt,
  }) => Client(
    id: id,
    name: name ?? this.name,
    phoneNumber: phoneNumber ?? this.phoneNumber,
    email: email ?? this.email,
    group: group ?? this.group,
    status: status ?? this.status,
    notes: notes ?? this.notes,
    createdAt: createdAt ?? this.createdAt,
    updatedAt: updatedAt ?? this.updatedAt,
    whatsappConsentStatus: whatsappConsentStatus ?? this.whatsappConsentStatus,
    whatsappConsentRecordedAt:
        whatsappConsentRecordedAt ?? this.whatsappConsentRecordedAt,
  );

  static String? _requiredString(Map<String, dynamic> json, String key) {
    final Object? value = json[key];
    if (value is! String || value.isEmpty) {
      throw FormatException('Field client $key tidak valid.');
    }
    return value;
  }

  static String? _nullableString(Map<String, dynamic> json, String key) {
    if (!json.containsKey(key)) {
      throw FormatException('Field client $key tidak tersedia.');
    }
    final Object? value = json[key];
    if (value != null && value is! String) {
      throw FormatException('Field client $key tidak valid.');
    }
    return value as String?;
  }

  static DateTime? _dateTime(Map<String, dynamic> json, String key) {
    final String? value = _nullableString(json, key);
    if (value == null) return null;
    final DateTime? parsed = DateTime.tryParse(value);
    if (parsed == null) {
      throw FormatException('Field client $key tidak valid.');
    }
    return parsed.toLocal();
  }

  static String _consentStatus(Object? value) {
    if (value == null) return 'unknown';
    if (value is! String || !['unknown', 'granted', 'withdrawn'].contains(value)) {
      throw const FormatException('Status persetujuan client tidak valid.');
    }
    return value;
  }
}
