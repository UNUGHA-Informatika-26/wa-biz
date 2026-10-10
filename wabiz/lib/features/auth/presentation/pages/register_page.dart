import 'package:flutter/material.dart';

import '../widgets/auth_header.dart';
import 'login_page.dart';

class RegisterPage extends StatefulWidget {
  const RegisterPage({super.key});

  @override
  State<RegisterPage> createState() => _RegisterPageState();
}

class _RegisterPageState extends State<RegisterPage> {
  final GlobalKey<FormState> _formKey = GlobalKey<FormState>();
  final TextEditingController _passwordController = TextEditingController();
  bool _obscurePassword = true;
  bool _obscureConfirmation = true;
  String? _notice;

  @override
  void dispose() {
    _passwordController.dispose();
    super.dispose();
  }

  String? _required(String? value, String label) {
    if (value == null || value.trim().isEmpty) return '$label wajib diisi.';
    return null;
  }

  String? _validateEmail(String? value) {
    final String email = value?.trim() ?? '';
    if (email.isEmpty) return 'Email wajib diisi.';
    if (!RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$').hasMatch(email)) {
      return 'Masukkan alamat email yang valid.';
    }
    return null;
  }

  String? _validatePhone(String? value) {
    final String? requiredError = _required(value, 'Nomor WhatsApp');
    if (requiredError != null) return requiredError;
    final String digits = value!.replaceAll(RegExp(r'\D'), '');
    if (digits.length < 8) return 'Masukkan nomor WhatsApp yang valid.';
    return null;
  }

  String? _validatePassword(String? value) {
    final String? requiredError = _required(value, 'Password');
    if (requiredError != null) return requiredError;
    if (value!.length < 8) return 'Password minimal 8 karakter.';
    return null;
  }

  void _clearNotice(String _) {
    if (_notice != null) setState(() => _notice = null);
  }

  void _submit() {
    FocusScope.of(context).unfocus();
    if (!_formKey.currentState!.validate()) {
      setState(() => _notice = null);
      return;
    }
    setState(() {
      _notice = 'Data formulir valid. Pendaftaran belum tersambung ke layanan autentikasi.';
    });
  }

  void _openLogin() {
    Navigator.of(context).pushReplacement(
      MaterialPageRoute<void>(builder: (_) => const LoginPage()),
    );
  }

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;

