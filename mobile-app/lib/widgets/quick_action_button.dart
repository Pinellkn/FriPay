import 'package:flutter/material.dart';

import '../theme/app_colors.dart';

/// Bouton d'action rapide en grille (Envoyer / Recevoir / Recharge / Factures...)
/// — portage du bloc `quick` de app.index.tsx.
///
/// Enveloppé dans un [FittedBox] : sur les très petits écrans, ou quand
/// l'utilisateur a une échelle de police système élevée (accessibilité),
/// le contenu se réduit proportionnellement au lieu de déborder la cellule
/// de la grille (évite les "RenderFlex overflowed").
class QuickActionButton extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final Color? color;

  const QuickActionButton({
    super.key,
    required this.icon,
    required this.label,
    required this.onTap,
    this.color,
  });

  @override
  Widget build(BuildContext context) {
    final tint = color ?? AppColors.primary;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(20),
      child: FittedBox(
        fit: BoxFit.scaleDown,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 56,
              height: 56,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: tint.withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(18),
              ),
              child: Icon(icon, color: tint, size: 24),
            ),
            const SizedBox(height: 8),
            ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 76),
              child: Text(
                label,
                textAlign: TextAlign.center,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppColors.foreground),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
