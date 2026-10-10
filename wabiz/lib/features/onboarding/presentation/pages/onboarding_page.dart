import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../../auth/presentation/pages/login_page.dart';

class OnboardingPage extends StatefulWidget {
  const OnboardingPage({super.key});

  @override
  State<OnboardingPage> createState() => _OnboardingPageState();
}

class _OnboardingPageState extends State<OnboardingPage> {
  static const List<_OnboardingContent> _pages = [
    _OnboardingContent(
      title: 'Kelola Kontak Lebih Teratur',
      description: 'Simpan dan kelompokkan kontak pelanggan dalam satu tempat agar komunikasi bisnis lebih tertata.',
      eyebrow: 'KONTAK DALAM SATU TEMPAT',
    ),
    _OnboardingContent(
      title: 'Bangun Campaign dengan Mudah',
      description: 'Siapkan pesan, pilih penerima, dan kelola campaign WhatsApp dengan alur yang lebih terstruktur.',
      eyebrow: 'CAMPAIGN LEBIH TERARAH',
    ),
    _OnboardingContent(
      title: 'Pantau Aktivitas Bisnis',
      description: 'Lihat aktivitas campaign dan status pengiriman untuk membantu memahami performa komunikasi bisnis Anda.',
      eyebrow: 'RINGKASAN PERFORMA',
    ),
  ];

  final PageController _pageController = PageController();
  int _currentPage = 0;

  @override
  void dispose() {
    _pageController.dispose();
    super.dispose();
  }

  void _goToPage(int page) {
    _pageController.animateToPage(
      page,
      duration: const Duration(milliseconds: 420),
      curve: Curves.easeInOutCubic,
    );
  }

  void _continue() {
    if (_currentPage < _pages.length - 1) {
      _goToPage(_currentPage + 1);
      return;
    }

    Navigator.of(context).pushReplacement(
      MaterialPageRoute<void>(builder: (_) => const LoginPage()),
    );
  }

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;

    return Scaffold(
      backgroundColor: colors.surface,
      body: SafeArea(
        child: LayoutBuilder(
          builder: (context, constraints) {
            final bool compact =
                constraints.maxHeight < 680 || constraints.maxWidth < 420;
            final double sidePadding = constraints.maxWidth < 380 ? 22 : 30;

            return Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 560),
                child: Padding(
                  padding: EdgeInsets.symmetric(horizontal: sidePadding),
                  child: Column(
                    children: [
                      SizedBox(
                        height: compact ? 48 : 56,
                        child: Row(
                          children: [
                            _BrandWordmark(colors: colors),
                            const Spacer(),
                            TextButton(
                              onPressed: () => _goToPage(_pages.length - 1),
                              style: TextButton.styleFrom(
                                foregroundColor: colors.onSurfaceVariant,
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 12,
                                ),
                              ),
                              child: const Text('Lewati'),
                            ),
                          ],
                        ),
                      ),
                      Expanded(
                        child: PageView.builder(
                          controller: _pageController,
                          itemCount: _pages.length,
                          onPageChanged: (index) {
                            setState(() => _currentPage = index);
                          },
                          itemBuilder: (context, index) {
                            return _OnboardingSlide(
                              content: _pages[index],
                              index: index,
                              compact: compact,
                            );
                          },
                        ),
                      ),
                      Padding(
                        padding: EdgeInsets.only(
                          top: compact ? 2 : 6,
                          bottom: compact ? 10 : 14,
                        ),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Row(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: List<Widget>.generate(
                                _pages.length,
                                (index) => _PageIndicator(
                                  selected: index == _currentPage,
                                  onTap: () => _goToPage(index),
                                ),
                              ),
                            ),
                            const SizedBox(height: 14),
                            SizedBox(
                              width: double.infinity,
                              child: FilledButton(
                                onPressed: _continue,
                                child: Padding(
                                  padding: const EdgeInsets.symmetric(
                                    vertical: 3,
                                  ),
                                  child: Row(
                                    mainAxisAlignment: MainAxisAlignment.center,
                                    children: [
                                      Text(
                                        _currentPage == _pages.length - 1
                                            ? 'Mulai'
                                            : 'Lanjut',
                                      ),
                                      const SizedBox(width: 10),
                                      const Icon(
                                        Icons.arrow_forward_rounded,
                                        size: 18,
                                      ),
                                    ],
                                  ),
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            );
          },
        ),
      ),
    );
  }
}

class _BrandWordmark extends StatelessWidget {
  const _BrandWordmark({required this.colors});

  final ColorScheme colors;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        CustomPaint(
          size: const Size(28, 28),
          painter: _MiniBrandPainter(color: colors.primary),
        ),
        const SizedBox(width: 9),
        Text(
          'WABiz',
          style: Theme.of(context).textTheme.titleMedium?.copyWith(
            color: colors.onSurface,
            fontWeight: FontWeight.w700,
            letterSpacing: -0.3,
          ),
        ),
      ],
    );
  }
}

