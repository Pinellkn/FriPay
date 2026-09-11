import 'package:flutter/material.dart';

import '../../models/models.dart' hide Wallet;
import '../../services/account_service.dart';
import '../../services/api_client.dart';
import '../../services/wallet_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/add_account_sheet.dart';
import '../../widgets/operator_dot.dart';
import '../../widgets/section_header.dart';
import '../../widgets/fripay_refresh.dart';
import '../recharge/recharge_screen.dart';

/// Portage de src/routes/app.portefeuilles.tsx — branché sur le solde
/// interne FriPay (GET /wallet, GET /wallet/transactions) et sur les
/// comptes mobile money réellement liés (GET /users/me/accounts).
///
/// Le wallet est un porte-monnaie interne à FriPay (ledger propre, pas le
/// solde réel chez l'opérateur) : chaque compte lié reste par ailleurs
/// géré par son opérateur mobile money.
class WalletsScreen extends StatefulWidget {
  const WalletsScreen({super.key});

  @override
  State<WalletsScreen> createState() => _WalletsScreenState();
}

class _WalletsScreenState extends State<WalletsScreen> {
  List<LinkedAccount>? _accounts;
  Wallet? _wallet;
  List<WalletLedgerEntry>? _ledger;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    await Future.wait([_loadAccounts(), _loadWallet(), _loadLedger()]);
  }

  Future<void> _loadAccounts() async {
    try {
      final accounts = await AccountService.instance.listAccounts();
      if (!mounted) return;
      setState(() => _accounts = accounts);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e.userMessage);
    }
  }

  Future<void> _loadWallet() async {
    try {
      final wallet = await WalletService.instance.getWallet();
      if (!mounted) return;
      setState(() => _wallet = wallet);
    } on ApiException catch (_) {
      // Solde optionnel : n'écrase pas une erreur déjà affichée pour
      // les comptes liés, on laisse simplement _wallet à null.
    }
  }

  Future<void> _loadLedger() async {
    try {
      final entries = await WalletService.instance.listTransactions();
      if (!mounted) return;
      setState(() => _ledger = entries);
    } on ApiException catch (_) {
      // Historique optionnel, même logique que le solde ci-dessus.
    }
  }

  String _ledgerReasonLabel(WalletLedgerEntry e) {
    if (e.description.isNotEmpty) return e.description;
    return switch (e.reason) {
      'topup' => 'Dépôt',
      'transfer_out' => 'Transfert envoyé',
      'transfer_in' => 'Transfert reçu',
      _ => e.reason.isNotEmpty ? e.reason : 'Mouvement',
    };
  }

  OperatorId _operatorIdFromCode(String code) => switch (code.toUpperCase()) {
        'MTN' => OperatorId.mtn,
        'MOOV' => OperatorId.moov,
        'CELTIIS' => OperatorId.celtiis,
        _ => OperatorId.fripay,
      };

  String _statusLabel(String s) => switch (s) {
        'active' => 'Actif',
        'pending' => 'En attente',
        _ => 'Hors ligne',
      };

  Color _statusColor(String s) => switch (s) {
        'active' => AppColors.success,
        'pending' => AppColors.accentForeground,
        _ => AppColors.mutedForeground,
      };

  Future<void> _addAccount() async {
    final created = await showAddAccountSheet(context);
    if (created == null || !mounted) return;
    setState(() => _accounts = [...?_accounts, created]);
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text('Compte ${created.msisdn} lié avec succès.')),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Portefeuilles'),
        actions: [
          IconButton(
            onPressed: _addAccount,
            icon: const Icon(Icons.add_circle_outline_rounded),
            tooltip: 'Lier un compte',
          ),
        ],
      ),
      body: SafeArea(
        child: FripayRefresh(
          onRefresh: _load,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
            children: [
              const SectionHeader(
                title: 'Solde FriPay',
                subtitle: 'Porte-monnaie interne, distinct de vos comptes mobile money.',
              ),
              Container(
                margin: const EdgeInsets.only(bottom: 22),
                padding: const EdgeInsets.all(18),
                decoration: BoxDecoration(
                  color: AppColors.primary,
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text('Solde disponible', style: TextStyle(color: Colors.white70, fontSize: 12.5)),
                          const SizedBox(height: 6),
                          Text(
                            _wallet != null ? formatFCFA(_wallet!.balance) : '—',
                            style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w800),
                          ),
                        ],
                      ),
                    ),
                    OutlinedButton.icon(
                      onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const RechargeScreen())),
                      style: OutlinedButton.styleFrom(
                        foregroundColor: Colors.white,
                        side: const BorderSide(color: Colors.white54),
                        // Corrige un crash de layout Flutter connu : sans ces deux
                        // réglages, le calcul interne de la zone de tap minimale
                        // (_InputPadding) reçoit une largeur infinie quand ce bouton
                        // est placé à côté d'un Expanded dans la même Row — ce qui
                        // faisait planter tout l'écran Portefeuilles (blanc ou texte
                        // compressé lettre par lettre).
                        minimumSize: Size.zero,
                        tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                      ),
                      icon: const Icon(Icons.add_rounded, size: 18),
                      label: const Text('Déposer'),
                    ),
                  ],
                ),
              ),
              const SectionHeader(
                title: 'Vos portefeuilles',
                subtitle: 'Comptes mobile money liés à votre compte FriPay.',
              ),
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 16),
                  child: Text(_error!, style: const TextStyle(color: AppColors.destructive, fontSize: 13)),
                ),
              if (_accounts == null && _error == null)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 40),
                  child: Center(child: CircularProgressIndicator()),
                ),
              if (_accounts != null && _accounts!.isEmpty) ...[
                const Padding(
                  padding: EdgeInsets.only(top: 24, bottom: 14),
                  child: Text(
                    'Aucun compte mobile money lié pour le moment. '
                    "Sans compte lié, vous ne pouvez ni envoyer d'argent ni payer par QR.",
                    style: TextStyle(color: AppColors.mutedForeground),
                  ),
                ),
                ElevatedButton.icon(
                  onPressed: _addAccount,
                  icon: const Icon(Icons.add_card_rounded, size: 18),
                  label: const Text('Lier un compte mobile money'),
                ),
                const SizedBox(height: 8),
              ],
              ...?_accounts?.map((a) => _AccountTile(
                    account: a,
                    operatorId: _operatorIdFromCode(a.operatorCode),
                    statusLabel: _statusLabel(a.status),
                    statusColor: _statusColor(a.status),
                  )),
              if (_accounts != null && _accounts!.isNotEmpty) ...[
                const SizedBox(height: 8),
                OutlinedButton.icon(
                  onPressed: _addAccount,
                  icon: const Icon(Icons.add_card_rounded, size: 18),
                  label: const Text('Lier un autre compte'),
                ),
              ],
              const SizedBox(height: 26),
              const SectionHeader(
                title: 'Historique du solde',
                subtitle: 'Dépôts, débits et crédits sur votre porte-monnaie FriPay.',
              ),
              if (_ledger != null && _ledger!.isEmpty)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 20),
                  child: Text('Aucun mouvement pour le moment.', style: TextStyle(color: AppColors.mutedForeground)),
                ),
              ...?_ledger?.map((e) => _LedgerTile(entry: e, label: _ledgerReasonLabel(e))),
            ],
          ),
        ),
      ),
    );
  }
}

