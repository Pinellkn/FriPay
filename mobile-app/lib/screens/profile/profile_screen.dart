import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:local_auth/local_auth.dart' show BiometricType;

import '../../services/api_client.dart';
import '../../services/auth_service.dart';
import '../../services/biometric_service.dart';
import '../../services/network_prefixes.dart';
import '../../services/token_storage.dart';
import '../../theme/app_colors.dart';
import '../../widgets/fripay_logo.dart';
import '../../widgets/fripay_refresh.dart';
import '../../widgets/otp_input_row.dart';
import '../auth/login_screen.dart';
import '../bills/bills_screen.dart';
import '../complaints/complaints_screen.dart';
import '../wallets/wallets_screen.dart';
import 'about_screen.dart';
import 'change_pin_screen.dart';
import 'help_center_screen.dart';
import 'notifications_screen.dart';
import 'technical_interface_screen.dart';

/// Portage de src/routes/app.profil.tsx : identité, raccourcis vers les
/// autres sections et déconnexion.
class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  bool _biometricEnabled = false;
  bool _biometricSupported = false;
  String _biometricName = 'biométrie';
  // Types de biométrie réellement inscrits sur l'appareil : permettent
  // d'afficher un BOUTON SÉPARÉ pour la reconnaissance faciale (Face ID)
  // et pour l'empreinte digitale, comme demandé.
  bool _hasFace = false;
  bool _hasFingerprint = false;
  String _displayName = '…';
  String _displayPhone = '';
  String _initials = '·';

  @override
  void initState() {
    super.initState();
    _loadBiometricState();
    _loadProfile();
  }

  Future<void> _loadBiometricState() async {
    final supported = await BiometricService.instance.isDeviceSupported();
    final phone = await TokenStorage.instance.phoneNumber;
    // ensureOwnedBy neutralise silencieusement un état biométrique hérité
    // d'un compte précédent (jamais activé par CE compte-ci) plutôt que
    // d'afficher le switch déjà activé à tort.
    final enabled = phone != null ? await BiometricService.instance.ensureOwnedBy(phone) : false;

    String name = 'biométrie';
    var hasFace = false;
    var hasFingerprint = false;
    if (supported) {
      name = await BiometricService.instance.getLocalizedName();
      final types = await BiometricService.instance.availableBiometrics();
      hasFace = types.contains(BiometricType.face);
      hasFingerprint = types.contains(BiometricType.fingerprint);
      // Android 11+ peut masquer le type exact avant la 1re authentification :
      // dans ce cas on montre les deux boutons, le prompt natif tranchera.
      if (!hasFace && !hasFingerprint) {
        hasFace = true;
        hasFingerprint = true;
      }
    }

    if (!mounted) return;
    setState(() {
      _biometricSupported = supported;
      _biometricEnabled = enabled;
      _biometricName = name;
      _hasFace = hasFace;
      _hasFingerprint = hasFingerprint;
    });
  }

  Future<void> _loadProfile() async {
    try {
      final me = await AuthService.instance.getMe();
      final first = (me['first_name'] as String?)?.trim() ?? '';
      final last = (me['last_name'] as String?)?.trim() ?? '';
      final full = [first, last].where((s) => s.isNotEmpty).join(' ');
      final phone = (me['phone_number'] as String?) ?? '';
      if (!mounted) return;
      setState(() {
        _displayName = full.isNotEmpty ? full : phone;
        _displayPhone = phone.isNotEmpty ? NetworkPrefixes.format(phone) : '';
        _initials = full.isNotEmpty
            ? full.trim().split(RegExp(r'\s+')).take(2).map((s) => s[0].toUpperCase()).join()
            : (phone.isNotEmpty ? phone.substring(phone.length - 2) : '·');
      });
    } on ApiException catch (_) {
      // Session expirée ou réseau indisponible : on garde l'affichage
      // par défaut, la biométrie/le reste de l'écran restent utilisables.
    }
  }

  /// Active/désactive le déverrouillage biométrique. Les deux méthodes
  /// (reconnaissance faciale / empreinte) partagent le même réglage et le
  /// même PIN mémorisé : les boutons sont séparés pour choisir LA méthode
  /// de déverrouillage, pas pour activer deux systèmes indépendants.
  Future<void> _toggleBiometric(bool value, {String? methodLabel}) async {
    if (!_biometricSupported) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Biométrie non disponible sur cet appareil.')),
      );
      return;
    }
    final label = methodLabel ?? _biometricName;
    if (value) {
      final ok = await BiometricService.instance.authenticate(
        reason: 'Confirmez votre identité pour activer le déverrouillage par $label',
      );
      if (!ok) {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Authentification échouée. Réessayez.')),
        );
        return;
      }
      // On a besoin du PIN en clair une seule fois pour l'enregistrer côté
      // biométrie (protégé ensuite par la méthode choisie) — sans ça, le
      // futur bouton "Connexion par biométrie" n'aurait rien à utiliser.
      if (!mounted) return;
      final pin = await _promptCurrentPin(label);
      if (pin == null) return; // annulé
      final phone = await TokenStorage.instance.phoneNumber;
      if (phone == null) return;
      try {
        // Valide le PIN côté serveur (et rafraîchit les tokens au passage) ;
        // AuthService.login mémorise automatiquement le PIN pour la
        // biométrie une fois celle-ci activée juste en dessous. On associe
        // explicitement ce numéro comme propriétaire pour éviter qu'un
        // futur compte sur cet appareil n'en hérite par erreur.
        await BiometricService.instance.setEnabled(true, phone: phone);
        await AuthService.instance.login(phoneNumber: phone, pin: pin);
      } on ApiException catch (e) {
        await BiometricService.instance.setEnabled(false);
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.userMessage.isNotEmpty ? e.userMessage : 'Code PIN incorrect.')),
        );
        return;
      } catch (_) {
        await BiometricService.instance.setEnabled(false);
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Une erreur est survenue. Réessayez.')),
        );
        return;
      }
    } else {
      await BiometricService.instance.setEnabled(false);
    }
    if (!mounted) return;
    setState(() => _biometricEnabled = value);
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(value ? 'Déverrouillage par $label activé.' : 'Déverrouillage biométrique désactivé.')),
    );
  }

  /// Petite boîte de dialogue à 5 cases pour confirmer le PIN actuel avant
  /// de l'associer à la biométrie. Retourne null si l'utilisateur annule.
  Future<String?> _promptCurrentPin(String methodLabel) {
    final key = GlobalKey<OtpInputRowState>();
    return showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
        title: const Text('Confirmez votre code PIN'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'Nécessaire une seule fois pour activer la connexion par $methodLabel.',
              style: const TextStyle(fontSize: 12.5, color: AppColors.mutedForeground),
            ),
            const SizedBox(height: 16),
            OtpInputRow(
              key: key,
              length: 5,
              onChanged: (_) {},
              onCompleted: (v) => Navigator.of(ctx).pop(v),
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('Annuler')),
        ],
      ),
    );
  }

  void _push(BuildContext context, Widget screen) {
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
  }

  void _logout(BuildContext context) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
        title: const Text('Se déconnecter ?'),
        content: const Text('Vous devrez vous reconnecter avec votre numéro et votre code PIN.'),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('Annuler')),
          TextButton(
            onPressed: () async {
              Navigator.of(ctx).pop();
              await AuthService.instance.logout();
              if (!context.mounted) return;
              Navigator.of(context).pushAndRemoveUntil(
                MaterialPageRoute(builder: (_) => const LoginScreen()),
                (route) => false,
              );
            },
            child: const Text('Déconnexion', style: TextStyle(color: AppColors.destructive)),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Profil')),
      body: SafeArea(
        child: FripayRefresh(
          onRefresh: () async {
            await _loadBiometricState();
            await _loadProfile();
          },
          child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.fromLTRB(18, 4, 18, 32),
          children: [
            Container(
              padding: const EdgeInsets.all(20),
              decoration: BoxDecoration(
                gradient: AppColors.gradientEmerald,
                borderRadius: BorderRadius.circular(22),
              ),
              child: Row(
                children: [
                  CircleAvatar(
                    radius: 28,
                    backgroundColor: Colors.white24,
                    child: Text(_initials, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 16)),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(_displayName, style: GoogleFonts.sora(color: Colors.white, fontSize: 17, fontWeight: FontWeight.w800)),
                        const SizedBox(height: 3),
                        Text(_displayPhone, style: TextStyle(color: Colors.white.withValues(alpha: 0.85), fontSize: 12.5)),
                      ],
                    ),
                  ),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                    decoration: BoxDecoration(color: Colors.white.withValues(alpha: 0.16), borderRadius: BorderRadius.circular(999)),
                    child: const Text('Vérifié', style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700)),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 22),
            Text('Compte', style: GoogleFonts.sora(fontSize: 14, fontWeight: FontWeight.w700)),
            const SizedBox(height: 10),
            _MenuGroup(items: [
              _MenuItem(
                icon: Icons.account_balance_wallet_rounded,
                label: 'Mes portefeuilles',
                onTap: () => _push(context, const WalletsScreen()),
              ),
              _MenuItem(
                icon: Icons.receipt_long_rounded,
                label: 'Factures',
                onTap: () => _push(context, const BillsScreen()),
              ),
              _MenuItem(
                icon: Icons.report_problem_rounded,
                label: 'Plaintes',
                onTap: () => _push(context, const ComplaintsScreen()),
              ),
            ]),
            const SizedBox(height: 20),
            Text('Sécurité', style: GoogleFonts.sora(fontSize: 14, fontWeight: FontWeight.w700)),
            const SizedBox(height: 10),
            // DEUX BOUTONS SÉPARÉS : reconnaissance faciale (Face ID) et
            // empreinte digitale — l'utilisateur choisit sa méthode. Les deux
            // méthodes partagent le même réglage/PIN : activer l'une après
            // l'autre ne fait que remplacer la méthode de déverrouillage.
            if (_biometricSupported) ...[
              if (_hasFace)
                _BiometricButton(
                  icon: Icons.face_retouching_natural_rounded,
                  title: _biometricEnabled && _biometricName.contains('faciale') || _biometricName == 'Face ID'
                      ? 'Déverrouillage par reconnaissance faciale activé'
                      : 'Déverrouillage par reconnaissance faciale',
                  subtitle: _biometricEnabled && (_biometricName.contains('faciale') || _biometricName == 'Face ID')
                      ? 'Appuyez pour désactiver'
                      : 'Utilisez votre visage (Face ID) pour déverrouiller FriPay',
                  enabled: _biometricEnabled && (_biometricName.contains('faciale') || _biometricName == 'Face ID'),
                  onTap: () => _toggleBiometric(true, methodLabel: 'reconnaissance faciale'),
                  onDisable: () => _toggleBiometric(false),
                ),
              if (_hasFace && _hasFingerprint) const SizedBox(height: 10),
              if (_hasFingerprint)
                _BiometricButton(
                  icon: Icons.fingerprint_rounded,
                  title: _biometricEnabled && (_biometricName.contains('empreinte') || _biometricName == 'biométrie')
                      ? 'Déverrouillage par empreinte activé'
                      : 'Déverrouillage par empreinte digitale',
                  subtitle: _biometricEnabled && (_biometricName.contains('empreinte') || _biometricName == 'biométrie')
                      ? 'Appuyez pour désactiver'
                      : 'Utilisez votre empreinte pour déverrouiller FriPay',
                  enabled: _biometricEnabled && (_biometricName.contains('empreinte') || _biometricName == 'biométrie'),
                  onTap: () => _toggleBiometric(true, methodLabel: 'empreinte digitale'),
                  onDisable: () => _toggleBiometric(false),
                ),
            ] else
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                decoration: BoxDecoration(
                  color: AppColors.card,
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(color: AppColors.border),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.no_accounts_rounded, size: 24, color: AppColors.mutedForeground),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Text(
                        'Déverrouillage biométrique non disponible sur cet appareil',
                        style: GoogleFonts.sora(fontSize: 13, fontWeight: FontWeight.w600, color: AppColors.mutedForeground),
                      ),
                    ),
                  ],
                ),
              ),
            const SizedBox(height: 10),
            _MenuGroup(items: [
              _MenuItem(
                icon: Icons.lock_reset_rounded,
                label: 'Modifier mon code PIN',
                onTap: () => _push(context, const ChangePinScreen()),
              ),
              _MenuItem(
                icon: Icons.notifications_none_rounded,
                label: 'Notifications',
                onTap: () => _push(context, const NotificationsScreen()),
              ),
            ]),
            const SizedBox(height: 20),
            Text('Assistance', style: GoogleFonts.sora(fontSize: 14, fontWeight: FontWeight.w700)),
            const SizedBox(height: 10),
            _MenuGroup(items: [
              _MenuItem(
                icon: Icons.help_outline_rounded,
                label: 'Centre d\'aide',
                onTap: () => _push(context, const HelpCenterScreen()),
              ),
              _MenuItem(
                icon: Icons.info_outline_rounded,
                label: 'À propos de FriPay',
                onTap: () => _push(context, const AboutScreen()),
              ),
              _MenuItem(
                icon: Icons.dns_rounded,
                label: 'Interface technique',
                onTap: () => _push(context, const TechnicalInterfaceScreen()),
              ),
            ]),
            const SizedBox(height: 26),
            OutlinedButton.icon(
              onPressed: () => _logout(context),
              icon: const Icon(Icons.logout_rounded, color: AppColors.destructive, size: 18),
              label: const Text('Se déconnecter', style: TextStyle(color: AppColors.destructive)),
              style: OutlinedButton.styleFrom(side: const BorderSide(color: AppColors.destructive)),
            ),
            const SizedBox(height: 22),
            const Center(child: FripayLogo(size: 26)),
            const SizedBox(height: 6),
            const Center(
              child: Text('Version 1.0.0', style: TextStyle(color: AppColors.mutedForeground, fontSize: 11)),
            ),
          ],
          ),
        ),
      ),
    );
  }
}

