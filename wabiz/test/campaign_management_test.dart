import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wabiz/features/campaigns/data/campaign_api_service.dart';
import 'package:wabiz/features/campaigns/domain/models/campaign.dart';
import 'package:wabiz/features/campaigns/presentation/pages/campaigns_page.dart';

class FakeCampaignApi extends CampaignApiService {
  FakeCampaignApi() : super(tokenProvider: _token);
  static Future<String?> _token() async => 'test-token';

  final List<Campaign> saved = [];
  int listCalls = 0;
  int updateCalls = 0;
  int sendCalls = 0;
  int scheduleCalls = 0;
  int cancelCalls = 0;
  int deleteCalls = 0;
  bool failList = false;
  @override
  Future<CampaignListPage> list({int page = 1, int perPage = 20}) async {
    listCalls++;
    if (failList) throw StateError('API unavailable');
    return CampaignListPage(
      items: List.of(saved),
      currentPage: page,
      lastPage: 1,
    );
  }

  @override
  Future<List<Map<String, dynamic>>> clients() async => [
    {'id': 12, 'name': 'Client Test', 'phone_number': '+6281234567890'},
  ];
  @override
  Future<List<Map<String, dynamic>>> connections() async => [
    {
      'id': 4,
      'display_name': 'WABiz Test',
      'phone_number': '+628111111111',
      'enabled': true,
      'status': 'connected',
    },
  ];
  @override
  Future<Campaign> create({
    required String name,
    required String message,
    required int connectionId,
    required List<int> clientIds,
  }) async {
    final item = Campaign(
      id: 21,
      name: name,
      message: message,
      status: CampaignStatus.draft,
      recipientCount: clientIds.length,
      connectionId: connectionId,
      connectionName: 'WABiz Test',
    );
    saved.add(item);
    return item;
  }

  @override
  Future<Campaign> update(
    int id, {
    required String name,
    required String message,
    required int connectionId,
    required List<int> clientIds,
  }) async {
    updateCalls++;
    final item = Campaign(
      id: id,
      name: name,
      message: message,
      status: CampaignStatus.draft,
      recipientCount: clientIds.length,
      connectionId: connectionId,
      connectionName: 'WABiz Test',
    );
    saved.removeWhere((campaign) => campaign.id == id);
    saved.add(item);
    return item;
  }

  @override
  Future<void> send(int id) async {
    sendCalls++;
  }

  @override
  Future<void> schedule(int id, DateTime at) async {
    scheduleCalls++;
  }

  @override
  Future<void> cancel(int id) async {
    cancelCalls++;
  }

  @override
  Future<void> delete(int id) async {
    deleteCalls++;
    saved.removeWhere((campaign) => campaign.id == id);
  }

  @override
  Future<Map<String, dynamic>> detail(int id) async {
    final item = saved.firstWhere((campaign) => campaign.id == id);
    return {
      'id': item.id,
      'name': item.name,
      'message': item.message,
      'whatsapp_connection_id': item.connectionId,
    };
  }

  @override
  Future<List<Map<String, dynamic>>> allRecipients(int id) async => [
    {
      'client_id': 12,
      'phone_number': '+6281234567890',
      'status': 'unknown',
      'error': 'delivery_outcome_unknown',
    },
  ];

  @override
  Future<CampaignRecipientPage> recipients(
    int id, {
    int page = 1,
    int perPage = 50,
  }) async => CampaignRecipientPage(
    items: [
      {
        'phone_number': '+6281234567890',
        'status': 'unknown',
        'error': 'delivery_outcome_unknown',
      },
    ],
    currentPage: page,
    lastPage: 1,
    total: 1,
  );
}

