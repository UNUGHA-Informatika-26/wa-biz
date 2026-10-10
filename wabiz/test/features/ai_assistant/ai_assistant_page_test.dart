import 'package:flutter/material.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wabiz/features/ai_assistant/data/ai_assistant_api_service.dart';
import 'package:wabiz/features/ai_assistant/presentation/pages/ai_assistant_page.dart';

void main() {
  testWidgets('AI knowledge page loads persisted organization settings', (
    tester,
  ) async {
    final service = _FakeAiAssistantApi();
    await tester.pumpWidget(
      MaterialApp(home: AiAssistantPage(service: service)),
    );
    await tester.pumpAndSettle();

    expect(find.text('Aktif'), findsOneWidget);
    await tester.tap(find.text('Kelola knowledge'));
    await tester.pumpAndSettle();
    expect(find.byType(TextField), findsNWidgets(12));
    final fieldValues = tester
        .widgetList<TextField>(find.byType(TextField))
        .map((field) => field.controller?.text ?? '')
        .toList();
    expect(fieldValues, contains('Persisted Institute'));
    expect(fieldValues, contains('84'));
    expect(fieldValues, contains('https://maps.example.test/institute'));
  });

  testWidgets('registration URL is saved through the API service', (
    tester,
  ) async {
    final service = _FakeAiAssistantApi();
    await tester.pumpWidget(
      MaterialApp(home: AiAssistantPage(service: service)),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Buka pengaturan'));
    await tester.pumpAndSettle();

    final registrationField = find.byWidgetPredicate(
      (widget) =>
          widget is TextField &&
          widget.decoration?.labelText ==
              'Link pendaftaran (Google Form / website)',
    );
    await tester.enterText(
      registrationField,
      'https://apply.example.test/new-form',
    );
    final saveButton = find.text('Simpan pengaturan');
    await tester.ensureVisible(saveButton);
    await tester.tap(saveButton);
    await tester.pumpAndSettle();

    expect(
      service.saved?['registration_url'],
      'https://apply.example.test/new-form',
    );
    expect(find.text('Pengaturan berhasil disimpan.'), findsOneWidget);
  });

  testWidgets('invalid institution count is rejected before API save', (
    tester,
  ) async {
    final service = _FakeAiAssistantApi();
    await tester.pumpWidget(
      MaterialApp(home: AiAssistantPage(service: service)),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Kelola knowledge'));
    await tester.pumpAndSettle();

    final teacherCountField = find.byWidgetPredicate(
      (widget) =>
          widget is TextField && widget.decoration?.labelText == 'Jumlah guru',
    );
    await tester.enterText(teacherCountField, '-1');
    final saveButton = find.text('Simpan pengaturan');
    await tester.ensureVisible(saveButton);
    await tester.tap(saveButton);
    await tester.pumpAndSettle();

    expect(
      find.textContaining(
        'Jumlah guru harus berupa bilangan bulat nol atau lebih.',
      ),
      findsOneWidget,
    );
    expect(service.saved, isNull);
  });

  testWidgets('Laravel validation errors are shown to the settings admin', (
    tester,
  ) async {
    final service = _FakeAiAssistantApi()
      ..updateError = DioException(
        requestOptions: RequestOptions(path: '/ai/settings'),
        response: Response<dynamic>(
          requestOptions: RequestOptions(path: '/ai/settings'),
          statusCode: 422,
          data: {
            'errors': {
              'google_maps_url': ['The URL field is invalid.'],
            },
          },
        ),
      );
    await tester.pumpWidget(
      MaterialApp(home: AiAssistantPage(service: service)),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Kelola knowledge'));
    await tester.pumpAndSettle();
    final saveButton = find.text('Simpan pengaturan');
    await tester.ensureVisible(saveButton);
    await tester.tap(saveButton);
    await tester.pumpAndSettle();

    expect(
      find.text('google_maps_url: The URL field is invalid.'),
      findsOneWidget,
    );
  });
}

class _FakeAiAssistantApi extends AiAssistantApiService {
  Map<String, dynamic>? saved;
  Object? updateError;

  @override
  Future<Map<String, dynamic>?> show() async => {
    'enabled': true,
    'assistant_name': 'WABiz Assistant',
    'communication_style': 'friendly',
    'language': 'indonesian',
    'institution_name': 'Persisted Institute',
    'institution_description': 'Persisted description',
    'student_count': 84,
    'caregiver_count': 7,
    'teacher_count': 12,
    'address': 'Persisted address',
    'google_maps_url': 'https://maps.example.test/institute',
    'additional_information': 'Persisted organization fact',
    'registration_enabled': true,
    'registration_url': 'https://apply.example.test/form',
    'faqs': [
      {'question': 'Question?', 'answer': 'Configured answer.'},
    ],
  };

  @override
  Future<Map<String, dynamic>> update(Map<String, dynamic> settings) async {
    if (updateError != null) throw updateError!;
    saved = Map<String, dynamic>.from(settings);
    return saved!;
  }
}
