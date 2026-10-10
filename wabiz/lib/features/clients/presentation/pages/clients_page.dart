import 'package:flutter/material.dart';

import '../../data/client_api_service.dart';
import '../../domain/models/client.dart';
import '../widgets/client_card.dart';

class ClientsPage extends StatefulWidget {
  const ClientsPage({super.key, this.api});

  final ClientApi? api;

  @override
  State<ClientsPage> createState() => _ClientsPageState();
}

enum _LoadedPagesRefreshResult { updated, failed, superseded }

class _ClientsPageState extends State<ClientsPage> {
  static const Map<String, String> _statusLabels = {
    'customer': 'Pelanggan',
    'prospect': 'Prospek',
    'vip': 'VIP',
    'new': 'Baru',
  };

  final TextEditingController _searchController = TextEditingController();
  late final ClientApi _api = widget.api ?? ClientApiService.instance;
  List<Client> _clients = [];
  String? _statusFilter;
  bool _loading = true;
  bool _loadingMore = false;
  bool _refreshing = false;
  String? _loadError;
  int _currentPage = 1;
  int _lastPage = 1;
  int _total = 0;
  int _listGeneration = 0;
  bool _hasMorePages = false;
  bool _totalConfirmedByServer = false;

  @override
  void initState() {
    super.initState();
    _loadClients();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _loadClients({bool loadMore = false, int? page}) async {
    final int requestedPage = page ?? (loadMore ? _currentPage + 1 : 1);
    if (loadMore &&
        (!_totalConfirmedByServer ||
            _loadingMore ||
            _refreshing ||
            requestedPage > _lastPage)) {
      return;
    }
    final int requestGeneration = loadMore
        ? _listGeneration
        : ++_listGeneration;
    setState(() {
      if (loadMore) {
        _loadingMore = true;
      } else {
        _refreshing = true;
        _loadingMore = false;
        _loadError = null;
        if (_clients.isEmpty) _loading = true;
      }
    });
    try {
      final ClientListPage result = await _api.list(page: requestedPage);
      if (!mounted || requestGeneration != _listGeneration) return;
      setState(() {
        if (loadMore) {
          final Map<int, Client> merged = {
            for (final Client client in _clients) client.id: client,
          };
          for (final Client client in result.items) {
            merged[client.id] = client;
          }
          _clients = merged.values.toList();
        } else {
          _clients = result.items;
        }
        _currentPage = result.currentPage;
        _lastPage = result.lastPage;
        _total = result.total;
        if (!loadMore) _totalConfirmedByServer = true;
        _hasMorePages = result.items.isNotEmpty &&
            result.currentPage < result.lastPage;
        _loadError = null;
        _loading = false;
        _loadingMore = false;
        if (!loadMore) _refreshing = false;
      });
    } catch (error) {
      if (!mounted || requestGeneration != _listGeneration) return;
      final String message = _errorMessage(error);
      setState(() {
        _loading = false;
        _loadingMore = false;
        if (!loadMore) _refreshing = false;
        if (_clients.isEmpty) {
          _loadError = message;
        }
      });
      if (_clients.isNotEmpty) _showMessage(message);
    }
  }

  String _errorMessage(Object error) => error is ClientApiException
      ? error.message
      : 'Terjadi kesalahan saat memproses data client.';

  void _showMessage(String message) {
    if (!mounted) return;
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(message)));
  }

  List<Client> get _visibleClients {
    final String query = _searchController.text.trim().toLowerCase();
    return _clients.where((client) {
      final bool matchesQuery =
          query.isEmpty ||
          client.name.toLowerCase().contains(query) ||
          client.phoneNumber.toLowerCase().contains(query);
      final bool matchesStatus =
          _statusFilter == null || client.status == _statusFilter;
      return matchesQuery && matchesStatus;
    }).toList();
  }

  Future<void> _editClient({Client? client}) async {
    final Client? result = await showModalBottomSheet<Client>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (context) => _ClientForm(
        client: client,
        onSave: client == null ? _api.create : _api.update,
      ),
    );
    if (result == null || !mounted) return;
    if (client == null) {
      final _LoadedPagesRefreshResult refreshResult =
          await _refreshLoadedPages();
      final String message = switch (refreshResult) {
        _LoadedPagesRefreshResult.updated =>
          'Client berhasil ditambahkan. Daftar mengikuti urutan server; muat halaman berikutnya bila belum terlihat.',
        _LoadedPagesRefreshResult.failed =>
          'Client berhasil ditambahkan, tetapi daftar belum dapat disegarkan.',
        _LoadedPagesRefreshResult.superseded =>
          'Client berhasil ditambahkan. Sinkronisasi daftar mengikuti permintaan terbaru.',
      };
      _showMessage(message);
      return;
    }

    final _LoadedPagesRefreshResult refreshResult =
        await _refreshLoadedPages();
    if (!mounted) return;
    final String message = switch (refreshResult) {
      _LoadedPagesRefreshResult.updated =>
        'Perubahan client berhasil disimpan dan daftar disinkronkan dari server.',
      _LoadedPagesRefreshResult.failed =>
        'Perubahan client berhasil disimpan, tetapi daftar belum dapat disegarkan.',
      _LoadedPagesRefreshResult.superseded =>
        'Perubahan client berhasil disimpan. Sinkronisasi daftar mengikuti permintaan terbaru.',
    };
    _showMessage(message);
  }

  Future<_LoadedPagesRefreshResult> _refreshLoadedPages() async {
    if (!mounted) return _LoadedPagesRefreshResult.superseded;
    final int generation = ++_listGeneration;
    final int pagesToRefresh = _currentPage;
    setState(() {
      _refreshing = true;
      _loadingMore = false;
      _loadError = null;
      _totalConfirmedByServer = false;
    });

    try {
      final List<Client> refreshedClients = [];
      ClientListPage? firstPage;
      ClientListPage? latestMetadata;
      int loadedThrough = 0;
      for (int page = 1; page <= pagesToRefresh; page++) {
        if (firstPage != null && page > firstPage.lastPage) break;
        final ClientListPage result = await _api.list(page: page);
        if (!mounted || generation != _listGeneration) {
          return _LoadedPagesRefreshResult.superseded;
        }
        final ClientListPage first = firstPage ?? result;
        firstPage ??= result;
        latestMetadata = result;
        if (result.items.isEmpty) {
          final bool validEmptyCollection =
              page == 1 && result.total == 0 && result.lastPage == 1;
          final bool validReducedBoundary =
              page > 1 &&
              page > result.lastPage &&
              loadedThrough == result.lastPage;
          if (validEmptyCollection) {
            break;
          }
          if (validReducedBoundary) {
            break;
          }
          throw const FormatException('Inconsistent empty client page.');
        }
        if (page > result.lastPage) {
          throw const FormatException('Inconsistent client pagination.');
        }
        if (first.total != result.total || first.lastPage != result.lastPage) {
          final bool validReducedBoundaryWithItems =
              result.lastPage < first.lastPage &&
              page == result.lastPage &&
              loadedThrough == page - 1;
          if (!validReducedBoundaryWithItems) {
            throw const FormatException(
              'Client pagination changed mid-refresh.',
            );
          }
        }

        final Map<int, Client> pageItems = {
          for (final Client existing in refreshedClients) existing.id: existing,
          for (final Client item in result.items) item.id: item,
        };
        refreshedClients
          ..clear()
          ..addAll(pageItems.values);
        loadedThrough = result.currentPage;
        if (page >= result.lastPage) break;
      }

      if (!mounted || generation != _listGeneration || firstPage == null) {
        return _LoadedPagesRefreshResult.superseded;
      }
      final ClientListPage metadata = latestMetadata!;
      setState(() {
        _clients = refreshedClients;
        _currentPage = loadedThrough == 0 ? 1 : loadedThrough;
        _lastPage = metadata.lastPage;
        _total = metadata.total;
        _totalConfirmedByServer = true;
        _hasMorePages =
            loadedThrough > 0 &&
            loadedThrough < metadata.lastPage;
        _loading = false;
        _loadingMore = false;
        _refreshing = false;
      });
      return _LoadedPagesRefreshResult.updated;
    } catch (_) {
      if (!mounted || generation != _listGeneration) {
        return _LoadedPagesRefreshResult.superseded;
      }
      setState(() {
        _loading = false;
        _loadingMore = false;
        _refreshing = false;
      });
      return _LoadedPagesRefreshResult.failed;
    }
  }

  Future<void> _recoverLoadedPages() async {
    if (!mounted || _refreshing) return;
    final _LoadedPagesRefreshResult result = await _refreshLoadedPages();
    if (!mounted) return;
    final String message = switch (result) {
      _LoadedPagesRefreshResult.updated =>
        'Daftar dan total berhasil disinkronkan dari server.',
      _LoadedPagesRefreshResult.failed =>
        'Penyegaran gagal. Total masih merupakan nilai terakhir yang diketahui.',
      _LoadedPagesRefreshResult.superseded =>
        'Penyegaran digantikan permintaan terbaru.',
    };
    _showMessage(message);
  }

  Future<void> _showDetails(Client client) {
    Future<Client> detailFuture = _api.detail(client.id);
    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (sheetContext) => StatefulBuilder(
        builder: (context, setSheetState) => FutureBuilder<Client>(
          future: detailFuture,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const SizedBox(
                height: 220,
                child: Center(child: CircularProgressIndicator()),
              );
            }
            if (snapshot.hasError || !snapshot.hasData) {
              return SizedBox(
                height: 220,
                child: Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(_errorMessage(snapshot.error ?? Exception())),
                      TextButton(
                        onPressed: () => setSheetState(
                          () => detailFuture = _api.detail(client.id),
                        ),
                        child: const Text('Coba lagi'),
                      ),
                    ],
                  ),
                ),
              );
            }
            final Client detailedClient = snapshot.data!;
            return _ClientDetails(
              client: detailedClient,
              consentApi: _api is ClientConsentApi ? _api as ClientConsentApi : null,
              onEdit: () {
                Navigator.pop(sheetContext);
                WidgetsBinding.instance.addPostFrameCallback((_) {
                  if (mounted) _editClient(client: detailedClient);
                });
              },
              onDelete: () => _deleteClient(detailedClient, sheetContext),
            );
          },
        ),
      ),
    );
  }

  Future<void> _deleteClient(Client client, BuildContext sheetContext) async {
    final bool? confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Hapus client?'),
        content: Text('${client.name} akan dihapus dari data organisasi.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('Batal'),
          ),
          FilledButton.tonal(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('Hapus'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    try {
      await _api.delete(client.id);
      if (!mounted) return;
      // Invalidate any page request that could otherwise reinsert the deleted row.
      _listGeneration++;
      if (sheetContext.mounted) Navigator.pop(sheetContext);
      setState(() {
        _loadingMore = false;
        _refreshing = false;
        _hasMorePages = false;
        _totalConfirmedByServer = false;
        _clients.removeWhere((item) => item.id == client.id);
      });
      final _LoadedPagesRefreshResult refreshResult =
          await _refreshLoadedPages();
      final String message = switch (refreshResult) {
        _LoadedPagesRefreshResult.updated => 'Client berhasil dihapus.',
        _LoadedPagesRefreshResult.failed =>
          'Client berhasil dihapus, tetapi daftar belum dapat disegarkan.',
        _LoadedPagesRefreshResult.superseded =>
          'Client berhasil dihapus. Sinkronisasi daftar mengikuti permintaan terbaru.',
      };
      _showMessage(message);
    } catch (error) {
      _showMessage(_errorMessage(error));
    }
  }

  void _showImportInfo() => showDialog<void>(
    context: context,
    builder: (context) => AlertDialog(
      title: const Text('Import Client'),
      content: const Text(
        'Import dari file akan tersedia pada tahap integrasi berikutnya.',
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('Mengerti'),
        ),
      ],
    ),
  );

  void _resetFilters() {
    _searchController.clear();
    setState(() => _statusFilter = null);
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_loadError != null && _clients.isEmpty) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(_loadError!, textAlign: TextAlign.center),
            const SizedBox(height: 12),
            FilledButton.tonal(
              onPressed: _loadClients,
              child: const Text('Coba lagi'),
            ),
          ],
        ),
      );
    }
    final ColorScheme colors = Theme.of(context).colorScheme;
    final List<Client> visible = _visibleClients;
    return LayoutBuilder(
      builder: (context, constraints) {
        final bool desktop = constraints.maxWidth >= 900;
        final double horizontalPadding = constraints.maxWidth < 420 ? 16 : 28;
        return RefreshIndicator(
          onRefresh: () => _totalConfirmedByServer
              ? _loadClients()
              : _recoverLoadedPages(),
          child: SingleChildScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: EdgeInsets.fromLTRB(
              horizontalPadding,
              26,
              horizontalPadding,
              32,
            ),
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 1120),
                child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _buildHeader(context, desktop),
                  const SizedBox(height: 22),
                  _buildStats(context),
                  const SizedBox(height: 22),
                  ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 760),
                    child: TextField(
                      controller: _searchController,
                      onChanged: (_) => setState(() {}),
                      decoration: InputDecoration(
                        hintText: 'Cari nama atau nomor WhatsApp...',
                        prefixIcon: const Icon(Icons.search_rounded),
                        suffixIcon: _searchController.text.isEmpty
                            ? null
                            : IconButton(
                                tooltip: 'Hapus pencarian',
                                onPressed: () {
                                  _searchController.clear();
                                  setState(() {});
                                },
                                icon: const Icon(Icons.close_rounded),
                              ),
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: Row(
                      children: [
                        ChoiceChip(
                          label: const Text('Semua'),
                          selected: _statusFilter == null,
                          onSelected: (_) =>
                              setState(() => _statusFilter = null),
                        ),
                        for (final String status in Client.statuses) ...[
                          const SizedBox(width: 8),
                          ChoiceChip(
                            label: Text(_statusLabels[status] ?? status),
                            selected: _statusFilter == status,
                            onSelected: (selected) => setState(
                              () => _statusFilter = selected ? status : null,
                            ),
                          ),
                        ],
                      ],
                    ),
                  ),
                  const SizedBox(height: 18),
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          _totalConfirmedByServer
                              ? '${visible.length} ditampilkan dari $_total client'
                              : '${visible.length} ditampilkan; total terakhir diketahui $_total client — belum diperbarui dari server',
                          style: Theme.of(context).textTheme.titleSmall
                              ?.copyWith(
                                color: colors.onSurfaceVariant,
                                fontWeight: FontWeight.w600,
                              ),
                        ),
                      ),
                      Text(
                        'DATA SERVER',
                        style: Theme.of(context).textTheme.labelSmall?.copyWith(
                          color: colors.onSurfaceVariant,
                          letterSpacing: .7,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ),
                  if (!_totalConfirmedByServer) ...[
                    const SizedBox(height: 8),
                    Align(
                      alignment: Alignment.centerLeft,
                      child: OutlinedButton.icon(
                        onPressed: _refreshing
                            ? null
                            : _recoverLoadedPages,
                        icon: _refreshing
                            ? const SizedBox(
                                width: 16,
                                height: 16,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                ),
                              )
                            : const Icon(Icons.sync_rounded),
                        label: Text(
                          _clients.isEmpty
                              ? 'Sinkronkan ulang daftar'
                              : 'Sinkronkan ulang halaman yang dimuat',
                        ),
                      ),
                    ),
                  ],
                  if (_searchController.text.trim().isNotEmpty ||
                      _statusFilter != null) ...[
                    const SizedBox(height: 8),
                    Text(
                      'Pencarian dan filter status hanya mencakup data yang sudah dimuat dari server.',
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: colors.onSurfaceVariant,
                      ),
                    ),
                  ],
                  const SizedBox(height: 12),
                  if (_clients.isEmpty)
                    const _EmptyState(onReset: null)
                  else if (visible.isEmpty)
                    _EmptyState(onReset: _resetFilters)
                  else
                    LayoutBuilder(
                      builder: (context, gridConstraints) {
                        final bool twoColumns = gridConstraints.maxWidth >= 760;
                        return GridView.builder(
                          itemCount: visible.length,
                          shrinkWrap: true,
                          physics: const NeverScrollableScrollPhysics(),
                          gridDelegate:
                              SliverGridDelegateWithFixedCrossAxisCount(
                                crossAxisCount: twoColumns ? 2 : 1,
                                mainAxisExtent: 140,
                                crossAxisSpacing: 14,
                                mainAxisSpacing: 12,
                              ),
                          itemBuilder: (context, index) => ClientCard(
                            client: visible[index],
                            onTap: () => _showDetails(visible[index]),
                          ),
                        );
                      },
                    ),
                  if (_hasMorePages) ...[
                    const SizedBox(height: 16),
                    OutlinedButton.icon(
                      onPressed: _loadingMore ||
                              _refreshing ||
                              !_totalConfirmedByServer
                          ? null
                          : () => _loadClients(loadMore: true),
                      icon: _loadingMore
                          ? const SizedBox(
                              width: 16,
                              height: 16,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Icon(Icons.expand_more_rounded),
                      label: Text(
                        _loadingMore ? 'Memuat...' : 'Muat halaman berikutnya',
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ),
          ),
        );
      },
    );
  }

  Widget _buildHeader(BuildContext context, bool desktop) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    final Widget title = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Client',
          style: Theme.of(context).textTheme.headlineSmall?.copyWith(
            color: colors.onSurface,
            fontWeight: FontWeight.w700,
            letterSpacing: -.5,
          ),
        ),
        const SizedBox(height: 5),
        Text(
          'Kelola kontak pelanggan dan penerima campaign Anda.',
          style: Theme.of(context).textTheme.bodyMedium
              ?.copyWith(color: colors.onSurfaceVariant),
        ),
      ],
    );
    final Widget actions = Wrap(
      spacing: 9,
      runSpacing: 8,
      children: [
        OutlinedButton.icon(
          onPressed: _showImportInfo,
          icon: const Icon(Icons.upload_file_outlined, size: 18),
          label: const Text('Import Client'),
        ),
        FilledButton.icon(
          onPressed: () => _editClient(),
          icon: const Icon(Icons.person_add_alt_1_rounded, size: 18),
          label: const Text('Tambah Client'),
        ),
      ],
    );
    if (desktop) {
      return Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Expanded(child: title),
          const SizedBox(width: 16),
          actions,
        ],
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [title, const SizedBox(height: 14), actions],
    );
  }

  Widget _buildStats(BuildContext context) {
    return _StatTile(
      label: _totalConfirmedByServer
          ? 'Total Client organisasi'
          : 'Total terakhir diketahui — belum diperbarui dari server',
      value: '$_total',
    );
  }
}

