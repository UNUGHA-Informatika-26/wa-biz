import 'package:dio/dio.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/auth_storage.dart';

class AiAssistantApiService {
  AiAssistantApiService({Dio? dio, Future<String?> Function()? tokenProvider})
    : _dio = dio ?? ApiClient.instance.dio,
      _tokenProvider = tokenProvider ?? AuthStorage.instance.getToken;

  static final instance = AiAssistantApiService();
  final Dio _dio;
  final Future<String?> Function() _tokenProvider;

  Future<Map<String, dynamic>?> show() async {
    final response = await _dio.get('/ai/settings', options: await _options());
    final data = _map(response.data)['data'];
    return data == null ? null : _map(data);
  }

  Future<Map<String, dynamic>> update(Map<String, dynamic> settings) async {
    final response = await _dio.put(
      '/ai/settings',
      data: settings,
      options: await _options(),
    );
    final data = _map(response.data)['data'];
    if (data == null) {
      throw const AiAssistantApiException('Respons pengaturan tidak valid.');
    }
    return _map(data);
  }

  Future<Options> _options() async {
    final token = await _tokenProvider();
    if (token == null || token.isEmpty) {
      throw const AiAssistantApiException('Sesi login tidak tersedia.');
    }
    return Options(headers: {'Authorization': 'Bearer $token'});
  }

  Map<String, dynamic> _map(Object? value) => value is Map<String, dynamic>
      ? value
      : Map<String, dynamic>.from(value as Map);
}

class AiAssistantApiException implements Exception {
  const AiAssistantApiException(this.message);
  final String message;

  @override
  String toString() => message;
}
