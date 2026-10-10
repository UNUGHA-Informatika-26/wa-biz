import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wabiz/features/ai_assistant/data/ai_assistant_api_service.dart';

void main() {
  test(
    'loads organization settings through authenticated Laravel API',
    () async {
      final dio = Dio(BaseOptions(baseUrl: 'http://localhost/api/'));
      final adapter = _RecordingAdapter(
        (options) => Response<dynamic>(
          requestOptions: options,
          data: {
            'success': true,
            'configured': true,
            'data': {
              'institution_name': 'Persisted Institute',
              'student_count': 84,
            },
          },
        ),
      );
      dio.httpClientAdapter = adapter;
      final api = AiAssistantApiService(
        dio: dio,
        tokenProvider: () async => 'session-token',
      );

      final result = await api.show();

      expect(result?['institution_name'], 'Persisted Institute');
      expect(result?['student_count'], 84);
      expect(adapter.lastRequest?.path, '/ai/settings');
      expect(
        adapter.lastRequest?.headers['Authorization'],
        'Bearer session-token',
      );
    },
  );

  test(
    'saves configured registration URL and institution values to Laravel',
    () async {
      final dio = Dio(BaseOptions(baseUrl: 'http://localhost/api/'));
      final adapter = _RecordingAdapter(
        (options) => Response<dynamic>(
          requestOptions: options,
          data: {'success': true, 'configured': true, 'data': options.data},
        ),
      );
      dio.httpClientAdapter = adapter;
      final api = AiAssistantApiService(
        dio: dio,
        tokenProvider: () async => 'session-token',
      );

      final result = await api.update({
        'institution_name': 'Persisted Institute',
        'student_count': 84,
        'registration_enabled': true,
        'registration_url': 'https://apply.example.test/form',
      });

      expect(adapter.lastRequest?.method, 'PUT');
      expect(adapter.lastRequest?.path, '/ai/settings');
      expect(
        adapter.lastRequest?.data['registration_url'],
        'https://apply.example.test/form',
      );
      expect(result['student_count'], 84);
    },
  );
}

class _RecordingAdapter implements HttpClientAdapter {
  _RecordingAdapter(this.respond);
  final Response<dynamic> Function(RequestOptions options) respond;
  RequestOptions? lastRequest;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<dynamic>? requestStream,
    Future<dynamic>? cancelFuture,
  ) async {
    lastRequest = options;
    final response = respond(options);
    return ResponseBody.fromString(
      jsonEncode(response.data),
      response.statusCode ?? 200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