class _StatTile extends StatelessWidget {
  const _StatTile({required this.label, required this.value});
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: colors.surfaceContainerLow,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: colors.outlineVariant),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(
            value,
            style: Theme.of(context).textTheme.titleLarge?.copyWith(
              fontWeight: FontWeight.w700,
              color: colors.onSurface,
            ),
          ),
          Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.labelSmall
                ?.copyWith(color: colors.onSurfaceVariant),
          ),
        ],
      ),
    );
  }
}

class _EmptyState extends StatelessWidget {
  const _EmptyState({required this.onReset});
  final VoidCallback? onReset;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 38),
      decoration: BoxDecoration(
        border: Border.all(color: colors.outlineVariant),
        borderRadius: BorderRadius.circular(18),
      ),
      child: Column(
        children: [
          Icon(Icons.person_search_outlined, size: 34, color: colors.primary),
          const SizedBox(height: 12),
          Text(
            onReset == null ? 'Belum ada client' : 'Client tidak ditemukan',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 6),
          Text(
            onReset == null
                ? 'Client yang tersimpan pada organisasi akan tampil di sini.'
                : 'Ubah kata kunci atau filter untuk melihat client lainnya.',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodyMedium
                ?.copyWith(color: colors.onSurfaceVariant),
          ),
          const SizedBox(height: 14),
          if (onReset != null)
            OutlinedButton(
              onPressed: onReset,
              child: const Text('Reset Filter'),
            ),
        ],
      ),
    );
  }
}

