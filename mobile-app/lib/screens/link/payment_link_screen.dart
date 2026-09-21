import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../services/payment_link_service.dart';
import '../../theme/app_colors.dart';

/// Fripay Link — écran de création d'un lien de paiement partageable.
///
/// L'utilisateur saisit un montant (obligatoire) et un motif (facultatif).
/// Le backend génère un lien unique non-devinable, verrouille le montant et
/// renvoie une URL publique /pay/{token} que n'importe qui peut ouvrir pour
/// payer via mobile (MTN, Moov, Celtiis), sans compte FriPay.
///
/// Accessible depuis l'accueil (actions rapides).
class PaymentLinkScreen extends StatefulWidget {
  const PaymentLinkScreen({super.key});

  @override
  State<PaymentLinkScreen> createState() => _PaymentLinkScreenState();
}

class _PaymentLinkScreenState extends State<PaymentLinkScreen> {
  final _amountCtrl = TextEditingController();
  final _reasonCtrl = TextEditingController();
  final _formKey = GlobalKey<FormState>();

  bool _submitting = false;
  FripayLink? _createdLink;

  @override
  void dispose() {
    _amountCtrl.dispose();
    _reasonCtrl.dispose();
    super.dispose();
  }

  Future<void> _createLink() async {
    if (!_formKey.currentState!.validate()) return;

    final amount = int.tryParse(_amountCtrl.text.replaceAll(' ', ''));
    if (amount == null || amount < 100) {
      _snack('Montant minimum : 100 FCFA');
      return;
    }

    setState(() => _submitting = true);
    try {
      final link = await PaymentLinkService.instance.create(
        amount: amount,
        description: _reasonCtrl.text.trim(),
      );
      if (!mounted) return;
      setState(() => _createdLink = link);
    } catch (e) {
      if (mounted) _snack(_errorMessage(e));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  Future<void> _share(FripayLink link) async {
    final url = link.shareUrl ?? 'https://fripay.bj/pay/${link.token}';
    final text = link.description == null || link.description!.isEmpty
        ? 'Payer ${_formatAmount(link.amount)} à votre contact via FriPay : $url'
        : '${link.description} — ${_formatAmount(link.amount)} via FriPay : $url';

    try {
      await SharePlus.instance.share(ShareParams(
        text: text,
        title: 'Demande de paiement FriPay',
      ));
    } catch (_) {
      // Partage indisponible : on propose la copie manuelle.
      await Clipboard.setData(ClipboardData(text: url));
      if (mounted) _snack('Lien copié dans le presse-papiers');
    }
  }

  Future<void> _copyLink(FripayLink link) async {
    final url = link.shareUrl ?? 'https://fripay.bj/pay/${link.token}';
    await Clipboard.setData(ClipboardData(text: url));
    if (mounted) _snack('Lien copié — collez-le où vous voulez');
  }

  /// Ouvre le lien dans le navigateur (aperçu de ce que verra le payeur).
  Future<void> _openLink(FripayLink link) async {
    final url = Uri.parse(link.shareUrl ?? 'https://fripay.bj/pay/${link.token}');
    try {
      final ok = await launchUrl(url, mode: LaunchMode.externalApplication);
      if (!ok && mounted) _snack('Impossible d\'ouvrir le navigateur.');
    } catch (_) {
      if (mounted) _snack('Impossible d\'ouvrir le navigateur.');
    }
  }

  void _snack(String message) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(message), behavior: SnackBarBehavior.floating),
    );
  }

  String _errorMessage(Object e) {
    final s = e.toString();
    if (s.contains('TIMEOUT') || s.contains('NETWORK')) {
      return 'Serveur injoignable. Vérifiez votre connexion.';
    }
    return 'Création du lien impossible. Réessayez.';
  }

  String _formatAmount(int amount) =>
      '${amount.toString().replaceAllMapped(RegExp(r'(\d)(?=(\d{3})+(?!\d))'), (m) => '${m[1]} ')} FCFA';

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Fripay Link')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: _createdLink == null ? _buildForm() : _buildResult(_createdLink!),
      ),
    );
  }

  // ── Formulaire de création ─────────────────────────────────────────
  Widget _buildForm() {
    return Form(
      key: _formKey,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Icon(Icons.link_rounded, size: 56, color: AppColors.primary),
          const SizedBox(height: 12),
          Text(
            'Demandez un paiement par lien',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 6),
          Text(
            'Partagez un lien sécurisé : votre contact paie via mobile (MTN, Moov, Celtiis), même sans l\'appli FriPay.',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(color: AppColors.mutedForeground),
          ),
          const SizedBox(height: 24),
          TextFormField(
            controller: _amountCtrl,
            keyboardType: TextInputType.number,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            maxLength: 7,
            decoration: InputDecoration(
              labelText: 'Montant (FCFA) *',
              hintText: 'Ex : 5000',
              counterText: '',
              prefixIcon: const Icon(Icons.payments_outlined),
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
            ),
            validator: (v) {
              final amount = int.tryParse(v?.replaceAll(' ', '') ?? '');
              if (amount == null || amount < 100) return 'Montant minimum : 100 FCFA';
              if (amount > 1000000) return 'Montant maximum : 1 000 000 FCFA';
              return null;
            },
          ),
          const SizedBox(height: 14),
          TextFormField(
            controller: _reasonCtrl,
            maxLength: 255,
            textInputAction: TextInputAction.done,
            decoration: InputDecoration(
              labelText: 'Motif (facultatif)',
              hintText: 'Ex : Facture janvier, part du loyer…',
              prefixIcon: const Icon(Icons.edit_note_rounded),
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
              counterText: '',
            ),
          ),
          const SizedBox(height: 22),
          FilledButton.icon(
            onPressed: _submitting ? null : _createLink,
            style: FilledButton.styleFrom(
              backgroundColor: AppColors.primary,
              foregroundColor: AppColors.primaryForeground,
              padding: const EdgeInsets.symmetric(vertical: 16),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
            ),
            icon: _submitting
                ? const SizedBox(
                    width: 18, height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                  )
                : const Icon(Icons.link_rounded),
            label: Text(_submitting ? 'Création…' : 'Créer le lien', style: const TextStyle(fontSize: 15.5)),
          ),
        ],
      ),
    );
  }

  // ── Résultat : lien créé ───────────────────────────────────────────
  Widget _buildResult(FripayLink link) {
    final url = link.shareUrl ?? 'https://fripay.bj/pay/${link.token}';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Icon(Icons.check_circle_rounded, size: 56, color: AppColors.primary),
        const SizedBox(height: 12),
        Text(
          'Lien créé !',
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 6),
        Text(
          'Quiconque ouvre ce lien peut vous payer de ${_formatAmount(link.amount)} via mobile.',
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.bodySmall?.copyWith(color: AppColors.mutedForeground),
        ),
        const SizedBox(height: 20),
        Card(
          elevation: 0,
          color: AppColors.muted,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
            side: const BorderSide(color: AppColors.border),
          ),
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              children: [
                if (link.description != null && link.description!.isNotEmpty) ...[
                  Text(link.description!, style: const TextStyle(fontWeight: FontWeight.w600)),
                  const SizedBox(height: 6),
                ],
                Text(_formatAmount(link.amount),
                    style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: AppColors.primary)),
                const SizedBox(height: 10),
                // VRAI LIEN CLIQUABLE (pas un simple texte à copier) :
                // un appui ouvre la page de paiement dans le navigateur.
                InkWell(
                  onTap: () => _openLink(link),
                  borderRadius: BorderRadius.circular(8),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    child: Text(
                      url,
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                        fontSize: 12.5,
                        color: AppColors.primary,
                        decoration: TextDecoration.underline,
                        decorationColor: AppColors.primary,
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 18),
        FilledButton.icon(
          onPressed: () => _share(link),
          style: FilledButton.styleFrom(
            backgroundColor: AppColors.primary,
            foregroundColor: AppColors.primaryForeground,
            padding: const EdgeInsets.symmetric(vertical: 15),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          ),
          icon: const Icon(Icons.share_rounded),
          label: const Text('Partager le lien', style: TextStyle(fontSize: 15.5)),
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: () => _openLink(link),
                style: OutlinedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                ),
                icon: const Icon(Icons.open_in_new_rounded, size: 18),
                label: const Text('Ouvrir', style: TextStyle(fontSize: 13)),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: OutlinedButton.icon(
                onPressed: () => _copyLink(link),
                style: OutlinedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                ),
                icon: const Icon(Icons.copy_rounded, size: 18),
                label: const Text('Copier', style: TextStyle(fontSize: 13)),
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        TextButton(
          onPressed: () {
            setState(() {
              _createdLink = null;
              _amountCtrl.clear();
              _reasonCtrl.clear();
            });
          },
          child: const Text('Créer un autre lien'),
        ),
      ],
    );
  }
}
