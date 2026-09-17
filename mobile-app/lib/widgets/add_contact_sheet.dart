import 'package:flutter/material.dart';
import 'package:flutter_contacts/flutter_contacts.dart';
import 'package:google_fonts/google_fonts.dart';

import '../services/api_client.dart';
import '../services/auth_service.dart';
import '../services/contact_service.dart';
import '../services/network_prefixes.dart';
import '../theme/app_colors.dart';

/// Bottom sheet pour ajouter un contact réel (POST /users/me/contacts).
/// Retourne le contact créé, ou null si annulé/échec.
Future<FripayContact?> showAddContactSheet(BuildContext context) {
  return showModalBottomSheet<FripayContact>(
    context: context,
    isScrollControlled: true,
    builder: (_) => const _AddContactSheet(),
  );
}

class _AddContactSheet extends StatefulWidget {
  const _AddContactSheet();

  @override
  State<_AddContactSheet> createState() => _AddContactSheetState();
}

class _AddContactSheetState extends State<_AddContactSheet> {
  final _nameCtrl = TextEditingController();
  final _phoneCtrl = TextEditingController();
  bool _saving = false;
  bool _picking = false;
  String? _error;

  @override
  void dispose() {
    _nameCtrl.dispose();
    _phoneCtrl.dispose();
    super.dispose();
  }

  Future<void> _pickFromContacts() async {
    setState(() {
      _picking = true;
      _error = null;
    });
    try {
      final granted = await FlutterContacts.requestPermission(readonly: true);
      if (!granted) {
        setState(() {
          _picking = false;
          _error = "Autorisation d'accès aux contacts refusée.";
        });
        return;
      }
      final contact = await FlutterContacts.openExternalPick();
      if (contact == null) {
        setState(() => _picking = false);
        return;
      }
      final phones = contact.phones;
      if (phones.isEmpty) {
        setState(() {
          _picking = false;
          _error = "Ce contact n'a pas de numéro de téléphone.";
        });
        return;
      }
      setState(() {
        _nameCtrl.text = contact.displayName;
        _phoneCtrl.text = phones.first.number.replaceAll(RegExp(r'[\s-]'), '');
        _picking = false;
      });
    } catch (_) {
      setState(() {
        _picking = false;
        _error = "Impossible d'accéder au répertoire pour le moment.";
      });
    }
  }

  Future<void> _save() async {
    final name = _nameCtrl.text.trim();
    final phone = _phoneCtrl.text.trim();
    if (name.isEmpty || phone.isEmpty) {
      setState(() => _error = 'Nom et numéro requis.');
      return;
    }

    // Validation stricte du numéro (cahier §2 & §5)
    final phoneErr = NetworkPrefixes.validationError(phone);
    if (phoneErr != null) {
      setState(() => _error = phoneErr);
      return;
    }

    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final contact = await ContactService.instance.add(
        name: name,
        phone: AuthService.normalizePhone(phone),
      );
      if (mounted) Navigator.of(context).pop(contact);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = e.userMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = "Impossible d'ajouter ce contact pour le moment.";
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      child: Padding(
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
                const Icon(Icons.person_add_alt_1_rounded, color: AppColors.primary, size: 20),
                const SizedBox(width: 8),
                Text('Ajouter un contact', style: GoogleFonts.sora(fontSize: 17, fontWeight: FontWeight.w700)),
              ],
            ),
            const SizedBox(height: 6),
            const Text('Il sera proposé comme raccourci pour vos prochains envois.',
                style: TextStyle(color: AppColors.mutedForeground, fontSize: 13, height: 1.4)),
            const SizedBox(height: 14),
            OutlinedButton.icon(
              onPressed: (_saving || _picking) ? null : _pickFromContacts,
              icon: _picking
                  ? const SizedBox(
                      width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.contacts_rounded, size: 18),
              label: const Text('Choisir dans mes contacts'),
            ),
            const SizedBox(height: 14),
            Row(
              children: [
                const Expanded(child: Divider()),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 10),
                  child: Text('ou saisir manuellement',
                      style: const TextStyle(color: AppColors.mutedForeground, fontSize: 12)),
                ),
                const Expanded(child: Divider()),
              ],
            ),
            const SizedBox(height: 14),
            const Text('Nom', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
            const SizedBox(height: 6),
            TextField(
              controller: _nameCtrl,
              autofocus: true,
              enabled: !_saving,
              decoration: const InputDecoration(hintText: 'Ex. Aïcha'),
            ),
            const SizedBox(height: 14),
            const Text('Numéro', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
            const SizedBox(height: 6),
            TextField(
              controller: _phoneCtrl,
              enabled: !_saving,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(hintText: '01 97 00 00 00'),
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
                  : const Text('Ajouter'),
            ),
          ],
        ),
      ),
    );
  }
}
