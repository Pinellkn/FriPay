import 'package:flutter/material.dart';

import '../../models/models.dart' show OperatorId, TxStatus;
import '../../services/api_client.dart';
import '../../services/transfer_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/operator_dot.dart';
import '../../widgets/section_header.dart';
import '../../widgets/status_badge.dart';
import '../../widgets/fripay_refresh.dart';

/// Portage de src/routes/app.historique.tsx — branché sur GET /transfers
/// (fripay-payments). Ne liste que les transferts envoyés par l'utilisateur
/// (l'API n'expose pas d'historique de réception séparé).
class HistoryScreen extends StatefulWidget {
  const HistoryScreen({super.key});

  @override
  State<HistoryScreen> createState() => _HistoryScreenState();
}

class _HistoryScreenState extends State<HistoryScreen> {
  List<Transaction>? _transactions;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final list = await TransferService.instance.list();
      if (!mounted) return;
      setState(() => _transactions = list);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e.userMessage);
    }
  }

  OperatorId _operatorIdFromCode(String? code) => switch ((code ?? '').toUpperCase()) {
        'MTN' => OperatorId.mtn,
        'MOOV' => OperatorId.moov,
        'CELTIIS' => OperatorId.celtiis,
        _ => OperatorId.fripay,
      };

  TxStatus _txStatus(String status) => switch (status) {
        'completed' => TxStatus.reussi,
        'pending' || 'processing' => TxStatus.enAttente,
        _ => TxStatus.echoue, // failed | cancelled
      };

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
                title: 'Historique des transactions',
                subtitle: 'Tous vos transferts envoyés, tous opérateurs confondus.',
              ),
              const SizedBox(height: 8),
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Text(_error!, style: const TextStyle(color: AppColors.destructive, fontSize: 13)),
                ),
              if (_transactions == null && _error == null)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 40),
                  child: Center(child: CircularProgressIndicator()),
                ),
              ...?_transactions?.map((t) => _HistoryTile(
                    tx: t,
                    operatorId: _operatorIdFromCode(t.recipientOperator),
                    status: _txStatus(t.status),
                  )),
              if (_transactions != null && _transactions!.isEmpty)
                const Padding(
                  padding: EdgeInsets.only(top: 40),
                  child: Center(
                    child: Text('Aucune transaction pour le moment.', style: TextStyle(color: AppColors.mutedForeground)),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _HistoryTile extends StatelessWidget {
  final Transaction tx;
  final OperatorId operatorId;
  final TxStatus status;
  const _HistoryTile({required this.tx, required this.operatorId, required this.status});

  @override
  Widget build(BuildContext context) {
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
          OperatorAvatar(id: operatorId, size: 42),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(tx.recipientName ?? tx.recipientPhone,
                    style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5)),
                const SizedBox(height: 2),
                Text(tx.reference, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 11.5)),
                const SizedBox(height: 2),
                Text(tx.initiatedAt ?? '', style: const TextStyle(color: AppColors.mutedForeground, fontSize: 10.5)),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                '-${formatFCFA(tx.amount.round())}',
                style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13, color: AppColors.foreground),
              ),
              const SizedBox(height: 4),
              StatusBadge(status: status),
            ],
          ),
        ],
      ),
    );
  }
}
