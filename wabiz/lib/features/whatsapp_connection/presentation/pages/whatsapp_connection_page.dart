import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';

import '../../data/models/whatsapp_connection.dart';
import '../../data/whatsapp_connection_service.dart';
import '../widgets/create_whatsapp_connection_form.dart';

class WhatsAppConnectionPage extends StatefulWidget {
  const WhatsAppConnectionPage({super.key});

  @override
  State<WhatsAppConnectionPage> createState() => _WhatsAppConnectionPageState();
}

class _WhatsAppConnectionPageState extends State<WhatsAppConnectionPage> {
  WhatsAppConnectionListResponse? _response;
  bool _isLoading = true;
  bool _hasError = false;
  final Set<int> _verifyingConnectionIds = <int>{};
  final Set<int> _lifecycleConnectionIds = <int>{};

  @override
  void initState() {
    super.initState();
    _loadConnections();
  }

  Future<void> _loadConnections() async {
    setState(() {
      _isLoading = true;
      _hasError = false;
    });

    try {
      final response = await WhatsAppConnectionService.instance
          .getConnections();
      if (!mounted) return;
      setState(() {
        _response = response;
        _isLoading = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _hasError = true;
        _isLoading = false;
      });
    }
  }

  Future<void> _refreshConnectionsSilently() async {
    try {
      final response = await WhatsAppConnectionService.instance
          .getConnections();
      if (!mounted) return;
      setState(() {
        _response = response;
        _hasError = false;
      });
    } catch (_) {
      // Keep the current list and verification result when refresh is offline.
    }
  }

