import 'package:flutter/material.dart';
import 'package:dio/dio.dart';

import '../../data/ai_assistant_api_service.dart';
import '../../domain/models/ai_settings.dart';
import '../../domain/models/faq_item.dart';
import '../../domain/models/institution_knowledge.dart';

class AiAssistantPage extends StatefulWidget {
  const AiAssistantPage({this.service, super.key});
  final AiAssistantApiService? service;

  @override
  State<AiAssistantPage> createState() => _AiAssistantPageState();
}

class _AiAssistantPageState extends State<AiAssistantPage> {
  late final AiAssistantApiService _api =
      widget.service ?? AiAssistantApiService.instance;
  AiSettings _settings = const AiSettings();
  InstitutionKnowledge _knowledge = const InstitutionKnowledge();
  final _name = TextEditingController();
  final _description = TextEditingController();
  final _studentCount = TextEditingController();
  final _caregiverCount = TextEditingController();
  final _teacherCount = TextEditingController();
  final _services = TextEditingController();
  final _address = TextEditingController();
  final _googleMapsUrl = TextEditingController();
  final _additionalInformation = TextEditingController();
  final _hours = TextEditingController();
  final _phone = TextEditingController();
  final _website = TextEditingController();
  final _assistantName = TextEditingController(text: 'Assistant WABiz');
  final _instructions = TextEditingController();
  final _registrationUrl = TextEditingController();
  bool _registrationEnabled = false;
  bool _loading = true;
  bool _saving = false;
  String? _loadError;
  String? _saveError;
  bool _saveComplete = false;
  bool _showKnowledge = false;
  bool _showSettings = false;

  @override
  void initState() {
    super.initState();
    _loadConfiguration();
  }

  @override
  void dispose() {
    for (final controller in [
      _name,
      _description,
      _studentCount,
      _caregiverCount,
      _teacherCount,
      _services,
      _address,
      _googleMapsUrl,
      _additionalInformation,
      _hours,
      _phone,
      _website,
      _assistantName,
      _instructions,
      _registrationUrl,
    ]) {
      controller.dispose();
    }
    super.dispose();
  }

  void _refreshKnowledge() => setState(
    () => _knowledge = _knowledge.copyWith(
      institutionName: _name.text.trim(),
      description: _description.text.trim(),
      studentCount: int.tryParse(_studentCount.text.trim()),
      clearStudentCount: _studentCount.text.trim().isEmpty,
      caregiverCount: int.tryParse(_caregiverCount.text.trim()),
      clearCaregiverCount: _caregiverCount.text.trim().isEmpty,
      teacherCount: int.tryParse(_teacherCount.text.trim()),
      clearTeacherCount: _teacherCount.text.trim().isEmpty,
      services: _services.text.trim(),
      address: _address.text.trim(),
      googleMapsUrl: _googleMapsUrl.text.trim(),
      additionalInformation: _additionalInformation.text.trim(),
      serviceHours: _hours.text.trim(),
      phone: _phone.text.trim(),
      website: _website.text.trim(),
      faqs: _knowledge.faqs,
    ),
  );

