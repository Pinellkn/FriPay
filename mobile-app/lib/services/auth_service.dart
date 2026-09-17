import 'package:fripay_app/models/models.dart';
import 'api_client.dart';
import 'biometric_service.dart';
import 'network_prefixes.dart';
import 'token_storage.dart';

/// Service d'authentification FriPay — miroir de AuthController (fripay-users).
/// Toutes les requêtes passent par le gateway (ApiConfig.baseUrl).
class AuthService {
  AuthService._();
  static final AuthService instance = AuthService._();

  final _api = ApiClient.instance;

  /// Normalise un numéro saisi en +22901XXXXXXXX. Délègue au module
  /// centralisé des préfixes (`NetworkPrefixes`, cahier des charges §4) —
  /// aucune règle de numérotation n'est dupliquée ici.
  static String normalizePhone(String input) => NetworkPrefixes.normalize(input);

  /// Un numéro FriPay valide est obligatoirement +229 suivi de 10 chiffres
  /// commençant par 01, ET avec un préfixe opérateur réellement attribué par
  /// l'ARCEP (voir `NetworkPrefixes`).
  static bool isValidPhone(String input) =>
      NetworkPrefixes.isValidOperatorNumber(input);

  /// Message d'erreur détaillé pour une saisie invalide, `null` si valide.
  /// [expectedOperator] : réseau choisi par l'utilisateur à l'inscription.
  static String? phoneError(String input, {OperatorId? expectedOperator}) =>
      NetworkPrefixes.validationError(input, expected: expectedOperator);

  /// POST /auth/register — crée le compte et déclenche l'envoi du code de
  /// confirmation. L'email est OBLIGATOIRE côté API (cahier §2).
  /// La réponse contient le numéro FriPay attribué automatiquement (§1).
  Future<RegisterResult> register({
    required String phoneNumber,
    required String email,
    String? firstName,
    String? lastName,
  }) async {
    final res = await _api.post('/auth/register', authenticated: false, body: {
      'phone_number': normalizePhone(phoneNumber),
      'email': email,
      if (firstName != null && firstName.isNotEmpty) 'first_name': firstName,
      if (lastName != null && lastName.isNotEmpty) 'last_name': lastName,
    });
    return RegisterResult(
      userId: res['user_id']?.toString() ?? '',
      phoneNumber: res['phone_number']?.toString() ?? '',
      fripayNumber: res['fripay_number'] as String?,
      otpExpiresIn: res['otp_expires_in'] as int? ?? 0,
      emailVerificationExpiresIn: res['email_verification_expires_in'] as int? ?? 0,
      devOtpCode: res['dev_otp_code'] as String?,
      devEmailOtpCode: res['dev_email_otp_code'] as String?,
    );
  }

  /// POST /auth/verify-email — valide le code de confirmation reçu par
  /// email (cahier §2). Marque email_verified_at côté API.
  Future<void> verifyEmail({required String email, required String code}) async {
    await _api.post('/auth/verify-email', authenticated: false, body: {
      'email': email,
      'code': code,
    });
  }

  /// POST /auth/resend-email-verification — renvoie un nouveau code si le
  /// premier a expiré ou n'a jamais été reçu.
  /// Retourne le code de test en environnement dev (null en prod).
  Future<String?> resendEmailVerification({required String email}) async {
    final res = await _api.post('/auth/resend-email-verification', authenticated: false, body: {
      'email': email,
    });
    return res['dev_email_otp_code'] as String?;
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
    await TokenStorage.instance.saveUser(
      userId: me['id']?.toString() ?? '',
      phoneNumber: normalized,
      fripayNumber: me['fripay_number']?.toString(),
    );
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
    await TokenStorage.instance.saveUser(
      userId: me['id']?.toString() ?? '',
      phoneNumber: normalized,
      fripayNumber: me['fripay_number']?.toString(),
    );
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
      if (currentPin != null) 'current_pin': currentPin,
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

  /// PUT /users/me — met à jour le profil (nom, email).
  /// L'email est obligatoire côté cahier des charges §2 mais n'est pas
  /// accepté par POST /auth/register : il est donc envoyé ici, juste après
  /// la vérification du code de confirmation.
  Future<Map<String, dynamic>> updateProfile({
    String? firstName,
    String? lastName,
    String? email,
  }) async {
    final res = await _api.put('/users/me', body: {
      if (firstName != null && firstName.isNotEmpty) 'first_name': firstName,
      if (lastName != null && lastName.isNotEmpty) 'last_name': lastName,
      if (email != null && email.isNotEmpty) 'email': email,
    });
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