  Future<void> _verifyConnection(WhatsAppConnection connection) async {
    if (_verifyingConnectionIds.contains(connection.id)) return;
    setState(() => _verifyingConnectionIds.add(connection.id));

    try {
      final result = await WhatsAppConnectionService.instance.verifyConnection(
        connection.id,
      );
      if (!mounted) return;

      if (result.status.toLowerCase() == 'connected') {
        _applyVerificationResult(connection.id, result);
        await _refreshConnectionsSilently();
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Koneksi WhatsApp berhasil diverifikasi.'),
          ),
        );
      } else {
        await _refreshConnectionsSilently();
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              'Backend belum mengonfirmasi koneksi. Status: ${result.status}.',
            ),
          ),
        );
      }
    } on WhatsAppConnectionServiceException catch (error) {
      await _refreshConnectionsSilently();
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(error.message)));
    } catch (_) {
      await _refreshConnectionsSilently();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Verifikasi gagal. Silakan coba kembali.'),
        ),
      );
    } finally {
      if (mounted) {
        setState(() => _verifyingConnectionIds.remove(connection.id));
      }
    }
  }

  void _applyVerificationResult(
    int connectionId,
    WhatsAppConnectionVerificationResult result,
  ) {
    final currentResponse = _response;
    if (!mounted || currentResponse == null) return;

    final connections = currentResponse.connections
        .map(
          (connection) => connection.id == connectionId
              ? connection.copyWith(
                  status: result.status,
                  lastConnectedAt:
                      result.verifiedAt ?? connection.lastConnectedAt,
                )
              : connection,
        )
        .toList(growable: false);
    setState(() {
      _response = WhatsAppConnectionListResponse(
        connections: connections,
        meta: currentResponse.meta,
      );
    });
  }

  Future<void> _openCreateForm() async {
    final createdConnection = await showModalBottomSheet<WhatsAppConnection>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (context) => const CreateWhatsAppConnectionForm(),
    );
    if (!mounted || createdConnection == null) return;

    await _loadConnections();
    if (!mounted) return;
    if (createdConnection.provider == 'wppconnect') {
      await _startQrConnection(createdConnection);
      return;
    }
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Koneksi WhatsApp berhasil ditambahkan.')),
    );
  }

  Future<void> _startQrConnection(WhatsAppConnection connection) async {
    if (_lifecycleConnectionIds.contains(connection.id)) return;
    setState(() => _lifecycleConnectionIds.add(connection.id));
    try {
      final status = await WhatsAppConnectionService.instance.startQrConnection(
        connection.id,
      );
      if (!mounted) return;
      await _refreshConnectionsSilently();
      if (status.status == 'connected') return;
      final qr = await WhatsAppConnectionService.instance.getQr(connection.id);
      if (!mounted) return;
      await showDialog<void>(
        context: context,
        barrierDismissible: false,
        builder: (_) => _QrConnectionDialog(
          connectionId: connection.id,
          initialStatus: status,
          qr: qr,
        ),
      );
      await _refreshConnectionsSilently();
    } on WhatsAppConnectionServiceException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) {
        setState(() => _lifecycleConnectionIds.remove(connection.id));
      }
    }
  }

  Future<void> _disconnectQrConnection(WhatsAppConnection connection) async {
    if (_lifecycleConnectionIds.contains(connection.id)) return;
    setState(() => _lifecycleConnectionIds.add(connection.id));
    try {
      await WhatsAppConnectionService.instance.disconnectQrConnection(
        connection.id,
      );
      await _refreshConnectionsSilently();
    } on WhatsAppConnectionServiceException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) {
        setState(() => _lifecycleConnectionIds.remove(connection.id));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    final TextTheme textTheme = Theme.of(context).textTheme;

    return LayoutBuilder(
      builder: (context, constraints) {
        final bool desktop = constraints.maxWidth >= 920;
        final double horizontalPadding = constraints.maxWidth < 420 ? 16 : 28;
        final Widget flowSection = _ConnectionFlowSection(
          desktop: constraints.maxWidth >= 560,
        );
        final Widget securityNotice = const _SecurityNotice();

        return RefreshIndicator(
          onRefresh: _loadConnections,
          child: SingleChildScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: EdgeInsets.fromLTRB(
              horizontalPadding,
              28,
              horizontalPadding,
              32,
            ),
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 960),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      'WhatsApp Connection',
                      style: textTheme.headlineSmall?.copyWith(
                        color: colors.onSurface,
                        fontWeight: FontWeight.w700,
                        letterSpacing: -.5,
                      ),
                    ),
                    const SizedBox(height: 7),
                    ConstrainedBox(
                      constraints: const BoxConstraints(maxWidth: 680),
                      child: Text(
                        'Hubungkan nomor WhatsApp bisnis Anda untuk mulai mengelola pesan dan campaign.',
                        style: textTheme.bodyLarge?.copyWith(
                          color: colors.onSurfaceVariant,
                          height: 1.5,
                        ),
                      ),
                    ),
                    const SizedBox(height: 24),
                    Align(
                      alignment: Alignment.centerRight,
                      child: FilledButton.icon(
                        onPressed: _openCreateForm,
                        icon: const Icon(Icons.add_rounded),
                        label: const Text('Tambah Koneksi'),
                      ),
                    ),
                    const SizedBox(height: 12),
                    _buildConnectionsSection(colors, textTheme),
                    const SizedBox(height: 30),
                    Text(
                      'Bagaimana koneksi bekerja?',
                      style: textTheme.titleLarge?.copyWith(
                        color: colors.onSurface,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'WhatsApp Connection menjadi penghubung antara nomor WhatsApp bisnis, campaign, client, dan AI Assistant WABiz.',
                      style: textTheme.bodyMedium?.copyWith(
                        color: colors.onSurfaceVariant,
                        height: 1.5,
                      ),
                    ),
                    const SizedBox(height: 16),
                    if (desktop)
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(child: flowSection),
                          const SizedBox(width: 16),
                          Expanded(child: securityNotice),
                        ],
                      )
                    else ...[
                      flowSection,
                      const SizedBox(height: 12),
                      securityNotice,
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

  Widget _buildConnectionsSection(ColorScheme colors, TextTheme textTheme) {
    if (_isLoading) {
      return _StateCard(
        child: const Center(
          child: Padding(
            padding: EdgeInsets.all(28),
            child: CircularProgressIndicator(),
          ),
        ),
      );
    }

    if (_hasError) {
      return _StateCard(
        child: Column(
          children: [
            Icon(Icons.cloud_off_outlined, color: colors.error, size: 32),
            const SizedBox(height: 12),
            Text(
              'Koneksi belum dapat dimuat',
              style: textTheme.titleMedium?.copyWith(
                color: colors.onSurface,
                fontWeight: FontWeight.w700,
              ),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 6),
            Text(
              'Periksa koneksi Anda, lalu coba kembali.',
              style: textTheme.bodyMedium?.copyWith(
                color: colors.onSurfaceVariant,
              ),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 16),
            OutlinedButton.icon(
              onPressed: _loadConnections,
              icon: const Icon(Icons.refresh_rounded),
              label: const Text('Coba Lagi'),
            ),
          ],
        ),
      );
    }

    final connections = _response?.connections ?? const <WhatsAppConnection>[];
    if (connections.isEmpty) {
      return _StateCard(
        child: Column(
          children: [
            Icon(
              Icons.chat_bubble_outline_rounded,
              color: colors.primary,
              size: 34,
            ),
            const SizedBox(height: 12),
            Text(
              'Belum ada koneksi WhatsApp',
              style: textTheme.titleMedium?.copyWith(
                color: colors.onSurface,
                fontWeight: FontWeight.w700,
              ),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 6),
            Text(
              'Koneksi WhatsApp bisnis yang tersedia akan ditampilkan di sini.',
              style: textTheme.bodyMedium?.copyWith(
                color: colors.onSurfaceVariant,
                height: 1.5,
              ),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 16),
            FilledButton.icon(
              onPressed: _openCreateForm,
              icon: const Icon(Icons.add_rounded),
              label: const Text('Tambah Koneksi'),
            ),
          ],
        ),
      );
    }

    return Column(
      children: [
        for (var index = 0; index < connections.length; index++) ...[
          if (index > 0) const SizedBox(height: 12),
          _WhatsAppConnectionCard(
            connection: connections[index],
            isVerifying: _verifyingConnectionIds.contains(
              connections[index].id,
            ),
            onVerify: () => _verifyConnection(connections[index]),
            isLifecycleBusy: _lifecycleConnectionIds.contains(
              connections[index].id,
            ),
            onStartQr: () => _startQrConnection(connections[index]),
            onDisconnect: () => _disconnectQrConnection(connections[index]),
          ),
        ],
      ],
    );
  }
}

class _StateCard extends StatelessWidget {
  const _StateCard({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    final colors = Theme.of(context).colorScheme;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(22),
      decoration: BoxDecoration(
        color: colors.surface,
        border: Border.all(color: colors.outlineVariant),
        borderRadius: BorderRadius.circular(20),
      ),
      child: child,
    );
  }
}

class _WhatsAppConnectionCard extends StatelessWidget {
  const _WhatsAppConnectionCard({
    required this.connection,
    required this.isVerifying,
    required this.onVerify,
    required this.isLifecycleBusy,
    required this.onStartQr,
    required this.onDisconnect,
  });

  final WhatsAppConnection connection;
  final bool isVerifying;
  final VoidCallback onVerify;
  final bool isLifecycleBusy;
  final VoidCallback onStartQr;
  final VoidCallback onDisconnect;

  @override
  Widget build(BuildContext context) {
    final colors = Theme.of(context).colorScheme;
    final textTheme = Theme.of(context).textTheme;
    final isConnected = connection.status.toLowerCase() == 'connected';
    final statusColor = switch (connection.status.toLowerCase()) {
      'connected' when connection.enabled => colors.primary,
      'error' => colors.error,
      _ => colors.onSurfaceVariant,
    };

    return _StateCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(Icons.chat_bubble_outline_rounded, color: statusColor),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      connection.displayName,
                      style: textTheme.titleMedium?.copyWith(
                        color: colors.onSurface,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      connection.phoneNumber.isEmpty
                          ? connection.provider
                          : '${connection.provider} · ${connection.phoneNumber}',
                      style: textTheme.bodyMedium?.copyWith(
                        color: colors.onSurfaceVariant,
                      ),
                    ),
                  ],
                ),
              ),
              if (connection.isPrimary)
                _InfoPill(
                  label: 'Utama',
                  background: colors.primaryContainer,
                  foreground: colors.onPrimaryContainer,
                ),
            ],
          ),
          const SizedBox(height: 16),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              _InfoPill(
                label: _statusLabel(connection.status),
                background: colors.surfaceContainerHighest,
                foreground: statusColor,
              ),
              _InfoPill(
                label: connection.enabled ? 'Aktif' : 'Nonaktif',
                background: colors.surfaceContainerHighest,
                foreground: colors.onSurfaceVariant,
              ),
            ],
          ),
          if (connection.lastConnectedAt != null ||
              connection.lastWebhookAt != null) ...[
            const SizedBox(height: 14),
            if (connection.lastConnectedAt != null)
              _TimestampLine(
                label: 'Terhubung terakhir',
                value: connection.lastConnectedAt!,
              ),
            if (connection.lastWebhookAt != null)
              _TimestampLine(
                label: 'Webhook terakhir',
                value: connection.lastWebhookAt!,
              ),
          ],
          const SizedBox(height: 16),
          Align(
            alignment: Alignment.centerRight,
            child: connection.provider == 'wppconnect'
                ? isConnected
                      ? OutlinedButton.icon(
                          onPressed: isLifecycleBusy ? null : onDisconnect,
                          icon: const Icon(Icons.link_off_rounded, size: 18),
                          label: const Text('Disconnect'),
                        )
                      : FilledButton.icon(
                          onPressed: isLifecycleBusy ? null : onStartQr,
                          icon: isLifecycleBusy
                              ? const SizedBox(
                                  width: 16,
                                  height: 16,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2,
                                  ),
                                )
                              : const Icon(Icons.qr_code_rounded, size: 18),
                          label: Text(
                            isLifecycleBusy ? 'Memuat...' : 'Tampilkan QR',
                          ),
                        )
                : OutlinedButton.icon(
                    onPressed: isVerifying ? null : onVerify,
                    icon: isVerifying
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.verified_outlined, size: 18),
                    label: Text(
                      isVerifying
                          ? 'Memverifikasi...'
                          : isConnected
                          ? 'Verify ulang'
                          : 'Verify',
                    ),
                  ),
          ),
        ],
      ),
    );
  }

  String _statusLabel(String status) {
    if (status.isEmpty) return 'Status tidak diketahui';
    return status
        .split(RegExp(r'[_\s-]+'))
        .where((part) => part.isNotEmpty)
        .map((part) => '${part[0].toUpperCase()}${part.substring(1)}')
        .join(' ');
  }
}

