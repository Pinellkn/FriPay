import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../models/models.dart';
import '../../services/api_client.dart';
import '../../services/complaint_service.dart';
import '../../services/transfer_service.dart' as api;
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/section_header.dart';

/// Portage de src/routes/app.plaintes.nouveau.tsx — branché sur POST
/// /complaints, avec le choix de transaction liée alimenté par le vrai
/// historique (GET /transfers) au lieu du mock.
class NewTicketScreen extends StatefulWidget {
  const NewTicketScreen({super.key});

  @override
  State<NewTicketScreen> createState() => _NewTicketScreenState();
}

class _NewTicketScreenState extends State<NewTicketScreen> {
  TicketReason? _reason;
  String? _linkedTxId;
  final _subjectCtrl = TextEditingController();
  final _descCtrl = TextEditingController();
  bool _submitting = false;

  List<api.Transaction> _transactions = [];
  bool _loadingTx = true;

  TicketReasonInfo? get _reasonInfo =>
      _reason == null ? null : ticketReasons.firstWhere((r) => r.value == _reason);

  void _snack(String msg) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));

  @override
  void initState() {
    super.initState();
    _loadTransactions();
  }

  Future<void> _loadTransactions() async {
    try {
      final txs = await api.TransferService.instance.list();
      if (!mounted) return;
      setState(() {
        _transactions = txs;
        _loadingTx = false;
      });
    } catch (_) {
      // Non bloquant : le champ "transaction liée" reste juste vide/optionnel.
      if (!mounted) return;
      setState(() => _loadingTx = false);
    }
  }

  Future<void> _submit() async {
    if (_reason == null) return _snack('Choisissez un motif.');
    if (_subjectCtrl.text.trim().isEmpty) return _snack('Le sujet est requis.');
    if (_descCtrl.text.trim().isEmpty) return _snack('Décrivez le problème.');

    setState(() => _submitting = true);
    try {
      final complaint = await ComplaintService.instance.create(
        reason: reasonToApi(_reason!),
        subject: _subjectCtrl.text.trim(),
        description: _descCtrl.text.trim(),
        linkedTransactionId: _linkedTxId,
      );
      if (!mounted) return;
      Navigator.of(context).pop(complaint.toTicket());
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _submitting = false);
      _snack(e.userMessage);
    } catch (_) {
      if (!mounted) return;
      setState(() => _submitting = false);
      _snack('Une erreur est survenue. Réessayez.');
    }
  }

  @override
  Widget build(BuildContext context) {
    final eligible = _reasonInfo?.refundEligible ?? false;
    return Scaffold(
      appBar: AppBar(title: const Text('Nouvelle plainte')),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
          children: [
            const SectionHeader(
              title: 'Signaler un problème',
              subtitle: 'Décrivez précisément ce qui s\'est passé — notre équipe traite chaque plainte sous 48h.',
            ),
            Text('Motif', style: GoogleFonts.sora(fontSize: 14, fontWeight: FontWeight.w700)),
            const SizedBox(height: 10),
            ...ticketReasons.map((r) {
              final selected = r.value == _reason;
              return Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: InkWell(
                  borderRadius: BorderRadius.circular(14),
                  onTap: () => setState(() => _reason = r.value),
                  child: Container(
                    padding: const EdgeInsets.all(14),
                    decoration: BoxDecoration(
                      color: selected ? AppColors.primary.withValues(alpha: 0.07) : AppColors.card,
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(
                        color: selected ? AppColors.primary : AppColors.border,
                        width: selected ? 1.6 : 1,
                      ),
                    ),
                    child: Row(
                      children: [
                        Icon(
                          selected ? Icons.radio_button_checked_rounded : Icons.radio_button_off_rounded,
                          size: 20,
                          color: selected ? AppColors.primary : AppColors.mutedForeground,
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Text(r.label, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
                        ),
                        if (r.refundEligible)
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                            decoration: BoxDecoration(
                              color: AppColors.success.withValues(alpha: 0.14),
                              borderRadius: BorderRadius.circular(999),
                            ),
                            child: const Text(
                              'Remboursable',
                              style: TextStyle(color: AppColors.success, fontSize: 10, fontWeight: FontWeight.w700),
                            ),
                          ),
                      ],
                    ),
                  ),
                ),
              );
            }),
            const SizedBox(height: 8),
            Text('Transaction liée (optionnel)', style: GoogleFonts.sora(fontSize: 14, fontWeight: FontWeight.w700)),
            const SizedBox(height: 10),
            if (_loadingTx)
              const Padding(padding: EdgeInsets.symmetric(vertical: 8), child: LinearProgressIndicator())
            else
              DropdownButtonFormField<String>(
                initialValue: _linkedTxId,
                decoration: const InputDecoration(hintText: 'Aucune transaction sélectionnée'),
                items: _transactions
                    .map((t) => DropdownMenuItem(
                          value: t.id,
                          child: Text(
                            '${t.recipientName ?? t.recipientPhone} · ${formatFCFA(t.amount.round())}',
                            overflow: TextOverflow.ellipsis,
                          ),
                        ))
                    .toList(),
                onChanged: (v) => setState(() => _linkedTxId = v),
              ),
            const SizedBox(height: 20),
            Text('Sujet', style: GoogleFonts.sora(fontSize: 14, fontWeight: FontWeight.w700)),
            const SizedBox(height: 10),
            TextField(controller: _subjectCtrl, decoration: const InputDecoration(hintText: 'Résumez le problème en une phrase')),
            const SizedBox(height: 20),
            Text('Description', style: GoogleFonts.sora(fontSize: 14, fontWeight: FontWeight.w700)),
            const SizedBox(height: 10),
            TextField(
              controller: _descCtrl,
              maxLines: 5,
              decoration: const InputDecoration(hintText: 'Expliquez en détail ce qui s\'est passé, avec dates et montants si possible.'),
            ),
            if (_reason != null) ...[
              const SizedBox(height: 18),
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: (eligible ? AppColors.success : AppColors.muted).withValues(alpha: eligible ? 0.1 : 1),
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: eligible ? AppColors.success.withValues(alpha: 0.3) : AppColors.border),
                ),
                child: Row(
                  children: [
                    Icon(
                      eligible ? Icons.check_circle_rounded : Icons.info_rounded,
                      size: 18,
                      color: eligible ? AppColors.success : AppColors.mutedForeground,
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        eligible
                            ? 'Ce motif est éligible à un remboursement si la plainte est validée.'
                            : 'Ce motif ne donne pas lieu à un remboursement automatique.',
                        style: TextStyle(
                          fontSize: 12,
                          height: 1.4,
                          color: eligible ? AppColors.success : AppColors.mutedForeground,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ],
            const SizedBox(height: 24),
            ElevatedButton(
              onPressed: _submitting ? null : _submit,
              child: _submitting
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                    )
                  : const Text('Envoyer la plainte'),
            ),
          ],
        ),
      ),
    );
  }
}
