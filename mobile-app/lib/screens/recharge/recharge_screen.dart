import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'dart:async';

import '../../models/models.dart';
import '../../services/api_client.dart';
import '../../services/auth_service.dart';
import '../../services/feexpay_service.dart';
import '../../services/network_prefixes.dart';
import '../../services/token_storage.dart';
import '../../services/wallet_service.dart' as wallet_api;
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/operator_dot.dart';
import '../../widgets/pin_confirm_sheet.dart';
import '../../widgets/section_header.dart';

/// Portage de src/routes/app.recharge.tsx (Recharge / Retrait).
///
/// RECHARGE : branchée sur l'agrégateur FeexPay (POST /wallet/topup/feexpay).
/// L'utilisateur reçoit un push sur son téléphone et valide avec son code
/// mobile money (MTN/Moov) ; le solde FriPay est crédité À LA CONFIRMATION
/// (suivi automatique du statut ici, + webhook côté serveur).
///
/// RETRAIT : toujours sur POST /wallet/withdraw (cash chez un agent
/// partenaire, PIN FriPay requis) — FeexPay n'expose pas de payout public.
class RechargeScreen extends StatefulWidget {
  const RechargeScreen({super.key});

  @override
  State<RechargeScreen> createState() => _RechargeScreenState();
}

class _RechargeScreenState extends State<RechargeScreen> with SingleTickerProviderStateMixin {
  late final TabController _tab = TabController(length: 2, vsync: this);
  OperatorId _operator = OperatorId.mtn;

  // Contrôleurs séparés par onglet : TabBarView construit les deux formulaires
  // en même temps, partager un seul contrôleur ferait fuiter le texte saisi
  // d'un onglet vers l'autre.
  final _depositPhoneCtrl = TextEditingController();
  final _depositAmountCtrl = TextEditingController();
  final _withdrawPhoneCtrl = TextEditingController();
  final _withdrawAmountCtrl = TextEditingController();

  String? _phoneError;

  @override
  void initState() {
    super.initState();
    _loadUserPhone();
  }

  Future<void> _loadUserPhone() async {
    final phone = await TokenStorage.instance.phoneNumber;
    if (phone != null && mounted) {
      final national = NetworkPrefixes.nationalDigits(phone);
      setState(() {
        _depositPhoneCtrl.text = national;
        _withdrawPhoneCtrl.text = national;
      });
    }
  }

