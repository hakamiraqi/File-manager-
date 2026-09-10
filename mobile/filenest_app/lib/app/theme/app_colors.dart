import 'package:flutter/material.dart';

/// Telegram-inspired color tokens — a bright, saturated accent blue against
/// clean neutral surfaces, the same combination Telegram's own apps use
/// (light: near-white panels, dark: deep navy panels), so nothing here is
/// tied to a single screen — every screen pulls its colors from
/// [AppTheme] which is built from these tokens.
abstract class AppColors {
  // Telegram's signature accent — the blue of its paper-plane logo and
  // outgoing-message accents.
  static const primary = Color(0xFF2AABEE);
  static const primaryVariant = Color(0xFF229ED9);

  static const success = Color(0xFF4DCD5E);
  static const danger = Color(0xFFE53935);
  static const warning = Color(0xFFF5A623);

  // Light theme — Telegram Desktop/iOS "classic" light palette.
  static const lightBackground = Color(0xFFFFFFFF);
  static const lightSurface = Color(0xFFF4F4F5);
  static const lightSurfaceVariant = Color(0xFFECECEC);
  static const lightDivider = Color(0xFFE4E4E6);
  static const lightOnSurfaceMuted = Color(0xFF707579);

  // Dark theme — Telegram Desktop "night" palette.
  static const darkBackground = Color(0xFF17212B);
  static const darkSurface = Color(0xFF232E3C);
  static const darkSurfaceVariant = Color(0xFF2B3A4A);
  static const darkDivider = Color(0xFF101921);
  static const darkOnSurfaceMuted = Color(0xFF7D8B99);
}
