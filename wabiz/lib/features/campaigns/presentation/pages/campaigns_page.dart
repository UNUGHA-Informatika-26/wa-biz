import 'package:flutter/material.dart';

import '../../data/campaign_api_service.dart';
import '../../domain/models/campaign.dart';

class CampaignsPage extends StatefulWidget {
  const CampaignsPage({this.service, super.key});
  final CampaignApiService? service;
  @override
  State<CampaignsPage> createState() => _CampaignsPageState();
}

class _CampaignsPageState extends State<CampaignsPage> {
  late final _api = widget.service ?? CampaignApiService.instance;
  late Future<CampaignListPage> _campaigns;
  int _page = 1;
  @override
  void initState() {
    super.initState();
    _campaigns = _api.list(page: _page);
  }

  Future<void> _reload() async {
    final future = _api.list(page: _page);
    setState(() {
      _campaigns = future;
    });
    await future;
  }

  Future<void> _goToPage(int page) async {
    _page = page;
    await _reload();
  }

  Future<void> _create({Map<String, dynamic>? initialData}) async {
    try {
      final clients = (await _api.clients())
          .where((client) => client['whatsapp_consent_status'] == 'granted')
          .toList(growable: false);
      final connections = await _api.connections();
      if (!mounted) return;
      final result = await showDialog<bool>(
        context: context,
        builder: (_) => _CampaignEditor(
          clients: clients,
          connections: connections,
          api: _api,
          initialData: initialData,
        ),
      );
      if (result == true && mounted) await _reload();
    } catch (_) {
      _notice('Gagal memuat data campaign. Periksa koneksi lalu coba lagi.');
    }
  }

  void _notice(String text) {
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));
    }
  }

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.all(24),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                'Campaign WhatsApp',
                style: Theme.of(context).textTheme.headlineSmall,
              ),
            ),
            FilledButton.icon(
              onPressed: _create,
              icon: const Icon(Icons.add),
              label: const Text('Buat Campaign'),
            ),
          ],
        ),
        const SizedBox(height: 16),
        Expanded(
          child: FutureBuilder<CampaignListPage>(
            future: _campaigns,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) {
                return const Center(child: CircularProgressIndicator());
              }
              if (snapshot.hasError) {
                return Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Text('Campaign tidak dapat dimuat. Coba lagi.'),
                      TextButton(
                        onPressed: _reload,
                        child: const Text('Coba lagi'),
                      ),
                    ],
                  ),
                );
              }
              final result = snapshot.data;
              final items = result?.items ?? const <Campaign>[];
              if (items.isEmpty) {
                return const Center(
                  child: Text('Belum ada campaign tersimpan.'),
                );
              }
              return Column(
                children: [
                  Expanded(
                    child: RefreshIndicator(
                      onRefresh: _reload,
                      child: ListView.separated(
                        itemCount: items.length,
                        separatorBuilder: (_, _) => const SizedBox(height: 8),
                        itemBuilder: (context, index) {
                          final campaign = items[index];
                          return Card(
                            child: ListTile(
                              title: Text(campaign.name),
                              subtitle: Text(
                                 '${campaign.connectionName ?? 'Koneksi'} · ${campaign.recipientCount} recipient · terkirim ${campaign.sentCount} · gagal ${campaign.failedCount} · ditekan ${campaign.suppressedCount} · tidak diketahui ${campaign.unknownCount}',
                              ),
                              trailing: Chip(label: Text(campaign.status.name)),
                              onTap: () => _details(campaign),
                            ),
                          );
                        },
                      ),
                    ),
                  ),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      IconButton(
                        tooltip: 'Halaman sebelumnya',
                        onPressed: _page > 1
                            ? () => _goToPage(_page - 1)
                            : null,
                        icon: const Icon(Icons.chevron_left),
                      ),
                      Text(
                        'Halaman ${result?.currentPage ?? _page} dari ${result?.lastPage ?? 1}',
                      ),
                      IconButton(
                        tooltip: 'Halaman berikutnya',
                        onPressed: _page < (result?.lastPage ?? 1)
                            ? () => _goToPage(_page + 1)
                            : null,
                        icon: const Icon(Icons.chevron_right),
                      ),
                    ],
                  ),
                ],
              );
            },
          ),
        ),
      ],
    ),
  );

  Future<void> _details(Campaign campaign) async {
    try {
      final detail = await _api.detail(campaign.id);
      if (campaign.status == CampaignStatus.draft) {
        detail['recipients'] = await _api.allRecipients(campaign.id);
      }
      if (!mounted) return;
      await showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          title: Text(campaign.name),
          content: SizedBox(
            width: 520,
            height: 500,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(campaign.message),
                const Divider(),
                Expanded(
                  child: _CampaignRecipients(
                    api: _api,
                    campaignId: campaign.id,
                  ),
                ),
              ],
            ),
          ),
          actions: [
            if (campaign.status == CampaignStatus.draft)
              TextButton(
                onPressed: () {
                  Navigator.pop(context);
                  _create(initialData: detail);
                },
                child: const Text('Edit draft'),
              ),
            if (campaign.status == CampaignStatus.draft)
              TextButton(
                onPressed: () async {
                  Navigator.pop(context);
                  await _api.delete(campaign.id);
                  _reload();
                },
                child: const Text('Hapus draft'),
              ),
            if (campaign.status == CampaignStatus.scheduled)
              TextButton(
                onPressed: () async {
                  Navigator.pop(context);
                  await _api.cancel(campaign.id);
                  _reload();
                },
                child: const Text('Batalkan jadwal'),
              ),
            if (campaign.status == CampaignStatus.draft)
              FilledButton(
                onPressed: () async {
                  Navigator.pop(context);
                  await _api.send(campaign.id);
                  _notice('Campaign masuk antrean pengiriman.');
                  _reload();
                },
                child: const Text('Kirim sekarang'),
              ),
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Tutup'),
            ),
          ],
        ),
      );
      _reload();
    } catch (_) {
      _notice('Gagal membuka campaign. Periksa koneksi lalu coba lagi.');
    }
  }
}

