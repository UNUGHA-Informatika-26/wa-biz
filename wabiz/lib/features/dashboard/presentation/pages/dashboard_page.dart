import 'package:flutter/material.dart';

import '../../../campaigns/presentation/pages/campaigns_page.dart';
import '../../../ai_assistant/presentation/pages/ai_assistant_page.dart';
import '../../../clients/presentation/pages/clients_page.dart';
import '../../../inbox/presentation/pages/inbox_page.dart';
import '../../../cs_management/presentation/pages/cs_management_page.dart';
import '../../../whatsapp_connection/presentation/pages/whatsapp_connection_page.dart';
import '../widgets/campaign_summary.dart';
import '../widgets/dashboard_header.dart';
import '../widgets/overview_card.dart';
import '../widgets/quick_action_card.dart';
import '../widgets/recent_activity.dart';
import '../widgets/whatsapp_connection_card.dart';

class DashboardPage extends StatefulWidget {
  const DashboardPage({super.key});

  @override
  State<DashboardPage> createState() => _DashboardPageState();
}

class _DashboardPageState extends State<DashboardPage> {
  static const List<_DashboardTab> _tabs = [
    _DashboardTab(
      'Beranda',
      'Beranda',
      Icons.home_outlined,
      Icons.home_rounded,
      'Ringkasan workspace WABiz.',
    ),
    _DashboardTab(
      'Inbox',
      'Inbox',
      Icons.inbox_outlined,
      Icons.inbox_rounded,
      'Lihat percakapan WhatsApp dan riwayat pesan yang tersimpan.',
    ),
    _DashboardTab(
      'WhatsApp Connection',
      'WhatsApp',
      Icons.chat_bubble_outline_rounded,
      Icons.chat_bubble_rounded,
      'Kelola koneksi nomor WhatsApp Business WABiz.',
    ),
    _DashboardTab(
      'Client',
      'Client',
      Icons.people_outline_rounded,
      Icons.people_rounded,
      'Kelola profil, grup, dan catatan client untuk campaign serta konteks AI.',
    ),
    _DashboardTab(
      'Campaign',
      'Campaign',
      Icons.campaign_outlined,
      Icons.campaign_rounded,
      'Buat, jadwalkan, dan pantau pesan campaign untuk client Anda.',
    ),
    _DashboardTab(
      'AI Assistant',
      'AI',
      Icons.auto_awesome_outlined,
      Icons.auto_awesome_rounded,
      'Atur perilaku AI dan knowledge lembaga sebelum integrasi backend.',
    ),
    _DashboardTab(
      'Pengaturan',
      'Pengaturan',
      Icons.settings_outlined,
      Icons.settings_rounded,
      'Kelola preferensi workspace pada tahap berikutnya.',
    ),
  ];

  static const List<CampaignSummaryItem> _campaigns = [
    CampaignSummaryItem(
      name: 'Promo Akhir Bulan',
      status: 'Selesai',
      recipients: 850,
      delivered: 812,
    ),
    CampaignSummaryItem(
      name: 'Informasi Produk Baru',
      status: 'Berjalan',
      recipients: 420,
      delivered: 386,
    ),
    CampaignSummaryItem(
      name: 'Reminder Pelanggan',
      status: 'Draft',
      recipients: 180,
    ),
  ];

  static const List<RecentActivityItem> _activities = [
    RecentActivityItem(
      title: 'Campaign “Promo Akhir Bulan” selesai dikirim',
      time: '5 menit lalu',
      icon: Icons.check_rounded,
    ),
    RecentActivityItem(
      title: '120 client baru ditambahkan',
      time: '1 jam lalu',
      icon: Icons.person_add_alt_1_rounded,
    ),
    RecentActivityItem(
      title: 'Campaign “Informasi Produk Baru” dimulai',
      time: '3 jam lalu',
      icon: Icons.send_outlined,
    ),
    RecentActivityItem(
      title: 'Data client diperbarui',
      time: 'Kemarin',
      icon: Icons.file_download_done_outlined,
    ),
  ];

  int _selectedIndex = 0;

  void _selectTab(int index) => setState(() => _selectedIndex = index);

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;

