import 'package:dio/dio.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/auth_storage.dart';

enum AuthFailureKind {
  unauthorized,
  validation,
  server,
  network,
  http,
  unexpected,
}

/// An authentication failure with the HTTP status and response payload, when
/// available, so callers can handle cases such as 401 and 422 separately.
class AuthServiceException implements Exception {
  const AuthServiceException({
    required this.kind,
    required this.message,
    this.statusCode,
    this.responseData,
  });

  final AuthFailureKind kind;
  final String message;
  final int? statusCode;
  final dynamic responseData;

  @override
  String toString() => 'AuthServiceException($statusCode, $kind): $message';
}

/// Calls the Laravel Sanctum authentication endpoints.
class AuthService {
  AuthService._();

  static final AuthService instance = AuthService._();

  final Dio _dio = ApiClient.instance.dio;
  final AuthStorage _authStorage = AuthStorage.instance;

  /// Authenticates the user, securely stores the Sanctum token, and returns
  /// the response's `data` object (including user and organization details).
  Future<Map<String, dynamic>> login(String email, String password) async {
    try {
      final response = await _dio.post<dynamic>(
        '/auth/login',
        data: {'email': email, 'password': password},
      );

      final responseBody = _asMap(response.data);
      final responseData = _asMap(responseBody?['data']);
      final token = responseData?['token'];

      if (responseData == null || token is! String || token.isEmpty) {
        throw const AuthServiceException(
          kind: AuthFailureKind.unexpected,
          message: 'Login response did not contain a valid token.',
        );
      }

      await _authStorage.saveToken(token);
      return responseData;
    } on DioException catch (error) {
      throw _mapDioException(error);
    }
  }

  /// Logs out remotely when a token exists, then always removes it locally.
  Future<void> logout() async {
    final token = await _authStorage.getToken();

    try {
      if (token != null && token.isNotEmpty) {
        await _dio.post<dynamic>(
          '/auth/logout',
          options: _authorizationOptions(token),
        );
      }
    } on DioException catch (error) {
      throw _mapDioException(error);
    } finally {
      await _authStorage.clearToken();
    }
  }

  /// Returns the current-user response data, or null when no token is stored.
  Future<Map<String, dynamic>?> getCurrentUser() async {
    final token = await _authStorage.getToken();
    if (token == null || token.isEmpty) return null;

    try {
      final response = await _dio.get<dynamic>(
        '/auth/me',
        options: _authorizationOptions(token),
      );

      final responseBody = _asMap(response.data);
      return _asMap(responseBody?['data']) ?? responseBody;
    } on DioException catch (error) {
      throw _mapDioException(error);
    }
  }

  Options _authorizationOptions(String token) {
    return Options(headers: _authorizationHeaders(token));
  }

  Map<String, String> _authorizationHeaders(String token) {
    return {'Authorization': 'Bearer $token'};
  }

  AuthServiceException _mapDioException(DioException error) {
    final statusCode = error.response?.statusCode;
    final kind = switch (statusCode) {
      401 => AuthFailureKind.unauthorized,
      422 => AuthFailureKind.validation,
      500 => AuthFailureKind.server,
      null => AuthFailureKind.network,
      _ => AuthFailureKind.http,
    };

    final responseData = error.response?.data;
    final responseMessage = _asMap(responseData)?['message'];
    final message = responseMessage is String && responseMessage.isNotEmpty
        ? responseMessage
        : error.message ?? 'Authentication request failed.';

    return AuthServiceException(
      kind: kind,
      message: message,
      statusCode: statusCode,
      responseData: responseData,
    );
  }

  Map<String, dynamic>? _asMap(dynamic value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) return Map<String, dynamic>.from(value);
    return null;
  }
}
