import 'api_client.dart';
import 'biometric_service.dart';
import 'token_storage.dart';

/// Résultat de l'étape register() — pas encore de session, juste l'attente
/// du code OTP envoyé par SMS.
class RegisterResult {
  final String userId;
  final String phoneNumber;
  final int otpExpiresIn;
  /// Code OTP renvoyé UNIQUEMENT par l'API en environnement dev (tant
  /// qu'aucun fournisseur SMS n'est branché — voir AuthController::register
  /// côté fripay-users). Null en production dès qu'un vrai SMS part.
  final String? devOtpCode;
  RegisterResult({
    required this.userId,
    required this.phoneNumber,
    required this.otpExpiresIn,
    this.devOtpCode,
  });
}

/// Service d'authentification FriPay — miroir de AuthController (fripay-users).
/// Toutes les requêtes passent par le gateway (ApiConfig.baseUrl).
class AuthService {
  AuthService._();
  static final AuthService instance = AuthService._();

  final _api = ApiClient.instance;

  /// Normalise un numéro saisi en +22901XXXXXXXX (10 chiffres après
  /// l'indicatif, dont le "01" initial fait partie intégrante du numéro
  /// depuis la réforme de numérotation du Bénin — on ne le retire plus).
  static String normalizePhone(String input) {
    var digits = input.replaceAll(RegExp(r'[^0-9+]'), '');
    if (digits.startsWith('+229')) return digits;
    if (digits.startsWith('229')) return '+$digits';
    return '+229$digits';
  }

  /// Un numéro FriPay valide est obligatoirement +229 suivi de 10 chiffres
  /// commençant par 01 (nouveau plan de numérotation béninois),
  /// ex. +22901979998XX. Rejette tout le reste (numéros courts, mauvais
  /// préfixe, lettres...).
  static final RegExp _phonePattern = RegExp(r'^\+22901\d{8}$');

  static bool isValidPhone(String input) => _phonePattern.hasMatch(normalizePhone(input));

  /// POST /auth/register — crée le compte et déclenche l'envoi d'un OTP SMS.
  Future<RegisterResult> register({
    required String phoneNumber,
    String? firstName,
    String? lastName,
  }) async {
    final res = await _api.post('/auth/register', authenticated: false, body: {
      'phone_number': normalizePhone(phoneNumber),
      if (firstName != null && firstName.isNotEmpty) 'first_name': firstName,
      if (lastName != null && lastName.isNotEmpty) 'last_name': lastName,
    });
    return RegisterResult(
      userId: res['user_id'],
      phoneNumber: res['phone_number'],
      otpExpiresIn: res['otp_expires_in'],
      devOtpCode: res['dev_otp_code'] as String?,
    );
  }

  /// POST /auth/verify-otp — vérifie le code SMS et ouvre la session
  /// (access_token + refresh_token), persistés localement.
  /// purpose: 'registration' | 'login' | 'transaction_confirmation' | 'password_reset'
  Future<void> verifyOtp({
    required String phoneNumber,
    required String code,
    String purpose = 'registration',
  }) async {
    final normalized = normalizePhone(phoneNumber);
    final res = await _api.post('/auth/verify-otp', authenticated: false, body: {
      'phone_number': normalized,
      'code': code,
      'purpose': purpose,
    });
    await TokenStorage.instance.saveTokens(
      accessToken: res['access_token'],
      refreshToken: res['refresh_token'],
    );
    // Le user_id n'est pas renvoyé ici : on va le chercher via /users/me.
    final me = await getMe();
    await TokenStorage.instance.saveUser(userId: me['id'], phoneNumber: normalized);
    // Un nouveau compte vient d'être créé sur cet appareil : si la
    // biométrie était restée activée pour un compte précédent (session
    // perdue sans passer par "Se déconnecter"), on la neutralise
    // immédiatement pour ce nouveau numéro plutôt que de la laisser
    // apparaître comme déjà activée dans son profil.
    await BiometricService.instance.ensureOwnedBy(normalized);
  }

  /// POST /auth/login — connexion par numéro + PIN (5 chiffres).
  Future<void> login({required String phoneNumber, required String pin}) async {
    final normalized = normalizePhone(phoneNumber);
    final res = await _api.post('/auth/login', authenticated: false, body: {
      'phone_number': normalized,
      'pin': pin,
    });
    await TokenStorage.instance.saveTokens(
      accessToken: res['access_token'],
      refreshToken: res['refresh_token'],
    );
    final me = await getMe();
    await TokenStorage.instance.saveUser(userId: me['id'], phoneNumber: normalized);
    // Garde le PIN mémorisé (biométrie) à jour si elle est activée POUR
    // CE numéro — sinon la biométrie d'un compte précédent sur ce même
    // appareil s'en trouve désactivée automatiquement (ensureOwnedBy).
    await BiometricService.instance.rememberPinIfEnabled(pin, phone: normalized);
  }

  /// POST /users/me/pin — définit le PIN (première fois après inscription,
  /// ou changement si currentPin est fourni).
  Future<void> setPin({required String newPin, String? currentPin}) async {
    await _api.post('/users/me/pin', body: {
      'new_pin': newPin,
      'current_pin': ?currentPin,
    });
    // Garde le PIN mémorisé (biométrie) à jour si elle est activée POUR CE
    // numéro — important après un changement de PIN, sinon la biométrie
    // continuerait à essayer l'ancien PIN et échouerait.
    final phone = await TokenStorage.instance.phoneNumber;
    if (phone != null) {
      await BiometricService.instance.rememberPinIfEnabled(newPin, phone: phone);
    }
  }

  /// GET /users/me
  Future<Map<String, dynamic>> getMe() async {
    final res = await _api.get('/users/me');
    return res as Map<String, dynamic>;
  }

  /// POST /auth/logout — invalide la session côté serveur puis localement.
  Future<void> logout() async {
    try {
      await _api.post('/auth/logout');
    } catch (_) {
      // Même si l'appel échoue (token déjà expiré, réseau...), on nettoie
      // la session locale pour renvoyer l'utilisateur à l'écran de connexion.
    }
    await TokenStorage.instance.clear();
    // Déconnexion explicite = on oublie tout ce qui est lié à CE compte,
    // y compris l'activation biométrique elle-même — sinon un nouveau
    // compte créé sur le même appareil hériterait à tort du toggle "activé"
    // du compte précédent (avec un PIN mémorisé qui ne serait plus le sien).
    await BiometricService.instance.setEnabled(false);
  }

  Future<bool> get isLoggedIn => TokenStorage.instance.hasSession;
}
