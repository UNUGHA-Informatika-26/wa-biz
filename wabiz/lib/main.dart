import 'package:flutter/material.dart';

import 'core/theme/app_theme.dart';
import 'features/splash/presentation/pages/splash_page.dart';

void main() {
  runApp(const WABizApp());
}

class WABizApp extends StatelessWidget {
  const WABizApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'WABiz',
      theme: AppTheme.light,
      darkTheme: AppTheme.dark,
      themeMode: ThemeMode.system,
      home: const SplashPage(),
    );
  }
}