class _OnboardingSlide extends StatelessWidget {
  const _OnboardingSlide({
    required this.content,
    required this.index,
    required this.compact,
  });

  final _OnboardingContent content;
  final int index;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    final TextTheme textTheme = Theme.of(context).textTheme;

    return LayoutBuilder(
      builder: (context, constraints) {
        final bool shortViewport = constraints.maxHeight < 340;
        final bool narrowViewport = constraints.maxWidth < 360;
        final double visualHeight = math.min(
          compact ? 250.0 : 340.0,
          constraints.maxHeight *
              (shortViewport
                  ? 0.34
                  : constraints.maxHeight < 430 || narrowViewport
                  ? 0.42
                  : compact
                  ? 0.50
                  : 0.56),
        );

        return Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              SizedBox(
                height: visualHeight,
                width: double.infinity,
                child: TweenAnimationBuilder<double>(
                  key: ValueKey<int>(index),
                  tween: Tween(begin: 0.94, end: 1),
                  duration: const Duration(milliseconds: 560),
                  curve: Curves.easeOutCubic,
                  builder: (context, scale, child) => Opacity(
                    opacity: ((scale - 0.94) / 0.06).clamp(0.0, 1.0).toDouble(),
                    child: Transform.scale(scale: scale, child: child),
                  ),
                  child: CustomPaint(
                    painter: _OnboardingIllustrationPainter(
                      index: index,
                      colors: colors,
                    ),
                  ),
                ),
              ),
              SizedBox(
                height: shortViewport
                    ? 4
                    : compact
                    ? 8
                    : 14,
              ),
              Text(
                content.eyebrow,
                textAlign: TextAlign.center,
                style: textTheme.labelSmall?.copyWith(
                  color: colors.primary,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 1.4,
                ),
              ),
              SizedBox(height: shortViewport ? 4 : 8),
              Text(
                content.title,
                textAlign: TextAlign.center,
                style:
                    (shortViewport
                            ? textTheme.titleLarge
                            : compact
                            ? textTheme.headlineSmall
                            : textTheme.headlineMedium)
                        ?.copyWith(
                          color: colors.onSurface,
                          fontWeight: FontWeight.w700,
                          height: 1.12,
                          letterSpacing: -0.7,
                        ),
              ),
              SizedBox(height: shortViewport ? 5 : 9),
              ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 410),
                child: Text(
                  content.description,
                  textAlign: TextAlign.center,
                  style:
                      (shortViewport
                              ? textTheme.bodySmall
                              : compact
                              ? textTheme.bodyMedium
                              : textTheme.bodyLarge)
                          ?.copyWith(
                            color: colors.onSurfaceVariant,
                            height: shortViewport ? 1.4 : 1.55,
                          ),
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}

class _PageIndicator extends StatelessWidget {
  const _PageIndicator({required this.selected, required this.onTap});

  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return Semantics(
      button: true,
      label: selected ? 'Halaman aktif' : 'Buka halaman onboarding',
      child: InkWell(
        onTap: onTap,
        customBorder: const StadiumBorder(),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 8),
          child: AnimatedContainer(
            duration: const Duration(milliseconds: 260),
            curve: Curves.easeOutCubic,
            width: selected ? 26 : 7,
            height: 7,
            decoration: BoxDecoration(
              color: selected ? colors.primary : colors.outlineVariant,
              borderRadius: BorderRadius.circular(8),
            ),
          ),
        ),
      ),
    );
  }
}

class _OnboardingContent {
  const _OnboardingContent({
    required this.title,
    required this.description,
    required this.eyebrow,
  });

  final String title;
  final String description;
  final String eyebrow;
}

class _MiniBrandPainter extends CustomPainter {
  const _MiniBrandPainter({required this.color});

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final Paint paint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.8
      ..strokeCap = StrokeCap.round;
    final Offset hub = Offset(size.width / 2, size.height / 2);
    for (final Offset node in [
      Offset(size.width * .18, size.height * .2),
      Offset(size.width * .82, size.height * .2),
      Offset(size.width * .18, size.height * .8),
      Offset(size.width * .82, size.height * .8),
    ]) {
      canvas.drawLine(node, hub, paint);
      canvas.drawCircle(node, 2.1, Paint()..color = color);
    }
    canvas.drawCircle(hub, 3, Paint()..color = color);
  }

  @override
  bool shouldRepaint(covariant _MiniBrandPainter oldDelegate) =>
      oldDelegate.color != color;
}

class _OnboardingIllustrationPainter extends CustomPainter {
  const _OnboardingIllustrationPainter({
    required this.index,
    required this.colors,
  });

