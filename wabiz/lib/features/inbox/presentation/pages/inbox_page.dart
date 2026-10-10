import 'package:flutter/material.dart';

import '../../data/conversation_api_service.dart';
import 'conversation_detail_page.dart';

class InboxPage extends StatefulWidget {
  const InboxPage({this.service, super.key});
  final ConversationApiService? service;

  @override
  State<InboxPage> createState() => _InboxPageState();
}

class _InboxPageState extends State<InboxPage> {
  late final ConversationApiService _api = widget.service ?? ConversationApiService.instance;
  final TextEditingController _search = TextEditingController();
  late Future<ConversationPage> _pageFuture;
  List<Map<String, dynamic>> _connections = const [];
  List<Map<String, dynamic>> _departments = const [];
  List<Map<String, dynamic>> _csMembers = const [];
  int _page = 1;
  int? _connectionId;
  int? _departmentId;
  int? _csMemberId;
  String? _status;
  bool _unreadOnly = false;

  @override
  void initState() {
    super.initState();
    _pageFuture = _api.list();
    _loadConnections();
    _loadAssignmentFilters();
  }

  Future<void> _loadAssignmentFilters() async {
    try {
      final values = await Future.wait([_api.departments(), _api.csMembers()]);
      if (mounted) setState(() { _departments = values[0]; _csMembers = values[1]; });
    } catch (_) {
      // Core Inbox remains available if optional assignment filters cannot load.
    }
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _loadConnections() async {
    try {
      final result = await _api.connections();
      if (mounted) setState(() => _connections = result);
    } catch (_) {
      // The conversation list remains useful if connection filters are unavailable.
    }
  }

  Future<void> _load({int page = 1}) async {
    setState(() {
      _page = page;
      _pageFuture = _api.list(page: page, search: _search.text, connectionId: _connectionId, departmentId: _departmentId, csMemberId: _csMemberId, status: _status, unreadOnly: _unreadOnly);
    });
    await _pageFuture;
  }

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.all(20),
    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Text('Inbox', style: Theme.of(context).textTheme.headlineSmall),
      const SizedBox(height: 14),
      Wrap(spacing: 12, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
        SizedBox(width: 300, child: TextField(
          controller: _search,
          textInputAction: TextInputAction.search,
          onSubmitted: (_) => _load(),
          decoration: InputDecoration(
            labelText: 'Cari nama atau nomor',
            prefixIcon: const Icon(Icons.search),
            suffixIcon: IconButton(icon: const Icon(Icons.clear), onPressed: () { _search.clear(); _load(); }),
            border: const OutlineInputBorder(),
          ),
        )),
        SizedBox(width: 260, child: DropdownButtonFormField<int?>(
          initialValue: _connectionId,
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'Semua koneksi', border: OutlineInputBorder()),
          items: [
            const DropdownMenuItem<int?>(value: null, child: Text('Semua koneksi')),
            ..._connections.map((row) => DropdownMenuItem<int?>(
              value: _asInt(row['id']),
              child: Text('${row['display_name'] ?? 'WhatsApp'} · ${row['phone_number'] ?? ''}', overflow: TextOverflow.ellipsis),
            )),
          ],
          onChanged: (value) { _connectionId = value; _load(); },
        )),
        SizedBox(width: 190, child: DropdownButtonFormField<String?>(
          initialValue: _status,
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'Semua status', border: OutlineInputBorder()),
          items: const [
            DropdownMenuItem(value: null, child: Text('Semua status')),
            DropdownMenuItem(value: 'AI_ACTIVE', child: Text('AI aktif')),
            DropdownMenuItem(value: 'WAITING_HUMAN', child: Text('Menunggu CS')),
            DropdownMenuItem(value: 'TRANSFERRED_TO_HUMAN', child: Text('Dialihkan')),
            DropdownMenuItem(value: 'HUMAN_ACTIVE', child: Text('Ditangani CS')),
            DropdownMenuItem(value: 'RESOLVED', child: Text('Selesai')),
            DropdownMenuItem(value: 'ARCHIVED', child: Text('Diarsipkan')),
          ],
          onChanged: (value) { _status = value; _load(); },
        )),
        if (_departments.isNotEmpty) SizedBox(width: 220, child: DropdownButtonFormField<int?>(
          initialValue: _departmentId,
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'Semua divisi', border: OutlineInputBorder()),
          items: [const DropdownMenuItem<int?>(value: null, child: Text('Semua divisi')),..._departments.map((row) => DropdownMenuItem<int?>(value: _asInt(row['id']), child: Text('${row['name'] ?? 'Divisi'}', overflow: TextOverflow.ellipsis)))],
          onChanged: (value) { _departmentId = value; _load(); },
        )),
        if (_csMembers.isNotEmpty) SizedBox(width: 220, child: DropdownButtonFormField<int?>(
          initialValue: _csMemberId,
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'Semua CS', border: OutlineInputBorder()),
          items: [const DropdownMenuItem<int?>(value: null, child: Text('Semua CS')),..._csMembers.map((row) => DropdownMenuItem<int?>(value: _asInt(row['id']), child: Text('${row['display_name'] ?? 'CS'}', overflow: TextOverflow.ellipsis)))],
          onChanged: (value) { _csMemberId = value; _load(); },
        )),
        FilterChip(label: const Text('Belum dibaca'), selected: _unreadOnly, onSelected: (value) { _unreadOnly = value; _load(); }),
        IconButton(tooltip: 'Muat ulang', onPressed: () => _load(page: _page), icon: const Icon(Icons.refresh)),
      ]),
      const SizedBox(height: 12),
      Expanded(child: FutureBuilder<ConversationPage>(
        future: _pageFuture,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
          if (snapshot.hasError) return _StateMessage(text: 'Percakapan tidak dapat dimuat.', action: 'Coba lagi', onPressed: () => _load(page: _page));
          final result = snapshot.data!;
          if (result.items.isEmpty) return const _StateMessage(text: 'Belum ada percakapan.');
          return Column(children: [
            Expanded(child: ListView.separated(
              itemCount: result.items.length,
              separatorBuilder: (_, _) => const Divider(height: 1),
              itemBuilder: (context, index) {
                final item = result.items[index];
                final client = _asMap(item['client']);
                final connection = _asMap(item['whatsapp_connection']);
                final department = _asMap(item['department']);
                final csMember = _asMap(item['cs_member']);
                final latest = _asMap(item['last_message']);
                final phone = '${client['phone_number'] ?? ''}';
                final name = '${client['name'] ?? phone}';
                final last = '${latest['body'] ?? 'Belum ada pesan'}';
                return ListTile(
                  leading: const CircleAvatar(child: Icon(Icons.person_outline)),
                  title: Text(name, maxLines: 1, overflow: TextOverflow.ellipsis),
                  subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(phone),
                    Text(last, maxLines: 1, overflow: TextOverflow.ellipsis),
                    Text('${connection['display_name'] ?? 'WhatsApp'} · ${item['status'] ?? ''}', style: Theme.of(context).textTheme.labelSmall),
                    if (_asInt(item['unread_count']) > 0) Text('${_asInt(item['unread_count'])} belum dibaca', style: Theme.of(context).textTheme.labelSmall?.copyWith(fontWeight: FontWeight.bold)),
                    if (department.isNotEmpty || csMember.isNotEmpty)
                      Text('${department['name'] ?? 'CS'}${csMember['display_name'] == null ? '' : ' · ${csMember['display_name']}'}', style: Theme.of(context).textTheme.labelSmall),
                  ]),
                  isThreeLine: true,
                  trailing: Text(_formatDate(latest['sent_at'] ?? latest['created_at'] ?? item['last_message_at'])),
                  onTap: () async {
                    await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => ConversationDetailPage(
                      conversationId: _asInt(item['id']), service: _api,
                    )));
                    if (mounted) _load(page: _page);
                  },
                );
              },
            )),
            Row(mainAxisAlignment: MainAxisAlignment.center, children: [
              IconButton(onPressed: result.currentPage > 1 ? () => _load(page: result.currentPage - 1) : null, icon: const Icon(Icons.chevron_left)),
              Text('${result.currentPage} / ${result.lastPage}'),
              IconButton(onPressed: result.currentPage < result.lastPage ? () => _load(page: result.currentPage + 1) : null, icon: const Icon(Icons.chevron_right)),
            ]),
          ]);
        },
      )),
    ]),
  );
}

class _StateMessage extends StatelessWidget {
  const _StateMessage({required this.text, this.action, this.onPressed});
  final String text;
  final String? action;
  final VoidCallback? onPressed;
  @override
  Widget build(BuildContext context) => Center(child: Column(mainAxisSize: MainAxisSize.min, children: [
    Text(text),
    if (action != null) TextButton(onPressed: onPressed, child: Text(action!)),
  ]));
}

Map<String, dynamic> _asMap(Object? value) => value is Map<String, dynamic> ? value : value is Map ? Map<String, dynamic>.from(value) : const {};
int _asInt(Object? value) => value is num ? value.toInt() : int.tryParse('$value') ?? 0;
String _formatDate(Object? value) {
  final date = DateTime.tryParse('$value');
  if (date == null) return '';
  final local = date.toLocal();
  return '${local.day.toString().padLeft(2, '0')}/${local.month.toString().padLeft(2, '0')} ${local.hour.toString().padLeft(2, '0')}:${local.minute.toString().padLeft(2, '0')}';
}
