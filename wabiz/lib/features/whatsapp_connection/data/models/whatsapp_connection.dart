class WhatsAppConnection {
  const WhatsAppConnection({
    required this.id,
    required this.provider,
    required this.displayName,
    required this.phoneNumber,
    required this.enabled,
    required this.status,
    required this.isPrimary,
    this.phoneNumberId,
    this.businessAccountId,
    this.lastConnectedAt,
    this.lastWebhookAt,
    this.createdAt,
    this.updatedAt,
  });

  final int id;
  final String provider;
  final String displayName;
  final String phoneNumber;
  final String? phoneNumberId;
  final String? businessAccountId;
  final bool enabled;
  final String status;
  final bool isPrimary;
  final DateTime? lastConnectedAt;
  final DateTime? lastWebhookAt;
  final DateTime? createdAt;
  final DateTime? updatedAt;

  WhatsAppConnection copyWith({String? status, DateTime? lastConnectedAt}) {
    return WhatsAppConnection(
      id: id,
      provider: provider,
      displayName: displayName,
      phoneNumber: phoneNumber,
      phoneNumberId: phoneNumberId,
      businessAccountId: businessAccountId,
      enabled: enabled,
      status: status ?? this.status,
      isPrimary: isPrimary,
      lastConnectedAt: lastConnectedAt ?? this.lastConnectedAt,
      lastWebhookAt: lastWebhookAt,
      createdAt: createdAt,
      updatedAt: updatedAt,
    );
  }

  factory WhatsAppConnection.fromJson(Map<String, dynamic> json) {
    return WhatsAppConnection(
      id: _requiredInt(json['id'], 'id'),
      provider: _requiredString(json['provider'], 'provider'),
      displayName: _requiredString(json['display_name'], 'display_name'),
      phoneNumber: _requiredString(json['phone_number'], 'phone_number'),
      phoneNumberId: _optionalString(json['phone_number_id']),
      businessAccountId: _optionalString(json['business_account_id']),
      enabled: _readBool(json['enabled']),
      status: _requiredString(json['status'], 'status'),
      isPrimary: _readBool(json['is_primary']),
      lastConnectedAt: _optionalDateTime(json['last_connected_at']),
      lastWebhookAt: _optionalDateTime(json['last_webhook_at']),
      createdAt: _optionalDateTime(json['created_at']),
      updatedAt: _optionalDateTime(json['updated_at']),
    );
  }
}

class WhatsAppConnectionPaginationMeta {
  const WhatsAppConnectionPaginationMeta({
    required this.currentPage,
    required this.lastPage,
    required this.perPage,
    required this.total,
  });

  final int currentPage;
  final int lastPage;
  final int perPage;
  final int total;

  factory WhatsAppConnectionPaginationMeta.fromJson(Map<String, dynamic> json) {
    return WhatsAppConnectionPaginationMeta(
      currentPage: _requiredInt(json['current_page'], 'current_page'),
      lastPage: _requiredInt(json['last_page'], 'last_page'),
      perPage: _requiredInt(json['per_page'], 'per_page'),
      total: _requiredInt(json['total'], 'total'),
    );
  }
}

class WhatsAppConnectionListResponse {
  const WhatsAppConnectionListResponse({
    required this.connections,
    required this.meta,
  });

  final List<WhatsAppConnection> connections;
  final WhatsAppConnectionPaginationMeta meta;

  factory WhatsAppConnectionListResponse.fromJson(Map<String, dynamic> json) {
    final data = json['data'];
    final meta = json['meta'];
    if (json['success'] != true || data is! List || meta is! Map) {
      throw const FormatException('Invalid WhatsApp connection list response.');
    }

    return WhatsAppConnectionListResponse(
      connections: data
          .map((item) => WhatsAppConnection.fromJson(_asJsonMap(item)))
          .toList(growable: false),
      meta: WhatsAppConnectionPaginationMeta.fromJson(_asJsonMap(meta)),
    );
  }
}

