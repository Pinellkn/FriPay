import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../theme/app_colors.dart';

/// Portage de components/fripay/Logo.tsx : badge "F" dégradé + wordmark.
class FripayLogo extends StatelessWidget {
  final bool inverted;
  final double size;

  const FripayLogo({super.key, this.inverted = false, this.size = 36});

  @override
  Widget build(BuildContext context) {
    final textColor = inverted ? Colors.white : AppColors.foreground;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: size,
          height: size,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            gradient: inverted ? AppColors.gradientGold : AppColors.gradientEmerald,
            borderRadius: BorderRadius.circular(size * 0.28),
          ),
          child: Text(
            'F',
            style: GoogleFonts.sora(
              fontSize: size * 0.5,
              fontWeight: FontWeight.w800,
              color: inverted ? AppColors.accentForeground : Colors.white,
            ),
          ),
        ),
        const SizedBox(width: 10),
        Text.rich(
          TextSpan(
            style: GoogleFonts.sora(
              fontSize: size * 0.44,
              fontWeight: FontWeight.w800,
              letterSpacing: -0.4,
              color: textColor,
            ),
            children: [
              const TextSpan(text: 'Fri'),
              TextSpan(
                text: 'Pay',
                style: TextStyle(color: textColor.withValues(alpha: 0.72)),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
