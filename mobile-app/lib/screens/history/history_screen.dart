import 'package:flutter/material.dart';

import '../../services/api_client.dart';
import '../../services/wallet_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/section_header.dart';
import '../../widgets/fripay_refresh.dart';

/// §7 — Suivi des transactions : historique complet et consultable de
/// TOUTE opération qui bouge le solde FriPay (transferts, QR envoyés /
/// encaissés / annulés / remboursés, factures, recharges/retraits
/// manuels). Branché sur GET /wallet/transactions (fripay-payments),
/// le grand-livre (ledger) unique où chaque mouvement est écrit — plus
/// complet que l'ancien écran qui ne listait que les transferts sortants.
class HistoryScreen extends StatefulWidget {
  const HistoryScreen({super.key});

  @override
  State<HistoryScreen> createState() => _HistoryScreenState();
}

class _HistoryScreenState extends State<HistoryScreen> {
  List<WalletLedgerEntry>? _entries;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final list = await WalletService.instance.listTransactions();
      if (!mounted) return;
      setState(() => _entries = list);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e.userMessage);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Historique')),
      body: SafeArea(
        child: FripayRefresh(
          onRefresh: _load,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
            children: [
              const SectionHeader(
                title: 'Historique des opérations',
                subtitle: 'Tous les mouvements de votre solde FriPay : transferts, QR, factures, recharges.',
              ),
              const SizedBox(height: 8),
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Text(_error!, style: const TextStyle(color: AppColors.destructive, fontSize: 13)),
                ),
              if (_entries == null && _error == null)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 40),
                  child: Center(child: CircularProgressIndicator()),
                ),
              ...?_entries?.map((e) => _LedgerTile(entry: e)),
              if (_entries != null && _entries!.isEmpty)
                const Padding(
                  padding: EdgeInsets.only(top: 40),
                  child: Center(
                    child: Text('Aucune opération pour le moment.', style: TextStyle(color: AppColors.mutedForeground)),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Libellé + icône lisibles pour chaque `reason` écrite au grand-livre par
/// les services backend (WalletService::debit/credit). Toute nouvelle
/// raison ajoutée côté backend retombe sur le libellé générique par défaut
/// (pas de crash, juste moins joli) — à compléter au fil de l'eau.
class _ReasonInfo {
  final String label;
  final IconData icon;
  const _ReasonInfo(this.label, this.icon);
}

const Map<String, _ReasonInfo> _reasonMap = {
  'transfer_out': _ReasonInfo('Transfert envoyé', Icons.north_east_rounded),
  'transfer_refund_failed': _ReasonInfo('Transfert échoué — remboursé', Icons.replay_rounded),
  'transfer_refund_cancelled': _ReasonInfo('Transfert annulé — remboursé', Icons.undo_rounded),
  'bill_payment': _ReasonInfo('Paiement de facture', Icons.receipt_long_rounded),
  'manual_topup': _ReasonInfo('Recharge', Icons.add_circle_outline_rounded),
  'manual_withdraw': _ReasonInfo('Retrait', Icons.remove_circle_outline_rounded),
  'qr_transfer_hold': _ReasonInfo('QR envoyé (fonds réservés)', Icons.qr_code_rounded),
  'qr_redeemed': _ReasonInfo('QR encaissé', Icons.qr_code_scanner_rounded),
  'qr_revoked_refund': _ReasonInfo('QR annulé — remboursé', Icons.cancel_outlined),
  'qr_external_claim_failed_refund': _ReasonInfo('QR non retiré — remboursé', Icons.error_outline_rounded),
};

_ReasonInfo _infoFor(String reason) =>
    _reasonMap[reason] ?? const _ReasonInfo('Opération', Icons.swap_horiz_rounded);

class _LedgerTile extends StatelessWidget {
  final WalletLedgerEntry entry;
  const _LedgerTile({required this.entry});

  @override
  Widget build(BuildContext context) {
    final isCredit = entry.type == 'credit';
    final info = _infoFor(entry.reason);
    final color = isCredit ? AppColors.success : AppColors.destructive;

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(13),
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.border.withValues(alpha: 0.7)),
      ),
      child: Row(
        children: [
          Container(
            width: 42,
            height: 42,
            decoration: BoxDecoration(
              color: color.withValues(alpha: 0.12),
              shape: BoxShape.circle,
            ),
            child: Icon(info.icon, color: color, size: 20),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(info.label, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5)),
                if (entry.description.isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(entry.description,
                      maxLines: 1, overflow: TextOverflow.ellipsis,
                      style: const TextStyle(color: AppColors.mutedForeground, fontSize: 11.5)),
                ],
                const SizedBox(height: 2),
                Text(formatDateTime(entry.createdAt),
                    style: const TextStyle(color: AppColors.mutedForeground, fontSize: 10.5)),
              ],
            ),
          ),
          Text(
            '${isCredit ? '+' : '-'}${formatFCFA(entry.amount)}',
            style: TextStyle(fontWeight: FontWeight.w800, fontSize: 13, color: color),
          ),
        ],
      ),
    );
  }
}
