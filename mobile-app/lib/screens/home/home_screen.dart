import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../models/models.dart' show OperatorId, TxStatus;
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/balance_card.dart';
import '../../widgets/operator_dot.dart';
import '../../widgets/quick_action_button.dart';
import '../../widgets/status_badge.dart';
import '../../services/account_service.dart';
import '../../services/api_client.dart';
import '../../services/auth_service.dart';
import '../../services/network_prefixes.dart';
import '../../services/transfer_service.dart';
import '../../services/wallet_service.dart';
import '../../services/token_storage.dart';
import '../../widgets/fripay_refresh.dart';
import '../bills/bills_screen.dart';
import '../complaints/complaints_screen.dart';
import '../history/history_screen.dart';
import '../profile/notifications_screen.dart';
import '../receive/receive_screen.dart';
import '../recharge/recharge_screen.dart';
import '../send/send_screen.dart';
import '../wallets/wallets_screen.dart';

/// Portage de src/routes/app.index.tsx — tableau de bord principal, branché
/// sur GET /users/me (identité), GET /users/me/accounts (comptes liés),
/// GET /wallet (solde interne FriPay) et GET /transfers (5 dernières
/// transactions envoyées). Si /wallet échoue (backend indispo, etc.), la
/// carte de solde retombe sur le nombre de comptes mobile money liés.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  String _displayName = '…';
  String _initials = '·';
  String? _fripayNumber;

  List<LinkedAccount>? _accounts;
  List<Transaction>? _recent;
  Wallet? _wallet;
  String? _loadError;

  @override
  void initState() {
    super.initState();
    _loadFromCache();
    _load();
  }

  Future<void> _loadFromCache() async {
    final fripay = await TokenStorage.instance.fripayNumber;
    final phone = await TokenStorage.instance.phoneNumber;
    if (!mounted) return;
    setState(() {
      _fripayNumber = fripay;
      if (_displayName == '…' && phone != null) {
        _displayName = NetworkPrefixes.format(phone);
        _initials = phone.substring(phone.length - 2);
      }
    });
  }

  Future<void> _load() async {
    setState(() => _loadError = null);
    await Future.wait([_loadProfile(), _loadAccounts(), _loadRecent(), _loadWallet()]);
  }

  Future<void> _loadWallet() async {
    try {
      final wallet = await WalletService.instance.getWallet();
      if (!mounted) return;
      setState(() => _wallet = wallet);
    } on ApiException catch (_) {
      // Solde optionnel sur ce tableau de bord : on retombe sur le
      // nombre de comptes liés (voir BalanceCard) plutôt que d'afficher
      // une erreur bloquante pour un simple échec de chargement du solde.
    }
  }

  Future<void> _loadProfile() async {
    try {
      final me = await AuthService.instance.getMe();
      final first = (me['first_name'] as String?)?.trim() ?? '';
      final last = (me['last_name'] as String?)?.trim() ?? '';
      final full = [first, last].where((s) => s.isNotEmpty).join(' ');
      final phone = (me['phone_number'] as String?) ?? '';
      final fripayNumber = (me['fripay_number'] as String?)?.trim();
      if (!mounted) return;
      setState(() {
        _displayName = full.isNotEmpty ? full : (phone.isNotEmpty ? NetworkPrefixes.format(phone) : '…');
        _initials = full.isNotEmpty
            ? full.trim().split(RegExp(r'\s+')).take(2).map((s) => s[0].toUpperCase()).join()
            : (phone.isNotEmpty ? phone.substring(phone.length - 2) : '·');
        _fripayNumber = (fripayNumber != null && fripayNumber.isNotEmpty) ? fripayNumber : null;
      });
    } on ApiException catch (_) {
      // Session expirée ou réseau indisponible : on garde le placeholder.
    }
  }

  Future<void> _loadAccounts() async {
    try {
      final accounts = await AccountService.instance.listAccounts();
      if (!mounted) return;
      setState(() => _accounts = accounts);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _loadError = e.userMessage);
    }
  }

  Future<void> _loadRecent() async {
    try {
      final list = await TransferService.instance.list(size: 5);
      if (!mounted) return;
      setState(() => _recent = list);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _loadError = e.userMessage);
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

  void _push(BuildContext context, Widget screen) {
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      bottom: false,
      child: FripayRefresh(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.fromLTRB(18, 14, 18, 24),
          children: [
            Row(
              children: [
                CircleAvatar(
                  radius: 20,
                  backgroundColor: const Color(0x1E1E7A5C),
                  child: Text(_initials, style: const TextStyle(color: AppColors.primary, fontWeight: FontWeight.w800)),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Bonjour', style: TextStyle(color: AppColors.mutedForeground, fontSize: 12.5)),
                      Text(_displayName, style: GoogleFonts.sora(fontSize: 17, fontWeight: FontWeight.w800)),
                    ],
                  ),
                ),
                InkWell(
                  onTap: () => _push(context, const NotificationsScreen()),
                  customBorder: const CircleBorder(),
                  child: Container(
                    width: 40,
                    height: 40,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(border: Border.all(color: AppColors.border), shape: BoxShape.circle),
                    child: const Icon(Icons.notifications_none_rounded, size: 20, color: AppColors.mutedForeground),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 20),
            if (_loadError != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Text(_loadError!, style: const TextStyle(color: AppColors.destructive, fontSize: 12.5)),
              ),
            BalanceCard(
              balance: _wallet?.balance,
              linkedAccountsCount: _accounts?.length,
              fripayNumber: _fripayNumber,
              onAdd: () => _push(context, const RechargeScreen()),
            ),
            const SizedBox(height: 22),
            Text('Actions rapides', style: GoogleFonts.sora(fontSize: 15, fontWeight: FontWeight.w700)),
            const SizedBox(height: 14),
            GridView.count(
              crossAxisCount: 4,
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              mainAxisSpacing: 14,
              childAspectRatio: 0.85,
              children: [
                QuickActionButton(icon: Icons.compare_arrows_rounded, label: 'Envoyer', onTap: () => _push(context, const SendScreen())),
                QuickActionButton(icon: Icons.qr_code_rounded, label: 'Recevoir', onTap: () => _push(context, const ReceiveScreen())),
                QuickActionButton(icon: Icons.add_card_rounded, label: 'Recharge', onTap: () => _push(context, const RechargeScreen())),
                QuickActionButton(icon: Icons.receipt_long_rounded, label: 'Factures', onTap: () => _push(context, const BillsScreen())),
                QuickActionButton(
                  icon: Icons.account_balance_wallet_rounded,
                  label: 'Portefeuilles',
                  color: AppColors.accent,
                  onTap: () => _push(context, const WalletsScreen()),
                ),
                QuickActionButton(
                  icon: Icons.history_rounded,
                  label: 'Historique',
                  color: AppColors.accent,
                  onTap: () => _push(context, const HistoryScreen()),
                ),
                QuickActionButton(
                  icon: Icons.report_problem_rounded,
                  label: 'Plaintes',
                  color: AppColors.destructive,
                  onTap: () => _push(context, const ComplaintsScreen()),
                ),
              ],
            ),
            const SizedBox(height: 24),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text('Transactions récentes', style: GoogleFonts.sora(fontSize: 15, fontWeight: FontWeight.w700)),
                TextButton(onPressed: () => _push(context, const HistoryScreen()), child: const Text('Tout voir')),
              ],
            ),
            const SizedBox(height: 6),
            if (_recent == null && _loadError == null)
              const Padding(padding: EdgeInsets.symmetric(vertical: 24), child: Center(child: CircularProgressIndicator())),
            if (_recent != null && _recent!.isEmpty)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 24),
                child: Text('Aucune transaction pour le moment.', style: TextStyle(color: AppColors.mutedForeground)),
              ),
            ...?_recent?.map((t) => _TxTile(
                  tx: t,
                  operatorId: _operatorIdFromCode(t.recipientOperator),
                  status: _txStatus(t.status),
                  onCancelled: _loadRecent,
                )),
          ],
        ),
      ),
    );
  }
}

