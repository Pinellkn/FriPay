import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';

import '../theme/app_colors.dart';

/// Portage de components/fripay/PinConfirmDialog.tsx : confirmation par
/// code PIN à 4 chiffres, dernière étape avant tout transfert/paiement.
/// Le PIN ne vit que dans l'état local de ce widget.
Future<void> showPinConfirmSheet({
  required BuildContext context,
  required String title,
  required String description,
  required Future<String?> Function(String pin) onConfirm,
}) {
  return showModalBottomSheet(
    context: context,
    isScrollControlled: true,
    builder: (ctx) => _PinConfirmSheet(title: title, description: description, onConfirm: onConfirm),
  );
}

class _PinConfirmSheet extends StatefulWidget {
  final String title;
  final String description;
  final Future<String?> Function(String pin) onConfirm;

  const _PinConfirmSheet({required this.title, required this.description, required this.onConfirm});

  @override
  State<_PinConfirmSheet> createState() => _PinConfirmSheetState();
}

class _PinConfirmSheetState extends State<_PinConfirmSheet> {
  static const _pinLength = 5;
  final _controllers = List.generate(_pinLength, (_) => TextEditingController());
  final _nodes = List.generate(_pinLength, (_) => FocusNode());
  bool _submitting = false;
  String? _error;

  String get _pin => _controllers.map((c) => c.text).join();

  Future<void> _submit() async {
    if (_pin.length != _pinLength) return;
    setState(() {
      _submitting = true;
      _error = null;
    });
    final err = await widget.onConfirm(_pin);
    if (!mounted) return;
    if (err == null) {
      Navigator.of(context).pop();
    } else {
      setState(() {
        _submitting = false;
        _error = err;
        for (final c in _controllers) {
          c.clear();
        }
      });
      _nodes.first.requestFocus();
    }
  }

  @override
  void dispose() {
    for (final c in _controllers) {
      c.dispose();
    }
    for (final n in _nodes) {
      n.dispose();
    }
    super.dispose();
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
              const Icon(Icons.verified_user_rounded, color: AppColors.primary, size: 20),
              const SizedBox(width: 8),
              Text(
                widget.title,
                style: GoogleFonts.sora(fontSize: 17, fontWeight: FontWeight.w700),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(widget.description, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 13, height: 1.4)),
          const SizedBox(height: 22),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: List.generate(_pinLength, (i) {
              return Padding(
                padding: const EdgeInsets.symmetric(horizontal: 5),
                child: SizedBox(
                  width: 46,
                  height: 58,
                  child: TextField(
                    controller: _controllers[i],
                    focusNode: _nodes[i],
                    autofocus: i == 0,
                    enabled: !_submitting,
                    textAlign: TextAlign.center,
                    keyboardType: TextInputType.number,
                    obscureText: true,
                    maxLength: 1,
                    style: GoogleFonts.sora(fontSize: 22, fontWeight: FontWeight.w700),
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    decoration: InputDecoration(
                      counterText: '',
                      filled: true,
                      fillColor: AppColors.muted,
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(14),
                        borderSide: BorderSide(color: AppColors.border),
                      ),
                    ),
                    onChanged: (v) {
                      if (v.isNotEmpty && i < _pinLength - 1) _nodes[i + 1].requestFocus();
                      if (v.isEmpty && i > 0) _nodes[i - 1].requestFocus();
                      if (_pin.length == _pinLength) _submit();
                      setState(() {});
                    },
                  ),
                ),
              );
            }),
          ),
          if (_error != null) ...[
            const SizedBox(height: 10),
            Center(
              child: Text(_error!, style: const TextStyle(color: AppColors.destructive, fontSize: 12.5)),
            ),
          ],
          const SizedBox(height: 22),
          ElevatedButton(
            onPressed: _pin.length == _pinLength && !_submitting ? _submit : null,
            child: _submitting
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                  )
                : const Text('Confirmer avec mon code PIN'),
          ),
        ],
      ),
    );
  }
}