class WhatsAppConnectionStatusInfo {
  const WhatsAppConnectionStatusInfo({
    required this.id,
    required this.status,
    required this.enabled,
    this.phoneNumber,
    this.lastConnectedAt,
    this.lastWebhookAt,
  });

  final int id;
  final String status;
  final bool enabled;
  final String? phoneNumber;
  final DateTime? lastConnectedAt;
  final DateTime? lastWebhookAt;

  factory WhatsAppConnectionStatusInfo.fromJson(Map<String, dynamic> json) {
    final data = json['data'];
    if (json['success'] != true || data is! Map) {
      throw const FormatException(
        'Invalid WhatsApp connection status response.',
      );
    }

    final statusData = _asJsonMap(data);
    return WhatsAppConnectionStatusInfo(
      id: _requiredInt(statusData['id'], 'id'),
      status: _requiredString(statusData['status'], 'status'),
      enabled: _readBool(statusData['enabled']),
      phoneNumber: _optionalString(statusData['phone_number']),
      lastConnectedAt: _optionalDateTime(statusData['last_connected_at']),
      lastWebhookAt: _optionalDateTime(statusData['last_webhook_at']),
    );
  }
}

class WhatsAppConnectionQr {
  const WhatsAppConnectionQr({required this.mimeType, required this.base64});

  final String mimeType;
  final String base64;

  factory WhatsAppConnectionQr.fromJson(Map<String, dynamic> json) {
    final data = json['data'];
    if (json['success'] != true || data is! Map) {
      throw const FormatException('Invalid WhatsApp QR response.');
    }
    final qr = _asJsonMap(data);
    return WhatsAppConnectionQr(
      mimeType: _requiredString(qr['mime_type'], 'mime_type'),
      base64: _requiredString(qr['base64'], 'base64'),
    );
  }
}

class WhatsAppConnectionVerificationResult {
  const WhatsAppConnectionVerificationResult({
    required this.provider,
    required this.status,
    this.verifiedAt,
  });

  final String provider;
  final String status;
  final DateTime? verifiedAt;

  factory WhatsAppConnectionVerificationResult.fromJson(
    Map<String, dynamic> json,
  ) {
    final data = json['data'];
    if (json['success'] != true || data is! Map) {
      throw const FormatException(
        'Invalid WhatsApp connection verification response.',
      );
    }

    final result = _asJsonMap(data);
    return WhatsAppConnectionVerificationResult(
      provider: _requiredString(result['provider'], 'provider'),
      status: _requiredString(result['status'], 'status'),
      verifiedAt: _optionalDateTime(result['verified_at']),
    );
  }
}

Map<String, dynamic> _asJsonMap(Object? value) {
  if (value is Map<String, dynamic>) return value;
  if (value is Map) {
    return value.map((key, value) => MapEntry(key.toString(), value));
  }
  throw const FormatException('Invalid JSON object.');
}

String _requiredString(Object? value, String field) {
  if (value is String) return value;
  throw FormatException('Invalid WhatsApp connection field: $field.');
}

String? _optionalString(Object? value) {
  if (value == null) return null;
  if (value is String) return value;
  throw const FormatException('Invalid optional WhatsApp connection field.');
}

int _requiredInt(Object? value, String field) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) {
    final parsed = int.tryParse(value);
    if (parsed != null) return parsed;
  }
  throw FormatException('Invalid WhatsApp connection field: $field.');
}

bool _readBool(Object? value) {
  if (value is bool) return value;
  if (value is num) return value != 0;
  return value == '1' || value == 'true';
}

DateTime? _optionalDateTime(Object? value) {
  if (value == null || value == '') return null;
  if (value is DateTime) return value;
  if (value is String) return DateTime.tryParse(value);
  throw const FormatException('Invalid WhatsApp connection date field.');
}
