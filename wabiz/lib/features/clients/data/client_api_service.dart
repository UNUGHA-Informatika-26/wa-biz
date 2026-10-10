import 'package:dio/dio.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/auth_storage.dart';
import '../domain/models/client.dart';

class ClientListPage {
  const ClientListPage({
    required this.items,
    required this.currentPage,
    required this.lastPage,
    required this.total,
  });

  final List<Client> items;
  final int currentPage;
  final int lastPage;
  final int total;
}

class ClientApiException implements Exception {
  const ClientApiException(this.message, {this.statusCode});

  final String message;
  final int? statusCode;

  @override
  String toString() => message;
}

abstract interface class ClientApi {
  Future<ClientListPage> list({int page = 1, int perPage = 20});
  Future<Client> detail(int id);
  Future<Client> create(Client client);
  Future<Client> update(Client client);
  Future<void> delete(int id);
}

abstract interface class ClientConsentApi {
  Future<Client> recordWhatsAppConsent({
    required int clientId,
    required String action,
    required String source,
    required String evidenceType,
    required String evidenceReference,
  });
}

class ClientApiService implements ClientApi, ClientConsentApi {
  ClientApiService({Dio? dio, Future<String?> Function()? tokenProvider})
    : _dio = dio ?? ApiClient.instance.dio,
      _tokenProvider = tokenProvider ?? AuthStorage.instance.getToken;

  static final instance = ClientApiService();

  final Dio _dio;
  final Future<String?> Function() _tokenProvider;

  @override
  Future<ClientListPage> list({int page = 1, int perPage = 20}) async {
    try {
      final response = await _dio.get(
        '/clients',
        queryParameters: {'page': page, 'per_page': perPage},
        options: await _authorizedOptions(),
      );
      final Map<String, dynamic> body = _asMap(response.data);
      final rows = body['data'];
      final meta = _asMap(body['meta']);
      if (body['success'] != true || rows is! List) {
        throw const ClientApiException('Respons daftar client tidak valid.');
      }
      final int currentPage = _requiredInteger(meta['current_page']);
      final int lastPage = _requiredInteger(meta['last_page']);
      final int pageSize = _requiredInteger(meta['per_page']);
      final int total = _requiredInteger(meta['total']);
      if (pageSize < 1 || total < 0 || page < 1 || perPage < 1 || perPage > 100) {
        throw const ClientApiException('Metadata pagination client tidak valid.');
      }
      final int expectedLastPage =
          total == 0 ? 1 : (total + pageSize - 1) ~/ pageSize;
      if (
          currentPage != page ||
          lastPage < 1 ||
          (currentPage > lastPage && rows.isNotEmpty) ||
          pageSize != perPage ||
          lastPage != expectedLastPage ||
          rows.length > pageSize) {
        throw const ClientApiException('Metadata pagination client tidak valid.');
      }
      return ClientListPage(
        items: rows.map((row) => Client.fromJson(_asMap(row))).toList(),
        currentPage: currentPage,
        lastPage: lastPage,
        total: total,
      );
    } on DioException catch (error) {
      throw _mapDioException(error, operation: 'list');
    } on FormatException {
      throw const ClientApiException('Respons daftar client tidak dapat dibaca.');
    }
  }

  @override
  Future<Client> detail(int id) async {
    try {
      final response = await _dio.get(
        '/clients/$id',
        options: await _authorizedOptions(),
      );
      return _clientFromResponse(response.data);
    } on DioException catch (error) {
      throw _mapDioException(error, operation: 'detail');
    } on FormatException {
      throw const ClientApiException('Respons detail client tidak dapat dibaca.');
    }
  }

  @override
  Future<Client> create(Client client) async {
    try {
      final response = await _dio.post(
        '/clients',
        data: client.toApiPayload(),
        options: await _authorizedOptions(),
      );
      return _clientFromResponse(response.data);
    } on DioException catch (error) {
      throw _mapDioException(error, operation: 'create');
    } on FormatException {
      throw const ClientApiException('Respons client baru tidak dapat dibaca.');
    }
  }

