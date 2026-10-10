import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wabiz/core/network/api_client.dart';
import 'package:wabiz/core/theme/app_theme.dart';
import 'package:wabiz/features/whatsapp_connection/presentation/pages/whatsapp_connection_page.dart';

void main() {
  testWidgets('connection creation displays Laravel-provided QR and status', (
    WidgetTester tester,
  ) async {
    final dio = ApiClient.instance.dio;
    final existingInterceptors = dio.interceptors.toList();
    dio.interceptors.clear();
    var created = false;
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          final path = options.path;
          if (path.endsWith('/auth/login')) {
            handler.resolve(
              _response(options, 200, {
                'success': true,
                'data': {
                  'token': 'test-token',
                  'user': {'id': 1},
                },
              }),
            );
          } else if (path.endsWith('/whatsapp/connections') &&
              options.method == 'GET') {
            handler.resolve(
              _response(options, 200, {
                'success': true,
                'data': created ? [_connection] : <Object>[],
                'meta': {
                  'current_page': 1,
                  'last_page': 1,
                  'per_page': 15,
                  'total': created ? 1 : 0,
                },
              }),
            );
          } else if (path.endsWith('/whatsapp/connections') &&
              options.method == 'POST') {
            created = true;
            handler.resolve(
              _response(options, 201, {'success': true, 'data': _connection}),
            );
          } else if (path.endsWith('/whatsapp/connections/73/start')) {
            handler.resolve(_response(options, 200, _status));
          } else if (path.endsWith('/whatsapp/connections/73/qr')) {
            handler.resolve(
              _response(options, 200, {
                'success': true,
                'data': {'mime_type': 'image/png', 'base64': _onePixelPng},
              }),
            );
          } else if (path.endsWith('/whatsapp/connections/73/status')) {
            handler.resolve(_response(options, 200, _status));
          } else {
            handler.reject(
              DioException(
                requestOptions: options,
                message: 'Unexpected API request in QR widget test: $path',
              ),
            );
          }
        },
      ),
    );

    const storage = MethodChannel(
      'plugins.it_nomads.com/flutter_secure_storage',
    );
    final messenger = tester.binding.defaultBinaryMessenger;
    messenger.setMockMethodCallHandler(storage, (call) async {
      if (call.method == 'read') return 'test-token';
      return null;
    });
    addTearDown(() {
      messenger.setMockMethodCallHandler(storage, null);
      dio.interceptors
        ..clear()
        ..addAll(existingInterceptors);
    });

    tester.view.physicalSize = const Size(1200, 1000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.light,
        home: const Scaffold(body: WhatsAppConnectionPage()),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Tambah Koneksi').first);
    await tester.pumpAndSettle();
    await tester.tap(find.byType(DropdownButtonFormField<String>));
    await tester.pumpAndSettle();
    await tester.tap(find.text('WhatsApp Web (QR)'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byType(TextFormField).first,
      'QR test connection',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'Simpan'));
    for (var i = 0; i < 8; i++) {
      await tester.pump(const Duration(milliseconds: 100));
    }

    expect(find.text('Pindai QR WhatsApp'), findsOneWidget);
    expect(find.text('Status: qr_ready'), findsOneWidget);
    expect(find.byType(Image), findsOneWidget);
    expect(tester.takeException(), isNull);

    await tester.tap(find.text('Tutup'));
    await tester.pumpAndSettle();
  });
}

Response<dynamic> _response(RequestOptions options, int status, Object data) =>
    Response<dynamic>(requestOptions: options, statusCode: status, data: data);

const _connection = <String, dynamic>{
  'id': 73,
  'provider': 'wppconnect',
  'display_name': 'QR test connection',
  'phone_number': '',
  'enabled': true,
  'status': 'disconnected',
  'is_primary': false,
};

const _status = <String, dynamic>{
  'success': true,
  'data': {
    'id': 73,
    'status': 'qr_ready',
    'enabled': true,
    'phone_number': null,
  },
};

const _onePixelPng =
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC';