class _ClientDetails extends StatefulWidget {
  const _ClientDetails({
    required this.client,
    this.consentApi,
    required this.onEdit,
    required this.onDelete,
  });
  final Client client;
  final ClientConsentApi? consentApi;
  final VoidCallback onEdit;
  final Future<void> Function() onDelete;

  @override
  State<_ClientDetails> createState() => _ClientDetailsState();
}

class _ClientDetailsState extends State<_ClientDetails> {
  bool _deleting = false;
  bool _recordingConsent = false;
  late Client _client;

  @override
  void initState() {
    super.initState();
    _client = widget.client;
  }

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    final Client client = _client;
    return SingleChildScrollView(
      padding: EdgeInsets.fromLTRB(
        24,
        8,
        24,
        24 + MediaQuery.viewInsetsOf(context).bottom,
      ),
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 540),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                client.name,
                style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: colors.onSurface,
                ),
              ),
              const SizedBox(height: 5),
              Text(
                client.phoneNumber,
                style: Theme.of(context).textTheme.bodyLarge
                    ?.copyWith(color: colors.onSurfaceVariant),
              ),
              const SizedBox(height: 22),
              _DetailRow(label: 'Grup', value: client.group ?? 'Tanpa grup'),
              _DetailRow(label: 'Status', value: client.statusLabel),
              _DetailRow(
                label: 'Persetujuan broadcast',
                value: switch (client.whatsappConsentStatus) {
                  'granted' => 'Disetujui',
                  'withdrawn' => 'Dicabut',
                  _ => 'Belum tercatat',
                },
              ),
              if (client.whatsappConsentRecordedAt != null)
                _DetailRow(
                  label: 'Waktu persetujuan',
                  value: _formatDateTime(client.whatsappConsentRecordedAt!),
                ),
              if (client.email != null && client.email!.isNotEmpty)
                _DetailRow(label: 'Email', value: client.email!),
              _DetailRow(
                label: 'Diperbarui',
                value: _formatDate(client.updatedAt),
              ),
              _DetailRow(
                label: 'Tanggal ditambahkan',
                value: _formatDate(client.createdAt),
              ),
              const SizedBox(height: 10),
              Text(
                'Catatan',
                style: Theme.of(context).textTheme.labelLarge
                    ?.copyWith(color: colors.onSurfaceVariant),
              ),
              const SizedBox(height: 5),
              Text(
                client.notes.isEmpty ? 'Belum ada catatan.' : client.notes,
                style: Theme.of(context).textTheme.bodyMedium
                    ?.copyWith(height: 1.45),
              ),
              const SizedBox(height: 22),
              if (widget.consentApi != null) ...[
                Text(
                  'Bukti dicatat sebagai referensi; jangan masukkan isi pesan atau data rahasia.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 8,
                  children: [
                    OutlinedButton(
                      onPressed: _recordingConsent || _deleting
                          ? null
                          : () => _recordConsent('granted'),
                      child: const Text('Catat persetujuan'),
                    ),
                    OutlinedButton(
                      onPressed: _recordingConsent || _deleting
                          ? null
                          : () => _recordConsent('withdrawn'),
                      child: const Text('Catat pencabutan'),
                    ),
                  ],
                ),
                if (_recordingConsent)
                  const LinearProgressIndicator(),
                const SizedBox(height: 12),
              ],
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: _deleting
                          ? null
                          : () async {
                              setState(() => _deleting = true);
                              await widget.onDelete();
                              if (mounted) setState(() => _deleting = false);
                            },
                      icon: const Icon(Icons.delete_outline_rounded),
                      label: Text(_deleting ? 'Menghapus...' : 'Hapus'),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: FilledButton.icon(
                      onPressed: _deleting || _recordingConsent
                          ? null
                          : widget.onEdit,
                      icon: const Icon(Icons.edit_outlined),
                      label: const Text('Edit'),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  String _formatDate(DateTime? date) {
    if (date == null) return '—';
    return '${date.day.toString().padLeft(2, '0')}/${date.month.toString().padLeft(2, '0')}/${date.year}';
  }

  String _formatDateTime(DateTime date) =>
      '${_formatDate(date)} ${date.hour.toString().padLeft(2, '0')}:${date.minute.toString().padLeft(2, '0')}';

  Future<void> _recordConsent(String action) async {
    final api = widget.consentApi;
    if (api == null || _recordingConsent) return;
    final source = TextEditingController();
    final evidenceType = TextEditingController();
    final evidenceReference = TextEditingController();
    final formKey = GlobalKey<FormState>();
    try {
      final values = await showDialog<List<String>>(
        context: context,
        builder: (dialogContext) => AlertDialog(
          title: Text(
            action == 'granted' ? 'Catat persetujuan' : 'Catat pencabutan',
          ),
          content: Form(
            key: formKey,
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  TextFormField(
                    controller: source,
                    decoration: const InputDecoration(labelText: 'Sumber'),
                    validator: _requiredEvidence,
                  ),
                  TextFormField(
                    controller: evidenceType,
                    decoration: const InputDecoration(
                      labelText: 'Jenis bukti',
                    ),
                    validator: _requiredEvidence,
                  ),
                  TextFormField(
                    controller: evidenceReference,
                    decoration: const InputDecoration(
                      labelText: 'Referensi bukti',
                    ),
                    validator: _requiredEvidence,
                  ),
                ],
              ),
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(dialogContext),
              child: const Text('Batal'),
            ),
            FilledButton(
              onPressed: () {
                if (formKey.currentState!.validate()) {
                  Navigator.pop(dialogContext, [
                    source.text.trim(),
                    evidenceType.text.trim(),
                    evidenceReference.text.trim(),
                  ]);
                }
              },
              child: const Text('Simpan bukti'),
            ),
          ],
        ),
      );
      if (values == null || !mounted) return;
      setState(() => _recordingConsent = true);
      final updated = await api.recordWhatsAppConsent(
        clientId: _client.id,
        action: action,
        source: values[0],
        evidenceType: values[1],
        evidenceReference: values[2],
      );
      if (mounted) setState(() => _client = updated);
    } catch (error) {
      if (mounted) {
        final message = error is ClientApiException
            ? error.message
            : 'Status persetujuan gagal disimpan. Silakan coba kembali.';
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(message)),
        );
      }
    } finally {
      source.dispose();
      evidenceType.dispose();
      evidenceReference.dispose();
      if (mounted) setState(() => _recordingConsent = false);
    }
  }

  String? _requiredEvidence(String? value) =>
      value == null || value.trim().isEmpty ? 'Wajib diisi.' : null;
}

