import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../theme/app_colors.dart';

/// Rangée de cases pour code OTP : avance automatique au chiffre suivant,
/// retour en arrière sur "Effacer", et collage du code entier en une fois
/// (ex. copié depuis le SMS) qui remplit toutes les cases d'un coup.
class OtpInputRow extends StatefulWidget {
  final int length;
  final ValueChanged<String> onChanged;
  final ValueChanged<String>? onCompleted;

  const OtpInputRow({
    super.key,
    this.length = 6,
    required this.onChanged,
    this.onCompleted,
  });

  @override
  State<OtpInputRow> createState() => OtpInputRowState();
}

class OtpInputRowState extends State<OtpInputRow> {
  late final List<TextEditingController> _controllers =
      List.generate(widget.length, (_) => TextEditingController());
  late final List<FocusNode> _nodes = List.generate(widget.length, (_) => FocusNode());

  String get value => _controllers.map((c) => c.text).join();

  void clear() {
    for (final c in _controllers) {
      c.clear();
    }
    _nodes.first.requestFocus();
    widget.onChanged('');
  }

  /// Remplit toutes les cases à partir d'une chaîne (collage presse-papiers).
  void fill(String digits) => _fill(digits);

  void _fill(String digits) {
    final clean = digits.replaceAll(RegExp(r'[^0-9]'), '');
    if (clean.isEmpty) return;
    // Enlève d'abord le focus/clavier : sinon un évènement IME en attente
    // peut écraser une partie des chiffres qu'on vient de poser juste après
    // (c'est ce qui causait un mélange de l'ordre au collage).
    FocusManager.instance.primaryFocus?.unfocus();
    for (var i = 0; i < widget.length; i++) {
      _controllers[i].text = i < clean.length ? clean[i] : '';
    }
    final lastIndex = clean.length >= widget.length ? widget.length - 1 : clean.length;
    setState(() {});
    widget.onChanged(value);
    if (clean.length >= widget.length) {
      widget.onCompleted?.call(value);
    } else {
      // Redonne le focus après la frame suivante, une fois l'ancien
      // évènement clavier dissipé.
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _nodes[lastIndex.clamp(0, widget.length - 1)].requestFocus();
      });
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
    return Row(
      children: List.generate(widget.length, (i) {
        return Expanded(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 4),
            child: KeyboardListener(
              focusNode: FocusNode(skipTraversal: true),
              onKeyEvent: (event) {
                if (event is KeyDownEvent &&
                    event.logicalKey == LogicalKeyboardKey.backspace &&
                    _controllers[i].text.isEmpty &&
                    i > 0) {
                  _controllers[i - 1].clear();
                  _nodes[i - 1].requestFocus();
                  widget.onChanged(value);
                  setState(() {});
                }
              },
              child: TextField(
                controller: _controllers[i],
                focusNode: _nodes[i],
                textAlign: TextAlign.center,
                keyboardType: TextInputType.number,
                maxLength: widget.length, // permet de coller le code entier dans une case
                style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800),
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                decoration: InputDecoration(
                  counterText: '',
                  contentPadding: const EdgeInsets.symmetric(vertical: 14),
                  filled: true,
                  fillColor: AppColors.muted,
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: const BorderSide(color: AppColors.border),
                  ),
                  focusedBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: const BorderSide(color: AppColors.primary, width: 1.6),
                  ),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                ),
                onChanged: (v) {
                  // Collage d'un code entier dans une seule case (Ctrl+V mobile) :
                  // le nombre de caractères dépasse 1 -> on distribue sur toutes les cases.
                  if (v.length > 1) {
                    _fill(v);
                    return;
                  }
                  if (v.isNotEmpty && i < widget.length - 1) {
                    _nodes[i + 1].requestFocus();
                  }
                  if (v.isEmpty && i > 0) {
                    // laissé vide volontairement : la gestion "backspace" est faite par RawKeyboardListener
                  }
                  widget.onChanged(value);
                  if (value.length == widget.length) {
                    FocusScope.of(context).unfocus();
                    widget.onCompleted?.call(value);
                  }
                },
                onTap: () {
                  _controllers[i].selection = TextSelection(
                    baseOffset: 0,
                    extentOffset: _controllers[i].text.length,
                  );
                },
              ),
            ),
          ),
        );
      }),
    );
  }
}
