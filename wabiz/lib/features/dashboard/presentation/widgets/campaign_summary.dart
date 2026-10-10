import 'package:flutter/material.dart';

class CampaignSummaryItem {
  const CampaignSummaryItem({
    required this.name,
    required this.status,
    required this.recipients,
    this.delivered,
  });

  final String name;
  final String status;
  final int recipients;
  final int? delivered;
}

class CampaignSummary extends StatelessWidget {
  const CampaignSummary({required this.campaigns, super.key});

  final List<CampaignSummaryItem> campaigns;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return Container(
      decoration: BoxDecoration(
        color: colors.surface,
        border: Border.all(color: colors.outlineVariant),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        children: [
          for (int index = 0; index < campaigns.length; index++) ...[
            if (index > 0)
              Divider(
                height: 1,
                indent: 16,
                endIndent: 16,
                color: colors.outlineVariant,
              ),
            _CampaignRow(campaign: campaigns[index]),
          ],
        ],
      ),
    );
  }
}

class _CampaignRow extends StatelessWidget {
  const _CampaignRow({required this.campaign});

  final CampaignSummaryItem campaign;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    final TextTheme textTheme = Theme.of(context).textTheme;
    final double progress =
        campaign.delivered == null || campaign.recipients == 0
        ? 0
        : (campaign.delivered! / campaign.recipients)
              .clamp(0.0, 1.0)
              .toDouble();
    final Color badgeBackground = switch (campaign.status) {
      'Selesai' => colors.primaryContainer,
      'Berjalan' => colors.secondaryContainer,
      _ => colors.surfaceContainerHighest,
    };
    final Color badgeForeground = switch (campaign.status) {
      'Selesai' => colors.onPrimaryContainer,
      'Berjalan' => colors.onSecondaryContainer,
      _ => colors.onSurfaceVariant,
    };

    return Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Wrap(
            alignment: WrapAlignment.spaceBetween,
            crossAxisAlignment: WrapCrossAlignment.center,
            runSpacing: 7,
            spacing: 8,
            children: [
              ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 230),
                child: Text(
                  campaign.name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: textTheme.titleSmall?.copyWith(
                    color: colors.onSurface,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
                decoration: BoxDecoration(
                  color: badgeBackground,
                  borderRadius: BorderRadius.circular(30),
                ),
                child: Text(
                  campaign.status,
                  style: textTheme.labelSmall?.copyWith(
                    color: badgeForeground,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            campaign.delivered == null
                ? '${campaign.recipients} penerima'
                : '${campaign.recipients} penerima  ·  ${campaign.delivered} terkirim',
            style: textTheme.bodySmall?.copyWith(
              color: colors.onSurfaceVariant,
            ),
          ),
          if (campaign.delivered != null) ...[
            const SizedBox(height: 11),
            ClipRRect(
              borderRadius: BorderRadius.circular(4),
              child: LinearProgressIndicator(
                value: progress,
                minHeight: 4,
                backgroundColor: colors.surfaceContainerHighest,
                color: colors.primary,
              ),
            ),
          ],
        ],
      ),
    );
  }
}
