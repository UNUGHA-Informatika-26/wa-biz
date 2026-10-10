import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wabiz/features/inbox/data/conversation_api_service.dart';
import 'package:wabiz/features/inbox/presentation/pages/inbox_page.dart';

void main() {
  testWidgets('Inbox shows API conversations and opens direction-aware history', (tester) async {
    final service = _FakeConversationApi();
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: InboxPage(service: service))));
    await tester.pumpAndSettle();

    expect(find.text('Customer One'), findsOneWidget);
    expect(find.text('+6281234567890'), findsOneWidget);
    await tester.tap(find.text('Customer One'));
    await tester.pumpAndSettle();

    expect(service.readCount, 1);
    expect(find.text('Incoming question'), findsOneWidget);
    expect(find.text('Human reply'), findsOneWidget);
    expect(find.text('customer · 07/10 17:00'), findsOneWidget);
    expect(find.text('human · 07/10 17:01'), findsOneWidget);
  });

  testWidgets('Reset action advances server history boundary and refreshes active messages', (tester) async {
    final service = _FakeConversationApi();
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: InboxPage(service: service))));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Customer One'));
    await tester.pumpAndSettle();

    await tester.tap(find.byTooltip('Reset riwayat'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Reset riwayat'));
    await tester.pumpAndSettle();

    expect(service.resetCount, 1);
    expect(find.text('Incoming question'), findsNothing);
    expect(find.text('Belum ada pesan pada riwayat aktif.'), findsOneWidget);
  });

  testWidgets('handoff assignment and human control come from API and update after action', (tester) async {
    final service = _FakeConversationApi()..status = 'TRANSFERRED_TO_HUMAN';
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: InboxPage(service: service))));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Customer One'));
    await tester.pumpAndSettle();

    expect(find.textContaining('Pendaftaran · CS Pendaftaran'), findsOneWidget);
    expect(find.textContaining('registration_intent'), findsOneWidget);
    await tester.tap(find.text('Ambil percakapan'));
    await tester.pumpAndSettle();

    expect(service.status, 'HUMAN_ACTIVE');
    expect(find.text('Selesaikan'), findsOneWidget);
    expect(find.text('https://example.test/register'), findsOneWidget);
  });

  testWidgets('handoff without a registration URL does not invent one', (tester) async {
    final service = _FakeConversationApi()..status = 'TRANSFERRED_TO_HUMAN'..registrationUrl = null;
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: InboxPage(service: service))));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Customer One'));
    await tester.pumpAndSettle();

    expect(find.text('Link pendaftaran'), findsNothing);
    expect(find.textContaining('example.test/register'), findsNothing);
  });

  testWidgets('unknown handoff notification requires explicit duplicate-risk confirmation', (tester) async {
    final service = _FakeConversationApi()
      ..status = 'TRANSFERRED_TO_HUMAN'
      ..notificationStatus = 'unknown';
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: InboxPage(service: service))));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Customer One'));
    await tester.pumpAndSettle();

    expect(find.text('Notifikasi CS: unknown'), findsOneWidget);
    await tester.tap(find.text('Pulihkan'));
    await tester.pumpAndSettle();
    expect(find.textContaining('dapat menghasilkan duplikat'), findsOneWidget);
    await tester.tap(find.text('Konfirmasi kirim ulang'));
    await tester.pumpAndSettle();

    expect(service.recoveryCount, 1);
  });

  testWidgets('reply composer follows backend reply permission', (tester) async {
    final service = _FakeConversationApi()..canReply = false;
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: InboxPage(service: service))));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Customer One'));
    await tester.pumpAndSettle();

    expect(find.text('Balasan dinonaktifkan'), findsOneWidget);
    expect(tester.widget<TextField>(find.byType(TextField).last).enabled, isFalse);
  });

  testWidgets('unauthorized CS does not see resolve control', (tester) async {
    final service = _FakeConversationApi()..status = 'HUMAN_ACTIVE'..canResolve = false;
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: InboxPage(service: service))));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Customer One'));
    await tester.pumpAndSettle();

    expect(find.text('Selesaikan'), findsNothing);
  });

  testWidgets('unlinked CS contact is clearly shown as not having a login identity', (tester) async {
    final service = _FakeConversationApi()..status = 'TRANSFERRED_TO_HUMAN'..hasCsIdentity = false;
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: InboxPage(service: service))));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Customer One'));
    await tester.pumpAndSettle();

    expect(find.text('CS belum memiliki akun login'), findsOneWidget);
    expect(find.textContaining('belum ditautkan ke user organisasi'), findsOneWidget);
  });

  testWidgets('403 reply failure keeps the draft and reports authorization error', (tester) async {
    final service = _FakeConversationApi()..rejectReply = true;
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: InboxPage(service: service))));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Customer One'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).last, 'Draft stays after 403');
    await tester.tap(find.byIcon(Icons.send));
    await tester.pumpAndSettle();

    expect(find.text('Anda tidak memiliki izin membalas percakapan ini. Draft tetap tersedia.'), findsOneWidget);
    expect(tester.widget<TextField>(find.byType(TextField).last).controller?.text, 'Draft stays after 403');
  });
}