class _TxTile extends StatefulWidget {
  final Transaction tx;
  final OperatorId operatorId;
  final TxStatus status;
  final VoidCallback onCancelled;
  const _TxTile({required this.tx, required this.operatorId, required this.status, required this.onCancelled});

  @override
  State<_TxTile> createState() => _TxTileState();
}

class _TxTileState extends State<_TxTile> {
  bool _cancelling = false;

  Future<void> _cancel() async {
    setState(() => _cancelling = true);
    try {
      await TransferService.instance.cancel(widget.tx.id);
      widget.onCancelled();
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.userMessage)));
    } finally {
      if (mounted) setState(() => _cancelling = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final tx = widget.tx;
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
          OperatorAvatar(id: widget.operatorId, size: 42),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(tx.recipientName ?? tx.recipientPhone, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5)),
                const SizedBox(height: 2),
                Text(tx.reference, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 11.5)),
                // §7 : une transaction en attente doit rester traçable ET
                // actionnable — avant ce correctif, rien ne permettait de
                // l'annuler depuis l'historique alors que la route backend
                // POST /transfers/{id}/cancel existait déjà, inutilisée.
                if (widget.status == TxStatus.enAttente)
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: _cancelling
                        ? const SizedBox(height: 14, width: 14, child: CircularProgressIndicator(strokeWidth: 2))
                        : InkWell(
                            onTap: _cancel,
                            child: const Text(
                              'Annuler',
                              style: TextStyle(color: AppColors.destructive, fontSize: 12, fontWeight: FontWeight.w700),
                            ),
                          ),
                  ),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text('-${formatFCFA(tx.amount.round())}', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13, color: AppColors.foreground)),
              const SizedBox(height: 4),
              StatusBadge(status: widget.status),
            ],
          ),
        ],
      ),
    );
  }
}