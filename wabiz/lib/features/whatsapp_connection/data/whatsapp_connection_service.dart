import 'package:dio/dio.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/auth_storage.dart';
import 'models/whatsapp_connection.dart';

enum WhatsAppConnectionFailureKind {
  unauthorized,
  validation,
  server,
  network,
  http,
  unexpected,
}

class WhatsAppConnectionServiceException implements Exception {
  const WhatsAppConnectionServiceException({
    required this.kind,
    required this.message,
    this.statusCode,
  });

  final WhatsAppConnectionFailureKind kind;
  final String message;
  final int? statusCode;

  @override
  String toString() =>
      'WhatsAppConnectionServiceException($statusCode, $kind): $message';
}

/// Reads and creates WhatsApp connections through the authenticated WABiz API.
class WhatsAppConnectionService {
  WhatsAppConnectionService._();

  static final WhatsAppConnectionService instance =
      WhatsAppConnectionService._();

  final Dio _dio = ApiClient.instance.dio;
  final AuthStorage _authStorage = AuthStorage.instance;

  Future<WhatsAppConnectionListResponse> getConnections({int? perPage}) async {
    try {
      final response = await _getAuthenticated(
        '/whatsapp/connections',
        queryParameters: perPage == null ? null : {'per_page': perPage},
      );
      return WhatsAppConnectionListResponse.fromJson(_asJsonMap(response.data));
    } on WhatsAppConnectionServiceException {
      rethrow;
    } on FormatException {
      throw const WhatsAppConnectionServiceException(
        kind: WhatsAppConnectionFailureKind.unexpected,
        message: 'Respons daftar koneksi WhatsApp tidak valid.',
      );
    }
  }

  Future<WhatsAppConnection> createConnection({
    required String provider,
    required String displayName,
    required String phoneNumber,
    String? phoneNumberId,
    String? businessAccountId,
    bool isPrimary = false,
  }) async {
    try {
      final payload = <String, dynamic>{
        'provider': provider,
        'display_name': displayName,
        'phone_number': phoneNumber,
        'is_primary': isPrimary,
        if (phoneNumberId != null && phoneNumberId.isNotEmpty)
          'phone_number_id': phoneNumberId,
        if (businessAccountId != null && businessAccountId.isNotEmpty)
          'business_account_id': businessAccountId,
      };
      final response = await _postAuthenticated(
        '/whatsapp/connections',
        data: payload,
      );
      final body = _asJsonMap(response.data);
      final data = body['data'];
      if (response.statusCode != 201 ||
          body['success'] != true ||
          data is! Map) {
        throw const FormatException('Invalid create connection response.');
      }
      return WhatsAppConnection.fromJson(_asJsonMap(data));
    } on WhatsAppConnectionServiceException {
      rethrow;
    } on FormatException {
      throw const WhatsAppConnectionServiceException(
        kind: WhatsAppConnectionFailureKind.unexpected,
        message: 'Respons pembuatan koneksi tidak valid.',
      );
    }
  }

  Future<WhatsAppConnectionVerificationResult> verifyConnection(
    int connectionId,
  ) async {
    try {
      final response = await _postAuthenticated(
        '/whatsapp/connections/$connectionId/verify',
        isVerify: true,
      );
      final body = _asJsonMap(response.data);
      if (response.statusCode != 200) {
        throw const FormatException('Unexpected verification status.');
      }
      return WhatsAppConnectionVerificationResult.fromJson(body);
    } on WhatsAppConnectionServiceException {
      rethrow;
    } on FormatException {
      throw const WhatsAppConnectionServiceException(
        kind: WhatsAppConnectionFailureKind.unexpected,
        message: 'Respons verifikasi koneksi tidak valid.',
      );
    }
  }

  Future<WhatsAppConnectionStatusInfo> getConnectionStatus(int id) async {
    try {
      final response = await _getAuthenticated(
        '/whatsapp/connections/$id/status',
      );
      return WhatsAppConnectionStatusInfo.fromJson(_asJsonMap(response.data));
    } on WhatsAppConnectionServiceException {
      rethrow;
    } on FormatException {
      throw const WhatsAppConnectionServiceException(
        kind: WhatsAppConnectionFailureKind.unexpected,
        message: 'Respons status koneksi WhatsApp tidak valid.',
      );
    }
  }

  Future<WhatsAppConnectionStatusInfo> startQrConnection(int id) async {
    final response = await _postAuthenticated(
      '/whatsapp/connections/$id/start',
    );
    return WhatsAppConnectionStatusInfo.fromJson(_asJsonMap(response.data));
  }

  Future<WhatsAppConnectionQr> getQr(int id) async {
    final response = await _getAuthenticated('/whatsapp/connections/$id/qr');
    return WhatsAppConnectionQr.fromJson(_asJsonMap(response.data));
  }

  Future<void> disconnectQrConnection(int id) async {
    await _postAuthenticated('/whatsapp/connections/$id/disconnect');
  }

