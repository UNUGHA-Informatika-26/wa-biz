import 'package:flutter/material.dart';

import '../../data/models/whatsapp_connection.dart';
import '../../data/whatsapp_connection_service.dart';

class CreateWhatsAppConnectionForm extends StatefulWidget {
  const CreateWhatsAppConnectionForm({super.key});

  @override
  State<CreateWhatsAppConnectionForm> createState() =>
      _CreateWhatsAppConnectionFormState();
}

class _CreateWhatsAppConnectionFormState
    extends State<CreateWhatsAppConnectionForm> {
  final _formKey = GlobalKey<FormState>();
  final _displayNameController = TextEditingController();
  final _phoneNumberController = TextEditingController();
  final _phoneNumberIdController = TextEditingController();
  final _businessAccountIdController = TextEditingController();

  String? _provider = 'meta_cloud';
  bool _isPrimary = false;
  bool _isSubmitting = false;
  String? _errorMessage;

  @override
  void dispose() {
    _displayNameController.dispose();
    _phoneNumberController.dispose();
    _phoneNumberIdController.dispose();
    _businessAccountIdController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_isSubmitting || !_formKey.currentState!.validate()) return;
    setState(() {
      _isSubmitting = true;
      _errorMessage = null;
    });

    try {
      final connection = await WhatsAppConnectionService.instance
          .createConnection(
            provider: _provider!,
            displayName: _displayNameController.text.trim(),
            phoneNumber: _phoneNumberController.text.trim(),
            phoneNumberId: _phoneNumberIdController.text.trim(),
            businessAccountId: _businessAccountIdController.text.trim(),
            isPrimary: _isPrimary,
          );
      if (!mounted) return;
      Navigator.of(context).pop<WhatsAppConnection>(connection);
    } on WhatsAppConnectionServiceException catch (error) {
      if (!mounted) return;
      setState(() {
        _errorMessage = error.message;
        _isSubmitting = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _errorMessage = 'Koneksi gagal dibuat. Silakan coba kembali.';
        _isSubmitting = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final colors = Theme.of(context).colorScheme;
    final textTheme = Theme.of(context).textTheme;

    return Padding(
      padding: EdgeInsets.only(
        left: 24,
        right: 24,
        bottom: MediaQuery.viewInsetsOf(context).bottom + 20,
      ),
      child: ConstrainedBox(
        constraints: BoxConstraints(
          maxWidth: 560,
          maxHeight: MediaQuery.sizeOf(context).height * .85,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'Tambah Koneksi WhatsApp',
              style: textTheme.titleLarge?.copyWith(
                color: colors.onSurface,
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 6),
            Text(
              'Masukkan informasi koneksi. Credential provider tidak diminta di aplikasi.',
              style: textTheme.bodyMedium?.copyWith(
                color: colors.onSurfaceVariant,
                height: 1.45,
              ),
            ),
            const SizedBox(height: 20),
            Flexible(
              child: SingleChildScrollView(
                child: Form(
                  key: _formKey,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      DropdownButtonFormField<String>(
                        initialValue: _provider,
                        decoration: const InputDecoration(
                          labelText: 'Provider',
                          border: OutlineInputBorder(),
                        ),
                        items: const [
                          DropdownMenuItem(
                            value: 'wppconnect',
                            child: Text('WhatsApp Web (QR)'),
                          ),
                          DropdownMenuItem(
                            value: 'meta_cloud',
                            child: Text('Meta Cloud API'),
                          ),
                        ],
                        validator: (value) => value == null || value.isEmpty
                            ? 'Pilih provider.'
                            : null,
                        onChanged: _isSubmitting
                            ? null
                            : (value) => setState(() => _provider = value),
                      ),
                      const SizedBox(height: 14),
                      TextFormField(
                        controller: _displayNameController,
                        enabled: !_isSubmitting,
                        textCapitalization: TextCapitalization.words,
                        decoration: const InputDecoration(
                          labelText: 'Nama koneksi',
                          hintText: 'Contoh: WhatsApp Toko Utama',
                          border: OutlineInputBorder(),
                        ),
                        validator: _requiredValidator(
                          'Nama koneksi wajib diisi.',
                        ),
                      ),
                      if (_provider == 'meta_cloud') ...[
                        const SizedBox(height: 14),
                        TextFormField(
                          controller: _phoneNumberController,
                          enabled: !_isSubmitting,
                          keyboardType: TextInputType.phone,
                          decoration: const InputDecoration(
                            labelText: 'Nomor WhatsApp',
                            hintText: 'Contoh: +628123456789',
                            border: OutlineInputBorder(),
                          ),
                          validator: _requiredValidator(
                            'Nomor WhatsApp wajib diisi.',
                          ),
                        ),
                        const SizedBox(height: 14),
                        TextFormField(
                          controller: _phoneNumberIdController,
                          enabled: !_isSubmitting,
                          decoration: const InputDecoration(
                            labelText: 'Phone Number ID (opsional)',
                            border: OutlineInputBorder(),
                          ),
                        ),
                        const SizedBox(height: 14),
                        TextFormField(
                          controller: _businessAccountIdController,
                          enabled: !_isSubmitting,
                          decoration: const InputDecoration(
                            labelText: 'Business Account ID (opsional)',
                            border: OutlineInputBorder(),
                          ),
                        ),
                      ] else ...[
                        const SizedBox(height: 12),
                        Text(
                          'Nomor akan terdeteksi setelah pairing QR berhasil.',
                          style: textTheme.bodySmall?.copyWith(
                            color: colors.onSurfaceVariant,
                          ),
                        ),
                      ],
                      const SizedBox(height: 8),
                      CheckboxListTile(
                        contentPadding: EdgeInsets.zero,
                        value: _isPrimary,
                        onChanged: _isSubmitting
                            ? null
                            : (value) =>
                                  setState(() => _isPrimary = value ?? false),
                        title: const Text('Jadikan koneksi utama'),
                        subtitle: const Text(
                          'Koneksi ini akan menjadi nomor utama organisasi.',
                        ),
                        controlAffinity: ListTileControlAffinity.leading,
                      ),
                      if (_errorMessage != null) ...[
                        const SizedBox(height: 8),
                        Container(
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(
                            color: colors.errorContainer,
                            borderRadius: BorderRadius.circular(12),
                          ),
                          child: Text(
                            _errorMessage!,
                            style: textTheme.bodyMedium?.copyWith(
                              color: colors.onErrorContainer,
                            ),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: _isSubmitting
                        ? null
                        : () => Navigator.of(context).pop(),
                    child: const Text('Batal'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: FilledButton.icon(
                    onPressed: _isSubmitting ? null : _submit,
                    icon: _isSubmitting
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.add_rounded),
                    label: Text(_isSubmitting ? 'Menyimpan...' : 'Simpan'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  String? Function(String?) _requiredValidator(String message) => (value) {
    if (value == null || value.trim().isEmpty) return message;
    return null;
  };
}
