import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

// `Wallet` existe aussi dans models.dart : on le masque ici — le wallet
// affiché est celui du service (GET /wallet, solde réel du ledger).
import '../../models/models.dart' hide Wallet;
import '../../services/account_service.dart';
import '../../services/api_client.dart';
import '../../services/auth_service.dart';
import '../../services/network_prefixes.dart';
import '../../services/transfer_service.dart';
import '../../services/wallet_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/operator_dot.dart';
import '../../widgets/pin_confirm_sheet.dart';
import '../../widgets/section_header.dart';

/// RETRAIT — vire le solde FriPay vers le compte mobile auquel l'utilisateur
/// est lié (MTN / Moov / Celtiis).
///
/// Fondu sur le moteur de transferts existant : POST /transfers/quote puis
/// POST /transfers (PIN requis). Côté serveur le WALLET FriPay est débité et
/// le règlement part vers le numéro destinataire via le connecteur du
/// corridor (agrégateur FeexPay en attendant les API natives) — exactement
/// le sens du « retrait » : FriPay → compte mobile.
///
/// La RECHARGE (mobile → FriPay) reste dans recharge_screen.dart, accessible
/// via le bouton « Recharge » de la carte de solde de l'accueil.
class WithdrawalScreen extends StatefulWidget {
  const WithdrawalScreen({super.key});

  @override
  State<WithdrawalScreen> createState() => _WithdrawalScreenState();
}

class _WithdrawalScreenState extends State<WithdrawalScreen> {
  final _phoneCtrl = TextEditingController();
  final _amountCtrl = TextEditingController();

  Wallet? _wallet;
  List<LinkedAccount>? _accounts;
  LinkedAccount? _destination;

  TransferQuote? _quote;
  String? _error;
  bool _quoting = false;
  bool _loading = true;
  _PendingWithdrawal? _pending;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _phoneCtrl.dispose();
    _amountCtrl.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final results = await Future.wait([
        WalletService.instance.getWallet(),
        AccountService.instance.listAccounts(),
      ]);
      if (!mounted) return;
      final accounts = results[1] as List<LinkedAccount>;
      final primary = accounts.isNotEmpty
          ? accounts.firstWhere((a) => a.isPrimary, orElse: () => accounts.first)
          : null;
      setState(() {
        _wallet = results[0] as Wallet;
        _accounts = accounts;
        _destination = primary;
        if (primary != null) _phoneCtrl.text = NetworkPrefixes.nationalDigits(primary.msisdn);
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
      setState(() => _loading = false);
    }
  }

  OperatorId _operatorIdFromCode(String code) => switch (code.toUpperCase()) {
        'MTN' => OperatorId.mtn,
        'MOOV' => OperatorId.moov,
        'CELTIIS' => OperatorId.celtiis,
        _ => OperatorId.fripay,
      };

