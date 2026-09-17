import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../services/api_client.dart';
import '../../services/auth_service.dart';
import '../../services/network_prefixes.dart';
import '../../services/token_storage.dart';
import '../../theme/app_colors.dart';
import '../../widgets/otp_input_row.dart';
import '../../widgets/app_scaffold.dart';
import 'login_screen.dart';

/// Écran de déblocage par code PIN — utilisé en secours quand la
/// biométrie échoue/est annulée, ou quand l'utilisateur préfère taper
/// son code. Le numéro est déjà connu (session existante) : on ne
/// redemande que le PIN, puis on rappelle /auth/login pour ré-émettre
/// des tokens frais.
class PinUnlockScreen extends StatefulWidget {
  const PinUnlockScreen({super.key});

  @override
  State<PinUnlockScreen> createState() => _PinUnlockScreenState();
}

class _PinUnlockScreenState extends State<PinUnlockScreen> {
  final _key = GlobalKey<OtpInputRowState>();
  String? _phone;
  bool _error = false;
  String? _errorMessage;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    TokenStorage.instance.phoneNumber.then((p) {
      if (mounted) setState(() => _phone = p);
    });
  }

  Future<void> _onCompleted(String pin) async {
    final phone = _phone;
    if (phone == null) return;
    setState(() {
      _busy = true;
      _error = false;
      _errorMessage = null;
    });
    try {
      await AuthService.instance.login(phoneNumber: phone, pin: pin);
      if (!mounted) return;
      Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute(builder: (_) => const AppScaffold()),
        (route) => false,
      );
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = true;
        _errorMessage = e.userMessage;
      });
      _key.currentState?.clear();
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = true;
        _errorMessage = 'Une erreur est survenue. Réessayez.';
      });
      _key.currentState?.clear();
    }
  }

  void _logoutInstead() {
    AuthService.instance.logout().then((_) {
      if (!mounted) return;
      Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute(builder: (_) => const LoginScreen()),
        (route) => false,
      );
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 40, 24, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Icon(Icons.lock_rounded, size: 42, color: AppColors.primary),
              const SizedBox(height: 14),
              Text('Entrez votre code PIN', textAlign: TextAlign.center,
                  style: GoogleFonts.sora(fontSize: 18, fontWeight: FontWeight.w800)),
              const SizedBox(height: 6),
              Text(
                _phone != null ? NetworkPrefixes.format(_phone!) : '',
                textAlign: TextAlign.center,
                style: const TextStyle(color: AppColors.mutedForeground, fontSize: 12.5),
              ),
              const SizedBox(height: 26),
              OtpInputRow(
                key: _key,
                length: 5,
                onChanged: (_) => setState(() {
                  _error = false;
                  _errorMessage = null;
                }),
                onCompleted: _busy ? null : _onCompleted,
              ),
              if (_error) ...[
                const SizedBox(height: 10),
                Text(_errorMessage ?? 'Code incorrect. Réessayez.',
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: AppColors.destructive, fontSize: 12.5, fontWeight: FontWeight.w600)),
              ],
              if (_busy) ...[
                const SizedBox(height: 18),
                const Center(child: CircularProgressIndicator(strokeWidth: 2.4)),
              ],
              const SizedBox(height: 60),
              TextButton(
                onPressed: _logoutInstead,
                child: const Text('Se déconnecter et utiliser un autre compte'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
