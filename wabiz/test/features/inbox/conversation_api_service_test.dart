import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wabiz/features/inbox/data/conversation_api_service.dart';

void main() {
  test('conversation list carries pagination, search, and connection filters to Laravel', () async {
    final dio = Dio(BaseOptions(baseUrl: 'http://localhost/api/'));
    final adapter = _RecordingAdapter((options) => Response<dynamic>(
      requestOptions: options,
      data: {
        'success': true,
        'data': [{'id': 8, 'status': 'AI_ACTIVE'}],
        'meta': {'current_page': 2, 'last_page': 4, 'per_page': 20, 'total': 70},
      },
    ));
    dio.httpClientAdapter = adapter;
    final api = ConversationApiService(dio: dio, tokenProvider: () async => 'test-token');

    final result = await api.list(page: 2, search: '0812', connectionId: 5);

    expect(result.currentPage, 2);
    expect(result.lastPage, 4);
    expect(adapter.lastRequest!.queryParameters, containsPair('search', '0812'));
    expect(adapter.lastRequest!.queryParameters, containsPair('whatsapp_connection_id', 5));
    expect(adapter.lastRequest!.path, '/conversations');
    expect(adapter.lastRequest!.headers['Authorization'], 'Bearer test-token');
  });

  test('operational filters and mark-read use authenticated Laravel endpoints', () async {
    final dio = Dio(BaseOptions(baseUrl: 'http://localhost/api/'));
    final requests = <RequestOptions>[];
    dio.httpClientAdapter = _RecordingAdapter((options) {
      requests.add(options);
      return Response<dynamic>(requestOptions: options, data: {'success': true, 'data': [], 'meta': {'current_page': 1, 'last_page': 1}});
    });
    final api = ConversationApiService(dio: dio, tokenProvider: () async => 'test-token');

    await api.list(status: 'WAITING_HUMAN', departmentId: 3, csMemberId: 7, unreadOnly: true);
    await api.markRead(14);

    expect(requests.first.queryParameters, containsPair('status', 'WAITING_HUMAN'));
    expect(requests.first.queryParameters, containsPair('department_id', 3));
    expect(requests.first.queryParameters, containsPair('cs_member_id', 7));
    expect(requests.first.queryParameters, containsPair('unread', 1));
    expect(requests.last.path, '/conversations/14/read');
    expect(requests.last.method, 'POST');
  });
}

class _RecordingAdapter implements HttpClientAdapter {
  _RecordingAdapter(this.respond);
  final Response<dynamic> Function(RequestOptions options) respond;
  RequestOptions? lastRequest;

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream< dynamic>? requestStream, Future<dynamic>? cancelFuture) async {
    lastRequest = options;
    final response = respond(options);
    return ResponseBody.fromString(
      jsonEncode(response.data),
      response.statusCode ?? 200,
      headers: {Headers.contentTypeHeader: [Headers.jsonContentType]},
    );
  }

  @override
  void close({bool force = false}) {}
}
