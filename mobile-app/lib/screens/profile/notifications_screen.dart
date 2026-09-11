import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../theme/app_colors.dart';
import '../../services/api_client.dart';
import '../../services/user_notification_service.dart' as api;
import '../../widgets/fripay_refresh.dart';

/// Centre de notifications — branché sur GET /notifications et
/// PUT /notifications/{id}/read (fripay-users). Le backend n'écrit pas
/// encore de notifications aujourd'hui (aucun service ne les crée) : la
/// liste peut donc être vide même après un transfert — c'est l'état réel,
/// pas un bug d'affichage.
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  List<api.UserNotification>? _items;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final items = await api.UserNotificationService.instance.list();
      if (!mounted) return;
      setState(() => _items = items);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e.userMessage);
    }
  }

  Future<void> _markAllRead() async {
    final unread = (_items ?? []).where((n) => !n.read).toList();
    for (final n in unread) {
      setState(() => n.read = true);
      try {
        await api.UserNotificationService.instance.markAsRead(n.id);
      } on ApiException catch (_) {
        // On laisse la case cochée localement même si un appel échoue ;
        // un prochain _load() resynchronisera avec le serveur.
      }
    }
  }

  IconData _iconFor(String type) {
    switch (type) {
      case 'transfer':
      case 'transfert':
        return Icons.swap_horiz_rounded;
      case 'security':
      case 'securite':
        return Icons.shield_rounded;
      case 'bill':
      case 'facture':
        return Icons.receipt_long_rounded;
      default:
        return Icons.notifications_rounded;
    }
  }

  Color _colorFor(String type) {
    switch (type) {
      case 'transfer':
      case 'transfert':
        return AppColors.success;
      case 'security':
      case 'securite':
        return AppColors.destructive;
      case 'bill':
      case 'facture':
        return AppColors.accent;
      default:
        return AppColors.moov;
    }
  }

  String _timeAgo(String? iso) {
    if (iso == null) return '';
    final dt = DateTime.tryParse(iso);
    if (dt == null) return '';
    final diff = DateTime.now().difference(dt);
    if (diff.inMinutes < 1) return "À l'instant";
    if (diff.inMinutes < 60) return 'Il y a ${diff.inMinutes} min';
    if (diff.inHours < 24) return 'Il y a ${diff.inHours} h';
    return 'Il y a ${diff.inDays} j';
  }

  @override
  Widget build(BuildContext context) {
    final unread = (_items ?? []).where((n) => !n.read).length;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifications'),
        actions: [
          if (unread > 0) TextButton(onPressed: _markAllRead, child: const Text('Tout marquer lu')),
        ],
      ),
      body: SafeArea(
        child: _buildBody(),
      ),
    );
  }

  Widget _buildBody() {
    if (_error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(_error!, textAlign: TextAlign.center, style: const TextStyle(color: AppColors.destructive)),
              const SizedBox(height: 12),
              OutlinedButton(onPressed: _load, child: const Text('Réessayer')),
            ],
          ),
        ),
      );
    }
    if (_items == null) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_items!.isEmpty) {
      return const _EmptyState();
    }
    return FripayRefresh(
      onRefresh: _load,
      child: ListView.separated(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        itemCount: _items!.length,
        separatorBuilder: (context, index) => const SizedBox(height: 10),
        itemBuilder: (context, i) => _tile(_items![i]),
      ),
    );
  }

  Widget _tile(api.UserNotification n) {
    return InkWell(
      borderRadius: BorderRadius.circular(16),
      onTap: n.read
          ? null
          : () async {
              setState(() => n.read = true);
              try {
                await api.UserNotificationService.instance.markAsRead(n.id);
              } on ApiException catch (_) {
                // état local déjà mis à jour ; resynchro au prochain _load()
              }
            },
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: n.read ? AppColors.card : AppColors.primary.withValues(alpha: 0.06),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: AppColors.border.withValues(alpha: 0.7)),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 40,
              height: 40,
              alignment: Alignment.center,
              decoration: BoxDecoration(color: _colorFor(n.type).withValues(alpha: 0.12), shape: BoxShape.circle),
              child: Icon(_iconFor(n.type), color: _colorFor(n.type), size: 19),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(n.title,
                            style: TextStyle(fontWeight: n.read ? FontWeight.w600 : FontWeight.w800, fontSize: 13.5)),
                      ),
                      if (!n.read)
                        Container(
                          width: 8,
                          height: 8,
                          margin: const EdgeInsets.only(left: 6, top: 2),
                          decoration: const BoxDecoration(color: AppColors.primary, shape: BoxShape.circle),
                        ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  Text(n.body, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 12.5, height: 1.4)),
                  const SizedBox(height: 6),
                  Text(_timeAgo(n.createdAt), style: const TextStyle(color: AppColors.mutedForeground, fontSize: 11)),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _EmptyState extends StatelessWidget {
  const _EmptyState();

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.notifications_off_rounded, size: 40, color: AppColors.mutedForeground),
            const SizedBox(height: 12),
            Text('Aucune notification', style: GoogleFonts.sora(fontWeight: FontWeight.w700, fontSize: 15)),
            const SizedBox(height: 6),
            const Text('Vous êtes à jour.', style: TextStyle(color: AppColors.mutedForeground, fontSize: 12.5)),
          ],
        ),
      ),
    );
  }
}
