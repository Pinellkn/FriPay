import 'package:flutter/material.dart';
import 'package:intl/date_symbol_data_local.dart';

import 'screens/splash/splash_screen.dart';
import 'services/notification_service.dart';
import 'theme/app_theme.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  // Requis par formatters.dart (DateFormat('...', 'fr_FR')) — sans cet
  // appel, tout DateFormat avec une locale explicite lève une
  // LocaleDataException au premier appel (ex. écran Plaintes :
  // ComplaintService.toTicket() -> formatDateTime() -> _shortDateFormat).
  // Cette exception n'est pas une ApiException donc elle atterrit dans le
  // catch générique de l'écran -> "Une erreur est survenue.".
  await initializeDateFormatting('fr_FR', null);
  // Prépare le canal de notifications système et demande la permission
  // (obligatoire à partir d'Android 13) avant l'affichage du splash.
  await NotificationService.instance.init();
  runApp(const FripayApp());
}

/// Point d'entrée FriPay Mobile — portage fidèle de benin-money-hub-main.
/// Démarre sur le SplashScreen (dégradé emerald) puis Connexion -> AppScaffold.
class FripayApp extends StatelessWidget {
  const FripayApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'FriPay',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light,
      home: const SplashScreen(),
    );
  }
}
