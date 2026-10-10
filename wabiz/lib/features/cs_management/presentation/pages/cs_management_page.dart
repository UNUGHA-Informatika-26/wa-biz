import 'package:flutter/material.dart';

import '../../data/cs_management_api_service.dart';

class CsManagementPage extends StatefulWidget {
  const CsManagementPage({this.service, super.key});
  final CsManagementApiService? service;

  @override
  State<CsManagementPage> createState() => _CsManagementPageState();
}

class _CsManagementPageState extends State<CsManagementPage> {
  late final CsManagementApiService _service = widget.service ?? CsManagementApiService();
  bool _loading = true;
  bool _saving = false;
  String? _error;
  List<Map<String, dynamic>> _departments = [];
  List<Map<String, dynamic>> _members = [];
  int? _registrationDepartment;

  @override
  void initState() { super.initState(); _load(); }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final loadedDepartments = await _service.departments();
      final loadedMembers = await _service.members();
      final settings = await _service.settings();
      if (!mounted) return;
      setState(() {
        _departments = loadedDepartments;
        _members = loadedMembers;
        _registrationDepartment = settings?['registration_department_id'] as int?;
        _loading = false;
      });
    } catch (_) {
      if (mounted) setState(() { _loading = false; _error = 'Data CS dan divisi gagal dimuat.'; });
    }
  }

  Future<void> _saveRegistration(int? id) async {
    setState(() => _saving = true);
    try {
      await _service.saveRegistrationDepartment(id);
      if (mounted) setState(() => _registrationDepartment = id);
    } catch (_) { _notice('Divisi pendaftaran gagal disimpan.'); }
    finally { if (mounted) setState(() => _saving = false); }
  }

  Future<void> _editDepartment([Map<String, dynamic>? item]) async {
    final name = TextEditingController(text: '${item?['name'] ?? ''}');
    var active = item?['active'] != false;
    final ok = await showDialog<bool>(context: context, builder: (context) => StatefulBuilder(builder: (context, setDialog) => AlertDialog(
      title: Text(item == null ? 'Tambah divisi' : 'Ubah divisi'),
      content: Column(mainAxisSize: MainAxisSize.min, children: [TextField(controller: name, autofocus: true, maxLength: 100, decoration: const InputDecoration(labelText: 'Nama divisi')), SwitchListTile(title: const Text('Aktif'), value: active, onChanged: (v) => setDialog(() => active = v))]),
      actions: [TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Batal')), FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Simpan'))],
    )));
    if (ok != true) return;
    if (name.text.trim().isEmpty) { _notice('Nama divisi wajib diisi.'); return; }
    await _mutate(() => _service.saveDepartment(id: item?['id'] as int?, name: name.text.trim(), active: active));
  }

  Future<void> _editMember([Map<String, dynamic>? item]) async {
    final name = TextEditingController(text: '${item?['display_name'] ?? ''}');
    final phone = TextEditingController(text: '${item?['phone_number'] ?? ''}');
    var departmentId = (item?['department'] as Map?)?['id'] as int?;
    var active = item?['active'] != false;
    final ok = await showDialog<bool>(context: context, builder: (context) => StatefulBuilder(builder: (context, setDialog) => AlertDialog(
      title: Text(item == null ? 'Tambah CS' : 'Ubah CS'),
      content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
        TextField(controller: name, autofocus: true, maxLength: 255, decoration: const InputDecoration(labelText: 'Nama')),
        TextField(controller: phone, keyboardType: TextInputType.phone, maxLength: 30, decoration: const InputDecoration(labelText: 'Nomor WhatsApp')),
        DropdownButtonFormField<int?>(initialValue: departmentId, decoration: const InputDecoration(labelText: 'Divisi'), items: [const DropdownMenuItem<int?>(value: null, child: Text('Tanpa divisi')), ..._departments.map((d) => DropdownMenuItem<int?>(value: d['id'] as int, child: Text('${d['name']}')))], onChanged: (v) => setDialog(() => departmentId = v)),
        SwitchListTile(title: const Text('Aktif'), value: active, onChanged: (v) => setDialog(() => active = v)),
      ])),
      actions: [TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Batal')), FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Simpan'))],
    )));
    if (ok != true) return;
    if (name.text.trim().isEmpty || phone.text.trim().length < 8) { _notice('Nama dan nomor WhatsApp yang valid wajib diisi.'); return; }
    await _mutate(() => _service.saveMember(id: item?['id'] as int?, name: name.text.trim(), phone: phone.text.trim(), departmentId: departmentId, active: active));
  }

  Future<void> _linkMemberIdentity(Map<String, dynamic> member) async {
    final memberId = member['id'] as int;
    try {
      final users = await _service.linkableUsers(memberId);
      if (!mounted) return;
      if (users.isEmpty) {
        _notice('Tidak ada akun staff organisasi yang tersedia untuk ditautkan.');
        return;
      }
      int? selectedUserId;
      final selected = await showDialog<int>(context: context, builder: (context) => StatefulBuilder(builder: (context, setDialog) => AlertDialog(
        title: const Text('Tautkan akun CS'),
        content: DropdownButtonFormField<int>(
          initialValue: selectedUserId,
          decoration: const InputDecoration(labelText: 'Akun staff organisasi'),
          items: users.map((user) => DropdownMenuItem<int>(value: user['id'] as int, child: Text('${user['name']} · ${user['email']}'))).toList(),
          onChanged: (value) => setDialog(() => selectedUserId = value),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('Batal')),
          FilledButton(onPressed: selectedUserId == null ? null : () => Navigator.pop(context, selectedUserId), child: const Text('Tautkan')),
        ],
      )));
      if (selected == null) return;
      await _mutate(() => _service.linkMemberIdentity(memberId: memberId, userId: selected));
    } catch (_) {
      _notice('Akun CS gagal ditautkan. Periksa izin dan keanggotaan organisasi.');
    }
  }

  Future<void> _mutate(Future<Object?> Function() action) async {
    setState(() => _saving = true);
    try { await action(); await _load(); }
    catch (_) { _notice('Perubahan gagal disimpan. Periksa data dan izin akun.'); }
    finally { if (mounted) setState(() => _saving = false); }
  }

  void _notice(String text) { if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text))); }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Divisi & Customer Service'), actions: [IconButton(onPressed: _load, icon: const Icon(Icons.refresh), tooltip: 'Muat ulang')]),
    body: _loading ? const Center(child: CircularProgressIndicator()) : _error != null
      ? Center(child: Column(mainAxisSize: MainAxisSize.min, children: [Text(_error!), TextButton(onPressed: _load, child: const Text('Coba lagi'))]))
      : RefreshIndicator(onRefresh: _load, child: ListView(padding: const EdgeInsets.all(16), children: [
        Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('Divisi pendaftaran', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          DropdownButtonFormField<int?>(initialValue: _registrationDepartment, decoration: const InputDecoration(border: OutlineInputBorder(), hintText: 'Pilih divisi'), items: [const DropdownMenuItem<int?>(value: null, child: Text('Belum ditentukan')), ..._departments.map((d) => DropdownMenuItem<int?>(value: d['id'] as int, enabled: d['active'] == true, child: Text('${d['name']}${d['active'] == true ? '' : ' · nonaktif'}')))], onChanged: _saving ? null : _saveRegistration),
          const SizedBox(height: 6), const Text('Handoff registrasi hanya memilih anggota aktif di divisi ini.'),
        ]))),
        Row(children: [Expanded(child: Text('Divisi', style: Theme.of(context).textTheme.titleLarge)), IconButton(onPressed: _saving ? null : () => _editDepartment(), icon: const Icon(Icons.add), tooltip: 'Tambah divisi')]),
        if (_departments.isEmpty) const ListTile(title: Text('Belum ada divisi.')),
        ..._departments.map((d) => ListTile(title: Text('${d['name']}'), subtitle: Text(d['active'] == true ? 'Aktif' : 'Nonaktif'), trailing: IconButton(onPressed: _saving ? null : () => _editDepartment(d), icon: const Icon(Icons.edit_outlined)))),
        const Divider(height: 32),
        Row(children: [Expanded(child: Text('Customer Service', style: Theme.of(context).textTheme.titleLarge)), IconButton(onPressed: _saving ? null : () => _editMember(), icon: const Icon(Icons.person_add_alt_1), tooltip: 'Tambah CS')]),
        if (_members.isEmpty) const ListTile(title: Text('Belum ada anggota CS.')),
        ..._members.map((m) {
          final user = m['user'] as Map?;
          return ListTile(
            title: Text('${m['display_name'] ?? 'CS'}'),
            subtitle: Text('${m['phone_number'] ?? 'Nomor belum diatur'} · ${(m['department'] as Map?)?['name'] ?? 'Tanpa divisi'} · ${m['active'] == true ? 'Aktif' : 'Nonaktif'} · ${user?['email'] ?? 'Akun belum tertaut'}'),
            trailing: Wrap(spacing: 4, children: [
              if (user == null) IconButton(onPressed: _saving ? null : () => _linkMemberIdentity(m), icon: const Icon(Icons.link), tooltip: 'Tautkan akun'),
              IconButton(onPressed: _saving ? null : () => _editMember(m), icon: const Icon(Icons.edit_outlined)),
            ]),
          );
        }),
      ])),
  );
}
