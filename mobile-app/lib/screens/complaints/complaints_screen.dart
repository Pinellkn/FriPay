import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../models/models.dart';
import '../../services/api_client.dart';
import '../../services/complaint_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/section_header.dart';
import '../../widgets/status_badge.dart';
import '../../widgets/fripay_refresh.dart';
import 'new_ticket_screen.dart';
import 'ticket_detail_screen.dart';

/// Portage de src/routes/app.plaintes.tsx — branché sur GET/POST
/// /complaints (fripay-payments) au lieu du mock.
class ComplaintsScreen extends StatefulWidget {
  const ComplaintsScreen({super.key});

  @override
  State<ComplaintsScreen> createState() => _ComplaintsScreenState();
}

class _ComplaintsScreenState extends State<ComplaintsScreen> {
  List<Ticket>? _tickets;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final complaints = await ComplaintService.instance.list();
      if (!mounted) return;
      setState(() {
        _tickets = complaints.map((c) => c.toTicket()).toList();
        _error = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e.userMessage);
    } catch (e) {
      if (!mounted) return;
      // Toute erreur non convertie en ApiException (ex. parsing) atterrit
      // ici — on affiche désormais e.toString() en plus du message
      // générique pour ne plus jamais perdre la cause réelle en debug.
      assert(() {
        debugPrint('ComplaintsScreen._load unexpected error: $e');
        return true;
      }());
      setState(() => _error = 'Une erreur est survenue. Tirez pour réessayer.');
    }
  }

  Future<void> _openNewTicket() async {
    final created = await Navigator.of(context).push<Ticket>(
      MaterialPageRoute(builder: (_) => const NewTicketScreen()),
    );
    if (created != null) {
      setState(() => _tickets = [created, ...(_tickets ?? [])]);
    }
  }

  @override
  Widget build(BuildContext context) {
    final tickets = _tickets;
    return Scaffold(
      appBar: AppBar(title: const Text('Plaintes')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _openNewTicket,
        backgroundColor: AppColors.primary,
        foregroundColor: AppColors.primaryForeground,
        icon: const Icon(Icons.add_rounded),
        label: const Text('Nouvelle plainte'),
      ),
      body: SafeArea(
        child: FripayRefresh(
          onRefresh: _load,
          child: tickets == null && _error == null
              ? const Center(child: CircularProgressIndicator())
              : ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.fromLTRB(18, 4, 18, 96),
                  children: [
                    const SectionHeader(
                      title: 'Vos plaintes',
                      subtitle:
                          'Signalez un problème sur une transaction : transfert erroné, non reçu, débit en double ou souci de compte.',
                    ),
                    if (_error != null)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 12),
                        child: Text(_error!, style: const TextStyle(color: AppColors.destructive, fontSize: 12.5)),
                      )
                    else if ((tickets ?? []).isEmpty)
                      _EmptyState(onCreate: _openNewTicket)
                    else
                      ...tickets!.map((t) => _TicketTile(
                            ticket: t,
                            onTap: () => Navigator.of(context).push(
                              MaterialPageRoute(builder: (_) => TicketDetailScreen(ticket: t)),
                            ),
                          )),
                  ],
                ),
        ),
      ),
    );
  }
}

class _TicketTile extends StatelessWidget {
  final Ticket ticket;
  final VoidCallback onTap;
  const _TicketTile({required this.ticket, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      child: Material(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(18),
        child: InkWell(
          borderRadius: BorderRadius.circular(18),
          onTap: onTap,
          child: Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: AppColors.border.withValues(alpha: 0.7)),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        ticket.subject,
                        style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    const SizedBox(width: 8),
                    TicketStatusBadge(status: ticket.status),
                  ],
                ),
                const SizedBox(height: 8),
                Text(
                  ticket.description,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(color: AppColors.mutedForeground, fontSize: 12.5, height: 1.4),
                ),
                const SizedBox(height: 10),
                Row(
                  children: [
                    Icon(Icons.confirmation_number_rounded, size: 13, color: AppColors.mutedForeground),
                    const SizedBox(width: 5),
                    Text(ticket.reference, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 11.5)),
                    const Spacer(),
                    Text(ticket.createdAt, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 11.5)),
                  ],
                ),
                if (ticket.refundRequested) ...[
                  const SizedBox(height: 10),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    decoration: BoxDecoration(
                      color: AppColors.accent.withValues(alpha: 0.12),
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Icon(Icons.replay_rounded, size: 13, color: AppColors.accentForeground),
                        const SizedBox(width: 6),
                        Text(
                          '${refundStatusLabel[ticket.refundStatus]}'
                          '${ticket.refundAmount != null ? ' · ${formatFCFA(ticket.refundAmount!)}' : ''}',
                          style: const TextStyle(color: AppColors.accentForeground, fontSize: 11.5, fontWeight: FontWeight.w700),
                        ),
                      ],
                    ),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _EmptyState extends StatelessWidget {
  final VoidCallback onCreate;
  const _EmptyState({required this.onCreate});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 72,
              height: 72,
              alignment: Alignment.center,
              decoration: BoxDecoration(color: AppColors.muted, shape: BoxShape.circle),
              child: const Icon(Icons.task_alt_rounded, size: 34, color: AppColors.primary),
            ),
            const SizedBox(height: 18),
            Text('Aucune plainte', style: GoogleFonts.sora(fontSize: 17, fontWeight: FontWeight.w800)),
            const SizedBox(height: 8),
            const Text(
              'Vous n\'avez signalé aucun problème pour le moment. Tout va bien !',
              textAlign: TextAlign.center,
              style: TextStyle(color: AppColors.mutedForeground, fontSize: 13, height: 1.4),
            ),
            const SizedBox(height: 20),
            OutlinedButton(onPressed: onCreate, child: const Text('Signaler un problème')),
          ],
        ),
      ),
    );
  }
}