class _MenuItem {
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  _MenuItem({required this.icon, required this.label, required this.onTap});
}

/// Bouton dédié à UNE méthode de déverrouillage biométrique (reconnaissance
/// faciale OU empreinte) — les deux méthodes ont chacune leur bouton.
/// État "activé" : contour vert + coche ; état inactif : dégradé emerald.
class _BiometricButton extends StatelessWidget {
  final IconData icon;
  final String title;
  final String subtitle;
  final bool enabled;
  final VoidCallback onTap;
  final VoidCallback onDisable;

  const _BiometricButton({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.enabled,
    required this.onTap,
    required this.onDisable,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        gradient: enabled ? null : AppColors.gradientEmerald,
        color: enabled ? AppColors.card : null,
        borderRadius: BorderRadius.circular(16),
        border: enabled
            ? Border.all(color: AppColors.primary.withValues(alpha: 0.55), width: 1.2)
            : null,
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: enabled ? onDisable : onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
          child: Row(
            children: [
              Icon(icon, size: 24, color: enabled ? AppColors.primary : Colors.white),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: GoogleFonts.sora(
                        fontSize: 13.5,
                        fontWeight: FontWeight.w800,
                        color: enabled ? AppColors.foreground : Colors.white,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      subtitle,
                      style: TextStyle(
                        fontSize: 11.5,
                        color: enabled
                            ? AppColors.mutedForeground
                            : Colors.white.withValues(alpha: 0.85),
                      ),
                    ),
                  ],
                ),
              ),
              Icon(
                enabled ? Icons.check_circle_rounded : Icons.arrow_forward_ios_rounded,
                size: enabled ? 22 : 16,
                color: enabled ? AppColors.primary : Colors.white.withValues(alpha: 0.85),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _MenuGroup extends StatelessWidget {
  final List<_MenuItem> items;
  const _MenuGroup({required this.items});

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.border.withValues(alpha: 0.7)),
      ),
      child: Column(
        children: List.generate(items.length, (i) {
          final item = items[i];
          return InkWell(
            onTap: item.onTap,
            borderRadius: BorderRadius.vertical(
              top: i == 0 ? const Radius.circular(16) : Radius.zero,
              bottom: i == items.length - 1 ? const Radius.circular(16) : Radius.zero,
            ),
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
              decoration: BoxDecoration(
                border: i == items.length - 1
                    ? null
                    : const Border(bottom: BorderSide(color: AppColors.border, width: 0.6)),
              ),
              child: Row(
                children: [
                  Icon(item.icon, size: 19, color: AppColors.primary),
                  const SizedBox(width: 14),
                  Expanded(child: Text(item.label, style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w600))),
                  const Icon(Icons.chevron_right_rounded, size: 18, color: AppColors.mutedForeground),
                ],
              ),
            ),
          );
        }),
      ),
    );
  }
}
