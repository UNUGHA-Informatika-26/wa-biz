import 'package:flutter/material.dart';

enum WhatsAppConnectionStatus { disconnected, connected }

class ConnectionStatusCard extends StatelessWidget {
  const ConnectionStatusCard({
    required this.status,
    required this.onConnect,
    this.phoneNumber,
    this.businessName,
    this.onManage,
    this.onDisconnect,
    super.key,
  });

  final WhatsAppConnectionStatus status;
  final String? phoneNumber;
  final String? businessName;
  final VoidCallback onConnect;
  final VoidCallback? onManage;
  final VoidCallback? onDisconnect;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    final TextTheme textTheme = Theme.of(context).textTheme;
    final bool connected = status == WhatsAppConnectionStatus.connected;

    // TODO: Populate connection status and account details from the WABiz API.
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(22),
      decoration: BoxDecoration(
        color: colors.surface,
        border: Border.all(color: colors.outlineVariant),
        borderRadius: BorderRadius.circular(20),
      ),
      child: LayoutBuilder(
        builder: (context, constraints) {
          final bool compact = constraints.maxWidth < 520;
          return Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: 48,
                    height: 48,
                    decoration: BoxDecoration(
                      color: connected
                          ? colors.primaryContainer
                          : colors.surfaceContainerHighest,
                      borderRadius: BorderRadius.circular(15),
                    ),
                    child: Icon(
                      Icons.chat_bubble_outline_rounded,
                      color: connected
                          ? colors.onPrimaryContainer
                          : colors.onSurfaceVariant,
                      size: 23,
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Wrap(
                          spacing: 9,
                          runSpacing: 7,
                          crossAxisAlignment: WrapCrossAlignment.center,
                          children: [
                            Text(
                              connected ? 'Terhubung' : 'Belum Terhubung',
                              style: textTheme.titleLarge?.copyWith(
                                color: colors.onSurface,
                                fontWeight: FontWeight.w700,
                                letterSpacing: -.3,
                              ),
                            ),
                            _StatusPill(connected: connected),
                          ],
                        ),
                        const SizedBox(height: 8),
                        Text(
                          connected
                              ? '${businessName ?? 'WhatsApp Business'}  ·  ${phoneNumber ?? ''}'
                              : 'Hubungkan nomor WhatsApp untuk menggunakan fitur campaign dan AI Assistant.',
                          style: textTheme.bodyMedium?.copyWith(
                            color: colors.onSurfaceVariant,
                            height: 1.5,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 22),
              if (!connected)
                SizedBox(
                  width: compact ? double.infinity : null,
                  child: FilledButton.icon(
                    onPressed: onConnect,
                    icon: const Icon(Icons.link_rounded, size: 18),
                    label: const Text('Hubungkan WhatsApp'),
                  ),
                )
              else
                Wrap(
                  spacing: 10,
                  runSpacing: 8,
                  children: [
                    OutlinedButton.icon(
                      onPressed: onManage,
                      icon: const Icon(Icons.settings_outlined, size: 18),
                      label: const Text('Kelola'),
                    ),
                    TextButton.icon(
                      onPressed: onDisconnect,
                      icon: const Icon(Icons.link_off_rounded, size: 18),
                      label: const Text('Putuskan koneksi'),
                    ),
                  ],
                ),
            ],
          );
        },
      ),
    );
  }
}

class _StatusPill extends StatelessWidget {
  const _StatusPill({required this.connected});

  final bool connected;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    final Color background = connected
        ? colors.primaryContainer
        : colors.surfaceContainerHighest;
    final Color foreground = connected
        ? colors.onPrimaryContainer
        : colors.onSurfaceVariant;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(30),
      ),
      child: Text(
        connected ? 'Connected' : 'Status demo',
        style: Theme.of(context).textTheme.labelSmall
            ?.copyWith(color: foreground, fontWeight: FontWeight.w700),
      ),
    );
  }
}
