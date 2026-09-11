import 'package:local_auth/local_auth.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Service centralisant le déverrouillage biométrique (empreinte / Face ID)
/// et la persistance locale du code PIN — utilisé par le profil (activation)
/// et par l'écran de modification du PIN.
class BiometricService {
  BiometricService._();
  static final BiometricService instance = BiometricService._();

  final LocalAuthentication _auth = LocalAuthentication();

  static const _kBiometricEnabledKey = 'fripay_biometric_enabled';
  static const _kPinKey = 'fripay_pin';
  // Numéro du compte pour lequel la biométrie a été activée. Sans ce
  // repère, le flag "activé" + le PIN mémorisé restaient globaux à
  // l'appareil : un nouveau compte créé sur le même téléphone (sans passer
  // par "Se déconnecter", ex. session perdue après un restart backend)
  // héritait à tort du réglage et du PIN d'un compte précédent.
  static const _kOwnerPhoneKey = 'fripay_biometric_owner_phone';

  /// Le matériel du téléphone supporte-t-il la biométrie (empreinte/visage) ?
  Future<bool> isDeviceSupported() async {
    try {
      final supported = await _auth.isDeviceSupported();
      final canCheck = await _auth.canCheckBiometrics;
      return supported && canCheck;
    } catch (_) {
      return false;
    }
  }

  Future<List<BiometricType>> availableBiometrics() async {
    try {
      return await _auth.getAvailableBiometrics();
    } catch (_) {
      return [];
    }
  }

  /// Déclenche le prompt natif (empreinte / Face ID / code de l'appareil).
  Future<bool> authenticate({String reason = 'Confirmez votre identité pour continuer'}) async {
    try {
      return await _auth.authenticate(
        localizedReason: reason,
        options: const AuthenticationOptions(biometricOnly: false, stickyAuth: true),
      );
    } catch (_) {
      return false;
    }
  }

  Future<bool> isEnabled() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(_kBiometricEnabledKey) ?? false;
  }

  Future<void> setEnabled(bool value, {String? phone}) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_kBiometricEnabledKey, value);
    if (!value) {
      // Désactivation -> on oublie le PIN mémorisé et le propriétaire,
      // ils n'ont plus lieu d'être.
      await clearPin();
      await prefs.remove(_kOwnerPhoneKey);
    } else if (phone != null) {
      await prefs.setString(_kOwnerPhoneKey, phone);
    }
  }

  Future<String?> getOwnerPhone() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_kOwnerPhoneKey);
  }

  /// À appeler avant toute utilisation du flag "activé" / du PIN mémorisé
  /// pour un numéro donné (écran de connexion, profil, verrouillage au
  /// démarrage). Si la biométrie était activée pour un AUTRE numéro que
  /// celui actuellement connecté (compte changé sans déconnexion explicite),
  /// on désactive silencieusement plutôt que de faire hériter le nouveau
  /// compte du réglage — et surtout du PIN — d'un compte précédent.
  /// Retourne true seulement si la biométrie est bien activée ET associée
  /// à ce numéro.
  Future<bool> ensureOwnedBy(String phone) async {
    final enabled = await isEnabled();
    if (!enabled) return false;
    final owner = await getOwnerPhone();
    if (owner != null && owner != phone) {
      await setEnabled(false);
      return false;
    }
    if (owner == null) {
      // État hérité d'avant l'introduction de ce repère : on l'associe
      // maintenant à ce numéro plutôt que de forcer une désactivation.
      await setOwnerPhoneOnly(phone);
    }
    return true;
  }

  Future<void> setOwnerPhoneOnly(String phone) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kOwnerPhoneKey, phone);
  }

  /// PIN de connexion mémorisé localement, UNIQUEMENT quand le
  /// déverrouillage biométrique est activé — c'est ce qui permet au
  /// bouton "Connexion par empreinte" de rappeler /auth/login sans
  /// redemander le PIN. Null tant qu'il n'a jamais été enregistré.
  ///
  /// Remarque : shared_preferences n'est pas chiffré. Pour une version
  /// production, envisager flutter_secure_storage pour ce PIN.
  Future<String?> getPin() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_kPinKey);
  }

  Future<void> setPin(String pin) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kPinKey, pin);
  }

  Future<void> clearPin() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_kPinKey);
  }

  /// À appeler après toute saisie réussie du PIN (connexion manuelle,
  /// définition/changement de PIN) : met à jour le PIN mémorisé pour la
  /// biométrie si elle est activée **pour ce numéro**, sans rien faire
  /// sinon. Le paramètre [phone] est requis pour éviter qu'un compte
  /// différent (biométrie activée par un compte précédent sur le même
  /// appareil) ne récupère silencieusement le PIN de CE compte-ci.
  Future<void> rememberPinIfEnabled(String pin, {required String phone}) async {
    if (await ensureOwnedBy(phone)) {
      await setPin(pin);
    }
  }
}
