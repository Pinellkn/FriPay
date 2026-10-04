import 'package:flutter/material.dart';

import '../../services/api_client.dart';
import '../../services/payment_link_service.dart';
import '../../services/wallet_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/pin_confirm_sheet.dart';

/// Paiement IN-APP d'un FriPay Link reçu — parcours « Payer via FriPay » de
/// web1 : la page web vérifie que le numéro possède un compte puis ouvre
/// `fripay://pay/{token}` (deep link) qui atterrit ICI.
///
/// Le payeur voit le montant verrouillé et son solde, confirme avec son PIN :
/// POST /payment-links/{token}/pay-wallet débite son wallet et crédite le
/// créateur en une transaction atomique (paiement instantané, sans FeexPay).
class LinkPayScreen extends StatefulWidget {
  final String token;

  const LinkPayScreen({super.key, required this.token});

  @override
  State<LinkPayScreen> createState() => _LinkPayScreenState();
}

class _LinkPayScreenState extends State<LinkPayScreen> {
  FripayLink? _link;
  String? _error;
  bool _loading = true;
  bool _paying = false;

  @override
  void initState() {
    super.initState();
    _lookup();
  }

  void _snack(String msg) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));

  /// GET /payment-links/{token} — infos publiques du lien (montant,
  /// créateur masqué, payable ou non).
  Future<void> _lookup() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await ApiClient.instance.get('/payment-links/${widget.token}');
      final map = res as Map<String, dynamic>;
      if (!mounted) return;
      setState(() {
        _link = PaymentLinkService.fromJson(map);
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e.userMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = 'Impossible de charger ce lien. Réessayez.';
      });
    }
  }

  /// POST /payment-links/{token}/pay-wallet — débit du solde FriPay du
  /// payeur, crédit du créateur (PIN requis). Paiement instantané.
  Future<void> _pay() async {
    final link = _link;
    if (link == null || _paying) return;

    await showPinConfirmSheet(
      context: context,
      title: 'Code PIN requis',
      description: 'Payer ${formatFCFA(link.amount)} à ${link.creator} depuis votre solde FriPay ?',
      onConfirm: (pin) async {
        setState(() => _paying = true);
        try {
          await ApiClient.instance.post('/payment-links/${widget.token}/pay-wallet', body: {'pin': pin});
          if (!mounted) return null;
          setState(() => _paying = false);
          _snack('Paiement effectué — ${formatFCFA(link.amount)} envoyés à ${link.creator} ✅');
          Navigator.of(context).pop(true);
        } on ApiException catch (e) {
          if (!mounted) return null;
          setState(() => _paying = false);
          return e.userMessage;
        } catch (_) {
          if (!mounted) return null;
          setState(() => _paying = false);
          return 'Une erreur est survenue. Réessayez.';
        }
        return null;
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Payer via FriPay')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(18, 8, 18, 24),
          child: _loading
              ? const Center(child: Padding(padding: EdgeInsets.all(48), child: CircularProgressIndicator()))
              : _error != null || _link == null
                  ? _errorCard(_error ?? 'Lien indisponible.')
                  : _content(_link!),
        ),
      ),
    );
  }

  Widget _errorCard(String message) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          children: [
            const Icon(Icons.error_outline_rounded, size: 48, color: AppColors.destructive),
            const SizedBox(height: 12),
            const Text('Impossible d\'afficher ce lien', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
            const SizedBox(height: 6),
            Text(message, textAlign: TextAlign.center, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 13)),
            const SizedBox(height: 16),
            OutlinedButton(onPressed: _lookup, child: const Text('Réessayer')),
          ],
        ),
      ),
    );
  }

  Widget _content(FripayLink link) {
    final alreadyPaid = link.isPaid || link.expired || !link.isPending;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // ── Carte du lien ──
        Container(
          padding: const EdgeInsets.all(22),
          decoration: BoxDecoration(
            gradient: AppColors.gradientEmerald,
            borderRadius: BorderRadius.circular(24),
          ),
          child: Column(
            children: [
              Text(
                '${link.creator} vous demande',
                style: TextStyle(color: Colors.white.withValues(alpha: 0.78), fontSize: 13),
              ),
              const SizedBox(height: 6),
              Text(
                formatFCFA(link.amount),
                style: const TextStyle(color: Colors.white, fontSize: 34, fontWeight: FontWeight.w800, letterSpacing: -0.5),
              ),
              if (link.description != null && link.description!.isNotEmpty) ...[
                const SizedBox(height: 10),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: 0.14),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Text(
                    link.description!,
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: Colors.white, fontSize: 12.5),
                  ),
                ),
              ],
            ],
          ),
        ),
        const SizedBox(height: 20),

        if (alreadyPaid) ...[
          Card(
            child: Padding(
              padding: const EdgeInsets.all(20),
              child: Column(
                children: [
                  Icon(
                    link.isPaid ? Icons.check_circle_rounded : Icons.info_outline_rounded,
                    size: 40,
                    color: link.isPaid ? AppColors.primary : AppColors.mutedForeground,
                  ),
                  const SizedBox(height: 10),
                  Text(
                    link.isPaid ? 'Ce lien a déjà été payé' : 'Ce lien ne peut plus être payé',
                    style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14.5),
                  ),
                ],
              ),
            ),
          ),
        ] else ...[
          // ── Solde disponible ──
          FutureBuilder<Wallet>(
            future: _walletFuture ??= WalletService.instance.getWallet(),
            builder: (context, snap) {
              return Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: AppColors.secondary.withValues(alpha: 0.5),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.account_balance_wallet_rounded, color: AppColors.primary, size: 22),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        snap.hasData
                            ? 'Solde disponible : ${formatFCFA(snap.data!.balance)}'
                            : 'Chargement du solde…',
                        style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5),
                      ),
                    ),
                  ],
                ),
              );
            },
          ),
          const SizedBox(height: 20),
          ElevatedButton.icon(
            onPressed: _paying ? null : _pay,
            icon: _paying
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Icon(Icons.lock_outline_rounded, size: 18),
            label: Text(_paying ? 'Paiement…' : 'Payer ${formatFCFA(link.amount)}'),
          ),
          const SizedBox(height: 10),
          const Text(
            'Le montant est débité de votre solde FriPay et envoyé instantanément '
            'au créateur du lien — validation par votre code PIN.',
            textAlign: TextAlign.center,
            style: TextStyle(color: AppColors.mutedForeground, fontSize: 11.5, height: 1.4),
          ),
        ],
      ],
    );
  }

  Future<Wallet>? _walletFuture;
}