class _LedgerTile extends StatelessWidget {
  final WalletLedgerEntry entry;
  final String label;

  const _LedgerTile({required this.entry, required this.label});

  @override
  Widget build(BuildContext context) {
    final isCredit = entry.type == 'credit';
    final color = isCredit ? AppColors.success : AppColors.foreground;
    final sign = isCredit ? '+' : '-';
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.border.withValues(alpha: 0.7)),
      ),
      child: Row(
        children: [
          Container(
            width: 38,
            height: 38,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: (isCredit ? AppColors.success : AppColors.mutedForeground).withValues(alpha: 0.12),
              shape: BoxShape.circle,
            ),
            child: Icon(
              isCredit ? Icons.south_west_rounded : Icons.north_east_rounded,
              size: 18,
              color: isCredit ? AppColors.success : AppColors.mutedForeground,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5)),
                const SizedBox(height: 2),
                Text(
                  '${entry.createdAt.day.toString().padLeft(2, '0')}/${entry.createdAt.month.toString().padLeft(2, '0')}/${entry.createdAt.year}',
                  style: const TextStyle(color: AppColors.mutedForeground, fontSize: 11.5),
                ),
              ],
            ),
          ),
          Text('$sign${formatFCFA(entry.amount)}', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 13, color: color)),
        ],
      ),
    );
  }
}

class _AccountTile extends StatelessWidget {
  final LinkedAccount account;
  final OperatorId operatorId;
  final String statusLabel;
  final Color statusColor;

  const _AccountTile({
    required this.account,
    required this.operatorId,
    required this.statusLabel,
    required this.statusColor,
  });

  @override
  Widget build(BuildContext context) {
    final op = operatorById(operatorId);
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: account.isPrimary ? AppColors.primary.withValues(alpha: 0.4) : AppColors.border),
      ),
      child: Row(
        children: [
          OperatorAvatar(id: operatorId, size: 46),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Text(op.name, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14)),
                    if (account.isPrimary) ...[
                      const SizedBox(width: 6),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                        decoration: BoxDecoration(color: AppColors.accent.withValues(alpha: 0.2), borderRadius: BorderRadius.circular(999)),
                        child: const Text('Principal', style: TextStyle(fontSize: 9.5, fontWeight: FontWeight.w800, color: AppColors.accentForeground)),
                      ),
                    ],
                  ],
                ),
                const SizedBox(height: 3),
                Text(account.msisdn, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 12)),
              ],
            ),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
            decoration: BoxDecoration(color: statusColor.withValues(alpha: 0.14), borderRadius: BorderRadius.circular(999)),
            child: Text(statusLabel, style: TextStyle(color: statusColor, fontSize: 10.5, fontWeight: FontWeight.w700)),
          ),
        ],
      ),
    );
  }
}
