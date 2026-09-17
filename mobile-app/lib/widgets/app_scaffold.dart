import 'package:flutter/material.dart';

import '../screens/history/history_screen.dart';
import '../screens/home/home_screen.dart';
import '../screens/profile/profile_screen.dart';
import '../screens/receive/receive_screen.dart';
import '../screens/send/send_screen.dart';
import '../theme/app_colors.dart';

/// Coquille principale de l'app une fois connecté — portage de
/// components/fripay/AppShell.tsx : la barre de navigation basse reprend
/// exactement les 5 mêmes entrées que la version mobile du web
/// (Accueil, Envoyer, Recevoir, Historique, Profil). Les 5 autres sections
/// (Dépôt/Retrait, Factures, Portefeuilles, Hors ligne, Plaintes) restent
/// accessibles depuis la grille d'actions rapides de l'accueil — comme sur
/// le site — et depuis le menu du profil.
class AppScaffold extends StatefulWidget {
  const AppScaffold({super.key});

  @override
  State<AppScaffold> createState() => _AppScaffoldState();
}

class _AppScaffoldState extends State<AppScaffold> {
  int _index = 0;

  static const _screens = [
    HomeScreen(),
    SendScreen(),
    ReceiveScreen(),
    HistoryScreen(),
    ProfileScreen(),
  ];

  static const _items = [
    (icon: Icons.home_rounded, label: 'Accueil'),
    (icon: Icons.compare_arrows_rounded, label: 'Envoyer'),
    (icon: Icons.qr_code_rounded, label: 'Recevoir'),
    (icon: Icons.history_rounded, label: 'Historique'),
    (icon: Icons.person_rounded, label: 'Profil'),
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(child: IndexedStack(index: _index, children: _screens)),
      bottomNavigationBar: DecoratedBox(
        decoration: BoxDecoration(
          color: AppColors.background,
          border: Border(top: BorderSide(color: AppColors.border)),
        ),
        child: SafeArea(
          top: false,
          child: SizedBox(
            height: 64,
            child: Row(
              children: List.generate(_items.length, (i) {
                final selected = i == _index;
                final item = _items[i];
                return Expanded(
                  child: InkWell(
                    onTap: () => setState(() => _index = i),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(
                          item.icon,
                          size: 22,
                          color: selected ? AppColors.primary : AppColors.mutedForeground,
                        ),
                        const SizedBox(height: 3),
                        Text(
                          item.label,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            fontSize: 10.5,
                            fontWeight: FontWeight.w700,
                            color: selected ? AppColors.primary : AppColors.mutedForeground,
                          ),
                        ),
                      ],
                    ),
                  ),
                );
              }),
            ),
          ),
        ),
      ),
    );
  }
}
