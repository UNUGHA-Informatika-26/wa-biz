import 'package:flutter/material.dart';

import '../../models/contact.dart';
import '../widgets/contact_card.dart';
import '../widgets/contact_group_filter.dart';
import '../widgets/contact_search_bar.dart';
import '../widgets/contact_stats.dart';

class ContactsPage extends StatefulWidget {
  const ContactsPage({super.key});

  @override
  State<ContactsPage> createState() => _ContactsPageState();
}

class _ContactsPageState extends State<ContactsPage> {
  static const List<String> _groups = ['Pelanggan', 'Prospek', 'VIP'];
  static const List<String> _filters = [
    'Semua',
    'Pelanggan',
    'Prospek',
    'VIP',
    'Baru',
  ];

  final TextEditingController _searchController = TextEditingController();
  final List<Contact> _contacts = [
    const Contact(
      id: 1,
      name: 'Andi Pratama',
      phone: '0812 0000 0001',
      group: 'Pelanggan',
      status: 'Aktif',
      lastActivity: 'Terakhir berinteraksi 2 jam lalu',
      notes: 'Pelanggan reguler. Tertarik pada promo bulanan.',
    ),
    const Contact(
      id: 2,
      name: 'Siti Rahma',
      phone: '0857 0000 0002',
      group: 'Prospek',
      status: 'Baru',
      lastActivity: 'Ditambahkan 5 menit lalu',
      notes: 'Menanyakan informasi produk.',
      recentlyAdded: true,
    ),
    const Contact(
      id: 3,
      name: 'Budi Santoso',
      phone: '0896 0000 0003',
      group: 'VIP',
      status: 'Aktif',
      lastActivity: 'Terakhir berinteraksi kemarin',
      notes: 'Prioritaskan informasi produk terbaru.',
    ),
    const Contact(
      id: 4,
      name: 'Dewi Lestari',
      phone: '0813 0000 0004',
      group: 'Pelanggan',
      status: 'Aktif',
      lastActivity: 'Terakhir berinteraksi 3 hari lalu',
      notes: 'Memilih pengiriman pada akhir pekan.',
    ),
    const Contact(
      id: 5,
      name: 'Rizky Maulana',
      phone: '0821 0000 0005',
      group: 'Prospek',
      status: 'Baru',
      lastActivity: 'Ditambahkan 1 jam lalu',
      notes: 'Meminta katalog layanan.',
      recentlyAdded: true,
    ),
    const Contact(
      id: 6,
      name: 'Maya Putri',
      phone: '0878 0000 0006',
      group: 'VIP',
      status: 'Aktif',
      lastActivity: 'Terakhir berinteraksi 1 hari lalu',
      notes: 'Kontak utama untuk pesanan bisnis.',
    ),
  ];

  String _selectedFilter = 'Semua';
  int _nextId = 7;

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  List<Contact> get _filteredContacts {
    final String query = _searchController.text.trim().toLowerCase();
    return _contacts.where((Contact contact) {
      final bool matchesQuery =
          query.isEmpty ||
          contact.name.toLowerCase().contains(query) ||
          contact.phone.toLowerCase().contains(query) ||
          contact.group.toLowerCase().contains(query);
      final bool matchesFilter = switch (_selectedFilter) {
        'Baru' => contact.recentlyAdded,
        'Semua' => true,
        _ => contact.group == _selectedFilter,
      };
      return matchesQuery && matchesFilter;
    }).toList();
  }

  Future<void> _openContactForm({Contact? contact}) async {
    final Contact? result = await showModalBottomSheet<Contact>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (context) => _ContactFormSheet(
        contact: contact,
        groups: _groups,
        onSave: (name, phone, group, notes) {
          final Contact saved = contact == null
              ? Contact(
                  id: _nextId++,
                  name: name,
                  phone: phone,
                  group: group,
                  status: 'Baru',
                  lastActivity: 'Baru ditambahkan',
                  notes: notes,
                  recentlyAdded: true,
                )
              : contact.copyWith(
                  name: name,
                  phone: phone,
                  group: group,
                  notes: notes,
                  lastActivity: 'Diperbarui barusan',
                );
          Navigator.of(context).pop(saved);
        },
      ),
    );