class _FakeConversationApi extends ConversationApiService {
  int resetCount = 0;
  bool isReset = false;
  String status = 'AI_ACTIVE';
  bool canReply = true;
  bool canResolve = true;
  String? registrationUrl = 'https://example.test/register';
  bool rejectReply = false;
  bool hasCsIdentity = true;
  String? notificationStatus;
  int recoveryCount = 0;
  int readCount = 0;

  @override
  Future<List<Map<String, dynamic>>> connections() async => [
    {'id': 4, 'display_name': 'WABiz Main', 'phone_number': '+6281111111111'},
  ];

  @override
  Future<List<Map<String, dynamic>>> departments() async => [{'id': 7, 'name': 'Pendaftaran'}];

  @override
  Future<List<Map<String, dynamic>>> csMembers() async => [{'id': 8, 'display_name': 'CS Pendaftaran'}];

  @override
  Future<ConversationPage> list({int page = 1, String? search, int? connectionId, int? departmentId, int? csMemberId, String? status, bool unreadOnly = false, int perPage = 20}) async => ConversationPage(
    items: [
      {
        'id': 9,
        'status': status,
        'unread_count': 1,
        'client': {'id': 2, 'name': 'Customer One', 'phone_number': '+6281234567890'},
        'whatsapp_connection': {'id': 4, 'display_name': 'WABiz Main'},
        'last_message': {'body': 'Hello', 'created_at': '2026-10-07T10:00:00Z'},
      },
    ],
    currentPage: page,
    lastPage: 1,
  );

  @override
  Future<Map<String, dynamic>> detail(int id) async => {
    'id': id,
    'status': status,
    'can_reply': canReply,
    'can_take': status == 'TRANSFERRED_TO_HUMAN',
    'can_resolve': status == 'HUMAN_ACTIVE' && canResolve,
    'can_reopen': status == 'RESOLVED',
    'registration_url': status == 'AI_ACTIVE' ? null : registrationUrl,
    'handoff_notification': notificationStatus == null ? null : {'status': notificationStatus, 'can_recover': notificationStatus == 'failed' || notificationStatus == 'unknown'},
    'handoff_reason': status == 'AI_ACTIVE' ? null : 'registration_intent',
    'handoff_requested_at': status == 'AI_ACTIVE' ? null : '2026-10-07T10:02:00Z',
    'department': status == 'AI_ACTIVE' ? null : {'name': 'Pendaftaran'},
    'cs_member': status == 'AI_ACTIVE' ? null : {
      'display_name': 'CS Pendaftaran',
      'has_authenticated_identity': hasCsIdentity,
    },
    'client': {'name': 'Customer One', 'phone_number': '+6281234567890'},
    'whatsapp_connection': {'display_name': 'WABiz Main'},
  };

  @override
  Future<MessagePage> messages(int id, {int page = 1}) async => MessagePage(
    items: isReset ? [] : [
      {'id': 1, 'direction': 'inbound', 'sender_type': 'customer', 'body': 'Incoming question', 'created_at': '2026-10-07T10:00:00Z'},
      {'id': 2, 'direction': 'outbound', 'sender_type': 'human', 'body': 'Human reply', 'created_at': '2026-10-07T10:01:00Z'},
    ],
    currentPage: page,
    lastPage: 1,
  );

  @override
  Future<void> reset(int id) async {
    resetCount++;
    isReset = true;
  }

  @override
  Future<void> markRead(int id) async { readCount++; }

  @override
  Future<void> retryHandoffNotification(int id) async {
    recoveryCount++;
    notificationStatus = 'pending';
  }

  @override
  Future<void> send(int id, String body, String idempotencyKey) async {
    if (rejectReply) {
      throw DioException(
        requestOptions: RequestOptions(path: '/conversations/$id/messages/send'),
        response: Response<dynamic>(requestOptions: RequestOptions(path: '/conversations/$id/messages/send'), statusCode: 403),
      );
    }
  }

  @override
  Future<Map<String, dynamic>> updateStatus(int id, String nextStatus) async {
    status = nextStatus;
    return {'id': id, 'status': status};
  }
}
