import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../models/models.dart';
import '../../services/transfer_service.dart' as api;
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/status_badge.dart';

/// Détail d'une plainte : motif, description, transaction liée et statut
/// du remboursement — portage de la vue détail de app.plaintes.tsx.
/// La transaction liée (si présente) est chargée via GET /transfers/{id}
/// au lieu du mock.
class TicketDetailScreen extends StatefulWidget {
  final Ticket ticket;
  const TicketDetailScreen({super.key, required this.ticket});

  @override
  State<TicketDetailScreen> createState() => _TicketDetailScreenState();
}

class _TicketDetailScreenState extends State<TicketDetailScreen> {
  api.Transaction? _linkedTx;

  @override
  void initState() {
    super.initState();
    final id = widget.ticket.linkedTransactionId;
    if (id != null) {
      api.TransferService.instance.show(id).then((tx) {
        if (mounted) setState(() => _linkedTx = tx);
      }).catchError((_) {
        // Non bloquant : la ligne "Transaction liée" reste juste masquée.
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final ticket = widget.ticket;
    final reasonInfo = ticketReasons.firstWhere((r) => r.value == ticket.reason);
    final linkedTx = _linkedTx;

    return Scaffold(
      appBar: AppBar(title: Text(ticket.reference)),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    ticket.subject,
                    style: GoogleFonts.sora(fontSize: 19, fontWeight: FontWeight.w800, height: 1.25),
                  ),
                ),
                const SizedBox(width: 10),
                TicketStatusBadge(status: ticket.status),
              ],
            ),
            const SizedBox(height: 6),
            Text('Créée le ${ticket.createdAt}', style: const TextStyle(color: AppColors.mutedForeground, fontSize: 12.5)),
            const SizedBox(height: 20),
            _Card(
              children: [
                _Row(label: 'Motif', value: reasonInfo.label),
                _Row(label: 'Référence', value: ticket.reference),
                if (linkedTx != null)
                  _Row(
                    label: 'Transaction liée',
                    value: '${linkedTx.recipientName ?? linkedTx.recipientPhone} · ${formatFCFA(linkedTx.amount.round())}',
                  ),
              ],
            ),
            const SizedBox(height: 16),
            Text('Description', style: GoogleFonts.sora(fontSize: 14, fontWeight: FontWeight.w700)),
            const SizedBox(height: 8),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: AppColors.card,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: AppColors.border.withValues(alpha: 0.7)),
              ),
              child: Text(ticket.description, style: const TextStyle(fontSize: 13, height: 1.5)),
            ),
            if (ticket.refundRequested) ...[
              const SizedBox(height: 16),
              Text('Remboursement', style: GoogleFonts.sora(fontSize: 14, fontWeight: FontWeight.w700)),
              const SizedBox(height: 8),
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: AppColors.accent.withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: AppColors.accent.withValues(alpha: 0.3)),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.replay_rounded, color: AppColors.accentForeground, size: 20),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            refundStatusLabel[ticket.refundStatus] ?? '',
                            style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13, color: AppColors.accentForeground),
                          ),
                          if (ticket.refundAmount != null)
                            Text(
                              formatFCFA(ticket.refundAmount!),
                              style: const TextStyle(color: AppColors.accentForeground, fontSize: 12.5),
                            ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _Card extends StatelessWidget {
  final List<Widget> children;
  const _Card({required this.children});

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.border.withValues(alpha: 0.7)),
      ),
      child: Column(children: children),
    );
  }
}

class _Row extends StatelessWidget {
  final String label;
  final String value;
  const _Row({required this.label, required this.value});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: const BoxDecoration(border: Border(bottom: BorderSide(color: AppColors.border, width: 0.6))),
      child: Row(
        children: [
          Text(label, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 12.5)),
          const Spacer(),
          Flexible(
            child: Text(
              value,
              textAlign: TextAlign.end,
              style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5),
            ),
          ),
        ],
      ),
    );
  }
}