    if (result == null || !mounted) return;
    setState(() {
      final int index = _contacts.indexWhere((item) => item.id == result.id);
      if (index == -1) {
        _contacts.insert(0, result);
      } else {
        _contacts[index] = result;
      }
    });
  }

  Future<void> _showContactDetails(Contact contact) {
    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (sheetContext) => _ContactDetailsSheet(
        contact: contact,
        onEdit: () {
          Navigator.of(sheetContext).pop();
          WidgetsBinding.instance.addPostFrameCallback((_) {
            if (mounted) _openContactForm(contact: contact);
          });
        },
        onDelete: () async {
          final bool shouldDelete = await _confirmDelete(contact);
          if (!shouldDelete || !mounted || !sheetContext.mounted) return;
          Navigator.of(sheetContext).pop();
          setState(
            () => _contacts.removeWhere((item) => item.id == contact.id),
          );
        },
      ),
    );
  }

  Future<bool> _confirmDelete(Contact contact) async {
    final bool? confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Hapus kontak?'),
        content: Text('${contact.name} akan dihapus dari daftar demo ini.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Batal'),
          ),
          FilledButton.tonal(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Hapus'),
          ),
        ],
      ),
    );
    return confirmed ?? false;
  }

  void _showImportInfo() {
    showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Import Kontak'),
        content: const Text(
          'Import kontak akan tersedia pada tahap integrasi berikutnya.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Mengerti'),
          ),
        ],
      ),
    );
  }

  void _resetFilters() {
    _searchController.clear();
    setState(() => _selectedFilter = 'Semua');
  }

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    final List<Contact> visibleContacts = _filteredContacts;

    return LayoutBuilder(
      builder: (context, constraints) {
        final bool desktop = constraints.maxWidth >= 900;
        final double horizontalPadding = constraints.maxWidth < 420 ? 16 : 28;
        return SingleChildScrollView(
          padding: EdgeInsets.fromLTRB(
            horizontalPadding,
            26,
            horizontalPadding,
            32,
          ),
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 1120),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _buildHeader(context, desktop),
                  const SizedBox(height: 22),
                  ContactStats(
                    totalContacts: _contacts.length,
                    groupCount: _contacts
                        .map((item) => item.group)
                        .toSet()
                        .length,
                    recentCount: _contacts
                        .where((item) => item.recentlyAdded)
                        .length,
                  ),
                  const SizedBox(height: 24),
                  ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 860),
                    child: ContactSearchBar(
                      controller: _searchController,
                      onChanged: (_) => setState(() {}),
                    ),
                  ),
                  const SizedBox(height: 10),
                  ContactGroupFilter(
                    groups: _filters,
                    selectedGroup: _selectedFilter,
                    onSelected: (group) =>
                        setState(() => _selectedFilter = group),
                  ),
                  const SizedBox(height: 20),
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          '${visibleContacts.length} kontak',
                          style: Theme.of(context).textTheme.titleSmall
                              ?.copyWith(
                                color: colors.onSurfaceVariant,
                                fontWeight: FontWeight.w600,
                              ),
                        ),
                      ),
                      Text(
                        'DATA CONTOH',
                        style: Theme.of(context).textTheme.labelSmall?.copyWith(
                          color: colors.onSurfaceVariant,
                          letterSpacing: .7,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  if (visibleContacts.isEmpty)
                    _ContactsEmptyState(onReset: _resetFilters)
                  else
                    LayoutBuilder(
                      builder: (context, gridConstraints) {
                        final bool twoColumns = gridConstraints.maxWidth >= 760;
                        return GridView.builder(
                          itemCount: visibleContacts.length,
                          shrinkWrap: true,
                          physics: const NeverScrollableScrollPhysics(),
                          gridDelegate:
                              SliverGridDelegateWithFixedCrossAxisCount(
                                crossAxisCount: twoColumns ? 2 : 1,
                                mainAxisExtent: twoColumns ? 148 : 142,
                                crossAxisSpacing: 14,
                                mainAxisSpacing: 12,
                              ),
                          itemBuilder: (context, index) {
                            final Contact contact = visibleContacts[index];
                            return ContactCard(
                              contact: contact,
                              onTap: () => _showContactDetails(contact),
                            );
                          },
                        );
                      },
                    ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _buildHeader(BuildContext context, bool desktop) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (desktop)
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Expanded(child: _title(context, colors)),
              const SizedBox(width: 16),
              _headerActions(),
            ],
          )
        else ...[
          _title(context, colors),
          const SizedBox(height: 16),
          _headerActions(),
        ],
      ],
    );
  }

  Widget _title(BuildContext context, ColorScheme colors) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Kontak',
          style: Theme.of(context).textTheme.headlineSmall?.copyWith(
            color: colors.onSurface,
            fontWeight: FontWeight.w700,
            letterSpacing: -.5,
          ),
        ),
        const SizedBox(height: 5),
        Text(
          'Kelola dan kelompokkan kontak pelanggan Anda.',
          style: Theme.of(context).textTheme.bodyMedium
              ?.copyWith(color: colors.onSurfaceVariant),
        ),
      ],
    );
  }

  Widget _headerActions() {
    return Wrap(
      spacing: 9,
      runSpacing: 8,
      children: [
        OutlinedButton.icon(
          onPressed: _showImportInfo,
          icon: const Icon(Icons.upload_file_outlined, size: 18),
          label: const Text('Import Kontak'),
        ),
        FilledButton.icon(
          onPressed: _openContactForm,
          icon: const Icon(Icons.person_add_alt_1_rounded, size: 18),
          label: const Text('Tambah Kontak'),
        ),
      ],
    );
  }
}