class _QrConnectionDialog extends StatefulWidget {
  const _QrConnectionDialog({
    required this.connectionId,
    required this.initialStatus,
    required this.qr,
  });

  final int connectionId;
  final WhatsAppConnectionStatusInfo initialStatus;
  final WhatsAppConnectionQr qr;

  @override
  State<_QrConnectionDialog> createState() => _QrConnectionDialogState();
}

class _QrConnectionDialogState extends State<_QrConnectionDialog> {
  late WhatsAppConnectionStatusInfo _status = widget.initialStatus;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _timer = Timer.periodic(const Duration(seconds: 3), (_) => _refresh());
  }

  Future<void> _refresh() async {
    try {
      final status = await WhatsAppConnectionService.instance
          .getConnectionStatus(widget.connectionId);
      if (mounted) setState(() => _status = status);
      if (status.status == 'connected') _timer?.cancel();
    } catch (_) {
      // Keep showing the last confirmed gateway state during brief outages.
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final connected = _status.status == 'connected';
    return AlertDialog(
      title: Text(connected ? 'WhatsApp terhubung' : 'Pindai QR WhatsApp'),
      content: SizedBox(
        width: 360,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (!connected)
              Image.memory(
                base64Decode(widget.qr.base64),
                width: 280,
                height: 280,
              )
            else
              const Icon(
                Icons.check_circle_outline,
                size: 72,
                color: Colors.green,
              ),
            const SizedBox(height: 12),
            Text(
              connected
                  ? (_status.phoneNumber ?? 'Nomor terdeteksi')
                  : 'Status: ${_status.status}',
            ),
            if (!connected)
              const Padding(
                padding: EdgeInsets.only(top: 6),
                child: Text(
                  'QR hanya digunakan untuk pairing dan tidak disimpan.',
                ),
              ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: Text(connected ? 'Selesai' : 'Tutup'),
        ),
      ],
    );
  }
}