  void _snack(String msg) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));

  @override
  void dispose() {
    _tab.dispose();
    _depositPhoneCtrl.dispose();
    _depositAmountCtrl.dispose();
    _withdrawPhoneCtrl.dispose();
    _withdrawAmountCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Recharge / Retrait')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const SectionHeader(
                title: 'Recharge & retrait agent',
                subtitle: "Alimentez votre solde FriPay ou retirez du cash chez un agent partenaire.",
              ),
              Container(
                decoration: BoxDecoration(color: AppColors.muted, borderRadius: BorderRadius.circular(999)),
                padding: const EdgeInsets.all(4),
                child: TabBar(
                  controller: _tab,
                  indicator: BoxDecoration(color: AppColors.card, borderRadius: BorderRadius.circular(999)),
                  labelColor: AppColors.foreground,
                  unselectedLabelColor: AppColors.mutedForeground,
                  dividerColor: Colors.transparent,
                  onTap: (_) => setState(() {}), // pour rafraîchir le libellé du bouton "Continuer"
                  tabs: const [Tab(text: 'Recharge'), Tab(text: 'Retrait')],
                ),
              ),
              const SizedBox(height: 20),
              AnimatedBuilder(
                animation: _tab,
                builder: (context, _) => _tab.index == 0 ? _form(isDeposit: true) : _form(isDeposit: false),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _form({required bool isDeposit}) {
    final phoneCtrl = isDeposit ? _depositPhoneCtrl : _withdrawPhoneCtrl;
    final amountCtrl = isDeposit ? _depositAmountCtrl : _withdrawAmountCtrl;

    // Recharge via FeexPay : MTN et Moov uniquement (collecte).
    final networks = isDeposit
        ? [OperatorId.mtn, OperatorId.moov]
        : [OperatorId.mtn, OperatorId.moov, OperatorId.celtiis];

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(isDeposit ? 'Réseau (paiement mobile money)' : 'Réseau',
                style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
              const SizedBox(height: 10),
              Wrap(
                spacing: 10,
                runSpacing: 10,
                children: networks.map((id) {
                  final selected = id == _operator;
                  return ChoiceChip(
                    selected: selected,
                    onSelected: (_) {
                      setState(() {
                        _operator = id;
                        _phoneError = null;
                      });
                    },
                    avatar: OperatorDot(id: id, size: 8),
                    label: Text(operatorById(id).short),
                    selectedColor: AppColors.primary.withValues(alpha: 0.14),
                    labelStyle: TextStyle(
                      fontWeight: FontWeight.w700,
                      color: selected ? AppColors.primary : AppColors.foreground,
                    ),
                  );
                }).toList(),
              ),
              const SizedBox(height: 16),
              const Text('Numéro de téléphone', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
              const SizedBox(height: 8),
              TextField(
                controller: phoneCtrl,
                keyboardType: TextInputType.phone,
                onChanged: (_) => setState(() => _phoneError = null),
                inputFormatters: [
                  FilteringTextInputFormatter.digitsOnly,
                  LengthLimitingTextInputFormatter(NetworkPrefixes.fripayLength),
                ],
                decoration: InputDecoration(
                  hintText: '01 97 00 00 00',
                  errorText: _phoneError,
                ),
              ),
              const SizedBox(height: 16),
              const Text('Montant (FCFA)', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
              const SizedBox(height: 8),
              TextField(
                controller: amountCtrl,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                decoration: const InputDecoration(hintText: '50 000'),
              ),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                children: [10000, 25000, 50000, 100000].map((v) {
                  return ActionChip(label: Text(formatFCFA(v)), onPressed: () => setState(() => amountCtrl.text = '$v'));
                }).toList(),
              ),
              const SizedBox(height: 20),
              ElevatedButton(
                onPressed: () => _submit(isDeposit: isDeposit, phoneCtrl: phoneCtrl, amountCtrl: amountCtrl),
                child: Text(isDeposit ? 'Recharger' : 'Retirer'),
              ),
              if (isDeposit) ...[
                const SizedBox(height: 10),
                Text(
                  'Vous validez le paiement sur votre téléphone avec votre code '
                  'mobile money. Le solde FriPay est crédité dès confirmation.',
                  style: TextStyle(fontSize: 12, color: AppColors.mutedForeground),
                ),
              ],
            ],
          ),
        ),
      );
  }

  void _submit({
    required bool isDeposit,
    required TextEditingController phoneCtrl,
    required TextEditingController amountCtrl,
  }) {
    final phone = phoneCtrl.text.trim();
    final phoneErr = NetworkPrefixes.validationError(phone, expected: _operator);
    if (phoneErr != null) {
      setState(() => _phoneError = phoneErr);
      return;
    }

    final amount = int.tryParse(amountCtrl.text) ?? 0;
    if (amount <= 0) {
      _snack('Indiquez un montant valide.');
      return;
    }
    showPinConfirmSheet(
      context: context,
      title: 'Code PIN requis',
      description: isDeposit
          ? 'Confirmez la recharge de ${formatFCFA(amount)} via ${operatorById(_operator).name}.'
          : 'Confirmez le retrait de ${formatFCFA(amount)} via ${operatorById(_operator).name}.',
      onConfirm: (pin) async {
        if (isDeposit) {
          // Recharge via FeexPay : le PIN FriPay protège l'ouverture de la
          // demande ; le débit réel se fait sur le téléphone mobile money.
          return _submitFeexpayTopup(amount);
        }
        try {
          await wallet_api.WalletService.instance.withdraw(
            amount,
            pin: pin,
            phoneNumber: AuthService.normalizePhone(phone),
          );
        } on ApiException catch (e) {
          return e.userMessage;
        } catch (_) {
          return 'Une erreur est survenue. Réessayez.';
        }
        if (mounted) {
          _snack('Retrait de ${formatFCFA(amount)} effectué. Solde mis à jour.');
          setState(() {
            amountCtrl.clear();
          });
        }
        return null;
      },
    );
  }

  /// Recharge via l'agrégateur FeexPay : initiation puis suivi du statut
  /// (l'utilisateur valide sur son téléphone ; crédit à la confirmation).
  Future<String?> _submitFeexpayTopup(int amount) async {
    final WalletTopup topup;
    try {
      topup = await FeexpayService.instance.initiate(
        amount: amount,
        operator: _operator.name.toUpperCase(),
      );
    } on ApiException catch (e) {
      return e.userMessage;
    } catch (_) {
      return 'Impossible de créer la recharge. Réessayez.';
    }

    if (topup.isFailed) {
      return topup.failureReason ?? 'Recharge refusée par FeexPay.';
    }

    if (mounted) {
      _snack('Demande envoyée — validez sur votre téléphone ($amount FCFA).');
    }

    // Suivi du statut : la confirmation peut prendre quelques secondes
    // (l'utilisateur doit valider le push USSD/STK sur son téléphone).
    unawaited(_pollTopupStatus(topup.id));

    return null;
  }

  /// Interroge le statut du topup toutes les 3 s (max ~60 s). Dès que le
  /// paiement est confirmé, le serveur crédite le solde et on l'affiche.
  Future<void> _pollTopupStatus(String topupId) async {
    for (var i = 0; i < 20; i++) {
      await Future<void>.delayed(const Duration(seconds: 3));
      if (!mounted) return;
      try {
        final t = await FeexpayService.instance.checkStatus(topupId);
        if (t.isCompleted) {
          if (mounted) {
            _snack('Recharge confirmée — solde crédité ✅');
          }
          return;
        }
        if (t.isFailed) {
          if (mounted) {
            _snack('Recharge échouée : ${t.failureReason ?? 'paiement refusé'}');
          }
          return;
        }
      } catch (_) {
        // Erreur réseau passagère : on retente au prochain tick.
      }
    }
    if (mounted) {
      _snack('Paiement toujours en attente — le solde sera crédité dès confirmation.');
    }
  }
}
