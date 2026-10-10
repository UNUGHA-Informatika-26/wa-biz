import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wabiz/features/clients/data/client_api_service.dart';
import 'package:wabiz/features/clients/domain/models/client.dart';
import 'package:wabiz/features/clients/presentation/pages/clients_page.dart';

void main() {
  testWidgets('shows loading then API-backed clients and paginates', (
    WidgetTester tester,
  ) async {
    final Completer<ClientListPage> firstPage = Completer<ClientListPage>();
    final _FakeClientApi api = _FakeClientApi(
      clients: List<Client>.generate(21, (index) => _client(index + 1)),
      firstList: firstPage,
    );

    await tester.pumpWidget(MaterialApp(home: ClientsPage(api: api)));
    await tester.pump();
    expect(find.byType(CircularProgressIndicator), findsOneWidget);

    firstPage.complete(api.pageFor(1));
    await tester.pumpAndSettle();
    expect(find.text('Client 1'), findsOneWidget);
    expect(find.text('20 ditampilkan dari 21 client'), findsOneWidget);
    expect(find.text('DATA SERVER'), findsOneWidget);

    await tester.tap(find.text('Muat halaman berikutnya'));
    await tester.pumpAndSettle();
    expect(find.text('Client 21'), findsOneWidget);
    expect(api.requestedPages, contains(2));
  });

  testWidgets('stops pagination after an empty page response', (
    WidgetTester tester,
  ) async {
    final _FakeClientApi api = _FakeClientApi(
      clients: List<Client>.generate(21, (index) => _client(index + 1)),
    )..pageOverrides[2] = const ClientListPage(
      items: [],
      currentPage: 2,
      lastPage: 3,
      total: 45,
    );

    await tester.pumpWidget(MaterialApp(home: ClientsPage(api: api)));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Muat halaman berikutnya'));
    await tester.pumpAndSettle();

    expect(api.requestedPages, [1, 2]);
    expect(find.text('Muat halaman berikutnya'), findsNothing);
  });

  testWidgets('newer refresh result wins over an older pending refresh', (
    WidgetTester tester,
  ) async {
    final _FakeClientApi api = _FakeClientApi(clients: [_client(1)]);
    await tester.pumpWidget(MaterialApp(home: ClientsPage(api: api)));
    await tester.pumpAndSettle();

    final Completer<ClientListPage> older = api.holdNextList(1);
    final Completer<ClientListPage> newer = api.holdNextList(1);
    final RefreshIndicator refresh =
        tester.widget<RefreshIndicator>(find.byType(RefreshIndicator));
    final Future<void> olderRequest = refresh.onRefresh();
    await tester.pump();
    final Future<void> newerRequest = refresh.onRefresh();
    await tester.pump();

    newer.complete(_page([_client(2)], currentPage: 1, lastPage: 1, total: 1));
    await tester.pump();
    older.complete(_page([_client(3)], currentPage: 1, lastPage: 1, total: 1));
    await tester.pump();
    await Future.wait([olderRequest, newerRequest]);
    await tester.pumpAndSettle();

    expect(find.text('Client 2'), findsOneWidget);
    expect(find.text('Client 3'), findsNothing);
  });

  testWidgets('stale load-more result is discarded after refresh starts', (
    WidgetTester tester,
  ) async {
    final _FakeClientApi api = _FakeClientApi(
      clients: List<Client>.generate(21, (index) => _client(index + 1)),
    );
    await tester.pumpWidget(MaterialApp(home: ClientsPage(api: api)));
    await tester.pumpAndSettle();

    final Completer<ClientListPage> stalePage = api.holdNextList(2);
    await tester.tap(find.text('Muat halaman berikutnya'));
    await tester.pump();
    final Completer<ClientListPage> refreshPage = api.holdNextList(1);
    final RefreshIndicator refresh =
        tester.widget<RefreshIndicator>(find.byType(RefreshIndicator));
    final Future<void> refreshRequest = refresh.onRefresh();
    await tester.pump();

    refreshPage.complete(
      _page([_client(100)], currentPage: 1, lastPage: 1, total: 1),
    );
    await tester.pump();
    stalePage.complete(
      _page([_client(21)], currentPage: 2, lastPage: 2, total: 21),
    );
    await tester.pump();
    await refreshRequest;
    await tester.pumpAndSettle();

    expect(find.text('Client 100'), findsOneWidget);
    expect(find.text('Client 21'), findsNothing);
  });

  testWidgets('pending list response after dispose does not update state', (
    WidgetTester tester,
  ) async {
    final Completer<ClientListPage> pending = Completer<ClientListPage>();
    final _FakeClientApi api = _FakeClientApi(firstList: pending);
    await tester.pumpWidget(MaterialApp(home: ClientsPage(api: api)));
    await tester.pump();

    await tester.pumpWidget(const SizedBox.shrink());
    pending.complete(api.pageFor(1));
    await tester.pump();

    expect(tester.takeException(), isNull);
  });

  testWidgets('shows a controlled API error and can retry', (
    WidgetTester tester,
  ) async {
    final _FakeClientApi api = _FakeClientApi()
      ..listFailure = const ClientApiException('Respons daftar client tidak valid.');

    await tester.pumpWidget(MaterialApp(home: ClientsPage(api: api)));
    await tester.pumpAndSettle();
    expect(find.text('Respons daftar client tidak valid.'), findsOneWidget);

    api.listFailure = null;
    api.clients = [_client(1)];
    await tester.tap(find.text('Coba lagi'));
    await tester.pumpAndSettle();
    expect(find.text('Client 1'), findsOneWidget);
  });

  testWidgets('create and edit update the page only from saved API models', (
    WidgetTester tester,
  ) async {
    final _FakeClientApi api = _FakeClientApi();
    await tester.pumpWidget(MaterialApp(home: ClientsPage(api: api)));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(FilledButton, 'Tambah Client'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField).at(0), 'Nadia Contoh');
    await tester.enterText(find.byType(TextFormField).at(1), '+62 812 0000 9001');
    await tester.tap(find.widgetWithText(FilledButton, 'Tambah Client').last);
    await tester.pumpAndSettle();
    expect(api.created, 1);
    expect(find.text('Nadia Contoh'), findsOneWidget);

    await tester.tap(find.text('Nadia Contoh'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Edit'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField).at(0), 'Nadia Baru');
    await tester.tap(find.widgetWithText(FilledButton, 'Simpan Perubahan'));
    await tester.pumpAndSettle();
    expect(api.updated, 1);
    expect(find.text('Nadia Baru'), findsOneWidget);
  });

  testWidgets('HTTP 409 delete keeps client visible', (
    WidgetTester tester,
  ) async {
    final _FakeClientApi api = _FakeClientApi(clients: [_client(1)])
      ..deleteFailure = const ClientApiException(
        'Client masih memiliki percakapan dan tidak dapat dihapus.',
        statusCode: 409,
      );
    await tester.pumpWidget(MaterialApp(home: ClientsPage(api: api)));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Client 1'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(OutlinedButton, 'Hapus'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Hapus').last);
    await tester.pumpAndSettle();

    expect(find.text('Client 1'), findsWidgets);
    expect(find.text('Client masih memiliki percakapan dan tidak dapat dihapus.'), findsOneWidget);
    expect(api.deleted, 1);
  });

  test('rejects malformed client fields and unknown status', () {
    expect(
      () => Client.fromJson(_clientJson(1)..['status'] = 'active'),
      throwsA(isA<FormatException>()),
    );
    expect(
      () => Client.fromJson(_clientJson(1)..['id'] = 1.5),
      throwsA(isA<FormatException>()),
    );
    expect(
      () => Client.fromJson(_clientJson(1)..['group'] = 42),
      throwsA(isA<FormatException>()),
    );
  });

  test('rejects malformed list envelope without network access', () async {
    final Dio dio = Dio(BaseOptions(baseUrl: 'http://unit.test/api/'))
      ..httpClientAdapter = _FixedResponseAdapter({
        'success': true,
        'data': <Object?>[],
        'meta': {'current_page': 1, 'last_page': 1, 'per_page': 20},
      });
    final ClientApiService api = ClientApiService(
      dio: dio,
      tokenProvider: () async => 'test-only-token',
    );

    await expectLater(api.list(), throwsA(isA<ClientApiException>()));
    dio.close(force: true);
  });

  test('accepts ordinary paginator response and preserves server total', () async {
    final Dio dio = _dioFor(_listBody(
      rows: [_clientJson(1)],
      currentPage: 1,
      lastPage: 2,
      total: 21,
    ));
    final ClientApiService api = _serviceFor(dio);

    try {
      final ClientListPage result = await api.list();
      expect(result.items.map((item) => item.id), [1]);
      expect(result.total, 21);
      expect(result.currentPage, 1);
      expect(result.lastPage, 2);
    } finally {
      dio.close(force: true);
    }
  });

  test('accepts an empty requested page beyond last_page', () async {
    final Dio dio = _dioFor(_listBody(
      rows: [],
      currentPage: 3,
      lastPage: 2,
      total: 21,
    ));
    final ClientApiService api = _serviceFor(dio);

    try {
      final ClientListPage result = await api.list(page: 3);
      expect(result.items, isEmpty);
      expect(result.currentPage, 3);
      expect(result.lastPage, 2);
      expect(result.total, 21);
    } finally {
      dio.close(force: true);
    }
  });

  test('does not reject rows that race with the paginator count query', () async {
    final Dio dio = _dioFor(_listBody(
      rows: [_clientJson(1)],
      currentPage: 1,
      lastPage: 1,
      total: 0,
    ));
    final ClientApiService api = _serviceFor(dio);

    try {
      final ClientListPage result = await api.list();
      expect(result.items, hasLength(1));
      expect(result.total, 0);
    } finally {
      dio.close(force: true);
    }
  });

  test('rejects missing, wrongly typed, and inconsistent pagination metadata', () async {
    final List<Object?> malformedBodies = [
      {
        'success': true,
        'data': <Object?>[],
        'meta': {'current_page': 1, 'last_page': 1, 'per_page': 20},
      },
      _listBody(
        rows: [],
        currentPage: '1',
        lastPage: 1,
        total: 0,
      ),
      _listBody(
        rows: [],
        currentPage: 1,
        lastPage: 4,
        total: 21,
      ),
    ];

    for (final Object? body in malformedBodies) {
      final Dio dio = _dioFor(body);
      final ClientApiService api = _serviceFor(dio);
      try {
        await expectLater(api.list(), throwsA(isA<ClientApiException>()));
      } finally {
        dio.close(force: true);
      }
    }
  });

  test('maps synthetic HTTP 409 by delete operation only', () async {
    final Dio deleteDio = _dioFor(
      {'message': 'conflict'},
      statusCode: 409,
    );
    final ClientApiService deleteApi = _serviceFor(deleteDio);
    try {
      await expectLater(
        deleteApi.delete(1),
        throwsA(
          isA<ClientApiException>()
              .having((error) => error.statusCode, 'statusCode', 409)
              .having(
                (error) => error.message,
                'message',
                'Client masih memiliki percakapan dan tidak dapat dihapus.',
              ),
        ),
      );
    } finally {
      deleteDio.close(force: true);
    }

    final Dio updateDio = _dioFor(
      {'message': 'conflict'},
      statusCode: 409,
    );
    final ClientApiService updateApi = _serviceFor(updateDio);
    try {
      await expectLater(
        updateApi.update(_client(1)),
        throwsA(
          isA<ClientApiException>()
              .having((error) => error.statusCode, 'statusCode', 409)
              .having(
                (error) => error.message,
                'message',
                'Data client berubah atau mengalami konflik.',
              ),
        ),
      );
    } finally {
      updateDio.close(force: true);
    }
  });
}