class _DetailRow extends StatelessWidget {
  const _DetailRow({required this.label, required this.value});
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 140,
            child: Text(
              label,
              style: Theme.of(context).textTheme.bodySmall
                  ?.copyWith(color: colors.onSurfaceVariant),
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                color: colors.onSurface,
                fontWeight: FontWeight.w500,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ClientForm extends StatefulWidget {
  const _ClientForm({this.client, required this.onSave});
  final Client? client;
  final Future<Client> Function(Client client) onSave;

  @override
  State<_ClientForm> createState() => _ClientFormState();
}

class _ClientFormState extends State<_ClientForm> {
  final GlobalKey<FormState> _formKey = GlobalKey<FormState>();
  late final TextEditingController _name;
  late final TextEditingController _phone;
  late final TextEditingController _email;
  late final TextEditingController _group;
  late final TextEditingController _notes;
  late String _status;
  bool _saving = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _name = TextEditingController(text: widget.client?.name ?? '');
    _phone = TextEditingController(text: widget.client?.phoneNumber ?? '');
    _email = TextEditingController(text: widget.client?.email ?? '');
    _group = TextEditingController(text: widget.client?.group ?? '');
    _notes = TextEditingController(text: widget.client?.notes ?? '');
    _status = widget.client?.status ?? 'new';
  }

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    _email.dispose();
    _group.dispose();
    _notes.dispose();
    super.dispose();
  }