  Future<void> _loadConfiguration() async {
    setState(() {
      _loading = true;
      _loadError = null;
    });
    try {
      final configuration = await _api.show();
      if (!mounted) return;
      _applyConfiguration(configuration ?? const <String, dynamic>{});
      setState(() => _loading = false);
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _loadError = _errorMessage(error);
        _loading = false;
      });
    }
  }

  void _applyConfiguration(Map<String, dynamic> data) {
    String value(String key) => data[key]?.toString() ?? '';
    int? count(String key) => data[key] is num
        ? (data[key] as num).toInt()
        : int.tryParse(value(key));
    final styleName = value('communication_style');
    final languageName = value('language');
    _settings = AiSettings(
      enabled: data['enabled'] == true,
      assistantName: value('assistant_name').isEmpty
          ? 'Assistant WABiz'
          : value('assistant_name'),
      communicationStyle: CommunicationStyle.values.firstWhere(
        (style) => style.name == styleName,
        orElse: () => CommunicationStyle.friendly,
      ),
      language: AssistantLanguage.values.firstWhere(
        (language) => language.name == languageName,
        orElse: () => AssistantLanguage.indonesian,
      ),
      additionalInstructions: value('additional_instructions'),
    );
    _knowledge = InstitutionKnowledge(
      institutionName: value('institution_name'),
      description: value('institution_description'),
      studentCount: count('student_count'),
      caregiverCount: count('caregiver_count'),
      teacherCount: count('teacher_count'),
      services: value('services'),
      address: value('address'),
      googleMapsUrl: value('google_maps_url'),
      additionalInformation: value('additional_information'),
      serviceHours: value('service_hours'),
      phone: value('phone'),
      website: value('website'),
      faqs: (data['faqs'] is List ? data['faqs'] as List : const [])
          .whereType<Map>()
          .map(
            (faq) => FaqItem(
              question: faq['question']?.toString() ?? '',
              answer: faq['answer']?.toString() ?? '',
            ),
          )
          .toList(growable: false),
    );
    _registrationEnabled = data['registration_enabled'] == true;
    _name.text = _knowledge.institutionName;
    _description.text = _knowledge.description;
    _studentCount.text = _knowledge.studentCount?.toString() ?? '';
    _caregiverCount.text = _knowledge.caregiverCount?.toString() ?? '';
    _teacherCount.text = _knowledge.teacherCount?.toString() ?? '';
    _services.text = _knowledge.services;
    _address.text = _knowledge.address;
    _googleMapsUrl.text = _knowledge.googleMapsUrl;
    _additionalInformation.text = _knowledge.additionalInformation;
    _hours.text = _knowledge.serviceHours;
    _phone.text = _knowledge.phone;
    _website.text = _knowledge.website;
    _assistantName.text = _settings.assistantName;
    _instructions.text = _settings.additionalInstructions;
    _registrationUrl.text = value('registration_url');
  }

  Future<void> _saveConfiguration() async {
    if (_saving) return;
    _saveError = null;
    final studentCount = _readCount(_studentCount, 'Jumlah siswa/santri');
    final caregiverCount = _readCount(_caregiverCount, 'Jumlah pengasuh');
    final teacherCount = _readCount(_teacherCount, 'Jumlah guru');
    if (_saveError != null) {
      setState(() {});
      return;
    }
    setState(() {
      _saving = true;
      _saveError = null;
      _saveComplete = false;
    });
    try {
      final saved = await _api.update({
        'enabled': _settings.enabled,
        'assistant_name': _assistantName.text.trim(),
        'communication_style': _settings.communicationStyle.name,
        'language': _settings.language.name,
        'additional_instructions': _instructions.text.trim(),
        'institution_name': _name.text.trim(),
        'institution_description': _description.text.trim(),
        'student_count': studentCount,
        'caregiver_count': caregiverCount,
        'teacher_count': teacherCount,
        'services': _services.text.trim(),
        'address': _address.text.trim(),
        'google_maps_url': _googleMapsUrl.text.trim().isEmpty
            ? null
            : _googleMapsUrl.text.trim(),
        'additional_information': _additionalInformation.text.trim(),
        'service_hours': _hours.text.trim(),
        'phone': _phone.text.trim(),
        'website': _website.text.trim().isEmpty ? null : _website.text.trim(),
        'registration_enabled': _registrationEnabled,
        'registration_url': _registrationUrl.text.trim().isEmpty
            ? null
            : _registrationUrl.text.trim(),
        'faqs': _knowledge.faqs
            .map((faq) => {'question': faq.question, 'answer': faq.answer})
            .toList(growable: false),
      });
      if (!mounted) return;
      _applyConfiguration(saved);
      setState(() {
        _saving = false;
        _saveComplete = true;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _saveError = _errorMessage(error);
      });
    }
  }

  int? _readCount(TextEditingController controller, String label) {
    final text = controller.text.trim();
    if (text.isEmpty) return null;
    final value = int.tryParse(text);
    if (value == null || value < 0) {
      _saveError = '$label harus berupa bilangan bulat nol atau lebih.';
      return null;
    }
    return value;
  }

  String _errorMessage(Object error) {
    if (error is DioException && error.response?.data is Map) {
      final payload = Map<String, dynamic>.from(error.response!.data as Map);
      final errors = payload['errors'];
      if (errors is Map) {
        return errors.entries
            .map((entry) => '${entry.key}: ${(entry.value as List).join(', ')}')
            .join('\n');
      }
      if (payload['message'] is String) return payload['message'] as String;
    }
    return error.toString();
  }

  @override
  Widget build(BuildContext context) {
    final colors = Theme.of(context).colorScheme;
    return Scaffold(
      backgroundColor: colors.surface,
      body: SafeArea(
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : _loadError != null
            ? Center(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text('Pengaturan AI tidak dapat dimuat: $_loadError'),
                    TextButton(
                      onPressed: _loadConfiguration,
                      child: const Text('Coba lagi'),
                    ),
                  ],
                ),
              )
            : LayoutBuilder(
                builder: (context, constraints) {
                  final compact = constraints.maxWidth < 600;
                  return SingleChildScrollView(
                    padding: EdgeInsets.fromLTRB(
                      compact ? 16 : 32,
                      24,
                      compact ? 16 : 32,
                      36,
                    ),
                    child: Center(
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 1000),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            if (_showKnowledge || _showSettings)
                              Align(
                                alignment: Alignment.centerLeft,
                                child: TextButton.icon(
                                  onPressed: () => setState(() {
                                    _showKnowledge = false;
                                    _showSettings = false;
                                  }),
                                  icon: const Icon(Icons.arrow_back_rounded),
                                  label: const Text('AI Assistant'),
                                ),
                              ),
                            Text(
                              _showKnowledge
                                  ? 'Knowledge Lembaga'
                                  : _showSettings
                                  ? 'Pengaturan AI'
                                  : 'AI Assistant',
                              style: Theme.of(context).textTheme.headlineMedium
                                  ?.copyWith(
                                    fontWeight: FontWeight.w700,
                                    color: colors.onSurface,
                                  ),
                            ),
                            const SizedBox(height: 6),
                            Text(
                              _showKnowledge
                                  ? 'Informasi ini digunakan AI sebagai konteks saat menjawab pertanyaan pelanggan.'
                                  : _showSettings
                                  ? 'Atur nama, gaya komunikasi, bahasa, dan aturan jawaban.'
                                  : 'Atur bagaimana AI WABiz membantu menjawab pesan pelanggan.',
                              style: Theme.of(context).textTheme.bodyLarge
                                  ?.copyWith(color: colors.onSurfaceVariant),
                            ),
                            const SizedBox(height: 24),
                            if (_showKnowledge)
                              _knowledgeForm(context)
                            else if (_showSettings)
                              _settingsForm(context)
                            else ...[
                              _statusCard(context),
                              const SizedBox(height: 18),
                              _section(
                                context,
                                'Pengaturan AI',
                                'Kelola perilaku dan sumber informasi assistant.',
                                Icons.tune_rounded,
                                action: TextButton(
                                  onPressed: () =>
                                      setState(() => _showSettings = true),
                                  child: const Text('Buka pengaturan'),
                                ),
                              ),
                              const SizedBox(height: 14),
                              _section(
                                context,
                                'Knowledge Lembaga',
                                'Informasi yang menjadi konteks jawaban AI.',
                                Icons.business_outlined,
                                action: TextButton(
                                  onPressed: () =>
                                      setState(() => _showKnowledge = true),
                                  child: const Text('Kelola knowledge'),
                                ),
                                child: _preview(context),
                              ),
                              const SizedBox(height: 18),
                              _sourceNotice(context),
                            ],
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

  Widget _statusCard(BuildContext context) {
    final c = Theme.of(context).colorScheme;
    return _panel(
      context,
      Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'AI Assistant',
                      style: Theme.of(context).textTheme.titleLarge
                          ?.copyWith(fontWeight: FontWeight.w700),
                    ),
                    const SizedBox(height: 7),
                    _badge(
                      context,
                      _settings.enabled ? 'Aktif' : 'Nonaktif',
                      _settings.enabled
                          ? c.primaryContainer
                          : c.surfaceContainerHighest,
                    ),
                  ],
                ),
              ),
              Switch(
                value: _settings.enabled,
                onChanged: (value) {
                  setState(
                    () => _settings = _settings.copyWith(enabled: value),
                  );
                  _saveConfiguration();
                },
              ),
            ],
          ),
          const SizedBox(height: 12),
          if (_saveError != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Text(
                _saveError!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ),
          Text(
            'Pengaturan tersimpan pada server dan digunakan backend AI per organisasi. Kredensial model tidak dikirim ke aplikasi ini.',
            style: Theme.of(context).textTheme.bodyMedium
                ?.copyWith(color: c.onSurfaceVariant, height: 1.45),
          ),
        ],
      ),
    );
  }

  Widget _knowledgeForm(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      _section(
        context,
        'Profil Lembaga',
        'Lengkapi informasi yang boleh digunakan sebagai konteks.',
        Icons.business_outlined,
        child: Column(
          children: [
            _field(
              'Nama Lembaga',
              _name,
              hint: 'Universitas ABC',
              onChanged: (_) => _refreshKnowledge(),
            ),
            _field(
              'Deskripsi Lembaga',
              _description,
              hint: 'Ceritakan profil lembaga...',
              lines: 4,
              onChanged: (_) => _refreshKnowledge(),
            ),
            _field(
              'Jumlah santri/siswa',
              _studentCount,
              keyboard: TextInputType.number,
              onChanged: (_) => _refreshKnowledge(),
            ),
            _field(
              'Jumlah pengasuh',
              _caregiverCount,
              keyboard: TextInputType.number,
              onChanged: (_) => _refreshKnowledge(),
            ),
            _field(
              'Jumlah guru',
              _teacherCount,
              keyboard: TextInputType.number,
              onChanged: (_) => _refreshKnowledge(),
            ),
            _field(
              'Layanan / Program',
              _services,
              hint: 'S1 Informatika\nProgram Beasiswa...',
              lines: 3,
              onChanged: (_) => _refreshKnowledge(),
            ),
            _field('Alamat', _address, onChanged: (_) => _refreshKnowledge()),
            _field(
              'Link Google Maps',
              _googleMapsUrl,
              keyboard: TextInputType.url,
              onChanged: (_) => _refreshKnowledge(),
            ),
            _field(
              'Informasi tambahan',
              _additionalInformation,
              lines: 4,
              onChanged: (_) => _refreshKnowledge(),
            ),
            _field(
              'Jam Layanan',
              _hours,
              hint: 'Senin - Jumat, 08.00 - 16.00',
              onChanged: (_) => _refreshKnowledge(),
            ),
            _field(
              'Nomor Kontak',
              _phone,
              keyboard: TextInputType.phone,
              onChanged: (_) => _refreshKnowledge(),
            ),
            _field(
              'Website',
              _website,
              keyboard: TextInputType.url,
              onChanged: (_) => _refreshKnowledge(),
            ),
          ],
        ),
      ),
      const SizedBox(height: 18),
      _section(
        context,
        'FAQ',
        'Jawaban untuk pertanyaan yang sering diajukan.',
        Icons.quiz_outlined,
        action: TextButton.icon(
          onPressed: () => _editFaq(),
          icon: const Icon(Icons.add),
          label: const Text('Tambah FAQ'),
        ),
        child: _knowledge.faqs.isEmpty
            ? _empty(
                context,
                'Belum ada FAQ. Tambahkan pertanyaan dan jawaban.',
              )
            : Column(
                children: [
                  for (var i = 0; i < _knowledge.faqs.length; i++)
                    Material(
                      type: MaterialType.transparency,
                      child: ListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text(
                          _knowledge.faqs[i].question,
                          style: const TextStyle(fontWeight: FontWeight.w600),
                        ),
                        subtitle: Text(
                          _knowledge.faqs[i].answer,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                        ),
                        onTap: () => _editFaq(index: i),
                        trailing: IconButton(
                          tooltip: 'Hapus FAQ',
                          icon: const Icon(Icons.delete_outline),
                          onPressed: () => setState(() {
                            final items = [..._knowledge.faqs]..removeAt(i);
                            _knowledge = _knowledge.copyWith(faqs: items);
                          }),
                        ),
                      ),
                    ),
                ],
              ),
      ),
      const SizedBox(height: 18),
      _section(
        context,
        'Knowledge Preview',
        'Pratinjau ringkas konteks lembaga.',
        Icons.visibility_outlined,
        child: _preview(context),
      ),
      const SizedBox(height: 18),
      _savePanel(context),
    ],
  );

  Widget _settingsForm(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      _section(
        context,
        'Perilaku AI',
        'Atur jawaban dan pendaftaran. Perubahan disimpan ke akun organisasi ini.',
        Icons.tune_rounded,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _field(
              'Nama AI',
              _assistantName,
              hint: 'Assistant WABiz',
              onChanged: (v) => setState(
                () => _settings = _settings.copyWith(assistantName: v),
              ),
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: const Text('Pendaftaran tersedia'),
              value: _registrationEnabled,
              onChanged: (value) =>
                  setState(() => _registrationEnabled = value),
            ),
            _field(
              'Link pendaftaran (Google Form / website)',
              _registrationUrl,
              keyboard: TextInputType.url,
            ),
            const SizedBox(height: 12),
            _label(context, 'Gaya komunikasi'),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: CommunicationStyle.values
                  .map(
                    (style) => ChoiceChip(
                      label: Text(_styleLabel(style)),
                      selected: _settings.communicationStyle == style,
                      onSelected: (_) => setState(
                        () => _settings = _settings.copyWith(
                          communicationStyle: style,
                        ),
                      ),
                    ),
                  )
                  .toList(),
            ),
            const SizedBox(height: 18),
            _label(context, 'Bahasa'),
            SegmentedButton<AssistantLanguage>(
              segments: const [
                ButtonSegment(
                  value: AssistantLanguage.indonesian,
                  label: Text('Indonesia'),
                ),
                ButtonSegment(
                  value: AssistantLanguage.english,
                  label: Text('English'),
                ),
              ],
              selected: {_settings.language},
              onSelectionChanged: (s) => setState(
                () => _settings = _settings.copyWith(language: s.first),
              ),
            ),
            _field(
              'Instruksi tambahan',
              _instructions,
              hint: 'Jawab dengan singkat, ramah, dan jangan memberikan informasi yang tidak tersedia dalam knowledge lembaga.',
              lines: 4,
              onChanged: (v) => setState(
                () => _settings = _settings.copyWith(additionalInstructions: v),
              ),
            ),
          ],
        ),
      ),
      const SizedBox(height: 18),
      _savePanel(context),
    ],
  );

  Widget _sourceNotice(BuildContext context) => _section(
    context,
    'Sumber data AI',
    'Fakta lembaga dan link pendaftaran berasal dari pengaturan tersimpan organisasi ini. Informasi kosong tidak dijadikan fakta oleh provider.',
    Icons.verified_user_outlined,
  );

  Widget _savePanel(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      if (_saveError != null)
        Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: Text(
            _saveError!,
            style: TextStyle(color: Theme.of(context).colorScheme.error),
          ),
        ),
      if (_saveComplete)
        const Padding(
          padding: EdgeInsets.only(bottom: 10),
          child: Text('Pengaturan berhasil disimpan.'),
        ),
      FilledButton.icon(
        onPressed: _saving ? null : _saveConfiguration,
        icon: _saving
            ? const SizedBox(
                width: 18,
                height: 18,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : const Icon(Icons.save_outlined),
        label: Text(_saving ? 'Menyimpan...' : 'Simpan pengaturan'),
      ),
      TextButton(
        onPressed: _saving ? null : _loadConfiguration,
        child: const Text('Muat ulang dari server'),
      ),
    ],
  );

  Widget _preview(BuildContext context) {
    final k = _knowledge;
    final entries = <String, String>{
      'Nama': k.institutionName,
      'Tentang': k.description,
      'Jumlah siswa/santri': k.studentCount?.toString() ?? '',
      'Jumlah pengasuh': k.caregiverCount?.toString() ?? '',
      'Jumlah guru': k.teacherCount?.toString() ?? '',
      'Program': k.services,
      'Alamat': k.address,
      'Google Maps': k.googleMapsUrl,
      'Informasi tambahan': k.additionalInformation,
      'Jam layanan': k.serviceHours,
      'Kontak': k.phone,
      'Website': k.website,
    };
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final e in entries.entries.where((e) => e.value.isNotEmpty))
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: RichText(
              text: TextSpan(
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: Theme.of(context).colorScheme.onSurface,
                  height: 1.4,
                ),
                children: [
                  TextSpan(
                    text: '${e.key}: ',
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                  TextSpan(text: e.value),
                ],
              ),
            ),
          ),
        Text(
          'FAQ: ${k.faqs.length} item${k.faqs.length == 1 ? '' : 's'}',
          style: Theme.of(context).textTheme.bodyMedium
              ?.copyWith(fontWeight: FontWeight.w600),
        ),
        if (entries.values.every((value) => value.isEmpty) && k.faqs.isEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: _empty(
              context,
              'Preview akan terisi saat informasi lembaga ditambahkan.',
            ),
          ),
      ],
    );
  }

  Widget _section(
    BuildContext context,
    String title,
    String subtitle,
    IconData icon, {
    Widget? child,
    Widget? action,
  }) => _panel(
    context,
    Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Icon(icon, color: Theme.of(context).colorScheme.primary),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                title,
                style: Theme.of(context).textTheme.titleMedium
                    ?.copyWith(fontWeight: FontWeight.w700),
              ),
            ),
            ?action,
          ],
        ),
        const SizedBox(height: 5),
        Text(
          subtitle,
          style: Theme.of(context).textTheme.bodySmall?.copyWith(
            color: Theme.of(context).colorScheme.onSurfaceVariant,
            height: 1.4,
          ),
        ),
        if (child != null) ...[const SizedBox(height: 16), child],
      ],
    ),
  );
  Widget _panel(BuildContext context, Widget child) => Material(
    color: Theme.of(context).colorScheme.surface,
    shape: RoundedRectangleBorder(
      side: BorderSide(color: Theme.of(context).colorScheme.outlineVariant),
      borderRadius: BorderRadius.circular(16),
    ),
    clipBehavior: Clip.antiAlias,
    child: Padding(padding: const EdgeInsets.all(18), child: child),
  );
  Widget _field(
    String label,
    TextEditingController controller, {
    String? hint,
    int lines = 1,
    TextInputType? keyboard,
    ValueChanged<String>? onChanged,
  }) => Padding(
    padding: const EdgeInsets.only(top: 14),
    child: TextField(
      controller: controller,
      minLines: lines,
      maxLines: lines,
      keyboardType:
          keyboard ??
          (lines > 1 ? TextInputType.multiline : TextInputType.text),
      onChanged: onChanged,
      decoration: InputDecoration(labelText: label, hintText: hint),
    ),
  );
  Widget _label(BuildContext context, String text) => Text(
    text,
    style: Theme.of(context).textTheme.labelLarge
        ?.copyWith(color: Theme.of(context).colorScheme.onSurface),
  );
  Widget _badge(BuildContext context, String text, Color color) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
    decoration: BoxDecoration(
      color: color,
      borderRadius: BorderRadius.circular(30),
    ),
    child: Text(
      text,
      style: Theme.of(context).textTheme.labelMedium
          ?.copyWith(fontWeight: FontWeight.w700),
    ),
  );
  Widget _empty(BuildContext context, String text) => Text(
    text,
    style: Theme.of(context).textTheme.bodyMedium
        ?.copyWith(color: Theme.of(context).colorScheme.onSurfaceVariant),
  );

  String _styleLabel(CommunicationStyle style) => switch (style) {
    CommunicationStyle.professional => 'Profesional',
    CommunicationStyle.friendly => 'Ramah',
    CommunicationStyle.formal => 'Formal',
    CommunicationStyle.casual => 'Santai',
  };

  Future<void> _editFaq({int? index}) async {
    final existing = index == null ? null : _knowledge.faqs[index];
    final question = TextEditingController(text: existing?.question ?? '');
    final answer = TextEditingController(text: existing?.answer ?? '');
    final item = await showDialog<FaqItem>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(existing == null ? 'Tambah FAQ' : 'Edit FAQ'),
        content: SizedBox(
          width: 480,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: question,
                decoration: const InputDecoration(labelText: 'Pertanyaan'),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: answer,
                minLines: 3,
                maxLines: 5,
                decoration: const InputDecoration(labelText: 'Jawaban'),
              ),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Batal'),
          ),
          FilledButton(
            onPressed: () {
              if (question.text.trim().isEmpty || answer.text.trim().isEmpty) {
                return;
              }
              Navigator.pop(
                context,
                FaqItem(
                  question: question.text.trim(),
                  answer: answer.text.trim(),
                ),
              );
            },
            child: const Text('Simpan'),
          ),
        ],
      ),
    );
    question.dispose();
    answer.dispose();
    if (item == null || !mounted) return;
    setState(() {
      final items = [..._knowledge.faqs];
      if (index == null) {
        items.add(item);
      } else {
        items[index] = item;
      }
      _knowledge = _knowledge.copyWith(faqs: items);
    });
  }
}
