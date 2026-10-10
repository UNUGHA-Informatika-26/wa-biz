import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

/// Shared Dio client for requests to the WABiz API.
///
/// Authentication interceptors can be registered on [dio] when token handling
/// is added; this client intentionally does not read or store credentials.
class ApiClient {
  ApiClient._()
    : dio =
          Dio(
              BaseOptions(
                baseUrl: baseUrl,
                connectTimeout: const Duration(seconds: 15),
                sendTimeout: const Duration(seconds: 15),
                receiveTimeout: const Duration(seconds: 15),
                headers: const {
                  'Accept': 'application/json',
                  'Content-Type': 'application/json',
                },
              ),
            )
            ..interceptors.add(
              InterceptorsWrapper(
                onRequest: (options, handler) {
                  // Dio resolves a leading slash against the host root and drops
                  // the /api prefix. Normalize service paths before resolution.
                  options.path = options.path.replaceFirst(RegExp(r'^/+'), '');
                  handler.next(options);
                },
              ),
            );

  static const String _configuredBaseUrl = String.fromEnvironment(
    'WABIZ_API_BASE_URL',
    defaultValue: 'http://127.0.0.1:8000/api/',
  );

  static final String baseUrl = _resolveBaseUrl();

  static String _resolveBaseUrl() {
    final Uri? uri = Uri.tryParse(_configuredBaseUrl);
    if (uri == null ||
        !uri.hasAuthority ||
        uri.host.isEmpty ||
        !const {'http', 'https'}.contains(uri.scheme) ||
        uri.userInfo.isNotEmpty ||
        uri.query.isNotEmpty ||
        uri.fragment.isNotEmpty ||
        !uri.path.endsWith('/api/')) {
      throw StateError(
        'WABIZ_API_BASE_URL must be an absolute HTTP(S) API URL ending in /api/.',
      );
    }

    final String host = uri.host.toLowerCase();
    final String normalizedHost = host.endsWith('.')
        ? host.substring(0, host.length - 1)
        : host;
    final List<String> hostLabels = normalizedHost.split('.');
    final bool ipLiteral =
        normalizedHost.contains(':') ||
        hostLabels.every(
          (label) =>
              int.tryParse(label) != null ||
              RegExp(r'^0x[0-9a-f]+$').hasMatch(label),
        );
    final bool loopbackHost =
        normalizedHost == 'localhost' ||
        normalizedHost.endsWith('.localhost') ||
        normalizedHost == '0.0.0.0' ||
        ipLiteral;
    if (!kDebugMode && (uri.scheme != 'https' || loopbackHost)) {
      throw StateError(
        'Non-debug builds require an HTTPS WABiz API URL using a DNS hostname.',
      );
    }

    return uri.toString();
  }

  static final ApiClient instance = ApiClient._();

  final Dio dio;
}