  final int index;
  final ColorScheme colors;

  @override
  void paint(Canvas canvas, Size size) {
    final double scale = math.min(size.width / 340, size.height / 280);
    canvas.save();
    canvas.translate(
      (size.width - 340 * scale) / 2,
      (size.height - 280 * scale) / 2,
    );
    canvas.scale(scale);

    final Paint aura = Paint()..color = colors.primary.withValues(alpha: .055);
    canvas.drawCircle(const Offset(170, 140), 124, aura);
    canvas.drawCircle(
      const Offset(170, 140),
      99,
      Paint()
        ..color = colors.primary.withValues(alpha: .035)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1,
    );

    switch (index) {
      case 0:
        _paintContacts(canvas);
        break;
      case 1:
        _paintCampaign(canvas);
        break;
      default:
        _paintAnalytics(canvas);
    }
    canvas.restore();
  }

  void _paintContacts(Canvas canvas) {
    _card(canvas, const Rect.fromLTWH(62, 24, 216, 232), fill: colors.surface);
    _labelLine(canvas, const Offset(82, 47), 74, colors.onSurface, 8);
    _labelLine(canvas, const Offset(82, 64), 112, colors.outlineVariant, 5);
    _pill(
      canvas,
      const Rect.fromLTWH(82, 86, 62, 20),
      colors.secondaryContainer,
    );
    _labelLine(
      canvas,
      const Offset(93, 93),
      39,
      colors.onSecondaryContainer,
      5,
    );
    _labelLine(canvas, const Offset(157, 93), 34, colors.outlineVariant, 5);
    for (int row = 0; row < 3; row++) {
      final double top = 121 + row * 39;
      final Color tint = row.isEven
          ? colors.primaryContainer
          : colors.tertiaryContainer;
      canvas.drawCircle(Offset(94, top + 10), 12, Paint()..color = tint);
      canvas.drawCircle(
        Offset(94, top + 7),
        4,
        Paint()..color = row.isEven ? colors.primary : colors.tertiary,
      );
      canvas.drawOval(
        Rect.fromCenter(center: Offset(94, top + 15), width: 12, height: 7),
        Paint()..color = row.isEven ? colors.primary : colors.tertiary,
      );
      _labelLine(
        canvas,
        Offset(116, top + 3),
        78 - row * 8,
        colors.onSurface,
        6,
      );
      _labelLine(canvas, Offset(116, top + 15), 48, colors.outlineVariant, 5);
      _pill(
        canvas,
        Rect.fromLTWH(220, top + 4, 38, 14),
        row == 0 ? colors.tertiaryContainer : colors.surfaceContainerHighest,
      );
    }
    _floatingCard(canvas, const Rect.fromLTWH(224, 42, 70, 54), colors);
    canvas.drawCircle(
      const Offset(239, 60),
      5,
      Paint()..color = colors.primary,
    );
    _labelLine(canvas, const Offset(250, 56), 29, colors.onSurface, 4);
    _labelLine(canvas, const Offset(235, 76), 42, colors.outlineVariant, 4);
  }

  void _paintCampaign(Canvas canvas) {
    _card(canvas, const Rect.fromLTWH(74, 27, 192, 118), fill: colors.surface);
    canvas.drawCircle(
      const Offset(98, 52),
      12,
      Paint()..color = colors.primaryContainer,
    );
    canvas.drawCircle(const Offset(98, 52), 4, Paint()..color = colors.primary);
    _labelLine(canvas, const Offset(120, 45), 100, colors.onSurface, 6);
    _labelLine(canvas, const Offset(120, 58), 72, colors.outlineVariant, 5);
    _pill(
      canvas,
      const Rect.fromLTWH(94, 80, 146, 43),
      colors.surfaceContainerHighest,
    );
    _labelLine(canvas, const Offset(106, 91), 105, colors.onSurfaceVariant, 5);
    _labelLine(canvas, const Offset(106, 105), 73, colors.outlineVariant, 5);

    final Paint pathPaint = Paint()
      ..color = colors.primary.withValues(alpha: .62)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2;
    final Path path = Path()
      ..moveTo(170, 150)
      ..cubicTo(170, 177, 87, 164, 87, 192)
      ..moveTo(170, 150)
      ..cubicTo(170, 177, 170, 164, 170, 192)
      ..moveTo(170, 150)
      ..cubicTo(170, 177, 253, 164, 253, 192);
    canvas.drawPath(path, pathPaint);
    canvas.drawCircle(
      const Offset(170, 152),
      4,
      Paint()..color = colors.primary,
    );
    for (int i = 0; i < 3; i++) {
      final double x = 87 + i * 83;
      _card(canvas, Rect.fromLTWH(x - 27, 192, 54, 45), fill: colors.surface);
      canvas.drawCircle(
        Offset(x, 207),
        8,
        Paint()
          ..color = i == 1
              ? colors.tertiaryContainer
              : colors.secondaryContainer,
      );
      canvas.drawCircle(
        Offset(x, 207),
        3,
        Paint()..color = i == 1 ? colors.tertiary : colors.primary,
      );
      _labelLine(canvas, Offset(x - 12, 224), 24, colors.outlineVariant, 4);
    }
    _floatingCard(canvas, const Rect.fromLTWH(231, 96, 62, 34), colors);
    _labelLine(canvas, const Offset(244, 109), 35, colors.primary, 5);
  }

