import 'package:dio/dio.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/auth_storage.dart';
import '../domain/models/campaign.dart';

class CampaignListPage {
  const CampaignListPage({
    required this.items,
    required this.currentPage,
    required this.lastPage,
  });
  final List<Campaign> items;
  final int currentPage;
  final int lastPage;
}

class CampaignRecipientPage {
  const CampaignRecipientPage({
    required this.items,
    required this.currentPage,
    required this.lastPage,
    required this.total,
  });
  final List<Map<String, dynamic>> items;
  final int currentPage;
  final int lastPage;
  final int total;
}

class CampaignApiService {
  CampaignApiService({Dio? dio, Future<String?> Function()? tokenProvider})
    : _dio = dio ?? ApiClient.instance.dio,
      _tokenProvider = tokenProvider ?? AuthStorage.instance.getToken;

  static final instance = CampaignApiService();
  final Dio _dio;
  final Future<String?> Function() _tokenProvider;

  Future<Options> _options() async {
    final token = await _tokenProvider();
    if (token == null || token.isEmpty) {
      throw StateError('Sesi login tidak tersedia.');
    }
    return Options(headers: {'Authorization': 'Bearer $token'});
  }

  Map<String, dynamic> _map(Object? value) => value is Map<String, dynamic>
      ? value
      : Map<String, dynamic>.from(value as Map);
  List<dynamic> _rows(Object? value) => value is List ? value : const [];

  Future<CampaignListPage> list({int page = 1, int perPage = 20}) async {
    final response = await _dio.get(
      '/campaigns',
      queryParameters: {'page': page, 'per_page': perPage},
      options: await _options(),
    );
    final payload = _map(response.data)['data'];
    final paginator = payload is Map
        ? _map(payload)
        : <String, dynamic>{'data': payload};
    final rows = _rows(paginator['data']);
    return CampaignListPage(
      items: rows
          .map((row) => Campaign.fromJson(_map(row)))
          .toList(growable: false),
      currentPage: _integer(paginator['current_page'], 1),
      lastPage: _integer(paginator['last_page'], 1),
    );
  }

  Future<List<Map<String, dynamic>>> clients() async {
    final options = await _options();
    final all = <Map<String, dynamic>>[];
    var page = 1;
    var lastPage = 1;
    do {
      final response = await _dio.get(
        '/clients',
        queryParameters: {'page': page, 'per_page': 100},
        options: options,
      );
      final body = _map(response.data);
      all.addAll(_rows(body['data']).map(_map));
      final meta = body['meta'] is Map
          ? _map(body['meta'])
          : const <String, dynamic>{};
      lastPage = _integer(meta['last_page'], page);
      page++;
    } while (page <= lastPage && page <= 10000);
    return all;
  }

  Future<List<Map<String, dynamic>>> connections() async {
    final response = await _dio.get(
      '/whatsapp/connections',
      queryParameters: {'per_page': 100},
      options: await _options(),
    );
    return _rows(_map(response.data)['data'])
        .map((row) => _map(row))
        .toList(growable: false);
  }

  Future<Campaign> create({
    required String name,
    required String message,
    required int connectionId,
    required List<int> clientIds,
  }) async {
    final response = await _dio.post(
      '/campaigns',
      data: {
        'name': name,
        'message': message,
        'whatsapp_connection_id': connectionId,
        'client_ids': clientIds,
      },
      options: await _options(),
    );
    return Campaign.fromJson(_map(_map(response.data)['data']));
  }

  Future<Campaign> update(
    int id, {
    required String name,
    required String message,
    required int connectionId,
    required List<int> clientIds,
  }) async {
    final response = await _dio.put(
      '/campaigns/$id',
      data: {
        'name': name,
        'message': message,
        'whatsapp_connection_id': connectionId,
        'client_ids': clientIds,
      },
      options: await _options(),
    );
    return Campaign.fromJson(_map(_map(response.data)['data']));
  }

  Future<void> send(int id) async =>
      _dio.post('/campaigns/$id/send', options: await _options());
  Future<void> schedule(int id, DateTime at) async => _dio.post(
    '/campaigns/$id/schedule',
    data: {'scheduled_at': at.toUtc().toIso8601String()},
    options: await _options(),
  );
  Future<void> cancel(int id) async =>
      _dio.post('/campaigns/$id/cancel', options: await _options());
  Future<void> delete(int id) async =>
      _dio.delete('/campaigns/$id', options: await _options());
  Future<Map<String, dynamic>> detail(int id) async {
    final response = await _dio.get(
      '/campaigns/$id',
      options: await _options(),
    );
    return _map(_map(response.data)['data']);
  }

  Future<CampaignRecipientPage> recipients(
    int id, {
    int page = 1,
    int perPage = 50,
  }) async {
    final response = await _dio.get(
      '/campaigns/$id/recipients',
      queryParameters: {'page': page, 'per_page': perPage},
      options: await _options(),
    );
    final payload = _map(_map(response.data)['data']);
    return CampaignRecipientPage(
      items: _rows(payload['data']).map(_map).toList(growable: false),
      currentPage: _integer(payload['current_page'], 1),
      lastPage: _integer(payload['last_page'], 1),
      total: _integer(payload['total'], 0),
    );
  }

  Future<List<Map<String, dynamic>>> allRecipients(int id) async {
    final all = <Map<String, dynamic>>[];
    var page = 1;
    var lastPage = 1;
    do {
      final result = await recipients(id, page: page);
      all.addAll(result.items);
      lastPage = result.lastPage;
      page++;
    } while (page <= lastPage && page <= 10000);
    return all;
  }
}

int _integer(Object? value, int fallback) =>
    value is num ? value.toInt() : int.tryParse('$value') ?? fallback;