class _InfoPill extends StatelessWidget {
  const _InfoPill({
    required this.label,
    required this.background,
    required this.foreground,
  });

  final String label;
  final Color background;
  final Color foreground;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(30),
      ),
      child: Text(
        label,
        style: Theme.of(context).textTheme.labelSmall
            ?.copyWith(color: foreground, fontWeight: FontWeight.w700),
      ),
    );
  }
}

class _TimestampLine extends StatelessWidget {
  const _TimestampLine({required this.label, required this.value});

  final String label;
  final DateTime value;

  @override
  Widget build(BuildContext context) {
    final local = value.toLocal();
    final formatted =
        '${local.day.toString().padLeft(2, '0')}/'
        '${local.month.toString().padLeft(2, '0')}/${local.year} '
        '${local.hour.toString().padLeft(2, '0')}:'
        '${local.minute.toString().padLeft(2, '0')}';
    return Padding(
      padding: const EdgeInsets.only(top: 4),
      child: Text(
        '$label: $formatted',
        style: Theme.of(context).textTheme.bodySmall
            ?.copyWith(color: Theme.of(context).colorScheme.onSurfaceVariant),
      ),
    );
  }
}

class _ConnectionFlowSection extends StatelessWidget {
  const _ConnectionFlowSection({required this.desktop});

