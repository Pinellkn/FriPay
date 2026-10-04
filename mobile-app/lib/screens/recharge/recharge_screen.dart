import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'dart:async';

import '../../models/models.dart';
import '../../services/api_client.dart';
import '../../services/feexpay_service.dart';
import '../../services/network_prefixes.dart';
import '../../services/token_storage.dart';
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/operator_dot.dart';
import '../../widgets/section_header.dart';

/// RECHARGE — branchée sur l'agrégateur FeexPay (POST /wallet/topup/feexpay).
///
/// L'utilisateur reçoit un push sur son téléphone et valide avec son code
/// mobile (MTN/Moov/Celtiis) ; le solde FriPay est crédité À LA CONFIRMATION
/// (suivi automatique du statut ici, + webhook côté serveur).
///
/// Le RETRAIT (solde FriPay → compte mobile lié) a déménagé dans
/// screens/withdraw/withdrawal_screen.dart — l'accueil expose désormais
/// « Retrait » comme action rapide, et la recharge se fait uniquement via
/// le bouton « Recharge » de la carte de solde.
class RechargeScreen extends StatefulWidget {
  const RechargeScreen({super.key});

  @override
  State<RechargeScreen> createState() => _RechargeScreenState();
}

class _RechargeScreenState extends State<RechargeScreen> {
  OperatorId _operator = OperatorId.mtn;

  final _phoneCtrl = TextEditingController();
  final _amountCtrl = TextEditingController();
  String? _phoneError;

  @override
  void initState() {
    super.initState();
    _loadUserPhone();
  }

  Future<void> _loadUserPhone() async {
    final phone = await TokenStorage.instance.phoneNumber;
    if (phone != null && mounted) {
      setState(() => _phoneCtrl.text = NetworkPrefixes.nationalDigits(phone));
    }
  }

  void _snack(String msg) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));

  @override
  void dispose() {
    _phoneCtrl.dispose();
    _amountCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Recharge')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const SectionHeader(
                title: 'Recharger mon solde',
                subtitle: 'Alimentez votre solde FriPay depuis votre compte mobile.',
              ),
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(18),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Réseau (paiement mobile)', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                      const SizedBox(height: 10),
                      // Recharge via FeexPay : MTN, Moov et Celtiis (collecte).
                      Wrap(
                        spacing: 10,
                        runSpacing: 10,
                        children: [OperatorId.mtn, OperatorId.moov, OperatorId.celtiis].map((id) {
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
                        controller: _phoneCtrl,
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
                        controller: _amountCtrl,
                        keyboardType: TextInputType.number,
                        inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                        decoration: const InputDecoration(hintText: '50 000'),
                      ),
                      const SizedBox(height: 8),
                      Wrap(
                        spacing: 8,
                        children: [10000, 25000, 50000, 100000].map((v) {
                          return ActionChip(label: Text(formatFCFA(v)), onPressed: () => setState(() => _amountCtrl.text = '$v'));
                        }).toList(),
                      ),
                      const SizedBox(height: 20),
                      SizedBox(
                        width: double.infinity,
                        child: ElevatedButton(
                          onPressed: _submit,
                          child: const Text('Recharger'),
                        ),
                      ),
                      const SizedBox(height: 10),
                      Text(
                        'Vous validez le paiement sur votre téléphone avec votre code '
                        'mobile. Le solde FriPay est crédité dès confirmation.',
                        style: TextStyle(fontSize: 12, color: AppColors.mutedForeground),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _submit() {
    final phone = _phoneCtrl.text.trim();
    final phoneErr = NetworkPrefixes.validationError(phone, expected: _operator);
    if (phoneErr != null) {
      setState(() => _phoneError = phoneErr);
      return;
    }

    final amount = int.tryParse(_amountCtrl.text) ?? 0;
    if (amount <= 0) {
      _snack('Indiquez un montant valide.');
      return;
    }

    // Recharge via FeexPay : le débit réel se fait sur le téléphone mobile
    // (pas de PIN FriPay — le PIN mobile est la protection ici).
    _submitFeexpayTopup(amount);
  }

  /// Recharge via l'agrégateur FeexPay : initiation puis suivi du statut
  /// (l'utilisateur valide sur son téléphone ; crédit à la confirmation).
  Future<void> _submitFeexpayTopup(int amount) async {
    final WalletTopup topup;
    try {
      topup = await FeexpayService.instance.initiate(
        amount: amount,
        operator: _operator.name.toUpperCase(),
      );
    } on ApiException catch (e) {
      _snack(e.userMessage);
      return;
    } catch (_) {
      _snack('Impossible de créer la recharge. Réessayez.');
      return;
    }

    if (topup.isFailed) {
      _snack(topup.failureReason ?? 'Recharge refusée par FeexPay.');
      return;
    }

    _snack('Demande envoyée — validez sur votre téléphone ($amount FCFA).');

    // Suivi du statut : la confirmation peut prendre quelques secondes
    // (l'utilisateur doit valider le push USSD/STK sur son téléphone).
    unawaited(_pollTopupStatus(topup.id));
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