  @override
  Future<Client> update(Client client) async {
    try {
      final response = await _dio.put(
        '/clients/${client.id}',
        data: client.toApiPayload(),
        options: await _authorizedOptions(),
      );
      return _clientFromResponse(response.data);
    } on DioException catch (error) {
      throw _mapDioException(error, operation: 'update');
    } on FormatException {
      throw const ClientApiException('Respons perubahan client tidak dapat dibaca.');
    }
  }

  @override
  Future<void> delete(int id) async {
    try {
      final response = await _dio.delete(
        '/clients/$id',
        options: await _authorizedOptions(),
      );
      final Map<String, dynamic> body = _asMap(response.data);
      if (body['success'] != true) {
        throw const ClientApiException('Client tidak dapat dihapus.');
      }
    } on DioException catch (error) {
      throw _mapDioException(error, operation: 'delete');
    } on FormatException {
      throw const ClientApiException('Respons penghapusan client tidak valid.');
    }
  }

  @override
  Future<Client> recordWhatsAppConsent({
    required int clientId,
    required String action,
    required String source,
    required String evidenceType,
    required String evidenceReference,
  }) async {
    try {
      final response = await _dio.post(
        '/clients/$clientId/whatsapp-consent',
        data: {
          'action': action,
          'source': source,
          'evidence_type': evidenceType,
          'evidence_reference': evidenceReference,
        },
        options: await _authorizedOptions(),
      );
      return _clientFromResponse(response.data);
    } on DioException catch (error) {
      throw _mapDioException(error, operation: 'consent');
    } on FormatException {
      throw const ClientApiException(
        'Respons persetujuan client tidak dapat dibaca.',
      );
    }
  }

  Future<Options> _authorizedOptions() async {
    final String? token = await _tokenProvider();
    if (token == null || token.isEmpty) {
      throw const ClientApiException(
        'Sesi login tidak tersedia. Silakan login kembali.',
        statusCode: 401,
      );
    }
    return Options(headers: {'Authorization': 'Bearer $token'});
  }

  Client _clientFromResponse(Object? value) {
    final Map<String, dynamic> body = _asMap(value);
    if (body['success'] != true || body['data'] == null) {
      throw const ClientApiException('Respons client dari server tidak valid.');
    }
    return Client.fromJson(_asMap(body['data']));
  }

  ClientApiException _mapDioException(
    DioException error, {
    required String operation,
  }) {
    final int? status = error.response?.statusCode;
    final String message = status == null
        ? switch (error.type) {
            DioExceptionType.connectionTimeout ||
            DioExceptionType.sendTimeout ||
            DioExceptionType.receiveTimeout =>
              'Waktu permintaan habis. Silakan coba kembali.',
            DioExceptionType.connectionError =>
              'Backend tidak dapat dihubungi. Periksa koneksi Anda.',
            DioExceptionType.cancel => 'Permintaan dibatalkan.',
            DioExceptionType.badCertificate =>
              'Koneksi aman ke backend gagal.',
            DioExceptionType.badResponse || DioExceptionType.unknown =>
              'Permintaan client gagal. Silakan coba kembali.',
          }
        : switch (status) {
            401 => 'Sesi login berakhir. Silakan login kembali.',
            403 => 'Akun Anda tidak memiliki izin untuk mengelola client.',
            404 => 'Client tidak ditemukan atau sudah tidak tersedia.',
            409 when operation == 'delete' =>
              'Client masih memiliki percakapan atau riwayat persetujuan dan tidak dapat dihapus.',
            409 => 'Data client berubah atau mengalami konflik.',
            422 => 'Data client tidak valid. Periksa kembali isian Anda.',
            _ => 'Permintaan client gagal. Silakan coba kembali.',
          };
    return ClientApiException(message, statusCode: status);
  }

  Map<String, dynamic> _asMap(Object? value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) {
      return value.map((key, value) => MapEntry(key.toString(), value));
    }
    throw const FormatException('Invalid API response.');
  }

  int _requiredInteger(Object? value) {
    if (value is int) return value;
    throw const FormatException('Metadata pagination tidak valid.');
  }
}