  void _snack(String msg) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));

  Future<void> _requestQuote() async {
    final amount = double.tryParse(_amountCtrl.text) ?? 0;
    final destination = _destination;
    if (destination == null || _phoneCtrl.text.trim().isEmpty || amount <= 0) {
      setState(() => _error = "Choisissez le compte mobile de destination et un montant valide.");
      return;
    }
    setState(() {
      _quoting = true;
      _error = null;
    });
    final phone = AuthService.normalizePhone(_phoneCtrl.text.trim());
    try {
      final quote = await TransferService.instance.quote(
        senderAccountId: destination.id,
        recipientPhone: phone,
        amount: amount,
      );
      if (!mounted) return;
      setState(() {
        _quoting = false;
        _quote = quote;
        _pending = _PendingWithdrawal(phone: phone, amount: amount);
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _quoting = false;
        _error = e.userMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _quoting = false;
        _error = 'Une erreur est survenue. Réessayez.';
      });
    }
  }

  Widget _confirmCard() {
    final p = _pending!;
    final q = _quote!;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Expanded(
                  child: Text('Confirmer le retrait',
                      overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                ),
                IconButton(
                  onPressed: () => setState(() {
                    _pending = null;
                    _quote = null;
                  }),
                  icon: const Icon(Icons.close_rounded),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(color: AppColors.muted, borderRadius: BorderRadius.circular(14)),
              child: Column(
                children: [
                  _row('Vers', NetworkPrefixes.format(p.phone)),
                  _row('Montant', formatFCFA(p.amount.round())),
                  _row('Frais', formatFCFA(q.feeAmount.round())),
                  const Divider(height: 20),
                  _row('Total débité du solde FriPay', formatFCFA(q.totalDebited.round()), bold: true),
                ],
              ),
            ),
            const SizedBox(height: 12),
            Text(
              "L'argent sera envoyé sur votre compte mobile ${operatorById(_operatorIdFromCode(_destination!.operatorCode)).name} "
              "— validez la réception avec votre code mobile si votre opérateur le demande.",
              style: const TextStyle(color: AppColors.mutedForeground, fontSize: 12, height: 1.4),
            ),
            const SizedBox(height: 18),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                onPressed: () => showPinConfirmSheet(
                  context: context,
                  title: 'Code PIN requis',
                  description:
                      "Confirmez le retrait de ${formatFCFA(q.totalDebited.round())} vers ${NetworkPrefixes.format(p.phone)}.",
                  onConfirm: (pin) async {
                    try {
                      await TransferService.instance.initiate(
                        quoteToken: q.quoteToken,
                        senderAccountId: _destination!.id,
                        recipientPhone: p.phone,
                        amount: p.amount,
                        pin: pin,
                      );
                    } on ApiException catch (e) {
                      return e.userMessage;
                    } catch (_) {
                      return 'Une erreur est survenue. Réessayez.';
                    }
                    if (mounted) {
                      _snack('Retrait de ${formatFCFA(p.amount.round())} en cours — arrive sur ${NetworkPrefixes.format(p.phone)}.');
                      setState(() {
                        _pending = null;
                        _quote = null;
                        _amountCtrl.clear();
                      });
                      _load(); // rafraîchit le solde
                    }
                    return null;
                  },
                ),
                icon: const Icon(Icons.check_rounded, size: 18),
                label: const Text('Confirmer le retrait'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _row(String label, String value, {bool bold = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 13)),
          Flexible(
            child: Text(value,
                overflow: TextOverflow.ellipsis,
                textAlign: TextAlign.right,
                style: TextStyle(fontWeight: bold ? FontWeight.w800 : FontWeight.w700, fontSize: bold ? 15 : 13)),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Retrait')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
          child: _pending != null
              ? _confirmCard()
              : Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const SectionHeader(
                      title: "Retirer vers mon compte mobile",
                      subtitle: "Transférez votre solde FriPay vers votre compte MTN, Moov ou Celtiis lié.",
                    ),
                    const SizedBox(height: 16),

                    // Solde FriPay disponible
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.all(18),
                      decoration: BoxDecoration(
                        gradient: AppColors.gradientEmerald,
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('Solde FriPay disponible',
                              style: TextStyle(color: Colors.white.withValues(alpha: 0.75), fontSize: 12.5)),
                          const SizedBox(height: 6),
                          Text(
                            _loading ? '…' : (_wallet != null ? formatFCFA(_wallet!.balance.round()) : 'Indisponible'),
                            style: const TextStyle(color: Colors.white, fontSize: 26, fontWeight: FontWeight.w800),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 20),

                    const Text('Compte mobile de destination', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                    const SizedBox(height: 10),
                    if (_error != null)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 10),
                        child: Text(_error!, style: const TextStyle(color: AppColors.destructive, fontSize: 12.5)),
                      ),
                    if (_loading)
                      const Center(child: Padding(padding: EdgeInsets.all(12), child: CircularProgressIndicator()))
                    else if (_accounts == null || _accounts!.isEmpty)
                      const Text(
                        'Aucun compte mobile lié. Liez-en un depuis Portefeuilles pour retirer.',
                        style: TextStyle(color: AppColors.mutedForeground, fontSize: 12.5),
                      )
                    else
                      Wrap(
                        spacing: 10,
                        runSpacing: 10,
                        children: _accounts!.map((a) {
                          final id = _operatorIdFromCode(a.operatorCode);
                          final selected = a.id == _destination?.id;
                          return InkWell(
                            onTap: () => setState(() {
                              _destination = a;
                              _phoneCtrl.text = NetworkPrefixes.nationalDigits(a.msisdn);
                              _error = null;
                            }),
                            borderRadius: BorderRadius.circular(14),
                            child: Container(
                              width: 155,
                              padding: const EdgeInsets.all(12),
                              decoration: BoxDecoration(
                                border: Border.all(
                                    color: selected ? AppColors.primary : AppColors.border, width: selected ? 1.6 : 1),
                                color: selected ? AppColors.primary.withValues(alpha: 0.06) : null,
                                borderRadius: BorderRadius.circular(14),
                              ),
                              child: Row(
                                children: [
                                  OperatorDot(id: id, size: 10),
                                  const SizedBox(width: 10),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(operatorById(id).name,
                                            overflow: TextOverflow.ellipsis,
                                            style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5)),
                                        Text(NetworkPrefixes.format(a.msisdn),
                                            overflow: TextOverflow.ellipsis,
                                            style: const TextStyle(color: AppColors.mutedForeground, fontSize: 11)),
                                      ],
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          );
                        }).toList(),
                      ),

                    const SizedBox(height: 18),
                    const Text('Numéro de réception', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                    const SizedBox(height: 8),
                    TextField(
                      controller: _phoneCtrl,
                      keyboardType: TextInputType.phone,
                      onChanged: (_) => setState(() => _error = null),
                      inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                      decoration: const InputDecoration(hintText: '01 97 00 00 00'),
                    ),

                    const SizedBox(height: 16),
                    const Text('Montant (FCFA)', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                    const SizedBox(height: 8),
                    TextField(
                      controller: _amountCtrl,
                      keyboardType: TextInputType.number,
                      inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                      decoration: const InputDecoration(hintText: '25 000'),
                    ),
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 8,
                      children: [5000, 10000, 25000, 50000].map((v) {
                        return ActionChip(label: Text(formatFCFA(v)), onPressed: () => setState(() => _amountCtrl.text = '$v'));
                      }).toList(),
                    ),

                    const SizedBox(height: 20),
                    SizedBox(
                      width: double.infinity,
                      child: ElevatedButton.icon(
                        onPressed: (_quoting || _destination == null) ? null : _requestQuote,
                        icon: _quoting
                            ? const SizedBox(
                                width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                            : const Icon(Icons.south_rounded, size: 18),
                        label: Text(_quoting ? 'Calcul des frais…' : 'Continuer'),
                      ),
                    ),
                  ],
                ),
        ),
      ),
    );
  }
}

class _PendingWithdrawal {
  final String phone;
  final double amount;
  _PendingWithdrawal({required this.phone, required this.amount});
}
