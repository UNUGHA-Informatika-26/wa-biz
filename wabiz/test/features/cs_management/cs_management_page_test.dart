import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wabiz/features/cs_management/data/cs_management_api_service.dart';
import 'package:wabiz/features/cs_management/presentation/pages/cs_management_page.dart';

void main() {
  testWidgets('CS management loads divisions, members, and configured registration division', (tester) async {
    await tester.pumpWidget(MaterialApp(home: CsManagementPage(service: _FakeCsManagementApi())));
    await tester.pumpAndSettle();

    expect(find.text('Pendaftaran'), findsWidgets);
    expect(find.text('CS Pendaftaran'), findsOneWidget);
    expect(find.text('+6281234567890 · Pendaftaran · Aktif · Akun belum tertaut'), findsOneWidget);
  });

  testWidgets('admin can link an existing organization staff identity to an unlinked CS member', (tester) async {
    final service = _FakeCsManagementApi();
    await tester.pumpWidget(MaterialApp(home: CsManagementPage(service: service)));
    await tester.pumpAndSettle();

    await tester.tap(find.byTooltip('Tautkan akun'));
    await tester.pumpAndSettle();
    await tester.tap(find.byType(DropdownButtonFormField<int>));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Staff CS · cs@example.test').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Tautkan'));
    await tester.pumpAndSettle();

    expect(service.linkedMemberId, 7);
    expect(service.linkedUserId, 12);
  });

  testWidgets('CS management shows an empty state and can retry a failed API load', (tester) async {
    final service = _FakeCsManagementApi()..fails = true;
    await tester.pumpWidget(MaterialApp(home: CsManagementPage(service: service)));
    await tester.pumpAndSettle();
    expect(find.text('Data CS dan divisi gagal dimuat.'), findsOneWidget);
    service.fails = false;
    service.empty = true;
    await tester.tap(find.text('Coba lagi'));
    await tester.pumpAndSettle();
    expect(find.text('Belum ada divisi.'), findsOneWidget);
    expect(find.text('Belum ada anggota CS.'), findsOneWidget);
  });
}

class _FakeCsManagementApi extends CsManagementApiService {
  bool fails = false;
  bool empty = false;
  int? linkedMemberId;
  int? linkedUserId;

  @override
  Future<List<Map<String, dynamic>>> departments() async {
    if (fails) throw StateError('offline');
    if (empty) return [];
    return [
      {'id': 3, 'name': 'Pendaftaran', 'active': true},
    ];
  }

  @override
  Future<List<Map<String, dynamic>>> members() async {
    if (fails) throw StateError('offline');
    if (empty) return [];
    return [
      {
        'id': 7,
        'display_name': 'CS Pendaftaran',
        'phone_number': '+6281234567890',
        'active': true,
        'user': null,
        'department': {'id': 3, 'name': 'Pendaftaran'},
      },
    ];
  }

  @override
  Future<Map<String, dynamic>?> settings() async => empty ? null : {'registration_department_id': 3};

  @override
  Future<List<Map<String, dynamic>>> linkableUsers(int memberId) async => [
    {'id': 12, 'name': 'Staff CS', 'email': 'cs@example.test'},
  ];

  @override
  Future<Map<String, dynamic>> linkMemberIdentity({required int memberId, required int userId}) async {
    linkedMemberId = memberId;
    linkedUserId = userId;
    return {'id': memberId, 'user': {'id': userId, 'email': 'cs@example.test'}};
  }
}