  final bool desktop;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    final List<Widget> nodes = [
      const _FlowNode(
        label: 'WhatsApp',
        icon: Icons.chat_bubble_outline_rounded,
      ),
      const _FlowNode(label: 'WABiz', icon: Icons.hub_outlined),
      const _FlowNode(
        label: 'Campaign / AI Assistant',
        icon: Icons.auto_awesome_outlined,
      ),
    ];

    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: colors.surface,
        border: Border.all(color: colors.outlineVariant),
        borderRadius: BorderRadius.circular(16),
      ),
      child: desktop
          ? Row(
              children: [
                Expanded(child: nodes[0]),
                _FlowArrow(horizontal: true),
                Expanded(child: nodes[1]),
                _FlowArrow(horizontal: true),
                Expanded(child: nodes[2]),
              ],
            )
          : Column(
              children: [
                nodes[0],
                const _FlowArrow(horizontal: false),
                nodes[1],
                const _FlowArrow(horizontal: false),
                nodes[2],
              ],
            ),
    );
  }
}

class _FlowNode extends StatelessWidget {
  const _FlowNode({required this.label, required this.icon});

  final String label;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 19, color: colors.primary),
        const SizedBox(width: 8),
        Flexible(
          child: Text(
            label,
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.labelLarge?.copyWith(
              color: colors.onSurface,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
      ],
    );
  }
}

class _FlowArrow extends StatelessWidget {
  const _FlowArrow({required this.horizontal});

  final bool horizontal;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return Padding(
      padding: horizontal
          ? const EdgeInsets.symmetric(horizontal: 8)
          : const EdgeInsets.symmetric(vertical: 8),
      child: Icon(
        horizontal ? Icons.arrow_forward_rounded : Icons.arrow_downward_rounded,
        size: 18,
        color: colors.outline,
      ),
    );
  }
}

class _SecurityNotice extends StatelessWidget {
  const _SecurityNotice();

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: colors.surfaceContainerLow,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: colors.outlineVariant),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.shield_outlined, color: colors.primary, size: 21),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              'Credential dan API key tidak disimpan langsung di aplikasi Flutter. Integrasi production akan menggunakan backend WABiz.',
              style: Theme.of(context).textTheme.bodySmall
                  ?.copyWith(color: colors.onSurfaceVariant, height: 1.5),
            ),
          ),
        ],
      ),
    );
  }
}