  String? _validatePhone(String? value) {
    final String phone = value?.trim() ?? '';
    if (phone.isEmpty) return 'Nomor WhatsApp wajib diisi.';
    final String digits = phone.replaceAll(RegExp(r'\D'), '');
    if (!RegExp(r'^[+\d\s()-]+$').hasMatch(phone) ||
        digits.length < 8 ||
        digits.length > 15) {
      return 'Masukkan nomor dengan format yang valid.';
    }
    return null;
  }

  Future<void> _submit() async {
    if (_saving || !_formKey.currentState!.validate()) return;
    final Client? previous = widget.client;
    final Client draft = Client(
        id: previous?.id ?? 0,
        name: _name.text.trim(),
        phoneNumber: _phone.text.trim(),
        email: _email.text.trim().isEmpty ? null : _email.text.trim(),
        group: _group.text.trim().isEmpty ? null : _group.text.trim(),
        status: _status,
        notes: _notes.text.trim(),
        createdAt: previous?.createdAt,
        updatedAt: previous?.updatedAt,
      );
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final Client saved = await widget.onSave(draft);
      if (mounted) Navigator.pop(context, saved);
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = error is ClientApiException
            ? error.message
            : 'Client gagal disimpan. Silakan coba kembali.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return SingleChildScrollView(
      padding: EdgeInsets.fromLTRB(
        24,
        8,
        24,
        20 + MediaQuery.viewInsetsOf(context).bottom,
      ),
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 560),
          child: Form(
            key: _formKey,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  widget.client == null ? 'Tambah Client' : 'Edit Client',
                  style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                    fontWeight: FontWeight.w700,
                    color: colors.onSurface,
                  ),
                ),
                const SizedBox(height: 18),
                TextFormField(
                  controller: _name,
                  textCapitalization: TextCapitalization.words,
                  decoration: const InputDecoration(labelText: 'Nama lengkap'),
                  validator: (value) => value == null || value.trim().isEmpty
                      ? 'Nama lengkap wajib diisi.'
                      : null,
                ),
                const SizedBox(height: 13),
                TextFormField(
                  controller: _phone,
                  keyboardType: TextInputType.phone,
                  decoration: const InputDecoration(
                    labelText: 'Nomor WhatsApp',
                    hintText: 'Contoh: +62 812 0000 0001',
                  ),
                  validator: _validatePhone,
                ),
                const SizedBox(height: 13),
                TextFormField(
                  controller: _email,
                  keyboardType: TextInputType.emailAddress,
                  decoration: const InputDecoration(labelText: 'Email (opsional)'),
                  validator: (value) {
                    final String email = value?.trim() ?? '';
                    if (email.isNotEmpty && !email.contains('@')) {
                      return 'Masukkan email yang valid.';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 13),
                TextFormField(
                  controller: _group,
                  decoration: const InputDecoration(
                    labelText: 'Grup (opsional)',
                    hintText: 'Kosongkan jika tanpa grup',
                  ),
                ),
                const SizedBox(height: 13),
                DropdownButtonFormField<String>(
                  initialValue: _status,
                  decoration: const InputDecoration(labelText: 'Status'),
                  items: [
                    for (final String status in Client.statuses)
                      DropdownMenuItem(
                        value: status,
                        child: Text(_statusLabel(status)),
                      ),
                  ],
                  onChanged: (value) {
                    if (value != null) setState(() => _status = value);
                  },
                ),
                const SizedBox(height: 13),
                TextFormField(
                  controller: _notes,
                  minLines: 2,
                  maxLines: 3,
                  textCapitalization: TextCapitalization.sentences,
                  decoration: const InputDecoration(
                    labelText: 'Catatan (opsional)',
                    alignLabelWithHint: true,
                  ),
                ),
                const SizedBox(height: 18),
                if (_error != null) ...[
                  Text(_error!, style: TextStyle(color: colors.error)),
                  const SizedBox(height: 10),
                ],
                FilledButton(
                  onPressed: _saving ? null : _submit,
                  child: _saving
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : Text(
                          widget.client == null
                              ? 'Tambah Client'
                              : 'Simpan Perubahan',
                        ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  String _statusLabel(String status) => switch (status) {
    'customer' => 'Pelanggan',
    'prospect' => 'Prospek',
    'vip' => 'VIP',
    'new' => 'Baru',
    _ => status,
  };
}
