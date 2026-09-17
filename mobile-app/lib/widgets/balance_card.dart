import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';

import '../services/network_prefixes.dart';
import '../theme/app_colors.dart';
import '../utils/formatters.dart';

/// Carte de solde héro du tableau de bord (surface-emerald du web) avec
/// bascule masquer/afficher le montant (Eye / EyeOff dans app.index.tsx).
///
/// L'API FriPay n'expose aucun solde consolidé (pas d'endpoint balance côté
/// fripay-payments) : [balance] reste donc nullable. Quand il est null,
/// la carte affiche le nombre de comptes mobile money liés plutôt qu'un
/// montant inventé — même parti pris que l'écran Portefeuilles.
///
/// [fripayNumber] : numéro FriPay de l'utilisateur (cahier §1), affiché en
/// permanence sur cette carte comme demandé — juste au-dessus du solde.
class BalanceCard extends StatefulWidget {
  final int? balance;
  final int? linkedAccountsCount;
  final String? fripayNumber;
  final VoidCallback? onAdd;

  const BalanceCard({
    super.key,
    this.balance,
    this.linkedAccountsCount,
    this.fripayNumber,
    this.onAdd,
  });

  @override
  State<BalanceCard> createState() => _BalanceCardState();
}

class _BalanceCardState extends State<BalanceCard> {
  bool _hidden = false;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(22, 24, 22, 22),
      decoration: BoxDecoration(
        gradient: AppColors.gradientEmerald,
        borderRadius: BorderRadius.circular(28),
        boxShadow: [
          BoxShadow(
            color: AppColors.primaryDeep.withValues(alpha: 0.35),
            blurRadius: 32,
            offset: const Offset(0, 18),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                widget.balance != null ? 'Solde total FriPay' : 'Comptes FriPay',
                style: TextStyle(color: Colors.white.withValues(alpha: 0.78), fontSize: 13),
              ),
              if (widget.balance != null)
                InkWell(
                  borderRadius: BorderRadius.circular(999),
                  onTap: () => setState(() => _hidden = !_hidden),
                  child: Container(
                    padding: const EdgeInsets.all(7),
                    decoration: BoxDecoration(
                      color: Colors.white.withValues(alpha: 0.14),
                      shape: BoxShape.circle,
                    ),
                    child: Icon(
                      _hidden ? Icons.visibility_off_rounded : Icons.visibility_rounded,
                      size: 16,
                      color: Colors.white,
                    ),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 10),
          if (widget.fripayNumber != null) ...[
            InkWell(
              borderRadius: BorderRadius.circular(8),
              onTap: () {
                Clipboard.setData(ClipboardData(text: widget.fripayNumber!));
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('Numéro FriPay copié.'), duration: Duration(seconds: 1)),
                );
              },
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Flexible(
                    child: Text(
                      'N° FriPay : ${NetworkPrefixes.format(widget.fripayNumber!)}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: GoogleFonts.manrope(
                        color: Colors.white,
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 0.2,
                      ),
                    ),
                  ),
                  const SizedBox(width: 6),
                  Icon(Icons.copy_rounded, size: 13, color: Colors.white.withValues(alpha: 0.78)),
                ],
              ),
            ),
            const SizedBox(height: 10),
          ],
          if (widget.balance != null)
            Text(
              _hidden ? '••••• FCFA' : formatFCFA(widget.balance!),
              style: GoogleFonts.sora(
                color: Colors.white,
                fontSize: 30,
                fontWeight: FontWeight.w800,
                letterSpacing: -0.6,
              ),
            )
          else
            Text(
              widget.linkedAccountsCount == null
                  ? '…'
                  : widget.linkedAccountsCount == 0
                      ? 'Aucun compte lié'
                      : '${widget.linkedAccountsCount} compte${widget.linkedAccountsCount! > 1 ? 's' : ''} lié${widget.linkedAccountsCount! > 1 ? 's' : ''}',
              style: GoogleFonts.sora(
                color: Colors.white,
                fontSize: 24,
                fontWeight: FontWeight.w800,
                letterSpacing: -0.4,
              ),
            ),
          if (widget.balance == null) ...[
            const SizedBox(height: 4),
            Text(
              'Solde consolidé indisponible — détail dans Portefeuilles',
              style: TextStyle(color: Colors.white.withValues(alpha: 0.78), fontSize: 11.5),
            ),
          ],
          const SizedBox(height: 18),
          Row(
            children: [
              Flexible(
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: 0.16),
                    borderRadius: BorderRadius.circular(999),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Container(
                        width: 6,
                        height: 6,
                        decoration: const BoxDecoration(color: AppColors.mtn, shape: BoxShape.circle),
                      ),
                      const SizedBox(width: 6),
                      Flexible(
                        child: Text(
                          'Réseau interopérable actif',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w600),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(width: 8),
              if (widget.onAdd != null)
                TextButton.icon(
                  onPressed: widget.onAdd,
                  style: TextButton.styleFrom(
                    backgroundColor: Colors.white,
                    foregroundColor: AppColors.primaryDeep,
                    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                    shape: const StadiumBorder(),
                  ),
                  icon: const Icon(Icons.add_rounded, size: 16),
                  label: const Text('Ajouter', style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700)),
                ),
            ],
          ),
        ],
      ),
    );
  }
}
