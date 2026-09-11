import 'package:shared_preferences/shared_preferences.dart';

/// Stockage local des jetons de session (access_token / refresh_token)
/// et de l'identifiant utilisateur, via shared_preferences.
///
/// Remarque : shared_preferences n'est pas chiffré. Pour une version
/// production, envisager flutter_secure_storage pour les tokens.
class TokenStorage {
  TokenStorage._();
  static final TokenStorage instance = TokenStorage._();

  static const _kAccessToken = 'fripay_access_token';
  static const _kRefreshToken = 'fripay_refresh_token';
  static const _kUserId = 'fripay_user_id';
  static const _kPhoneNumber = 'fripay_phone_number';

  Future<void> saveTokens({
    required String accessToken,
    required String refreshToken,
  }) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kAccessToken, accessToken);
    await prefs.setString(_kRefreshToken, refreshToken);
  }

  Future<void> saveUser({required String userId, required String phoneNumber}) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kUserId, userId);
    await prefs.setString(_kPhoneNumber, phoneNumber);
  }

  Future<String?> get accessToken async =>
      (await SharedPreferences.getInstance()).getString(_kAccessToken);

  Future<String?> get refreshToken async =>
      (await SharedPreferences.getInstance()).getString(_kRefreshToken);

  Future<String?> get userId async => (await SharedPreferences.getInstance()).getString(_kUserId);

  Future<String?> get phoneNumber async =>
      (await SharedPreferences.getInstance()).getString(_kPhoneNumber);

  Future<bool> get hasSession async => (await accessToken) != null;

  Future<void> clear() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_kAccessToken);
    await prefs.remove(_kRefreshToken);
    await prefs.remove(_kUserId);
    await prefs.remove(_kPhoneNumber);
  }
}