    return Scaffold(
      backgroundColor: colors.surface,
      body: SafeArea(
        child: LayoutBuilder(
          builder: (context, constraints) {
            if (_selectedIndex == 0) {
              return _buildHome(context, constraints.maxWidth);
            }
            if (_selectedIndex == 1) {
              return const InboxPage();
            }
            if (_selectedIndex == 2) {
              return const WhatsAppConnectionPage();
            }
            if (_selectedIndex == 3) {
              return const ClientsPage();
            }
            if (_selectedIndex == 4) {
              return const CampaignsPage();
            }
            if (_selectedIndex == 5) {
              return const AiAssistantPage();
            }
            if (_selectedIndex == 6) {
              return const CsManagementPage();
            }
            return _DashboardPlaceholder(
              tab: _tabs[_selectedIndex],
              onBackToHome: () => _selectTab(0),
            );
          },
        ),
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _selectedIndex,
        onDestinationSelected: _selectTab,
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
        destinations: [
          for (final _DashboardTab tab in _tabs)
            NavigationDestination(
              icon: Icon(tab.icon),
              selectedIcon: Icon(tab.selectedIcon),
              tooltip: tab.title,
              label: MediaQuery.sizeOf(context).width < 600
                  ? tab.mobileLabel
                  : tab.title,
            ),
        ],
      ),
    );
  }

  Widget _buildHome(BuildContext context, double screenWidth) {
    final ColorScheme colors = Theme.of(context).colorScheme;

    return LayoutBuilder(
      builder: (context, constraints) {
        final double horizontalPadding = screenWidth < 420 ? 16 : 28;
        return SingleChildScrollView(
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
                  DashboardHeader(onProfileTap: () => _selectTab(6)),
                  const SizedBox(height: 28),
                  WhatsAppConnectionCard(onTap: () => _selectTab(1)),
                  const SizedBox(height: 28),
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          'Ringkasan',
                          style: Theme.of(context).textTheme.titleLarge
                              ?.copyWith(
                                color: colors.onSurface,
                                fontWeight: FontWeight.w700,
                              ),
                        ),
                      ),
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 10,
                          vertical: 6,
                        ),
                        decoration: BoxDecoration(
                          color: colors.surfaceContainerHighest,
                          borderRadius: BorderRadius.circular(30),
                        ),
                        child: Text(
                          'DATA CONTOH',
                          style: Theme.of(context).textTheme.labelSmall
                              ?.copyWith(
                                color: colors.onSurfaceVariant,
                                fontWeight: FontWeight.w700,
                                letterSpacing: .6,
                              ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 14),
                  LayoutBuilder(
                    builder: (context, gridConstraints) {
                      final bool wide = gridConstraints.maxWidth >= 900;
                      return GridView.count(
                        crossAxisCount: wide ? 4 : 2,
                        mainAxisExtent: wide ? 138 : 142,
                        crossAxisSpacing: 12,
                        mainAxisSpacing: 12,
                        shrinkWrap: true,
                        physics: const NeverScrollableScrollPhysics(),
                        children: const [
                          OverviewCard(
                            title: 'Total Client',
                            value: '1.248',
                            caption: 'Data workspace demo',
                            icon: Icons.people_outline_rounded,
                          ),
                          OverviewCard(
                            title: 'Campaign Aktif',
                            value: '12',
                            caption: 'Data simulasi',
                            icon: Icons.campaign_outlined,
                          ),
                          OverviewCard(
                            title: 'Pesan Terkirim',
                            value: '8.420',
                            caption: 'Data simulasi',
                            icon: Icons.mark_chat_read_outlined,
                          ),
                          OverviewCard(
                            title: 'AI Assistant',
                            value: 'Aktif',
                            caption: 'Status simulasi',
                            icon: Icons.auto_awesome_outlined,
                          ),
                        ],
                      );
                    },
                  ),
                  const SizedBox(height: 28),
                  _SectionHeading(title: 'Aksi Cepat'),
                  const SizedBox(height: 14),
                  LayoutBuilder(
                    builder: (context, actionConstraints) {
                      final bool wide = actionConstraints.maxWidth >= 850;
                      return GridView.count(
                        crossAxisCount: wide ? 4 : 2,
                        mainAxisExtent: 70,
                        crossAxisSpacing: 12,
                        mainAxisSpacing: 12,
                        shrinkWrap: true,
                        physics: const NeverScrollableScrollPhysics(),
                        children: [
                          QuickActionCard(
                            title: 'Buat Campaign',
                            icon: Icons.add_comment_outlined,
                            onTap: () => _selectTab(4),
                          ),
                          QuickActionCard(
                            title: 'Kelola Client',
                            icon: Icons.people_outline_rounded,
                            onTap: () => _selectTab(3),
                          ),
                          QuickActionCard(
                            title: 'AI Assistant',
                            icon: Icons.auto_awesome_outlined,
                            onTap: () => _selectTab(5),
                          ),
                          QuickActionCard(
                            title: 'Pengaturan',
                            icon: Icons.settings_outlined,
                            onTap: () => _selectTab(6),
                          ),
                        ],
                      );
                    },
                  ),
                  const SizedBox(height: 28),
                  LayoutBuilder(
                    builder: (context, sectionConstraints) {
                      final bool sideBySide =
                          sectionConstraints.maxWidth >= 900;
                      final Widget campaigns = Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          _SectionHeading(
                            title: 'Campaign Terbaru',
                            actionLabel: 'Lihat semua',
                            onAction: () => _selectTab(4),
                          ),
                          const SizedBox(height: 14),
                          const CampaignSummary(campaigns: _campaigns),
                        ],
                      );
                      final Widget activities = Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          _SectionHeading(title: 'Aktivitas Terbaru'),
                          const SizedBox(height: 14),
                          const RecentActivity(activities: _activities),
                        ],
                      );

                      if (sideBySide) {
                        return Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Expanded(child: campaigns),
                            const SizedBox(width: 20),
                            Expanded(child: activities),
                          ],
                        );
                      }
                      return Column(
                        children: [
                          campaigns,
                          const SizedBox(height: 28),
                          activities,
                        ],
                      );
                    },
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}