class _CampaignRecipients extends StatefulWidget {
  const _CampaignRecipients({required this.api, required this.campaignId});
  final CampaignApiService api;
  final int campaignId;

  @override
  State<_CampaignRecipients> createState() => _CampaignRecipientsState();
}

class _CampaignRecipientsState extends State<_CampaignRecipients> {
  late Future<CampaignRecipientPage> _result;
  int _page = 1;

  @override
  void initState() {
    super.initState();
    _result = widget.api.recipients(widget.campaignId, page: _page);
  }

  Future<void> _move(int page) async {
    setState(() {
      _page = page;
      _result = widget.api.recipients(widget.campaignId, page: page);
    });
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<CampaignRecipientPage>(
    future: _result,
    builder: (context, snapshot) {
      if (snapshot.connectionState != ConnectionState.done) {
        return const Center(child: CircularProgressIndicator());
      }
      if (snapshot.hasError) {
        return Center(
          child: const Text('Recipient tidak dapat dimuat. Coba lagi.'),
        );
      }
      final result = snapshot.data!;
      return Column(
        children: [
          Expanded(
            child: ListView.builder(
              itemCount: result.items.length,
              itemBuilder: (context, index) {
                final row = result.items[index];
                final error = row['error'] ?? row['error_message'];
                return ListTile(
                  dense: true,
                  title: Text(
                    '${row['original_phone_number'] ?? row['phone_number'] ?? ''}',
                  ),
                  subtitle: Text(
                    '${row['status']}${error == null ? '' : ' · $error'}',
                  ),
                );
              },
            ),
          ),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text('${result.total} recipient'),
              Row(
                children: [
                  IconButton(
                    tooltip: 'Recipient sebelumnya',
                    onPressed: _page > 1 ? () => _move(_page - 1) : null,
                    icon: const Icon(Icons.chevron_left),
                  ),
                  Text('${result.currentPage}/${result.lastPage}'),
                  IconButton(
                    tooltip: 'Recipient berikutnya',
                    onPressed: _page < result.lastPage
                        ? () => _move(_page + 1)
                        : null,
                    icon: const Icon(Icons.chevron_right),
                  ),
                ],
              ),
            ],
          ),
        ],
      );
    },
  );
}

class _CampaignEditor extends StatefulWidget {
  const _CampaignEditor({
    required this.clients,
    required this.connections,
    required this.api,
    this.initialData,
  });
  final List<Map<String, dynamic>> clients;
  final List<Map<String, dynamic>> connections;
  final CampaignApiService api;
  final Map<String, dynamic>? initialData;
  @override
  State<_CampaignEditor> createState() => _CampaignEditorState();
}

class _CampaignEditorState extends State<_CampaignEditor> {
  final _name = TextEditingController();
  final _message = TextEditingController();
  final Set<int> _selected = {};
  int? _connection;
  bool _busy = false;
  DateTime? _scheduled;
  @override
  void initState() {
    super.initState();
    final initial = widget.initialData;
    if (initial != null) {
      _name.text = initial['name'] as String? ?? '';
      _message.text = initial['message'] as String? ?? '';
      _connection = int.tryParse('${initial['whatsapp_connection_id']}');
      final recipients = initial['recipients'];
      if (recipients is List) {
        for (final row in recipients) {
          if (row is Map && row['client_id'] != null) {
            final id = int.tryParse('${row['client_id']}');
            if (id != null && widget.clients.any((c) =>
                int.tryParse('${c['id']}') == id &&
                c['whatsapp_consent_status'] == 'granted')) {
              _selected.add(id);
            }
          }
        }
      }
    }
    final active = widget.connections.where(
      (c) => c['enabled'] == true && c['status'] == 'connected',
    );
    if (active.isNotEmpty) _connection = int.tryParse('${active.first['id']}');
  }

