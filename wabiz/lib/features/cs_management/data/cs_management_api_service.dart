import 'package:dio/dio.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/auth_storage.dart';

class CsManagementApiService {
  CsManagementApiService({Dio? dio, Future<String?> Function()? tokenProvider})
    : _dio = dio ?? ApiClient.instance.dio,
      _tokenProvider = tokenProvider ?? AuthStorage.instance.getToken;

  final Dio _dio;
  final Future<String?> Function() _tokenProvider;

  Future<List<Map<String, dynamic>>> departments() => _list('/departments');
  Future<List<Map<String, dynamic>>> members() => _list('/cs-members');

  Future<List<Map<String, dynamic>>> linkableUsers(int memberId) => _list('/cs-members/$memberId/linkable-users');

  Future<Map<String, dynamic>?> settings() async {
    final response = await _dio.get('/ai/settings', options: await _options());
    final data = _map(response.data)['data'];
    return data == null ? null : _map(data);
  }

  Future<List<Map<String, dynamic>>> _list(String path) async {
    final response = await _dio.get(path, options: await _options());
    final data = _map(response.data)['data'];
    return data is List ? data.map(_map).toList(growable: false) : const [];
  }

  Future<Map<String, dynamic>> saveDepartment({int? id, required String name, required bool active}) async {
    final response = id == null
        ? await _dio.post('/departments', data: {'name': name, 'active': active}, options: await _options())
        : await _dio.put('/departments/$id', data: {'name': name, 'active': active}, options: await _options());
    return _map(_map(response.data)['data']);
  }

  Future<Map<String, dynamic>> saveMember({int? id, required String name, required String phone, required int? departmentId, required bool active}) async {
    final data = {'display_name': name, 'phone_number': phone, 'department_id': departmentId, 'active': active};
    final response = id == null
        ? await _dio.post('/cs-members', data: data, options: await _options())
        : await _dio.put('/cs-members/$id', data: data, options: await _options());
    return _map(_map(response.data)['data']);
  }

  Future<Map<String, dynamic>> linkMemberIdentity({required int memberId, required int userId}) async {
    final response = await _dio.put('/cs-members/$memberId', data: {'user_id': userId}, options: await _options());
    return _map(_map(response.data)['data']);
  }

  Future<void> saveRegistrationDepartment(int? departmentId) async {
    await _dio.put('/ai/settings', data: {'registration_department_id': departmentId}, options: await _options());
  }

  Future<Options> _options() async {
    final token = await _tokenProvider();
    if (token == null || token.isEmpty) throw StateError('Sesi login tidak tersedia.');
    return Options(headers: {'Authorization': 'Bearer $token'});
  }

  Map<String, dynamic> _map(Object? value) => value is Map<String, dynamic> ? value : Map<String, dynamic>.from(value as Map);
}