class _SectionHeading extends StatelessWidget {
  const _SectionHeading({required this.title, this.actionLabel, this.onAction});

  final String title;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return Row(
      children: [
        Expanded(
          child: Text(
            title,
            style: Theme.of(context).textTheme.titleLarge?.copyWith(
              color: colors.onSurface,
              fontWeight: FontWeight.w700,
            ),
          ),
        ),
        if (actionLabel != null)
          TextButton(
            onPressed: onAction,
            style: TextButton.styleFrom(
              foregroundColor: colors.primary,
              padding: const EdgeInsets.symmetric(horizontal: 4),
            ),
            child: Text(actionLabel!),
          ),
      ],
    );
  }
}

class _DashboardTab {
  const _DashboardTab(
    this.title,
    this.mobileLabel,
    this.icon,
    this.selectedIcon,
    this.description,
  );

  final String title;
  final String mobileLabel;
  final IconData icon;
  final IconData selectedIcon;
  final String description;
}

class _DashboardPlaceholder extends StatelessWidget {
  const _DashboardPlaceholder({required this.tab, required this.onBackToHome});

  final _DashboardTab tab;
  final VoidCallback onBackToHome;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    final TextTheme textTheme = Theme.of(context).textTheme;

    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 440),
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 64,
                height: 64,
                decoration: BoxDecoration(
                  color: colors.primaryContainer,
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Icon(
                  tab.selectedIcon,
                  size: 30,
                  color: colors.onPrimaryContainer,
                ),
              ),
              const SizedBox(height: 20),
              Text(
                tab.title,
                textAlign: TextAlign.center,
                style: textTheme.headlineSmall?.copyWith(
                  color: colors.onSurface,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                tab.description,
                textAlign: TextAlign.center,
                style: textTheme.bodyLarge?.copyWith(
                  color: colors.onSurfaceVariant,
                  height: 1.5,
                ),
              ),
              const SizedBox(height: 22),
              OutlinedButton.icon(
                onPressed: onBackToHome,
                icon: const Icon(Icons.home_outlined),
                label: const Text('Kembali ke Beranda'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
