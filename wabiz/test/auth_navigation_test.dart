import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:dio/dio.dart';
import 'package:wabiz/core/network/api_client.dart';
import 'package:wabiz/core/theme/app_theme.dart';
import 'package:wabiz/features/dashboard/presentation/pages/dashboard_page.dart';
import 'package:wabiz/features/onboarding/presentation/pages/onboarding_page.dart';

void main() {
  testWidgets('onboarding and authentication reach dashboard', (
    WidgetTester tester,
  ) async {
    addTearDown(_installApiFakes(tester));
    tester.view.physicalSize = const Size(1200, 1000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      MaterialApp(theme: AppTheme.light, home: const OnboardingPage()),
    );
    await tester.tap(find.text('Lewati'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Mulai'));
    await tester.pumpAndSettle();
    expect(find.text('Selamat datang kembali'), findsOneWidget);

    await tester.enterText(find.byType(TextFormField).at(0), 'demo@wabiz.id');
    await tester.enterText(find.byType(TextFormField).at(1), 'password-demo');
    await tester.tap(find.widgetWithText(FilledButton, 'Masuk'));
    await tester.pumpAndSettle();

    expect(find.text('Ringkasan'), findsOneWidget);
    expect(find.text('WhatsApp Connection'), findsWidgets);
    expect(find.text('Belum Terhubung'), findsOneWidget);
    expect(find.text('Connected · Demo'), findsNothing);
    expect(find.text('AI Assistant'), findsWidgets);
  });

  testWidgets('dashboard navigation displays current feature states', (
    WidgetTester tester,
  ) async {
    addTearDown(_installApiFakes(tester));
    tester.view.physicalSize = const Size(1200, 1000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      MaterialApp(theme: AppTheme.light, home: const DashboardPage()),
    );

    for (final String tab in [
      'WhatsApp Connection',
      'Client',
      'Campaign',
      'AI Assistant',
      'Pengaturan',
    ]) {
      final Finder destination = find.descendant(
        of: find.byType(NavigationBar),
        matching: find.text(tab),
      );
      await tester.tap(destination);
      await tester.pumpAndSettle();
      expect(find.text(tab), findsWidgets);
      if (tab == 'WhatsApp Connection') {
        expect(find.text('Belum ada koneksi WhatsApp'), findsOneWidget);
        await tester.tap(
          find.widgetWithText(FilledButton, 'Tambah Koneksi').first,
        );
        await tester.pumpAndSettle();
        expect(find.text('Tambah Koneksi WhatsApp'), findsOneWidget);
        await tester.tap(find.text('Meta Cloud API'));
        await tester.pumpAndSettle();
        expect(find.text('WhatsApp Web (QR)'), findsOneWidget);
        await tester.tap(find.text('WhatsApp Web (QR)'));
        await tester.pumpAndSettle();
        expect(
          find.text('Nomor akan terdeteksi setelah pairing QR berhasil.'),
          findsOneWidget,
        );
        await tester.tap(find.text('Batal'));
        await tester.pumpAndSettle();
      }
    }

    await tester.tap(
      find.descendant(
        of: find.byType(NavigationBar),
        matching: find.text('Beranda'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('DATA CONTOH'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('dashboard is responsive and readable in dark theme', (
    WidgetTester tester,
  ) async {
    addTearDown(_installApiFakes(tester));
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    tester.view.devicePixelRatio = 1;

    for (final Size viewport in [
      const Size(360, 740),
      const Size(820, 900),
      const Size(1440, 900),
    ]) {
      tester.view.physicalSize = viewport;
      await tester.pumpWidget(
        MaterialApp(theme: AppTheme.light, home: const DashboardPage()),
      );
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
    }

    tester.view.physicalSize = const Size(390, 844);
    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.light,
        darkTheme: AppTheme.dark,
        themeMode: ThemeMode.dark,
        home: const DashboardPage(),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('WhatsApp Connection'), findsOneWidget);
    expect(find.text('AI Assistant'), findsWidgets);
    expect(tester.takeException(), isNull);

    await tester.tap(
      find.descendant(
        of: find.byType(NavigationBar),
        matching: find.text('WhatsApp'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Belum ada koneksi WhatsApp'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}

void Function() _installApiFakes(WidgetTester tester) {
  final dio = ApiClient.instance.dio;
  String? storedToken = 'test-sanctum-token';
  final interceptor = InterceptorsWrapper(
    onRequest: (options, handler) {
      if (options.path.endsWith('/auth/login') ||
          options.path == 'auth/login') {
        handler.resolve(
          Response<dynamic>(
            requestOptions: options,
            statusCode: 200,
            data: {
              'success': true,
              'data': {
                'token': 'test-sanctum-token',
                'user': {'id': 1, 'name': 'Demo'},
              },
            },
          ),
        );
        return;
      }
      if (options.path.endsWith('/whatsapp/connections') ||
          options.path == 'whatsapp/connections') {
        handler.resolve(
          Response<dynamic>(
            requestOptions: options,
            statusCode: 200,
            data: {
              'success': true,
              'data': <Object>[],
              'meta': {
                'current_page': 1,
                'last_page': 1,
                'per_page': 20,
                'total': 0,
              },
            },
          ),
        );
        return;
      }
      handler.reject(
        DioException(
          requestOptions: options,
          response: Response<dynamic>(requestOptions: options, statusCode: 404),
          type: DioExceptionType.badResponse,
        ),
      );
    },
  );
  dio.interceptors.add(interceptor);

  const storageChannel = MethodChannel(
    'plugins.it_nomads.com/flutter_secure_storage',
  );
  tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
    storageChannel,
    (call) async {
      final arguments = call.arguments as Map<Object?, Object?>? ?? {};
      switch (call.method) {
        case 'write':
          storedToken = arguments['value'] as String?;
          return null;
        case 'read':
          return storedToken;
        case 'containsKey':
          return storedToken != null;
        case 'delete':
          storedToken = null;
          return null;
        case 'readAll':
          return storedToken == null
              ? <String, String>{}
              : {'auth_token': storedToken!};
        default:
          return null;
      }
    },
  );

  return () {
    dio.interceptors.remove(interceptor);
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      storageChannel,
      null,
    );
  };
}
