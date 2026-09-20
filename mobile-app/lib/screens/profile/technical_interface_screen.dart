import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../services/api_config.dart';
import '../../services/system_service.dart';
import '../../theme/app_colors.dart';

/// §8 - Interface technique : suivi des API (gateway) + préfixes réseau.
class TechnicalInterfaceScreen extends StatefulWidget {
  const TechnicalInterfaceScreen({super.key});

  @override
  State<TechnicalInterfaceScreen> createState() => _TechnicalInterfaceScreenState();
}

class _TechnicalInterfaceScreenState extends State<TechnicalInterfaceScreen> {
  bool _loading = true;
  String? _error;
  List<ServiceStatus> _services = [];
  List<OperatorPrefixes> _operators = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final results = await Future.wait([
        SystemService.instance.fetchGatewayStatus(),
        SystemService.instance.fetchNetworkPrefixes(),
      ]);
      setState(() {
        _services = results[0] as List<ServiceStatus>;
        _operators = results[1] as List<OperatorPrefixes>;
        _loading = false;
      });
    } catch (e) {
      setState(() {
        _error = e.toString();
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Interface technique'),
        actions: [IconButton(onPressed: _load, icon: const Icon(Icons.refresh))],
      ),
      body: SafeArea(
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : _error != null
                ? _ErrorState(message: _error!, onRetry: _load)
                : RefreshIndicator(
                    onRefresh: _load,
                    child: ListView(
                      padding: const EdgeInsets.fromLTRB(18, 14, 18, 30),
                      children: [
                        _SectionTitle('Serveur'),
                        const SizedBox(height: 10),
                        _ServerCard(onChanged: _load),
                        const SizedBox(height: 26),
                        _SectionTitle('Suivi des microservices'),
                        const SizedBox(height: 10),
                        ..._services.map((s) => _ServiceTile(service: s)),
                        const SizedBox(height: 26),
                        _SectionTitle('Préfixes réseau'),
                        const SizedBox(height: 10),
                        ..._operators.map((o) => _OperatorTile(operator: o)),
                      ],
                    ),
                  ),
      ),
    );
  }
}

/// Carte "Serveur" : affiche l'adresse actuellement utilisée et permet de
/// la changer SANS recompiler (surcharge persistée dans SharedPreferences).
/// Utile dès que la box change l'IP LAN du PC hébergeant le backend.
class _ServerCard extends StatefulWidget {
  final VoidCallback onChanged;
  const _ServerCard({required this.onChanged});

  @override
  State<_ServerCard> createState() => _ServerCardState();
}

class _ServerCardState extends State<_ServerCard> {
  Future<void> _edit() async {
    final ctrl = TextEditingController(text: ApiConfig.serverOverride ?? ApiConfig.kLanHost);
    String? error;
    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
          title: const Text('Adresse du serveur'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'IP du PC qui héberge le backend (même réseau Wi-Fi). '
                'Formats acceptés : 192.168.0.8 ou 192.168.0.8:8080.',
                style: TextStyle(fontSize: 12, color: AppColors.mutedForeground),
              ),
              const SizedBox(height: 14),
              TextField(
                controller: ctrl,
                keyboardType: TextInputType.url,
                autocorrect: false,
                decoration: const InputDecoration(hintText: '192.168.0.8:8080'),
                onChanged: (_) => setDialogState(() => error = null),
              ),
              if (error != null) ...[
                const SizedBox(height: 8),
                Text(error!, style: const TextStyle(color: AppColors.destructive, fontSize: 12, fontWeight: FontWeight.w600)),
              ],
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(ctx).pop(false),
              child: const Text('Annuler'),
            ),
            if (ApiConfig.serverOverride != null)
              TextButton(
                onPressed: () => Navigator.of(ctx).pop(true),
                child: const Text('Réinitialiser'),
              ),
            TextButton(
              onPressed: () async {
                try {
                  await ApiConfig.setServerOverride(ctrl.text);
                  if (ctx.mounted) Navigator.of(ctx).pop(true);
                } on FormatException catch (e) {
                  setDialogState(() => error = e.message);
                }
              },
              child: const Text('Enregistrer', style: TextStyle(fontWeight: FontWeight.w800)),
            ),
          ],
        ),
      ),
    );
    if (saved == true && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Serveur : ${ApiConfig.baseUrl}')),
      );
      widget.onChanged();
    }
  }

  @override
  Widget build(BuildContext context) {
    final hasOverride = ApiConfig.serverOverride != null;
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        leading: Icon(
          hasOverride ? Icons.dns_rounded : Icons.computer_rounded,
          color: AppColors.primary,
        ),
        title: Text(
          ApiConfig.baseUrl,
          style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5),
        ),
        subtitle: Text(
          hasOverride
              ? 'Adresse personnalisée — appuyez pour modifier'
              : 'Adresse par défaut — appuyez pour la changer sans recompiler',
          style: const TextStyle(color: AppColors.mutedForeground, fontSize: 11.5),
        ),
        trailing: const Icon(Icons.edit_rounded, size: 18, color: AppColors.mutedForeground),
        onTap: _edit,
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  final String text;
  const _SectionTitle(this.text);

  @override
  Widget build(BuildContext context) {
    return Text(text, style: GoogleFonts.sora(fontSize: 16, fontWeight: FontWeight.w800));
  }
}

class _ServiceTile extends StatelessWidget {
  final ServiceStatus service;
  const _ServiceTile({required this.service});

  Color get _statusColor {
    switch (service.circuit) {
      case 'closed':
        return Colors.green;
      case 'half_open':
        return Colors.orange;
      case 'open':
        return Colors.red;
      default:
        return AppColors.mutedForeground;
    }
  }

  String get _statusLabel {
    switch (service.circuit) {
      case 'closed':
        return 'Opérationnel';
      case 'half_open':
        return 'En test de reprise';
      case 'open':
        return 'En panne';
      default:
        return 'Inconnu';
    }
  }

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        leading: CircleAvatar(
          radius: 6,
          backgroundColor: _statusColor,
        ),
        title: Text(service.name, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5)),
        subtitle: Text('${service.baseUrl} · $_statusLabel',
            style: const TextStyle(color: AppColors.mutedForeground, fontSize: 11.5)),
        trailing: service.failures > 0
            ? Text('${service.failures} échec(s)', style: const TextStyle(color: Colors.red, fontSize: 11))
            : null,
      ),
    );
  }
}

class _OperatorTile extends StatelessWidget {
  final OperatorPrefixes operator;
  const _OperatorTile({required this.operator});

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Text(operator.name, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5)),
                const SizedBox(width: 6),
                if (!operator.active)
                  const Text('(inactif)', style: TextStyle(color: AppColors.mutedForeground, fontSize: 11)),
              ],
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: operator.prefixes
                  .map((p) => Chip(
                        label: Text(p, style: const TextStyle(fontSize: 11)),
                        padding: EdgeInsets.zero,
                        visualDensity: VisualDensity.compact,
                        materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                      ))
                  .toList(),
            ),
          ],
        ),
      ),
    );
  }
}

class _ErrorState extends StatelessWidget {
  final String message;
  final VoidCallback onRetry;
  const _ErrorState({required this.message, required this.onRetry});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.wifi_off, size: 40, color: AppColors.mutedForeground),
            const SizedBox(height: 12),
            Text(message, textAlign: TextAlign.center, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 12.5)),
            const SizedBox(height: 14),
            OutlinedButton(onPressed: onRetry, child: const Text('Réessayer')),
          ],
        ),
      ),
    );
  }
}
