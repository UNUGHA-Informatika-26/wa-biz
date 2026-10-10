import 'package:flutter/material.dart';

class AuthHeader extends StatelessWidget {
  const AuthHeader({required this.title, required this.description, super.key});

  final String title;
  final String description;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    final TextTheme textTheme = Theme.of(context).textTheme;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            CustomPaint(
              size: const Size(32, 32),
              painter: _AuthBrandMarkPainter(color: colors.primary),
            ),
            const SizedBox(width: 10),
            Text(
              'WABiz',
              style: textTheme.titleLarge?.copyWith(
                color: colors.onSurface,
                fontWeight: FontWeight.w700,
                letterSpacing: -0.5,
              ),
            ),
          ],
        ),
        const SizedBox(height: 32),
        Text(
          title,
          style: textTheme.headlineMedium?.copyWith(
            color: colors.onSurface,
            fontWeight: FontWeight.w700,
            height: 1.12,
            letterSpacing: -0.7,
          ),
        ),
        const SizedBox(height: 10),
        Text(
          description,
          style: textTheme.bodyLarge?.copyWith(
            color: colors.onSurfaceVariant,
            height: 1.5,
          ),
        ),
      ],
    );
  }
}

class _AuthBrandMarkPainter extends CustomPainter {
  const _AuthBrandMarkPainter({required this.color});

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final Offset hub = Offset(size.width / 2, size.height / 2);
    final Paint routePaint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.8
      ..strokeCap = StrokeCap.round;
    final Paint nodePaint = Paint()..color = color;
    final List<Offset> nodes = [
      Offset(size.width * .18, size.height * .2),
      Offset(size.width * .82, size.height * .2),
      Offset(size.width * .18, size.height * .8),
      Offset(size.width * .82, size.height * .8),
    ];

    for (final Offset node in nodes) {
      canvas.drawLine(node, hub, routePaint);
      canvas.drawCircle(node, 2.3, nodePaint);
    }
    canvas.drawCircle(hub, 3.2, nodePaint);
  }

  @override
  bool shouldRepaint(covariant _AuthBrandMarkPainter oldDelegate) =>
      oldDelegate.color != color;
}
