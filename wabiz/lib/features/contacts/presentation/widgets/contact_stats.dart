import 'package:flutter/material.dart';

class ContactStats extends StatelessWidget {
  const ContactStats({
    required this.totalContacts,
    required this.groupCount,
    required this.recentCount,
    super.key,
  });

  final int totalContacts;
  final int groupCount;
  final int recentCount;

  @override
  Widget build(BuildContext context) {
    final List<(String, String)> stats = [
      ('Total Kontak', _formatCount(totalContacts)),
      ('Grup', '$groupCount'),
      ('Baru Ditambahkan', '$recentCount'),
    ];

    return Row(
      children: [
        for (int index = 0; index < stats.length; index++) ...[
          if (index > 0) const SizedBox(width: 10),
          Expanded(
            child: _StatCard(label: stats[index].$1, value: stats[index].$2),
          ),
        ],
      ],
    );
  }

  String _formatCount(int value) {
    final String raw = value.toString();
    return raw.replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => '.');
  }
}

class _StatCard extends StatelessWidget {
  const _StatCard({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    final TextTheme textTheme = Theme.of(context).textTheme;

    return Container(
      constraints: const BoxConstraints(minHeight: 78),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: colors.surface,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: colors.outlineVariant),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(
            value,
            style: textTheme.titleLarge?.copyWith(
              color: colors.onSurface,
              fontWeight: FontWeight.w700,
              height: 1.1,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            label,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: textTheme.labelSmall?.copyWith(
              color: colors.onSurfaceVariant,
              height: 1.2,
            ),
          ),
        ],
      ),
    );
  }
}