    return Scaffold(
      backgroundColor: colors.surface,
      body: SafeArea(
        child: LayoutBuilder(
          builder: (context, constraints) {
            final double horizontalPadding = constraints.maxWidth < 380
                ? 22
                : 32;
            return SingleChildScrollView(
              padding: const EdgeInsets.symmetric(vertical: 28),
              child: Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 480),
                  child: Padding(
                    padding: EdgeInsets.symmetric(
                      horizontal: horizontalPadding,
                    ),
                    child: Form(
                      key: _formKey,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          AuthHeader(
                            title: 'Buat akun WABiz',
                            description: 'Mulai kelola komunikasi bisnis Anda dengan lebih teratur.',
                          ),
                          const SizedBox(height: 26),
                          TextFormField(
                            textCapitalization: TextCapitalization.words,
                            textInputAction: TextInputAction.next,
                            decoration: const InputDecoration(
                              labelText: 'Nama bisnis',
                              prefixIcon: Icon(Icons.storefront_outlined),
                            ),
                            validator: (value) =>
                                _required(value, 'Nama bisnis'),
                            onChanged: _clearNotice,
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            textCapitalization: TextCapitalization.words,
                            textInputAction: TextInputAction.next,
                            autofillHints: const [AutofillHints.name],
                            decoration: const InputDecoration(
                              labelText: 'Nama lengkap',
                              prefixIcon: Icon(Icons.person_outline_rounded),
                            ),
                            validator: (value) =>
                                _required(value, 'Nama lengkap'),
                            onChanged: _clearNotice,
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            keyboardType: TextInputType.emailAddress,
                            textInputAction: TextInputAction.next,
                            autofillHints: const [AutofillHints.email],
                            decoration: const InputDecoration(
                              labelText: 'Email',
                              prefixIcon: Icon(Icons.mail_outline_rounded),
                            ),
                            validator: _validateEmail,
                            onChanged: _clearNotice,
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            keyboardType: TextInputType.phone,
                            textInputAction: TextInputAction.next,
                            autofillHints: const [
                              AutofillHints.telephoneNumber,
                            ],
                            decoration: const InputDecoration(
                              labelText: 'Nomor WhatsApp',
                              hintText: 'Contoh: 0812 3456 7890',
                              prefixIcon: Icon(Icons.phone_outlined),
                            ),
                            validator: _validatePhone,
                            onChanged: _clearNotice,
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            controller: _passwordController,
                            obscureText: _obscurePassword,
                            textInputAction: TextInputAction.next,
                            autofillHints: const [AutofillHints.newPassword],
                            decoration: InputDecoration(
                              labelText: 'Password',
                              helperText: 'Gunakan minimal 8 karakter.',
                              prefixIcon: const Icon(
                                Icons.lock_outline_rounded,
                              ),
                              suffixIcon: IconButton(
                                tooltip: _obscurePassword
                                    ? 'Tampilkan password'
                                    : 'Sembunyikan password',
                                onPressed: () => setState(
                                  () => _obscurePassword = !_obscurePassword,
                                ),
                                icon: Icon(
                                  _obscurePassword
                                      ? Icons.visibility_outlined
                                      : Icons.visibility_off_outlined,
                                ),
                              ),
                            ),
                            validator: _validatePassword,
                            onChanged: _clearNotice,
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            obscureText: _obscureConfirmation,
                            textInputAction: TextInputAction.done,
                            autofillHints: const [AutofillHints.newPassword],
                            onFieldSubmitted: (_) => _submit(),
                            decoration: InputDecoration(
                              labelText: 'Konfirmasi password',
                              prefixIcon: const Icon(
                                Icons.lock_outline_rounded,
                              ),
                              suffixIcon: IconButton(
                                tooltip: _obscureConfirmation
                                    ? 'Tampilkan password'
                                    : 'Sembunyikan password',
                                onPressed: () => setState(
                                  () => _obscureConfirmation =
                                      !_obscureConfirmation,
                                ),
                                icon: Icon(
                                  _obscureConfirmation
                                      ? Icons.visibility_outlined
                                      : Icons.visibility_off_outlined,
                                ),
                              ),
                            ),
                            validator: (value) {
                              final String? requiredError = _required(
                                value,
                                'Konfirmasi password',
                              );
                              if (requiredError != null) return requiredError;
                              if (value != _passwordController.text) {
                                return 'Konfirmasi password belum sama.';
                              }
                              return null;
                            },
                            onChanged: _clearNotice,
                          ),
                          const SizedBox(height: 22),
                          FilledButton(
                            onPressed: _submit,
                            child: const Text('Daftar'),
                          ),
                          if (_notice != null) ...[
                            const SizedBox(height: 16),
                            _RegisterNotice(message: _notice!),
                          ],
                          const SizedBox(height: 20),
                          Center(
                            child: Wrap(
                              alignment: WrapAlignment.center,
                              crossAxisAlignment: WrapCrossAlignment.center,
                              children: [
                                Text(
                                  'Sudah punya akun?',
                                  style: Theme.of(context).textTheme.bodyMedium
                                      ?.copyWith(
                                        color: colors.onSurfaceVariant,
                                      ),
                                ),
                                TextButton(
                                  onPressed: _openLogin,
                                  child: const Text('Masuk'),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            );
          },
        ),
      ),
    );
  }
}

class _RegisterNotice extends StatelessWidget {
  const _RegisterNotice({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: colors.primaryContainer,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Text(
        message,
        style: Theme.of(context).textTheme.bodySmall
            ?.copyWith(color: colors.onPrimaryContainer, height: 1.45),
      ),
    );
  }
}