void main() {
  test('campaign response parses persisted backend status and counts', () {
    final campaign = Campaign.fromJson({
      'id': 7,
      'name': 'Broadcast',
      'message': 'Halo',
      'status': 'partial',
      'total_recipients': 10,
      'sent_count': 8,
      'failed_count': 2,
      'unknown_count': 1,
      'whatsapp_connection_id': 4,
      'connection': {'display_name': 'WABiz'},
    });
    expect(campaign.status, CampaignStatus.partial);
    expect(campaign.recipientCount, 10);
    expect(campaign.sentCount, 8);
    expect(campaign.failedCount, 2);
    expect(campaign.unknownCount, 1);
    expect(campaign.connectionId, 4);
  });

  testWidgets(
    'campaign draft is created through service and displayed from API state',
    (tester) async {
      final api = FakeCampaignApi();
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(body: CampaignsPage(service: api)),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Belum ada campaign tersimpan.'), findsOneWidget);

      await tester.tap(find.text('Buat Campaign'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField).at(0), 'Campaign Uji');
      await tester.enterText(
        find.byType(TextField).at(1),
        'Informasi untuk client',
      );
      await tester.tap(find.text('Client Test'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Simpan draft'));
      await tester.pumpAndSettle();

      expect(api.saved.single.name, 'Campaign Uji');
      expect(api.saved.single.recipientCount, 1);
      expect(api.listCalls, greaterThan(1));
      expect(find.text('Campaign Uji'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('draft editing persists through update API', (tester) async {
    final api = FakeCampaignApi()
      ..saved.add(
        const Campaign(
          id: 21,
          name: 'Draft',
          message: 'Old text',
          status: CampaignStatus.draft,
          recipientCount: 1,
          connectionId: 4,
        ),
      );
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(body: CampaignsPage(service: api)),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Draft'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Edit draft'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).at(0), 'Draft revised');
    await tester.enterText(find.byType(TextField).at(1), 'Updated content');
    await tester.tap(find.text('Simpan draft'));
    await tester.pumpAndSettle();
    expect(api.updateCalls, 1);
    expect(api.saved.single.name, 'Draft revised');
  });

  testWidgets('recipient status is visible and draft deletion calls API', (
    tester,
  ) async {
    final api = FakeCampaignApi()
      ..saved.add(
        const Campaign(
          id: 21,
          name: 'Draft',
          message: 'Text',
          status: CampaignStatus.draft,
          recipientCount: 1,
          connectionId: 4,
        ),
      );
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(body: CampaignsPage(service: api)),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Draft'));
    await tester.pumpAndSettle();
    expect(
      find.textContaining('unknown · delivery_outcome_unknown'),
      findsOneWidget,
    );
    await tester.tap(find.text('Hapus draft'));
    await tester.pumpAndSettle();
    expect(api.deleteCalls, 1);
    expect(find.text('Belum ada campaign tersimpan.'), findsOneWidget);
  });

  testWidgets('send now calls API and list errors are rendered', (
    tester,
  ) async {
    final api = FakeCampaignApi();
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(body: CampaignsPage(service: api)),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Buat Campaign'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).at(0), 'Ready');
    await tester.enterText(find.byType(TextField).at(1), 'Send this');
    await tester.tap(find.text('Client Test'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Kirim sekarang'));
    await tester.pumpAndSettle();
    expect(api.sendCalls, 1);

    api.failList = true;
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(body: CampaignsPage(service: api)),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.textContaining('Campaign tidak dapat dimuat:'), findsOneWidget);
  });

  testWidgets('schedule flow submits selected date and time to API', (
    tester,
  ) async {
    final api = FakeCampaignApi();
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(body: CampaignsPage(service: api)),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Buat Campaign'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).at(0), 'Scheduled');
    await tester.enterText(find.byType(TextField).at(1), 'Scheduled text');
    await tester.tap(find.text('Client Test'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('Pilih jadwal (opsional)'));
    await tester.tap(find.text('Pilih jadwal (opsional)'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('OK').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text('OK').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Jadwalkan'));
    await tester.pumpAndSettle();
    expect(api.scheduleCalls, 1);
  });

  testWidgets('scheduled campaign cancellation calls API', (tester) async {
    final api = FakeCampaignApi()
      ..saved.add(
        const Campaign(
          id: 21,
          name: 'Scheduled',
          message: 'Text',
          status: CampaignStatus.scheduled,
          recipientCount: 1,
          connectionId: 4,
        ),
      );
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(body: CampaignsPage(service: api)),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Scheduled'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Batalkan jadwal'));
    await tester.pumpAndSettle();
    expect(api.cancelCalls, 1);
  });
}
