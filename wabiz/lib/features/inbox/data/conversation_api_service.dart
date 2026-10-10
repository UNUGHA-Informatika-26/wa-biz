import 'package:dio/dio.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/auth_storage.dart';

class ConversationPage {
  const ConversationPage({required this.items, required this.currentPage, required this.lastPage});
  final List<Map<String, dynamic>> items;
  final int currentPage;
  final int lastPage;
}

class MessagePage {
  const MessagePage({required this.items, required this.currentPage, required this.lastPage});
  final List<Map<String, dynamic>> items;
  final int currentPage;
  final int lastPage;
}

class ConversationApiService {
  ConversationApiService({Dio? dio, Future<String?> Function()? tokenProvider})
    : _dio = dio ?? ApiClient.instance.dio,
      _tokenProvider = tokenProvider ?? AuthStorage.instance.getToken;

  static final instance = ConversationApiService();
  final Dio _dio;
  final Future<String?> Function() _tokenProvider;

  Future<Options> _options({Map<String, dynamic>? headers}) async {
    final token = await _tokenProvider();
    if (token == null || token.isEmpty) throw StateError('Sesi login tidak tersedia.');
    return Options(headers: {'Authorization': 'Bearer $token', ...?headers});
  }

  Map<String, dynamic> _map(Object? value) => value is Map<String, dynamic>
      ? value
      : Map<String, dynamic>.from(value as Map);
  int _int(Object? value, [int fallback = 1]) => value is num ? value.toInt() : int.tryParse('$value') ?? fallback;

  Future<ConversationPage> list({int page = 1, String? search, int? connectionId, int? departmentId, int? csMemberId, String? status, bool unreadOnly = false, int perPage = 20}) async {
    final response = await _dio.get('/conversations', queryParameters: {
      'page': page,
      'per_page': perPage,
      if (search?.trim().isNotEmpty ?? false) 'search': search!.trim(),
      'status': ?status,
      'department_id': ?departmentId,
      'cs_member_id': ?csMemberId,
      if (unreadOnly) 'unread': 1,
      ...?connectionId == null ? null : {'whatsapp_connection_id': connectionId},
    }, options: await _options());
    final body = _map(response.data);
    final meta = _map(body['meta']);
    final rows = body['data'] is List ? body['data'] as List : const [];
    return ConversationPage(
      items: rows.map(_map).toList(growable: false),
      currentPage: _int(meta['current_page'], page),
      lastPage: _int(meta['last_page']),
    );
  }

  Future<Map<String, dynamic>> detail(int id) async {
    final response = await _dio.get('/conversations/$id', options: await _options());
    return _map(_map(response.data)['data']);
  }

  Future<MessagePage> messages(int id, {int page = 1}) async {
    final response = await _dio.get('/conversations/$id/messages', queryParameters: {'page': page}, options: await _options());
    final body = _map(response.data);
    final meta = _map(body['meta']);
    final rows = body['data'] is List ? body['data'] as List : const [];
    return MessagePage(items: rows.map(_map).toList(growable: false), currentPage: _int(meta['current_page'], page), lastPage: _int(meta['last_page']));
  }

  Future<void> reset(int id) async => _dio.post('/conversations/$id/reset', options: await _options());

  Future<void> markRead(int id) async => _dio.post('/conversations/$id/read', options: await _options());

  Future<void> retryHandoffNotification(int id) async => _dio.post('/conversations/$id/handoff-notification/retry', options: await _options());

  Future<Map<String, dynamic>> updateStatus(int id, String status) async {
    final response = await _dio.patch('/conversations/$id/status', data: {'status': status}, options: await _options());
    return _map(_map(response.data)['data']);
  }

  Future<void> send(int id, String body, String idempotencyKey) async => _dio.post(
    '/conversations/$id/messages/send',
    data: {'body': body},
    options: await _options(headers: {'Idempotency-Key': idempotencyKey}),
  );

  Future<List<Map<String, dynamic>>> connections() async {
    final response = await _dio.get('/whatsapp/connections', queryParameters: {'per_page': 100}, options: await _options());
    final body = _map(response.data);
    final rows = body['data'] is List ? body['data'] as List : const [];
    return rows.map(_map).toList(growable: false);
  }

  Future<List<Map<String, dynamic>>> departments() async {
    final response = await _dio.get('/departments', queryParameters: {'per_page': 100}, options: await _options());
    final rows = _map(response.data)['data'];
    return rows is List ? rows.map(_map).toList(growable: false) : const [];
  }

  Future<List<Map<String, dynamic>>> csMembers() async {
    final response = await _dio.get('/cs-members', queryParameters: {'per_page': 100}, options: await _options());
    final rows = _map(response.data)['data'];
    return rows is List ? rows.map(_map).toList(growable: false) : const [];
  }
}
