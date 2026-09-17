import 'package:flutter/material.dart';

import 'package:fripay_app/models/models.dart';
import 'package:fripay_app/theme/app_colors.dart';

/// Petit disque de couleur identifiant un opérateur (dotClass du web).
class OperatorDot extends StatelessWidget {
  final OperatorId id;
  final double size;

  const OperatorDot({super.key, required this.id, this.size = 10});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: AppColors.operatorColor(id.id),
        shape: BoxShape.circle,
      ),
    );
  }
}

/// Avatar rond avec les initiales / lettre de l'opérateur, coloré.
class OperatorAvatar extends StatelessWidget {
  final OperatorId id;
  final double size;

  const OperatorAvatar({super.key, required this.id, this.size = 44});

  @override
  Widget build(BuildContext context) {
    final color = AppColors.operatorColor(id.id);
    final op = operatorById(id);
    return Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.16),
        shape: BoxShape.circle,
      ),
      child: Text(
        op.short.substring(0, 1),
        style: TextStyle(color: color, fontWeight: FontWeight.w800, fontSize: size * 0.38),
      ),
    );
  }
}
