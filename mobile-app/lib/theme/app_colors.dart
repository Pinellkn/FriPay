import 'package:flutter/material.dart';

/// Palette FriPay — "Deep emerald + gold on warm ivory".
/// Portée fidèlement depuis les variables OKLCH de src/styles.css du web
/// (benin-money-hub-main) pour garder exactement la même identité visuelle.
class AppColors {
  AppColors._();

  // Fond / texte
  static const background = Color(0xFFFAF9F4);
  static const foreground = Color(0xFF1E2B25);
  static const card = Color(0xFFFFFFFF);

  // Emerald (primaire)
  static const primary = Color(0xFF1E7A5C);
  static const primaryDeep = Color(0xFF16332A);
  static const primaryGlow = Color(0xFF3FAE85);
  static const primaryForeground = Color(0xFFFBFAF6);

  // Secondaire (vert pâle ivoire)
  static const secondary = Color(0xFFEEF3E9);
  static const secondaryForeground = Color(0xFF223B31);

  // Neutres
  static const muted = Color(0xFFF4F2EC);
  static const mutedForeground = Color(0xFF6B7C74);
  static const border = Color(0xFFDEE2D6);

  // Or (accent)
  static const accent = Color(0xFFE0AA3E);
  static const accentForeground = Color(0xFF4A3213);

  // États
  static const destructive = Color(0xFFD93A2B);
  static const success = Color(0xFF3FA86B);
  static const warning = Color(0xFFE0A23A);

  // Opérateurs mobiles (Bénin)
  static const mtn = Color(0xFFF2C94C);
  static const moov = Color(0xFF3B5FDB);
  static const celtiis = Color(0xFFD8432E);

  // Barre latérale / navigation sombre
  static const sidebar = Color(0xFF17332A);
  static const sidebarForeground = Color(0xFFEDF3E8);
  static const sidebarAccent = Color(0xFF2C4A3E);

  static const gradientEmerald = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [primaryDeep, primary, primaryGlow],
  );

  static const gradientGold = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xFFEFC868), accent],
  );

  static Color operatorColor(String operatorId) {
    switch (operatorId) {
      case 'mtn':
        return mtn;
      case 'moov':
        return moov;
      case 'celtiis':
        return celtiis;
      default:
        return primary;
    }
  }
}
