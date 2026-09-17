import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

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