class _FakeClientApi implements ClientApi {
  _FakeClientApi({List<Client>? clients, this.firstList})
    : clients = clients ?? [];

  List<Client> clients;
  final Completer<ClientListPage>? firstList;
  ClientApiException? listFailure;
  ClientApiException? deleteFailure;
  final List<int> requestedPages = [];
  final Map<int, ClientListPage> pageOverrides = {};
  final Map<int, List<Completer<ClientListPage>>> pendingLists = {};
  int created = 0;
  int updated = 0;
  int deleted = 0;

  ClientListPage pageFor(int page, {int perPage = 20}) {
    final ClientListPage? override = pageOverrides[page];
    if (override != null) return override;
    final int start = (page - 1) * perPage;
    final List<Client> items = start >= clients.length
        ? []
        : clients.skip(start).take(perPage).toList();
    final int lastPage = clients.isEmpty ? 1 : (clients.length / perPage).ceil();
    return ClientListPage(
      items: items,
      currentPage: page,
      lastPage: lastPage,
      total: clients.length,
    );
  }

  @override
  Future<ClientListPage> list({int page = 1, int perPage = 20}) async {
    requestedPages.add(page);
    if (listFailure != null) throw listFailure!;
    final List<Completer<ClientListPage>>? pending = pendingLists[page];
    if (pending != null && pending.isNotEmpty) {
      return pending.removeAt(0).future;
    }
    if (firstList != null && !firstList!.isCompleted) return firstList!.future;
    return pageFor(page, perPage: perPage);
  }

