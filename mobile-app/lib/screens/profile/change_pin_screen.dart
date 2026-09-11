import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../services/api_client.dart';
import '../../services/auth_service.dart';
import '../../theme/app_colors.dart';
import '../../widgets/otp_input_row.dart';

enum _Step { current, next, confirm }

/// Modification du code PIN à 5 chiffres — capture l'ancien code (vérifié
/// côté serveur), demande le nouveau deux fois, puis l'enregistre via
/// POST /users/me/pin (AuthService.setPin).
class ChangePinScreen extends StatefulWidget {
  const ChangePinScreen({super.key});

  @override
  State<ChangePinScreen> createState() => _ChangePinScreenState();
}

class _ChangePinScreenState extends State<ChangePinScreen> {
  _Step _step = _Step.current;
  final _key = GlobalKey<OtpInputRowState>();
  String? _currentPin;
  String? _newPin;
  bool _error = false;
  String? _errorMessage;
  bool _busy = false;

  String get _title {
    switch (_step) {
      case _Step.current:
        return 'Entrez votre code PIN actuel';
      case _Step.next:
        return 'Choisissez un nouveau code PIN';
      case _Step.confirm:
        return 'Confirmez le nouveau code PIN';
    }
  }

  Future<void> _onCompleted(String v) async {
    if (_step == _Step.current) {
      // On capture le PIN actuel sans le vérifier localement — c'est le
      // backend qui validera lors de l'appel POST /users/me/pin.
      _currentPin = v;
      setState(() {
        _step = _Step.next;
        _error = false;
      });
      _key.currentState?.clear();
      return;
    }

    if (_step == _Step.next) {
      _newPin = v;
      setState(() {
        _step = _Step.confirm;
        _error = false;
      });
      _key.currentState?.clear();
      return;
    }

    // _Step.confirm
    if (v != _newPin) {
      setState(() {
        _error = true;
        _errorMessage = null;
        _step = _Step.next;
        _newPin = null;
      });
      _key.currentState?.clear();
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Les deux codes ne correspondent pas. Recommencez.')),
      );
      return;
    }

    setState(() {
      _busy = true;
      _error = false;
      _errorMessage = null;
    });
    try {
      await AuthService.instance.setPin(newPin: v, currentPin: _currentPin);
      if (!mounted) return;
      setState(() => _busy = false);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Code PIN mis à jour avec succès.')),
      );
      Navigator.of(context).pop();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = true;
        _errorMessage = e.userMessage;
        // On repart du PIN actuel : soit currentPin était faux, soit le
        // nouveau PIN a été rejeté (ex. règle de complexité côté serveur).
        _step = _Step.current;
        _currentPin = null;
        _newPin = null;
      });
      _key.currentState?.clear();
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = true;
        _errorMessage = 'Une erreur est survenue. Réessayez.';
        _step = _Step.current;
        _currentPin = null;
        _newPin = null;
      });
      _key.currentState?.clear();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Modifier mon code PIN')),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(24, 20, 24, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // Indicateur d'étape (1/2/3)
              Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: List.generate(3, (i) {
                  final active = i <= _Step.values.indexOf(_step);
                  return Container(
                    width: 26,
                    height: 4,
                    margin: const EdgeInsets.symmetric(horizontal: 3),
                    decoration: BoxDecoration(
                      color: active ? AppColors.primary : AppColors.border,
                      borderRadius: BorderRadius.circular(2),
                    ),
                  );
                }),
              ),
              const SizedBox(height: 28),
              Icon(Icons.lock_reset_rounded, size: 40, color: AppColors.primary),
              const SizedBox(height: 14),
              Text(_title, textAlign: TextAlign.center, style: GoogleFonts.sora(fontSize: 17, fontWeight: FontWeight.w800)),
              const SizedBox(height: 6),
              const Text(
                'Code à 5 chiffres',
                textAlign: TextAlign.center,
                style: TextStyle(color: AppColors.mutedForeground, fontSize: 12.5),
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
              const Spacer(),
              const Text(
                'Ne partagez jamais votre code PIN, même avec le support FriPay.',
                textAlign: TextAlign.center,
                style: TextStyle(color: AppColors.mutedForeground, fontSize: 11.5),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
