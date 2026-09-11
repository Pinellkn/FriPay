import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../services/api_client.dart';
import '../services/contact_service.dart';
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
  String? _error;

  @override
  void dispose() {
    _nameCtrl.dispose();
    _phoneCtrl.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final name = _nameCtrl.text.trim();
    final phone = _phoneCtrl.text.trim();
    if (name.isEmpty || phone.isEmpty) {
      setState(() => _error = 'Nom et numéro requis.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final contact = await ContactService.instance.add(name: name, phone: phone);
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
              const Icon(Icons.person_add_alt_1_rounded, color: AppColors.primary, size: 20),
              const SizedBox(width: 8),
              Text('Ajouter un contact', style: GoogleFonts.sora(fontSize: 17, fontWeight: FontWeight.w700)),
            ],
          ),
          const SizedBox(height: 6),
          const Text('Il sera proposé comme raccourci pour vos prochains envois.',
              style: TextStyle(color: AppColors.mutedForeground, fontSize: 13, height: 1.4)),
          const SizedBox(height: 18),
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
            decoration: const InputDecoration(hintText: '+229 95 00 00 00'),
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
    );
  }
}