  @override
  void dispose() {
    _name.dispose();
    _message.dispose();
    super.dispose();
  }

  Future<void> _save({bool sendNow = false, bool saveDraft = false}) async {
    if (_name.text.trim().isEmpty ||
        _message.text.trim().isEmpty ||
        _selected.isEmpty ||
        _connection == null) {
      return;
    }
    setState(() => _busy = true);
    try {
      final existingId = widget.initialData?['id'];
      final campaign = existingId == null
          ? await widget.api.create(
              name: _name.text.trim(),
              message: _message.text.trim(),
              connectionId: _connection!,
              clientIds: _selected.toList(),
            )
          : await widget.api.update(
              int.parse('$existingId'),
              name: _name.text.trim(),
              message: _message.text.trim(),
              connectionId: _connection!,
              clientIds: _selected.toList(),
            );
      if (sendNow) {
        await widget.api.send(campaign.id);
      } else if (!saveDraft && _scheduled != null) {
        await widget.api.schedule(campaign.id, _scheduled!);
      }
      if (mounted) Navigator.pop(context, true);
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Gagal menyimpan campaign. Periksa data dan coba lagi.',
            ),
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Buat campaign'),
    content: SizedBox(
      width: 560,
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            TextField(
              controller: _name,
              decoration: const InputDecoration(labelText: 'Nama campaign'),
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<int>(
              initialValue: _connection,
              decoration: const InputDecoration(
                labelText: 'WhatsApp connection',
              ),
              items: [
                for (final c in widget.connections)
                  DropdownMenuItem(
                    value: int.tryParse('${c['id']}'),
                    child: Text('${c['display_name']} · ${c['phone_number']}'),
                  ),
              ],
              onChanged: (value) => setState(() => _connection = value),
            ),
            if (_connection == null)
              const Padding(
                padding: EdgeInsets.only(top: 8),
                child: Text('Tidak ada koneksi WhatsApp aktif.'),
              ),
            const SizedBox(height: 12),
            TextField(
              controller: _message,
              minLines: 3,
              maxLines: 6,
              maxLength: 4096,
              decoration: const InputDecoration(labelText: 'Pesan'),
            ),
            const SizedBox(height: 8),
            Text(
              'Pilih recipient (${_selected.length})',
              style: Theme.of(context).textTheme.titleSmall,
            ),
            const Text(
              'Hanya client dengan persetujuan WhatsApp eksplisit yang dapat dipilih. Status diperiksa kembali oleh server sebelum pengiriman.',
            ),
            if (widget.clients.isEmpty)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 8),
                child: Text(
                  'Belum ada client dengan persetujuan WhatsApp yang tercatat.',
                ),
              ),
            SizedBox(
              height: 220,
              child: ListView(
                children: [
                  for (final c in widget.clients)
                    CheckboxListTile(
                      dense: true,
                      value: _selected.contains(int.tryParse('${c['id']}')),
                      title: Text('${c['name'] ?? 'Client'}'),
                      subtitle: Text('${c['phone_number'] ?? ''}'),
                      onChanged: (yes) {
                        final id = int.tryParse('${c['id']}');
                        if (id != null) {
                          setState(
                            () => yes == true
                                ? _selected.add(id)
                                : _selected.remove(id),
                          );
                        }
                      },
                    ),
                ],
              ),
            ),
            OutlinedButton.icon(
              onPressed: () async {
                final date = await showDatePicker(
                  context: context,
                  firstDate: DateTime.now(),
                  lastDate: DateTime.now().add(const Duration(days: 365)),
                  initialDate: DateTime.now().add(const Duration(days: 1)),
                );
                if (date == null || !context.mounted) return;
                final time = await showTimePicker(
                  context: context,
                  initialTime: TimeOfDay.now(),
                );
                if (time != null) {
                  setState(
                    () => _scheduled = DateTime(
                      date.year,
                      date.month,
                      date.day,
                      time.hour,
                      time.minute,
                    ),
                  );
                }
              },
              icon: const Icon(Icons.schedule),
              label: Text(
                _scheduled == null
                    ? 'Pilih jadwal (opsional)'
                    : _scheduled!.toLocal().toString(),
              ),
            ),
          ],
        ),
      ),
    ),
    actions: [
      TextButton(
        onPressed: _busy ? null : () => Navigator.pop(context),
        child: const Text('Batal'),
      ),
      TextButton(
        onPressed: _busy ? null : () => _save(saveDraft: true),
        child: const Text('Simpan draft'),
      ),
      if (_scheduled != null)
        OutlinedButton(
          onPressed: _busy ? null : () => _save(),
          child: const Text('Jadwalkan'),
        ),
      FilledButton(
        onPressed: _busy ? null : () => _save(sendNow: true),
        child: Text(_busy ? 'Menyimpan…' : 'Kirim sekarang'),
      ),
    ],
  );
}