class _ContactsEmptyState extends StatelessWidget {
  const _ContactsEmptyState({required this.onReset});

  final VoidCallback onReset;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    final TextTheme textTheme = Theme.of(context).textTheme;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 40),
      decoration: BoxDecoration(
        color: colors.surface,
        border: Border.all(color: colors.outlineVariant),
        borderRadius: BorderRadius.circular(18),
      ),
      child: Column(
        children: [
          Icon(Icons.person_search_outlined, size: 34, color: colors.primary),
          const SizedBox(height: 14),
          Text(
            'Kontak tidak ditemukan',
            style: textTheme.titleMedium?.copyWith(
              color: colors.onSurface,
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            'Periksa kata kunci atau filter yang digunakan.',
            textAlign: TextAlign.center,
            style: textTheme.bodyMedium?.copyWith(
              color: colors.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 16),
          OutlinedButton(onPressed: onReset, child: const Text('Reset Filter')),
        ],
      ),
    );
  }
}

class _ContactDetailsSheet extends StatelessWidget {
  const _ContactDetailsSheet({
    required this.contact,
    required this.onEdit,
    required this.onDelete,
  });

  final Contact contact;
  final VoidCallback onEdit;
  final VoidCallback onDelete;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    final TextTheme textTheme = Theme.of(context).textTheme;
    return SingleChildScrollView(
      padding: EdgeInsets.fromLTRB(
        24,
        8,
        24,
        24 + MediaQuery.viewInsetsOf(context).bottom,
      ),
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 520),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Row(
                children: [
                  CircleAvatar(
                    radius: 28,
                    backgroundColor: colors.primaryContainer,
                    child: Text(
                      contact.name
                          .trim()
                          .split(RegExp(r'\s+'))
                          .take(2)
                          .map((word) => word[0])
                          .join()
                          .toUpperCase(),
                      style: textTheme.titleSmall?.copyWith(
                        color: colors.onPrimaryContainer,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          contact.name,
                          style: textTheme.titleLarge?.copyWith(
                            color: colors.onSurface,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          contact.phone,
                          style: textTheme.bodyMedium?.copyWith(
                            color: colors.onSurfaceVariant,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 22),
              _DetailLine(label: 'Grup', value: contact.group),
              _DetailLine(label: 'Status', value: contact.status),
              _DetailLine(
                label: 'Aktivitas terakhir',
                value: contact.lastActivity,
              ),
              const SizedBox(height: 8),
              Text(
                'Catatan',
                style: textTheme.labelLarge?.copyWith(
                  color: colors.onSurfaceVariant,
                  fontWeight: FontWeight.w600,
                ),
              ),
              const SizedBox(height: 6),
              Text(
                contact.notes.isEmpty ? 'Belum ada catatan.' : contact.notes,
                style: textTheme.bodyMedium?.copyWith(
                  color: colors.onSurface,
                  height: 1.45,
                ),
              ),
              const SizedBox(height: 24),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: onDelete,
                      icon: const Icon(Icons.delete_outline_rounded),
                      label: const Text('Hapus'),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: FilledButton.icon(
                      onPressed: onEdit,
                      icon: const Icon(Icons.edit_outlined),
                      label: const Text('Edit'),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _DetailLine extends StatelessWidget {
  const _DetailLine({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    final TextTheme textTheme = Theme.of(context).textTheme;
    return Padding(
      padding: const EdgeInsets.only(bottom: 13),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 132,
            child: Text(
              label,
              style: textTheme.bodySmall?.copyWith(
                color: colors.onSurfaceVariant,
              ),
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: textTheme.bodyMedium?.copyWith(
                color: colors.onSurface,
                fontWeight: FontWeight.w500,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ContactFormSheet extends StatefulWidget {
  const _ContactFormSheet({
    required this.groups,
    required this.onSave,
    this.contact,
  });

  final List<String> groups;
  final Contact? contact;
  final void Function(String name, String phone, String group, String notes)
  onSave;

  @override
  State<_ContactFormSheet> createState() => _ContactFormSheetState();
}

class _ContactFormSheetState extends State<_ContactFormSheet> {
  final GlobalKey<FormState> _formKey = GlobalKey<FormState>();
  late final TextEditingController _nameController;
  late final TextEditingController _phoneController;
  late final TextEditingController _notesController;
  late String _group;

  @override
  void initState() {
    super.initState();
    final Contact? contact = widget.contact;
    _nameController = TextEditingController(text: contact?.name ?? '');
    _phoneController = TextEditingController(text: contact?.phone ?? '');
    _notesController = TextEditingController(text: contact?.notes ?? '');
    _group = contact?.group ?? widget.groups.first;
  }

  @override
  void dispose() {
    _nameController.dispose();
    _phoneController.dispose();
    _notesController.dispose();
    super.dispose();
  }

  String? _validateName(String? value) => value == null || value.trim().isEmpty
      ? 'Nama lengkap wajib diisi.'
      : null;

  String? _validatePhone(String? value) {
    final String phone = value?.trim() ?? '';
    if (phone.isEmpty) return 'Nomor WhatsApp wajib diisi.';
    final String digits = phone.replaceAll(RegExp(r'\D'), '');
    if (!RegExp(r'^[+\d\s()-]+$').hasMatch(phone) ||
        digits.length < 8 ||
        digits.length > 15) {
      return 'Masukkan nomor dengan format yang valid.';
    }
    return null;
  }

  void _submit() {
    FocusScope.of(context).unfocus();
    if (!_formKey.currentState!.validate()) return;
    widget.onSave(
      _nameController.text.trim(),
      _phoneController.text.trim(),
      _group,
      _notesController.text.trim(),
    );
  }

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return Padding(
      padding: EdgeInsets.fromLTRB(
        24,
        8,
        24,
        20 + MediaQuery.viewInsetsOf(context).bottom,
      ),
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 560),
          child: Form(
            key: _formKey,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  widget.contact == null ? 'Tambah Kontak' : 'Edit Kontak',
                  style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                    color: colors.onSurface,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 20),
                TextFormField(
                  controller: _nameController,
                  textCapitalization: TextCapitalization.words,
                  textInputAction: TextInputAction.next,
                  decoration: const InputDecoration(labelText: 'Nama lengkap'),
                  validator: _validateName,
                ),
                const SizedBox(height: 14),
                TextFormField(
                  controller: _phoneController,
                  keyboardType: TextInputType.phone,
                  textInputAction: TextInputAction.next,
                  decoration: const InputDecoration(
                    labelText: 'Nomor WhatsApp',
                    hintText: 'Contoh: 0812 0000 0001',
                  ),
                  validator: _validatePhone,
                ),
                const SizedBox(height: 14),
                DropdownButtonFormField<String>(
                  initialValue: _group,
                  decoration: const InputDecoration(labelText: 'Grup'),
                  items: [
                    for (final String group in widget.groups)
                      DropdownMenuItem(value: group, child: Text(group)),
                  ],
                  onChanged: (value) {
                    if (value != null) setState(() => _group = value);
                  },
                ),
                const SizedBox(height: 14),
                TextFormField(
                  controller: _notesController,
                  minLines: 2,
                  maxLines: 3,
                  textCapitalization: TextCapitalization.sentences,
                  decoration: const InputDecoration(
                    labelText: 'Catatan (opsional)',
                    alignLabelWithHint: true,
                  ),
                ),
                const SizedBox(height: 20),
                FilledButton(
                  onPressed: _submit,
                  child: Text(
                    widget.contact == null
                        ? 'Simpan Kontak'
                        : 'Simpan Perubahan',
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