  Future<Response<dynamic>> _getAuthenticated(
    String path, {
    Map<String, dynamic>? queryParameters,
  }) async {
    try {
      final token = await _authStorage.getToken();
      if (token == null || token.isEmpty) {
        throw const WhatsAppConnectionServiceException(
          kind: WhatsAppConnectionFailureKind.unauthorized,
          message: 'Silakan login untuk mengakses koneksi WhatsApp.',
          statusCode: 401,
        );
      }

      return await _dio.get<dynamic>(
        path,
        queryParameters: queryParameters,
        options: Options(headers: {'Authorization': 'Bearer $token'}),
      );
    } on WhatsAppConnectionServiceException {
      rethrow;
    } on DioException catch (error) {
      throw _mapDioException(error);
    } catch (_) {
      throw const WhatsAppConnectionServiceException(
        kind: WhatsAppConnectionFailureKind.unexpected,
        message: 'Koneksi WhatsApp tidak dapat dimuat.',
      );
    }
  }

  Future<Response<dynamic>> _postAuthenticated(
    String path, {
    Map<String, dynamic>? data,
    bool isVerify = false,
  }) async {
    try {
      final token = await _authStorage.getToken();
      if (token == null || token.isEmpty) {
        throw WhatsAppConnectionServiceException(
          kind: WhatsAppConnectionFailureKind.unauthorized,
          message: isVerify
              ? 'Silakan login untuk memverifikasi koneksi WhatsApp.'
              : 'Silakan login untuk membuat koneksi WhatsApp.',
          statusCode: 401,
        );
      }

      return await _dio.post<dynamic>(
        path,
        data: data,
        options: Options(headers: {'Authorization': 'Bearer $token'}),
      );
    } on WhatsAppConnectionServiceException {
      rethrow;
    } on DioException catch (error) {
      throw _mapDioException(error, isCreate: data != null, isVerify: isVerify);
    } catch (_) {
      throw WhatsAppConnectionServiceException(
        kind: WhatsAppConnectionFailureKind.unexpected,
        message: isVerify
            ? 'Verifikasi koneksi gagal. Silakan coba kembali.'
            : 'Koneksi WhatsApp gagal dibuat. Silakan coba kembali.',
      );
    }
  }

  WhatsAppConnectionServiceException _mapDioException(
    DioException error, {
    bool isCreate = false,
    bool isVerify = false,
  }) {
    final statusCode = error.response?.statusCode;
    final kind = switch (statusCode) {
      401 => WhatsAppConnectionFailureKind.unauthorized,
      422 => WhatsAppConnectionFailureKind.validation,
      500 => WhatsAppConnectionFailureKind.server,
      null => WhatsAppConnectionFailureKind.network,
      _ when statusCode >= 500 => WhatsAppConnectionFailureKind.server,
      _ => WhatsAppConnectionFailureKind.http,
    };

    final message = switch (kind) {
      WhatsAppConnectionFailureKind.unauthorized =>
        'Sesi berakhir. Silakan login kembali.',
      WhatsAppConnectionFailureKind.validation =>
        isVerify
            ? 'Provider belum dapat memverifikasi koneksi. Periksa konfigurasi di backend.'
            : isCreate
            ? 'Data koneksi belum valid atau Phone Number ID sudah digunakan.'
            : 'Permintaan koneksi WhatsApp tidak valid.',
      WhatsAppConnectionFailureKind.server =>
        isVerify
            ? 'Layanan WhatsApp sedang bermasalah. Coba verifikasi kembali nanti.'
            : 'Server sedang bermasalah. Silakan coba kembali.',
      WhatsAppConnectionFailureKind.network =>
        isVerify
            ? 'Layanan verifikasi tidak dapat dihubungi. Periksa koneksi Anda.'
            : 'Backend tidak dapat dihubungi. Periksa koneksi Anda.',
      WhatsAppConnectionFailureKind.http =>
        statusCode == 403
            ? 'Akun Anda tidak memiliki izin untuk mengelola koneksi WhatsApp.'
            : isVerify && statusCode == 404
            ? 'Koneksi WhatsApp tidak ditemukan.'
            : isVerify && statusCode == 429
            ? 'Terlalu banyak permintaan verifikasi. Coba kembali nanti.'
            : isVerify
            ? 'Verifikasi koneksi gagal. Periksa konfigurasi koneksi di backend.'
            : isCreate
            ? 'Koneksi WhatsApp gagal dibuat. Silakan coba kembali.'
            : 'Koneksi WhatsApp tidak dapat dimuat.',
      WhatsAppConnectionFailureKind.unexpected =>
        isVerify
            ? 'Verifikasi koneksi gagal. Silakan coba kembali.'
            : isCreate
            ? 'Koneksi WhatsApp gagal dibuat. Silakan coba kembali.'
            : 'Koneksi WhatsApp tidak dapat dimuat.',
    };

    return WhatsAppConnectionServiceException(
      kind: kind,
      message: message,
      statusCode: statusCode,
    );
  }

  Map<String, dynamic> _asJsonMap(Object? value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) {
      return value.map((key, value) => MapEntry(key.toString(), value));
    }
    throw const FormatException('Invalid API response.');
  }
}