  Completer<ClientListPage> holdNextList(int page) {
    final Completer<ClientListPage> completer = Completer<ClientListPage>();
    pendingLists.putIfAbsent(page, () => []).add(completer);
    return completer;
  }

  @override
  Future<Client> detail(int id) async =>
      clients.firstWhere((item) => item.id == id);

  @override
  Future<Client> create(Client client) async {
    created++;
    final Client saved = clientFromDraft(client, clients.length + 1);
    clients = [...clients, saved];
    return saved;
  }

  @override
  Future<Client> update(Client client) async {
    updated++;
    final Client saved = clientFromDraft(client, client.id);
    clients = clients.map((item) => item.id == saved.id ? saved : item).toList();
    return saved;
  }

  @override
  Future<void> delete(int id) async {
    deleted++;
    if (deleteFailure != null) throw deleteFailure!;
    clients = clients.where((item) => item.id != id).toList();
  }
}

class _FixedResponseAdapter implements HttpClientAdapter {
  _FixedResponseAdapter(this.body, {this.statusCode = 200});

  final Object? body;
  final int statusCode;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async => ResponseBody.fromString(
    jsonEncode(body),
    statusCode,
    headers: {Headers.contentTypeHeader: [Headers.jsonContentType]},
  );

  @override
  void close({bool force = false}) {}
}