  void _paintAnalytics(Canvas canvas) {
    _card(canvas, const Rect.fromLTWH(54, 37, 232, 204), fill: colors.surface);
    _labelLine(canvas, const Offset(75, 57), 82, colors.onSurface, 7);
    _labelLine(canvas, const Offset(75, 72), 52, colors.outlineVariant, 5);
    _metric(
      canvas,
      const Rect.fromLTWH(75, 91, 88, 48),
      colors.primaryContainer,
    );
    _metric(
      canvas,
      const Rect.fromLTWH(174, 91, 88, 48),
      colors.tertiaryContainer,
    );
    _labelLine(canvas, const Offset(86, 101), 34, colors.onPrimaryContainer, 4);
    _labelLine(canvas, const Offset(86, 115), 52, colors.primary, 7);
    _labelLine(
      canvas,
      const Offset(185, 101),
      34,
      colors.onTertiaryContainer,
      4,
    );
    _labelLine(canvas, const Offset(185, 115), 45, colors.tertiary, 7);
    _labelLine(canvas, const Offset(75, 158), 72, colors.onSurface, 5);
    final Rect chart = const Rect.fromLTWH(75, 171, 190, 48);
    final Paint grid = Paint()
      ..color = colors.outlineVariant.withValues(alpha: .65)
      ..strokeWidth = 1;
    for (int i = 0; i < 3; i++) {
      final double y = chart.top + i * 20;
      canvas.drawLine(Offset(chart.left, y), Offset(chart.right, y), grid);
    }
    final List<Offset> points = [
      Offset(chart.left + 4, chart.bottom - 9),
      Offset(chart.left + 43, chart.bottom - 21),
      Offset(chart.left + 78, chart.bottom - 15),
      Offset(chart.left + 116, chart.bottom - 35),
      Offset(chart.left + 151, chart.bottom - 29),
      Offset(chart.right - 3, chart.top + 4),
    ];
    final Path graph = Path()..moveTo(points.first.dx, points.first.dy);
    for (int i = 1; i < points.length; i++) {
      graph.lineTo(points[i].dx, points[i].dy);
    }
    canvas.drawPath(
      graph,
      Paint()
        ..color = colors.primary
        ..style = PaintingStyle.stroke
        ..strokeWidth = 3
        ..strokeCap = StrokeCap.round
        ..strokeJoin = StrokeJoin.round,
    );
    for (final Offset point in [points[2], points[4], points.last]) {
      canvas.drawCircle(point, 4, Paint()..color = colors.surface);
      canvas.drawCircle(point, 3, Paint()..color = colors.primary);
    }
    _floatingCard(canvas, const Rect.fromLTWH(35, 173, 48, 36), colors);
    canvas.drawCircle(
      const Offset(50, 191),
      6,
      Paint()..color = colors.primary,
    );
  }

  void _metric(Canvas canvas, Rect rect, Color fill) {
    _pill(canvas, rect, fill);
  }

  void _floatingCard(Canvas canvas, Rect rect, ColorScheme scheme) {
    _card(canvas, rect, fill: scheme.surface);
  }

  void _card(Canvas canvas, Rect rect, {required Color fill}) {
    canvas.drawRRect(
      RRect.fromRectAndRadius(rect, const Radius.circular(16)),
      Paint()..color = fill,
    );
    canvas.drawRRect(
      RRect.fromRectAndRadius(rect, const Radius.circular(16)),
      Paint()
        ..color = colors.outlineVariant.withValues(alpha: .72)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1,
    );
  }

  void _pill(Canvas canvas, Rect rect, Color fill) {
    canvas.drawRRect(
      RRect.fromRectAndRadius(rect, Radius.circular(rect.height / 2)),
      Paint()..color = fill,
    );
  }

  void _labelLine(
    Canvas canvas,
    Offset start,
    double width,
    Color color,
    double height,
  ) {
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromLTWH(start.dx, start.dy, width, height),
        Radius.circular(height / 2),
      ),
      Paint()..color = color,
    );
  }

  @override
  bool shouldRepaint(covariant _OnboardingIllustrationPainter oldDelegate) =>
      oldDelegate.index != index || oldDelegate.colors != colors;
}
