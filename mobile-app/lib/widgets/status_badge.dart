import 'package:flutter/material.dart';

import '../models/models.dart';
import '../theme/app_colors.dart';

/// Portage des badges de statut (bg-success/12, bg-accent/15, ...) utilisés
/// dans app.index.tsx et app.historique.tsx.
class StatusBadge extends StatelessWidget {
  final TxStatus status;

  const StatusBadge({super.key, required this.status});

  (Color, Color, String) _style() {
    switch (status) {
      case TxStatus.reussi:
        return (AppColors.success.withValues(alpha: 0.14), AppColors.success, 'Réussi');
      case TxStatus.enAttente:
        return (AppColors.accent.withValues(alpha: 0.16), AppColors.accentForeground, 'En attente');
      case TxStatus.echoue:
        return (AppColors.destructive.withValues(alpha: 0.12), AppColors.destructive, 'Échoué');
      case TxStatus.horsLigne:
        return (AppColors.muted, AppColors.mutedForeground, 'Hors ligne');
    }
  }

  @override
  Widget build(BuildContext context) {
    final (bg, fg, label) = _style();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(999)),
      child: Text(
        label,
        style: TextStyle(color: fg, fontSize: 11.5, fontWeight: FontWeight.w700),
      ),
    );
  }
}

class TicketStatusBadge extends StatelessWidget {
  final TicketStatus status;
  const TicketStatusBadge({super.key, required this.status});

  (Color, Color) _style() {
    switch (status) {
      case TicketStatus.open:
        return (AppColors.muted, AppColors.mutedForeground);
      case TicketStatus.inProgress:
        return (AppColors.accent.withValues(alpha: 0.16), AppColors.accentForeground);
      case TicketStatus.resolved:
        return (AppColors.success.withValues(alpha: 0.14), AppColors.success);
      case TicketStatus.rejected:
        return (AppColors.destructive.withValues(alpha: 0.12), AppColors.destructive);
    }
  }

  @override
  Widget build(BuildContext context) {
    final (bg, fg) = _style();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(999)),
      child: Text(
        statusLabel[status]!,
        style: TextStyle(color: fg, fontSize: 11.5, fontWeight: FontWeight.w700),
      ),
    );
  }
}
