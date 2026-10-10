import 'dart:math';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../../data/conversation_api_service.dart';

class ConversationDetailPage extends StatefulWidget {
  const ConversationDetailPage({required this.conversationId, required this.service, super.key});
  final int conversationId;
  final ConversationApiService service;

  @override
  State<ConversationDetailPage> createState() => _ConversationDetailPageState();
}

class _ConversationDetailPageState extends State<ConversationDetailPage> {
  final TextEditingController _composer = TextEditingController();
  late Future<Map<String, dynamic>> _detail;
  late Future<MessagePage> _messages;
  int _messagePage = 1;
  bool _sending = false;

  @override
  void initState() {
    super.initState();
    _detail = widget.service.detail(widget.conversationId);
    _messages = widget.service.messages(widget.conversationId);
    widget.service.markRead(widget.conversationId).catchError((_) {});
  }

  @override
  void dispose() {
    _composer.dispose();
    super.dispose();
  }

  Future<void> _reload({int? page}) async {
    final nextPage = page ?? _messagePage;
    setState(() {
      _messagePage = nextPage;
      _detail = widget.service.detail(widget.conversationId);
      _messages = widget.service.messages(widget.conversationId, page: nextPage);
    });
    await Future.wait([_detail, _messages]);
  }

  Future<void> _reset() async {
    final confirmed = await showDialog<bool>(context: context, builder: (context) => AlertDialog(
      title: const Text('Reset riwayat percakapan?'),
      content: const Text('Pesan lama tetap disimpan untuk audit, tetapi tidak lagi tampil atau masuk ke context aktif.'),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Batal')),
        FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Reset riwayat')),
      ],
    ));
    if (confirmed != true || !mounted) return;
    try {
      await widget.service.reset(widget.conversationId);
      await _reload(page: 1);
    } catch (_) {
      _notice('Riwayat tidak dapat direset.');
    }
  }

  Future<void> _send() async {
    final body = _composer.text.trim();
    if (body.isEmpty || _sending) return;
    setState(() => _sending = true);
    try {
      final nonce = Random.secure().nextInt(0x7fffffff);
      final key = 'inbox-${widget.conversationId}-${DateTime.now().microsecondsSinceEpoch}-$nonce';
      await widget.service.send(widget.conversationId, body, key);
      _composer.clear();
      final latest = await widget.service.messages(widget.conversationId, page: 1);
      await _reload(page: latest.lastPage);
    } catch (error) {
      if (error is DioException && error.response?.statusCode == 403) {
        _notice('Anda tidak memiliki izin membalas percakapan ini. Draft tetap tersedia.');
        try {
          await _reload();
        } catch (_) {}
      } else {
        _notice('Pesan tidak terkirim. Draft tetap tersedia; periksa koneksi WhatsApp dan coba lagi.');
      }
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  Future<void> _changeStatus(String status) async {
    try {
      await widget.service.updateStatus(widget.conversationId, status);
      await _reload();
    } catch (_) {
      _notice('Status percakapan gagal diubah.');
    }
  }

  Future<void> _recoverHandoffNotification(Map<String, dynamic> notification) async {
    final unknown = notification['status'] == 'unknown';
    final staleSending = notification['stale_sending'] == true;
    final confirmed = await showDialog<bool>(context: context, builder: (context) => AlertDialog(
      title: const Text('Pulihkan notifikasi handoff?'),
      content: Text(staleSending
        ? 'Status pengiriman sudah macet lebih dari 5 menit. Provider mungkin sudah menerima pesan; pengiriman ulang dapat menghasilkan duplikat. Lanjutkan hanya setelah pemeriksaan operator.'
        : unknown
        ? 'Hasil pengiriman sebelumnya tidak diketahui. CS mungkin sudah menerima pesan; mengirim ulang dapat menghasilkan duplikat.'
        : 'Notifikasi sebelumnya gagal. Sistem akan mencoba satu pengiriman baru.'),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Batal')),
        FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Konfirmasi kirim ulang')),
      ],
    ));
    if (confirmed != true || !mounted) return;
    try {
      await widget.service.retryHandoffNotification(widget.conversationId);
      await _reload();
      _notice('Pemulihan notifikasi dimasukkan ke antrean.');
    } catch (error) {
      final code = error is DioException ? error.response?.statusCode : null;
      _notice(code == 409 ? 'Notifikasi sedang diproses atau tidak lagi dapat dipulihkan.' : 'Notifikasi gagal dipulihkan. Periksa status dan izin admin.');
    }
  }

  void _notice(String text) {
    if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Percakapan'), actions: [
      IconButton(tooltip: 'Reset riwayat', onPressed: _reset, icon: const Icon(Icons.restart_alt)),
      IconButton(tooltip: 'Muat ulang', onPressed: () => _reload(), icon: const Icon(Icons.refresh)),
    ]),
    body: FutureBuilder<Map<String, dynamic>>(
      future: _detail,
      builder: (context, detailSnapshot) {
        if (detailSnapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
        if (detailSnapshot.hasError) return const Center(child: Text('Detail percakapan tidak dapat dimuat.'));
        final data = detailSnapshot.data!;
        final client = _asMap(data['client']);
        final connection = _asMap(data['whatsapp_connection']);
        final department = _asMap(data['department']);
        final csMember = _asMap(data['cs_member']);
        final handoffNotification = _asMap(data['handoff_notification']);
        final hasCsIdentity = csMember['has_authenticated_identity'] == true;
        final status = '${data['status'] ?? ''}';
        final canReply = data['can_reply'] != false;
        final registrationUrl = data['registration_url'] is String ? (data['registration_url'] as String).trim() : '';
        return Column(children: [
          ListTile(
            title: Text('${client['name'] ?? client['phone_number'] ?? 'Client'}'),
            subtitle: Text('${client['phone_number'] ?? ''} · ${connection['display_name'] ?? 'WhatsApp'} · $status'),
          ),
          if (data['handoff_reason'] != null || department.isNotEmpty || csMember.isNotEmpty)
            ListTile(
              leading: const Icon(Icons.support_agent),
              title: Text('${department['name'] ?? 'Menunggu penanganan CS'}${csMember['display_name'] == null ? '' : ' · ${csMember['display_name']}'}'),
              subtitle: Text('Handoff: ${data['handoff_reason'] ?? '—'} · ${data['handoff_requested_at'] ?? ''}'),
            ),
          if (csMember.isNotEmpty && !hasCsIdentity)
            const ListTile(
              leading: Icon(Icons.person_off_outlined),
              title: Text('CS belum memiliki akun login'),
              subtitle: Text('Contact CS ini belum ditautkan ke user organisasi; admin/operator dapat menangani percakapan.'),
            ),
          if (registrationUrl.isNotEmpty)
            ListTile(
              leading: const Icon(Icons.assignment_outlined),
              title: const Text('Link pendaftaran'),
              subtitle: SelectableText(registrationUrl),
            ),
          if (handoffNotification.isNotEmpty)
            ListTile(
              leading: const Icon(Icons.notifications_active_outlined),
              title: Text('Notifikasi CS: ${handoffNotification['stale_sending'] == true ? 'sending (macet)' : handoffNotification['status'] ?? '—'}'),
              trailing: handoffNotification['can_recover'] == true
                ? TextButton(onPressed: _sending ? null : () => _recoverHandoffNotification(handoffNotification), child: const Text('Pulihkan'))
                : null,
            ),
          if (!canReply)
            const ListTile(
              leading: Icon(Icons.lock_outline),
              title: Text('Balasan dinonaktifkan'),
              subtitle: Text('Hanya CS yang ditugaskan atau administrator/operator yang berwenang yang dapat membalas.'),
            ),
          if (status == 'TRANSFERRED_TO_HUMAN' && data['can_take'] == true)
            Align(alignment: Alignment.centerRight, child: TextButton.icon(onPressed: _sending ? null : () => _changeStatus('HUMAN_ACTIVE'), icon: const Icon(Icons.play_arrow), label: const Text('Ambil percakapan'))),
          if (status == 'HUMAN_ACTIVE' && data['can_resolve'] == true)
            Align(alignment: Alignment.centerRight, child: TextButton.icon(onPressed: _sending ? null : () => _changeStatus('RESOLVED'), icon: const Icon(Icons.check), label: const Text('Selesaikan'))),
          if ((status == 'RESOLVED' || status == 'ARCHIVED') && data['can_reopen'] == true)
            Align(alignment: Alignment.centerRight, child: TextButton.icon(onPressed: _sending ? null : () => _changeStatus('AI_ACTIVE'), icon: const Icon(Icons.auto_awesome), label: const Text('Kembalikan ke AI'))),
          const Divider(height: 1),
          Expanded(child: FutureBuilder<MessagePage>(
            future: _messages,
            builder: (context, messageSnapshot) {
              if (messageSnapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
              if (messageSnapshot.hasError) return Center(child: TextButton(onPressed: () => _reload(), child: const Text('Pesan gagal dimuat. Coba lagi')));
              final page = messageSnapshot.data!;
              if (page.items.isEmpty) return const Center(child: Text('Belum ada pesan pada riwayat aktif.'));
              return Column(children: [
                Expanded(child: ListView.builder(
                  padding: const EdgeInsets.all(16),
                  itemCount: page.items.length,
                  itemBuilder: (context, index) {
                    final message = page.items[index];
                    final inbound = message['direction'] == 'inbound';
                    return Align(
                      alignment: inbound ? Alignment.centerLeft : Alignment.centerRight,
                      child: ConstrainedBox(
                        constraints: BoxConstraints(maxWidth: MediaQuery.sizeOf(context).width * .78),
                        child: Card(
                          color: inbound ? Theme.of(context).colorScheme.surfaceContainerHighest : Theme.of(context).colorScheme.primaryContainer,
                          child: Padding(padding: const EdgeInsets.all(12), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text('${message['body'] ?? ''}'),
                            const SizedBox(height: 5),
                            Text('${message['sender_type'] ?? ''} · ${_formatDate(message['sent_at'] ?? message['created_at'])}', style: Theme.of(context).textTheme.labelSmall),
                          ])),
                        ),
                      ),
                    );
                  },
                )),
                Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                  IconButton(onPressed: page.currentPage > 1 ? () => _reload(page: page.currentPage - 1) : null, icon: const Icon(Icons.chevron_left)),
                  Text('${page.currentPage} / ${page.lastPage}'),
                  IconButton(onPressed: page.currentPage < page.lastPage ? () => _reload(page: page.currentPage + 1) : null, icon: const Icon(Icons.chevron_right)),
                ]),
              ]);
            },
          )),
          SafeArea(top: false, child: Padding(padding: const EdgeInsets.fromLTRB(12, 8, 12, 8), child: Row(children: [
            Expanded(child: TextField(controller: _composer, enabled: canReply, minLines: 1, maxLines: 4, decoration: const InputDecoration(hintText: 'Tulis balasan sebagai human', border: OutlineInputBorder()))),
            const SizedBox(width: 8),
            IconButton.filled(onPressed: _sending || !canReply ? null : _send, icon: _sending ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.send)),
          ]))),
        ]);
      },
    ),
  );
}

Map<String, dynamic> _asMap(Object? value) => value is Map<String, dynamic> ? value : value is Map ? Map<String, dynamic>.from(value) : const {};
String _formatDate(Object? value) {
  final date = DateTime.tryParse('$value');
  if (date == null) return '';
  final local = date.toLocal();
  return '${local.day.toString().padLeft(2, '0')}/${local.month.toString().padLeft(2, '0')} ${local.hour.toString().padLeft(2, '0')}:${local.minute.toString().padLeft(2, '0')}';
}
