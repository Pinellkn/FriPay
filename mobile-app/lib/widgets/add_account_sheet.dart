import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';

import '../services/account_service.dart';
import '../services/api_client.dart';
import '../services/auth_service.dart';
import '../theme/app_colors.dart';

/// Bottom sheet pour lier un compte mobile money réel
/// (POST /users/me/accounts) — l'opérateur (MTN / Moov / Celtiis) est
/// détecté automatiquement côté API à partir du numéro, aucune sélection
/// manuelle n'est nécessaire.
///
/// Retourne le compte créé, ou null si annulé/échec.
Future<LinkedAccount?> showAddAccountSheet(BuildContext context) {
  return showModalBottomSheet<LinkedAccount>(
    context: context,
    isScrollControlled: true,
    builder: (_) => const _AddAccountSheet(),
  );
}

class _AddAccountSheet extends StatefulWidget {
  const _AddAccountSheet();

  @override
  State<_AddAccountSheet> createState() => _AddAccountSheetState();
}

class _AddAccountSheetState extends State<_AddAccountSheet> {
  final _phoneCtrl = TextEditingController();
  bool _saving = false;
  String? _error;

  @override
  void dispose() {
    _phoneCtrl.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final raw = _phoneCtrl.text.trim();
    if (raw.isEmpty) {
      setState(() => _error = 'Numéro requis.');
      return;
    }
    final normalized = AuthService.normalizePhone(raw);
    // Même règle que côté API (LinkedAccountController::store) : +229 suivi
    // de 10 chiffres. On valide avant l'appel réseau pour un retour immédiat.
    if (!RegExp(r'^\+229\d{10}$').hasMatch(normalized)) {
      setState(() => _error = 'Numéro invalide. Format attendu : +229 01 XX XX XX XX.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final account = await AccountService.instance.linkAccount(normalized);
      if (mounted) Navigator.of(context).pop(account);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = e.userMessage.isNotEmpty ? e.userMessage : 'Impossible de lier ce compte pour le moment.';
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = 'Impossible de lier ce compte pour le moment.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        left: 24,
        right: 24,
        top: 22,
        bottom: MediaQuery.of(context).viewInsets.bottom + 24,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Center(
            child: Container(
              width: 42,
              height: 4,
              margin: const EdgeInsets.only(bottom: 18),
              decoration: BoxDecoration(color: AppColors.border, borderRadius: BorderRadius.circular(4)),
            ),
          ),
          Row(
            children: [
              const Icon(Icons.add_card_rounded, color: AppColors.primary, size: 20),
              const SizedBox(width: 8),
              Text('Lier un compte mobile money', style: GoogleFonts.sora(fontSize: 17, fontWeight: FontWeight.w700)),
            ],
          ),
          const SizedBox(height: 6),
          const Text(
            "L'opérateur (MTN, Moov ou Celtiis) est détecté automatiquement "
            'à partir du numéro — aucune sélection nécessaire.',
            style: TextStyle(color: AppColors.mutedForeground, fontSize: 13, height: 1.4),
          ),
          const SizedBox(height: 18),
          const Text('Numéro à lier', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
          const SizedBox(height: 6),
          TextField(
            controller: _phoneCtrl,
            autofocus: true,
            enabled: !_saving,
            keyboardType: TextInputType.phone,
            inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9+ ]'))],
            decoration: const InputDecoration(hintText: '+229 01 XX XX XX XX'),
          ),
          if (_error != null) ...[
            const SizedBox(height: 10),
            Text(_error!, style: const TextStyle(color: AppColors.destructive, fontSize: 12.5, fontWeight: FontWeight.w600)),
          ],
          const SizedBox(height: 22),
          ElevatedButton(
            onPressed: _saving ? null : _save,
            child: _saving
                ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Text('Lier ce compte'),
          ),
        ],
      ),
    );
  }
}
