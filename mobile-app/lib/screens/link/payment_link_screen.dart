import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../services/payment_link_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';

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

  // ── Historique « Mes liens » ─────────────────────────────────────────
  bool _loadingHistory = false;
  String? _historyError;
  List<FripayLink> _history = [];

  /// GET /payment-links — les liens du créateur (statut, montant, motif).
  Future<void> _loadHistory() async {
    setState(() {
      _loadingHistory = true;
      _historyError = null;
    });
    try {
      final links = await PaymentLinkService.instance.history();
      if (!mounted) return;
      setState(() {
        _history = links;
        _loadingHistory = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loadingHistory = false;
        _historyError = _errorMessage(e);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Fripay Link')),
      body: DefaultTabController(
        length: 2,
        child: Column(
          children: [
            TabBar(
              // L'onglet « Mes liens » recharge à chaque ouverture : le
              // statut (payé / expiré) évolue côté serveur en continu.
              onTap: (i) {
                if (i == 1) _loadHistory();
              },
              labelColor: AppColors.primary,
              unselectedLabelColor: AppColors.mutedForeground,
              indicatorColor: AppColors.primary,
              tabs: const [Tab(text: 'Nouveau lien'), Tab(text: 'Mes liens')],
            ),
            Expanded(
              child: TabBarView(
                children: [
                  // Le contenu de création doit rester scrollable dans la
                  // TabBarView (le SingleChildScrollView d'origine y reste).
                  SingleChildScrollView(
                    padding: const EdgeInsets.all(20),
                    child: _createdLink == null ? _buildForm() : _buildResult(_createdLink!),
                  ),
                  _historyTab(),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _historyTab() {
    if (_loadingHistory) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_historyError != null) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(_historyError!, textAlign: TextAlign.center, style: const TextStyle(color: AppColors.destructive, fontSize: 13)),
            const SizedBox(height: 12),
            OutlinedButton(onPressed: _loadHistory, child: const Text('Réessayer')),
          ],
        ),
      );
    }
    if (_history.isEmpty) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(32),
          child: Text(
            'Aucun lien pour le moment.\nCréez-en un depuis l\'onglet « Nouveau lien ».',
            textAlign: TextAlign.center,
            style: TextStyle(color: AppColors.mutedForeground),
          ),
        ),
      );
    }
    return RefreshIndicator(
      onRefresh: _loadHistory,
      child: ListView.separated(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 24),
        itemCount: _history.length,
        separatorBuilder: (_, _) => const SizedBox(height: 10),
        itemBuilder: (context, i) {
          final link = _history[i];
          return _HistoryTile(
            link: link,
            url: link.shareUrl ?? 'https://fripay.bj/pay/${link.token}',
            onOpen: () => _openLink(link),
            onCopy: () => _copyLink(link),
            onShare: () => _share(link),
          );
        },
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

/// Tuile d'historique : montant, statut (payé / expiré / annulé / en
/// attente), motif, et actions ouvrir/copier/partager sur le lien web1.
class _HistoryTile extends StatelessWidget {
  final FripayLink link;
  final String url;
  final VoidCallback onOpen;
  final VoidCallback onCopy;
  final VoidCallback onShare;

  const _HistoryTile({
    required this.link,
    required this.url,
    required this.onOpen,
    required this.onCopy,
    required this.onShare,
  });

  ({String label, Color color, IconData icon}) get _status {
    if (link.isPaid) return (label: 'Payé', color: AppColors.primary, icon: Icons.check_circle_rounded);
    if (link.expired) return (label: 'Expiré', color: AppColors.mutedForeground, icon: Icons.schedule_rounded);
    switch (link.status) {
      case 'cancelled':
        return (label: 'Annulé', color: AppColors.destructive, icon: Icons.cancel_rounded);
      case 'paid':
        return (label: 'Payé', color: AppColors.primary, icon: Icons.check_circle_rounded);
      default:
        return (label: 'En attente', color: AppColors.accent, icon: Icons.hourglass_top_rounded);
    }
  }

  @override
  Widget build(BuildContext context) {
    final st = _status;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.border.withValues(alpha: 0.7)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  formatFCFA(link.amount),
                  style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15),
                ),
              ),
              Icon(st.icon, size: 15, color: st.color),
              const SizedBox(width: 4),
              Text(st.label, style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12, color: st.color)),
            ],
          ),
          if (link.description != null && link.description!.isNotEmpty) ...[
            const SizedBox(height: 4),
            Text(link.description!, maxLines: 1, overflow: TextOverflow.ellipsis,
                style: const TextStyle(color: AppColors.mutedForeground, fontSize: 12.5)),
          ],
          const SizedBox(height: 10),
          // Lien web1 cliquable (copie rapide, ouverture navigateur, partage).
          Row(
            children: [
              Expanded(
                child: InkWell(
                  onTap: onOpen,
                  borderRadius: BorderRadius.circular(8),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(vertical: 2),
                    child: Row(
                      children: [
                        const Icon(Icons.link_rounded, size: 14, color: AppColors.primary),
                        const SizedBox(width: 6),
                        Expanded(
                          child: Text(
                            url.replaceFirst(RegExp(r'^https?://'), ''),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              fontSize: 11.5,
                              color: AppColors.primary,
                              decoration: TextDecoration.underline,
                              decorationColor: AppColors.primary,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              IconButton(
                tooltip: 'Copier',
                visualDensity: VisualDensity.compact,
                onPressed: onCopy,
                icon: const Icon(Icons.copy_rounded, size: 16, color: AppColors.mutedForeground),
              ),
              IconButton(
                tooltip: 'Partager',
                visualDensity: VisualDensity.compact,
                onPressed: onShare,
                icon: const Icon(Icons.share_rounded, size: 16, color: AppColors.primary),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