Dio _dioFor(Object? body, {int statusCode = 200}) =>
    Dio(BaseOptions(baseUrl: 'http://unit.test/api/'))
      ..httpClientAdapter = _FixedResponseAdapter(
        body,
        statusCode: statusCode,
      );

ClientApiService _serviceFor(Dio dio) => ClientApiService(
  dio: dio,
  tokenProvider: () async => 'test-only-token',
);

Map<String, dynamic> _listBody({
  required List<Object?> rows,
  required Object? currentPage,
  required Object? lastPage,
  required Object? total,
}) => {
  'success': true,
  'data': rows,
  'meta': {
    'current_page': currentPage,
    'last_page': lastPage,
    'per_page': 20,
    'total': total,
  },
};

ClientListPage _page(
  List<Client> items, {
  required int currentPage,
  required int lastPage,
  required int total,
}) => ClientListPage(
  items: items,
  currentPage: currentPage,
  lastPage: lastPage,
  total: total,
);

Client clientFromDraft(Client draft, int id) => Client(
  id: id,
  name: draft.name,
  phoneNumber: draft.phoneNumber,
  email: draft.email,
  group: draft.group,
  status: draft.status,
  notes: draft.notes,
  createdAt: DateTime.utc(2026, 1, 1),
  updatedAt: DateTime.utc(2026, 1, 1),
);

Client _client(int id) => Client.fromJson(_clientJson(id));

Map<String, dynamic> _clientJson(int id) => {
  'id': id,
  'name': 'Client $id',
  'phone_number': '+628120000${id.toString().padLeft(4, '0')}',
  'email': null,
  'group': null,
  'status': 'customer',
  'notes': '',
  'created_at': '2026-01-01T00:00:00Z',
  'updated_at': '2026-01-01T00:00:00Z',
};
