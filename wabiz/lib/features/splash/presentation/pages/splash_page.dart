import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import '../../../auth/data/auth_service.dart';
import '../../../auth/presentation/pages/login_page.dart';
import '../../../dashboard/presentation/pages/dashboard_page.dart';
import '../../../onboarding/presentation/pages/onboarding_page.dart';
import '../../../../core/network/auth_storage.dart';

class SplashPage extends StatefulWidget {
  const SplashPage({super.key});

  @override
  State<SplashPage> createState() => _SplashPageState();
}

class _SplashPageState extends State<SplashPage>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;
  bool _sessionCheckStarted = false;

  @override
  void initState() {
    super.initState();
    _controller =
        AnimationController(
            vsync: this,
            duration: const Duration(milliseconds: 1800),
          )
          ..addStatusListener(_handleAnimationStatus)
          ..forward();
  }

  void _handleAnimationStatus(AnimationStatus status) {
    if (status == AnimationStatus.completed &&
        mounted &&
        !_sessionCheckStarted) {
      _sessionCheckStarted = true;
      _checkSession();
    }
  }

  Future<void> _checkSession() async {
    Widget destination = const OnboardingPage();

    try {
      final currentUser = await AuthService.instance.getCurrentUser();
      if (!mounted) return;

      destination = currentUser == null
          ? const OnboardingPage()
          : const DashboardPage();
    } on AuthServiceException catch (error) {
      if (error.statusCode == 401) {
        try {
          await AuthStorage.instance.clearToken();
        } catch (_) {
          // Continue to login even if local token cleanup itself fails.
        }
        if (!mounted) return;
        destination = const LoginPage();
      } else {
        if (!mounted) return;
        destination = const OnboardingPage();
      }
    } catch (_) {
      if (!mounted) return;
      destination = const OnboardingPage();
    }

    if (!mounted) return;
    Navigator.of(context).pushReplacement(
      MaterialPageRoute<void>(builder: (_) => destination),
    );
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  double _phase(double start, double end) {
    final double progress = ((_controller.value - start) / (end - start))
        .clamp(0.0, 1.0)
        .toDouble();
    return Curves.easeOutCubic.transform(progress);
  }

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    final TextTheme textTheme = Theme.of(context).textTheme;

    return Scaffold(
      backgroundColor: colors.surface,
      body: SafeArea(
        child: LayoutBuilder(
          builder: (context, constraints) {
            return SingleChildScrollView(
              child: ConstrainedBox(
                constraints: BoxConstraints(minHeight: constraints.maxHeight),
                child: IntrinsicHeight(
                  child: Center(
                    child: ConstrainedBox(
                      constraints: const BoxConstraints(maxWidth: 480),
                      child: Padding(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 28,
                          vertical: 24,
                        ),
                        child: AnimatedBuilder(
                          animation: _controller,
                          builder: (context, _) {
                            final double nodes = _phase(0.0, 0.38);
                            final double mark = _phase(0.12, 0.60);
                            final double wordmark = _phase(0.60, 0.78);
                            final double tagline = _phase(0.78, 0.93);
                            final double loading = _phase(0.93, 1.0);

                            return Column(
                              mainAxisSize: MainAxisSize.max,
                              children: [
                                const Spacer(),
                                Column(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    const SizedBox(height: 12),
                                    CustomPaint(
                                      size: const Size(92, 92),
                                      painter: _BrandMarkPainter(
                                        nodeProgress: nodes,
                                        routeProgress: mark,
                                        colors: colors,
                                      ),
                                    ),
                                    const SizedBox(height: 26),
                                    Opacity(
                                      opacity: wordmark,
                                      child: Transform.translate(
                                        offset: Offset(0, 8 * (1 - wordmark)),
                                        child: Text(
                                          'WABiz',
                                          textAlign: TextAlign.center,
                                          style: textTheme.displaySmall
                                              ?.copyWith(
                                                color: colors.onSurface,
                                                fontWeight: FontWeight.w700,
                                                letterSpacing: -1.2,
                                              ),
                                        ),
                                      ),
                                    ),
                                    const SizedBox(height: 8),
                                    Opacity(
                                      opacity: tagline,
                                      child: Text(
                                        'Business messaging, made simple.',
                                        textAlign: TextAlign.center,
                                        style: textTheme.bodyMedium?.copyWith(
                                          color: colors.onSurfaceVariant,
                                          letterSpacing: 0.15,
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                                const Spacer(flex: 2),
                                Opacity(
                                  opacity: loading * 0.65,
                                  child: SizedBox.square(
                                    dimension: 16,
                                    child: CircularProgressIndicator(
                                      strokeWidth: 1.8,
                                      color: colors.primary,
                                      semanticsLabel: 'Memuat WABiz',
                                    ),
                                  ),
                                ),
                                const SizedBox(height: 12),
                              ],
                            );
                          },
                        ),
                      ),
                    ),
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

class _BrandMarkPainter extends CustomPainter {
  const _BrandMarkPainter({
    required this.nodeProgress,
    required this.routeProgress,
    required this.colors,
  });

  final double nodeProgress;
  final double routeProgress;
  final ColorScheme colors;

  static const List<Offset> _starts = [
    Offset(8, 10),
    Offset(92, 10),
    Offset(8, 90),
    Offset(92, 90),
  ];

  static const List<Offset> _ends = [
    Offset(20, 24),
    Offset(80, 24),
    Offset(20, 76),
    Offset(80, 76),
  ];

  static final List<Path> _routes = [
    Path()
      ..moveTo(20, 24)
      ..cubicTo(40, 24, 35, 50, 50, 50),
    Path()
      ..moveTo(80, 24)
      ..cubicTo(60, 24, 65, 50, 50, 50),
    Path()
      ..moveTo(20, 76)
      ..cubicTo(40, 76, 35, 50, 50, 50),
    Path()
      ..moveTo(80, 76)
      ..cubicTo(60, 76, 65, 50, 50, 50),
  ];

  @override
  void paint(Canvas canvas, Size size) {
    canvas.save();
    canvas.scale(size.width / 100, size.height / 100);

    final Paint routePaint = Paint()
      ..color = colors.primary
      ..style = PaintingStyle.stroke
      ..strokeWidth = 5.5
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;

    for (final Path route in _routes) {
      final ui.PathMetric metric = route.computeMetrics().first;
      final Path visibleRoute = metric.extractPath(
        0,
        metric.length * routeProgress,
      );
      canvas.drawPath(visibleRoute, routePaint);
    }

    final Paint nodePaint = Paint()
      ..color = colors.secondary.withValues(
        alpha: Curves.easeOut.transform(nodeProgress),
      );
    for (int i = 0; i < _ends.length; i++) {
      final Offset position = Offset.lerp(_starts[i], _ends[i], nodeProgress)!;
      canvas.drawCircle(position, 5.2, nodePaint);
    }

    final double hubOpacity = Curves.easeOutCubic.transform(
      ((routeProgress - 0.45) / 0.55).clamp(0.0, 1.0).toDouble(),
    );
    final Paint hubPaint = Paint()
      ..color = colors.primaryContainer.withValues(alpha: hubOpacity);
    final Paint hubCorePaint = Paint()
      ..color = colors.primary.withValues(alpha: hubOpacity);
    canvas.drawCircle(const Offset(50, 50), 10, hubPaint);
    canvas.drawCircle(const Offset(50, 50), 4, hubCorePaint);

    canvas.restore();
  }

  @override
  bool shouldRepaint(covariant _BrandMarkPainter oldDelegate) {
    return oldDelegate.nodeProgress != nodeProgress ||
        oldDelegate.routeProgress != routeProgress ||
        oldDelegate.colors != colors;
  }
}
