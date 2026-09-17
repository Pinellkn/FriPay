import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';

import 'package:fripay_app/models/models.dart';
import 'package:fripay_app/services/api_client.dart';
import 'package:fripay_app/services/auth_service.dart';
import 'package:fripay_app/services/biometric_service.dart';
import 'package:fripay_app/services/network_prefixes.dart';
import 'package:fripay_app/services/token_storage.dart';
import 'package:fripay_app/theme/app_colors.dart';
import 'package:fripay_app/widgets/fripay_logo.dart';
import 'package:fripay_app/widgets/operator_dot.dart';
import 'package:fripay_app/widgets/otp_input_row.dart';
import 'package:fripay_app/widgets/app_scaffold.dart';

/// Portage fidèle de src/routes/auth.tsx : onglets Connexion / Créer un
/// compte. Branché sur l'API réelle (fripay-users via le gateway) :
/// - Connexion : numéro + PIN -> POST /auth/login
/// - Inscription : nom + numéro + PIN -> POST /auth/register -> OTP SMS ->
///   POST /auth/verify-otp -> POST /users/me/pin (définit le PIN choisi)
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> with SingleTickerProviderStateMixin {
  late final TabController _tab = TabController(length: 2, vsync: this);

  // --- Connexion ---
  final _loginPhoneCtrl = TextEditingController();
  final _loginPinCtrl = TextEditingController();
  bool _loggingIn = false;
  String? _loginError;
  // Connexion rapide par empreinte : dispo seulement si le téléphone
  // supporte la biométrie, qu'elle est activée, et qu'un PIN a été
  // mémorisé lors d'une précédente connexion (voir BiometricService).
  bool _biometricAvailable = false;
  bool _biometricChecking = false;
  String? _biometricPhone;
  String _biometricLabel = 'Connexion par empreinte';
  IconData _biometricIcon = Icons.fingerprint_rounded;

  // --- Inscription ---
  bool _otpStep = false;
  final _nameCtrl = TextEditingController();
  final _emailCtrl = TextEditingController();
  // Le champ ne contient QUE la partie nationale (01XXXXXXXX) : l'indicatif
  // +229 est affiché en prefixText et n'est pas modifiable (cahier §2).
  final _signupPhoneCtrl = TextEditingController(text: NetworkPrefixes.nationalPrefix);
  // Opérateur choisi à l'inscription (cahier §2) — sert à vérifier que le
  // préfixe saisi appartient bien à ce réseau.
  OperatorId _signupOperator = OperatorId.mtn;
  // Erreur de numéro calculée en direct pendant la frappe.
  String? _signupPhoneError;
  final _pinCtrl = TextEditingController();
  final _pinConfirmCtrl = TextEditingController();
  final _otpKey = GlobalKey<OtpInputRowState>();
  String _otpValue = '';
  bool _otpError = false;
  String? _otpErrorMessage;
  bool _verifying = false;
  bool _registering = false;
  String? _registerError;
  // Code OTP renvoyé par l'API UNIQUEMENT en environnement dev, tant
  // qu'aucun fournisseur SMS n'est branché (voir AuthService.register).
  // Affiché directement dans l'app pour permettre de tester sans SMS réel ;
  // sera automatiquement absent (null) dès qu'un vrai SMS partira en prod.
  String? _devOtpCode;

  // --- Confirmation email (cahier §2), étape après l'OTP SMS ---
  bool _emailStep = false;
  final _emailOtpKey = GlobalKey<OtpInputRowState>();
  String _emailOtpValue = '';
  bool _emailOtpError = false;
  String? _emailOtpErrorMessage;
  bool _verifyingEmail = false;
  bool _resendingEmail = false;
  String? _devEmailOtpCode;

  @override
  void initState() {
    super.initState();
    _loadBiometricAvailability();
  }

  @override
  void dispose() {
    _tab.dispose();
    _loginPhoneCtrl.dispose();
    _loginPinCtrl.dispose();
    _nameCtrl.dispose();
    _emailCtrl.dispose();
    _signupPhoneCtrl.dispose();
    _pinCtrl.dispose();
    _pinConfirmCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadBiometricAvailability() async {
    final supported = await BiometricService.instance.isDeviceSupported();
    final phone = await TokenStorage.instance.phoneNumber;
    // ensureOwnedBy garantit que le flag "activé" + le PIN mémorisé
    // appartiennent bien à CE numéro — sinon (compte précédent sur le
    // même appareil) il désactive silencieusement plutôt que de proposer
    // une "Connexion par empreinte" qui utiliserait le PIN d'un autre compte.
    final enabled = phone != null && await BiometricService.instance.ensureOwnedBy(phone);
    final pin = enabled ? await BiometricService.instance.getPin() : null;
    
    String label = 'Connexion par empreinte';
    IconData icon = Icons.fingerprint_rounded;
    if (supported) {
      label = await BiometricService.instance.getLoginButtonLabel();
      icon = await BiometricService.instance.getIcon();
    }

    if (!mounted) return;
    setState(() {
      _biometricAvailable = supported && enabled && pin != null;
      _biometricPhone = phone;
      _biometricLabel = label;
      _biometricIcon = icon;
    });
  }

  Future<void> _loginWithBiometric() async {
    final phone = _biometricPhone;
    if (phone == null || _biometricChecking) return;
    setState(() => _biometricChecking = true);
    final name = await BiometricService.instance.getLocalizedName();
    final ok = await BiometricService.instance.authenticate(
      reason: 'Déverrouillez FriPay avec votre $name',
    );
    if (!mounted) return;
    if (!ok) {
      setState(() => _biometricChecking = false);
      return;
    }
    final pin = await BiometricService.instance.getPin();
    if (pin == null) {
      if (!mounted) return;
      setState(() => _biometricChecking = false);
      return;
    }
    try {
      await AuthService.instance.login(phoneNumber: phone, pin: pin);
      if (!mounted) return;
      _enterApp();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _biometricChecking = false;
        _loginError = e.userMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _biometricChecking = false;
        _loginError = 'Une erreur est survenue. Réessayez.';
      });
    }
  }

  Future<void> _submitLogin() async {
    // Cahier §3 : deux méthodes alternatives par PIN — numéro FriPay + PIN,
    // ou numéro d'opérateur + PIN. Les deux formats sont acceptés ici.
    final input = _loginPhoneCtrl.text.trim();
    final isFripay = NetworkPrefixes.isFripayNumber(input);
    if (!isFripay && !AuthService.isValidPhone(input)) {
      setState(() => _loginError = AuthService.phoneError(input) ??
          'Numéro invalide. Utilisez votre numéro FriPay (30 XX XX XX XX) '
              'ou votre numéro opérateur (+229 01 XX XX XX XX).');
      return;
    }
    if (_loginPinCtrl.text.trim().length != 5) {
      setState(() => _loginError = 'Le code PIN doit contenir 5 chiffres.');
      return;
    }
    setState(() {
      _loggingIn = true;
      _loginError = null;
    });
    try {
      await AuthService.instance.login(
        phoneNumber: _loginPhoneCtrl.text.trim(),
        pin: _loginPinCtrl.text.trim(),
      );
      if (!mounted) return;
      _enterApp();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loggingIn = false;
        _loginError = e.userMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loggingIn = false;
        _loginError = 'Une erreur est survenue. Réessayez.';
      });
    }
  }

  /// Validation en direct du numéro d'inscription (cahier §2) : `01`
  /// obligatoire, préfixe cohérent avec l'opérateur choisi, 10 chiffres.
  void _revalidateSignupPhone() {
    setState(() {
      _signupPhoneError = _signupPhoneCtrl.text.trim() == NetworkPrefixes.nationalPrefix
          ? null // champ encore vide (juste le "01" pré-rempli) : pas d'erreur
          : NetworkPrefixes.validationError(
              _signupPhoneCtrl.text,
              expected: _signupOperator,
            );
      _registerError = null;
    });
  }

  static final RegExp _emailPattern = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]{2,}$');

  Future<void> _submitRegister() async {
    final phone = _signupPhoneCtrl.text.trim();
    final email = _emailCtrl.text.trim();
    final pin = _pinCtrl.text.trim();
    final pinConfirm = _pinConfirmCtrl.text.trim();

    // Numéro : format + cohérence avec l'opérateur choisi (cahier §2 / §4).
    final phoneErr = NetworkPrefixes.validationError(phone, expected: _signupOperator);
    if (phoneErr != null) {
      setState(() {
        _signupPhoneError = phoneErr;
        _registerError = phoneErr;
      });
      return;
    }
    // Email obligatoire (cahier §2).
    if (email.isEmpty) {
      setState(() => _registerError = "L'adresse email est obligatoire.");
      return;
    }
    if (!_emailPattern.hasMatch(email)) {
      setState(() => _registerError = 'Adresse email invalide.');
      return;
    }
    if (pin.length != 5) {
      setState(() => _registerError = 'Le code PIN doit contenir 5 chiffres.');
      return;
    }
    if (pin != pinConfirm) {
      setState(() => _registerError = 'Les deux codes PIN ne correspondent pas.');
      return;
    }
    setState(() {
      _registering = true;
      _registerError = null;
    });
    final parts = _nameCtrl.text.trim().split(RegExp(r'\s+'));
    final firstName = parts.isNotEmpty ? parts.first : null;
    final lastName = parts.length > 1 ? parts.sublist(1).join(' ') : null;
    try {
      final result = await AuthService.instance.register(
        phoneNumber: phone,
        email: email,
        firstName: firstName,
        lastName: lastName,
      );
      if (!mounted) return;
      setState(() {
        _registering = false;
        _otpStep = true;
        _devOtpCode = result.devOtpCode;
        _devEmailOtpCode = result.devEmailOtpCode;
      });
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Code de vérification envoyé par SMS.')),
      );
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _registering = false;
        _registerError = e.userMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _registering = false;
        _registerError = 'Une erreur est survenue. Réessayez.';
      });
    }
  }

  Future<void> _verifyOtp() async {
    if (_otpValue.length != 6) return;
    setState(() {
      _verifying = true;
      _otpError = false;
      _otpErrorMessage = null;
    });
    try {
      await AuthService.instance.verifyOtp(
        phoneNumber: _signupPhoneCtrl.text.trim(),
        code: _otpValue,
        purpose: 'registration',
      );
      // Le compte vient d'être créé et vérifié côté SMS : on enregistre le
      // PIN choisi à l'étape précédente comme PIN de connexion.
      await AuthService.instance.setPin(newPin: _pinCtrl.text.trim());
      if (!mounted) return;
      // Cahier §2 : la confirmation email reste requise même une fois le
      // numéro vérifié par SMS — on ne l'enterApp() qu'après ce 2e code.
      setState(() {
        _verifying = false;
        _otpStep = false;
        _emailStep = true;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _verifying = false;
        _otpError = true;
        _otpErrorMessage = e.userMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _verifying = false;
        _otpError = true;
        _otpErrorMessage = 'Une erreur est survenue. Réessayez.';
      });
    }
  }

  Future<void> _verifyEmailCode() async {
    if (_emailOtpValue.length != 6) return;
    setState(() {
      _verifyingEmail = true;
      _emailOtpError = false;
      _emailOtpErrorMessage = null;
    });
    try {
      await AuthService.instance.verifyEmail(
        email: _emailCtrl.text.trim(),
        code: _emailOtpValue,
      );
      if (!mounted) return;
      // Session déjà ouverte depuis _verifyOtp() (access/refresh token +
      // PIN déjà définis) : il ne reste plus qu'à entrer dans l'app.
      _enterApp();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _verifyingEmail = false;
        _emailOtpError = true;
        _emailOtpErrorMessage = e.userMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _verifyingEmail = false;
        _emailOtpError = true;
        _emailOtpErrorMessage = 'Une erreur est survenue. Réessayez.';
      });
    }
  }

  Future<void> _resendEmailCode() async {
    if (_resendingEmail) return;
    setState(() => _resendingEmail = true);
    try {
      final devCode = await AuthService.instance.resendEmailVerification(
        email: _emailCtrl.text.trim(),
      );
      if (!mounted) return;
      setState(() {
        _resendingEmail = false;
        _devEmailOtpCode = devCode;
      });
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Nouveau code envoyé par email.')),
      );
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _resendingEmail = false);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.userMessage)));
    } catch (_) {
      if (!mounted) return;
      setState(() => _resendingEmail = false);
    }
  }

  void _enterApp() {
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => const AppScaffold()),
      (route) => false,
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      body: SafeArea(
        child: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const _HeroBanner(),
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 22, 20, 28),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const FripayLogo(),
                    const SizedBox(height: 20),
                    Card(
                      child: Padding(
                        padding: const EdgeInsets.all(18),
                        child: Column(
                          children: [
                            Container(
                              decoration: BoxDecoration(
                                color: AppColors.muted,
                                borderRadius: BorderRadius.circular(999),
                              ),
                              padding: const EdgeInsets.all(4),
                              child: TabBar(
                                controller: _tab,
                                indicator: BoxDecoration(
                                  color: AppColors.card,
                                  borderRadius: BorderRadius.circular(999),
                                  boxShadow: const [BoxShadow(color: Colors.black12, blurRadius: 4)],
                                ),
                                labelColor: AppColors.foreground,
                                unselectedLabelColor: AppColors.mutedForeground,
                                dividerColor: Colors.transparent,
                                labelStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5),
                                tabs: const [Tab(text: 'Connexion'), Tab(text: 'Créer un compte')],
                              ),
                            ),
                            AnimatedBuilder(
                              animation: _tab,
                              builder: (context, _) => _tab.index == 0 ? _loginTab() : _signupTab(),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _loginTab() {
    return Padding(
      padding: const EdgeInsets.only(top: 20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Numéro FriPay ou numéro opérateur',
              style: GoogleFonts.manrope(fontWeight: FontWeight.w700, fontSize: 13)),
          const SizedBox(height: 8),
          TextField(
            controller: _loginPhoneCtrl,
            keyboardType: TextInputType.phone,
            decoration: const InputDecoration(
              hintText: '30 12 34 56 78  ou  +229 01 97 00 00 00',
            ),
          ),
          const SizedBox(height: 14),
          Text('Code PIN', style: GoogleFonts.manrope(fontWeight: FontWeight.w700, fontSize: 13)),
          const SizedBox(height: 8),
          TextField(
            controller: _loginPinCtrl,
            obscureText: true,
            maxLength: 5,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(hintText: '•••••', counterText: ''),
          ),
          if (_loginError != null) ...[
            const SizedBox(height: 6),
            Text(_loginError!, style: const TextStyle(color: AppColors.destructive, fontSize: 12, fontWeight: FontWeight.w600)),
          ],
          const SizedBox(height: 18),
          ElevatedButton(
            onPressed: _loggingIn ? null : _submitLogin,
            child: _loggingIn
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Text('Se connecter'),
          ),
          if (_biometricAvailable) ...[
            const SizedBox(height: 12),
            Row(
              children: [
                const Expanded(child: Divider(color: AppColors.border)),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 10),
                  child: Text('ou', style: TextStyle(color: AppColors.mutedForeground, fontSize: 11.5)),
                ),
                const Expanded(child: Divider(color: AppColors.border)),
              ],
            ),
            const SizedBox(height: 12),
            OutlinedButton.icon(
              onPressed: _biometricChecking ? null : _loginWithBiometric,
              icon: _biometricChecking
                  ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                  : Icon(_biometricIcon, size: 20),
              label: Text(_biometricLabel),
              style: OutlinedButton.styleFrom(
                foregroundColor: AppColors.primary,
                side: const BorderSide(color: AppColors.primary),
                padding: const EdgeInsets.symmetric(vertical: 13),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _signupTab() {
    if (_emailStep) {
      return Padding(
        padding: const EdgeInsets.only(top: 20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Confirmez votre email', style: GoogleFonts.manrope(fontWeight: FontWeight.w700, fontSize: 13)),
            const SizedBox(height: 4),
            Text(
              'Un code à 6 chiffres a été envoyé à ${_emailCtrl.text.trim()}.',
              style: TextStyle(color: AppColors.mutedForeground, fontSize: 11, height: 1.35),
            ),
            const SizedBox(height: 6),
            OtpInputRow(
              key: _emailOtpKey,
              onChanged: (v) => setState(() {
                _emailOtpValue = v;
                _emailOtpError = false;
              }),
              onCompleted: (_) => _verifyEmailCode(),
            ),
            if (_devEmailOtpCode != null) ...[
              const SizedBox(height: 10),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                decoration: BoxDecoration(
                  color: AppColors.muted,
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: AppColors.border),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.info_outline_rounded, size: 16, color: AppColors.mutedForeground),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'Mode test — mailer non branché. Code : $_devEmailOtpCode',
                        style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600, color: AppColors.mutedForeground),
                      ),
                    ),
                    TextButton(
                      style: TextButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 8), minimumSize: Size.zero),
                      onPressed: () => _emailOtpKey.currentState?.fill(_devEmailOtpCode!),
                      child: const Text('Remplir', style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700)),
                    ),
                  ],
                ),
              ),
            ],
            if (_emailOtpError) ...[
              const SizedBox(height: 8),
              Text(_emailOtpErrorMessage ?? 'Code incorrect. Vérifiez votre email et réessayez.',
                  style: const TextStyle(color: AppColors.destructive, fontSize: 12, fontWeight: FontWeight.w600)),
            ],
            const SizedBox(height: 18),
            ElevatedButton(
              onPressed: (_emailOtpValue.length == 6 && !_verifyingEmail) ? _verifyEmailCode : null,
              child: _verifyingEmail
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Text('Confirmer'),
            ),
            const SizedBox(height: 10),
            Center(
              child: TextButton(
                onPressed: _resendingEmail ? null : _resendEmailCode,
                child: Text(_resendingEmail ? 'Envoi...' : "Je n'ai pas reçu de code"),
              ),
            ),
          ],
        ),
      );
    }
    if (_otpStep) {
      return Padding(
        padding: const EdgeInsets.only(top: 20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Code de confirmation', style: GoogleFonts.manrope(fontWeight: FontWeight.w700, fontSize: 13)),
            const SizedBox(height: 4),
            Text(
              'Un code à 6 chiffres a été envoyé au '
              '${NetworkPrefixes.format(_signupPhoneCtrl.text)}.',
              style: TextStyle(color: AppColors.mutedForeground, fontSize: 11, height: 1.35),
            ),
            const SizedBox(height: 6),
            OtpInputRow(
              key: _otpKey,
              onChanged: (v) => setState(() {
                _otpValue = v;
                _otpError = false;
              }),
              onCompleted: (_) => _verifyOtp(),
            ),
            if (_devOtpCode != null) ...[
              const SizedBox(height: 10),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                decoration: BoxDecoration(
                  color: AppColors.muted,
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: AppColors.border),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.info_outline_rounded, size: 16, color: AppColors.mutedForeground),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'Mode test — SMS non branché. Code : $_devOtpCode',
                        style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600, color: AppColors.mutedForeground),
                      ),
                    ),
                    TextButton(
                      style: TextButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 8), minimumSize: Size.zero),
                      onPressed: () => _otpKey.currentState?.fill(_devOtpCode!),
                      child: const Text('Remplir', style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700)),
                    ),
                  ],
                ),
              ),
            ],
            if (_otpError) ...[
              const SizedBox(height: 8),
              Text(_otpErrorMessage ?? 'Code incorrect. Vérifiez le SMS et réessayez.',
                  style: const TextStyle(color: AppColors.destructive, fontSize: 12, fontWeight: FontWeight.w600)),
            ],
            const SizedBox(height: 18),
            ElevatedButton(
              onPressed: (_otpValue.length == 6 && !_verifying) ? _verifyOtp : null,
              child: _verifying
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Text('Entrer dans FriPay'),
            ),
          ],
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.only(top: 20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Nom complet', style: GoogleFonts.manrope(fontWeight: FontWeight.w700, fontSize: 13)),
          const SizedBox(height: 8),
          TextField(controller: _nameCtrl, decoration: const InputDecoration(hintText: 'Aïcha Dossou')),
          const SizedBox(height: 14),
          Row(
            children: [
              Text('Adresse email', style: GoogleFonts.manrope(fontWeight: FontWeight.w700, fontSize: 13)),
              const SizedBox(width: 4),
              const Text('*', style: TextStyle(color: AppColors.destructive, fontWeight: FontWeight.w800)),
            ],
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _emailCtrl,
            keyboardType: TextInputType.emailAddress,
            autocorrect: false,
            decoration: const InputDecoration(hintText: 'aicha.dossou@example.bj'),
          ),
          const SizedBox(height: 14),
          Text('Votre opérateur', style: GoogleFonts.manrope(fontWeight: FontWeight.w700, fontSize: 13)),
          const SizedBox(height: 8),
          Wrap(
            spacing: 10,
            runSpacing: 10,
            children: [OperatorId.mtn, OperatorId.moov, OperatorId.celtiis].map((id) {
              final selected = id == _signupOperator;
              return ChoiceChip(
                selected: selected,
                onSelected: (_) {
                  setState(() => _signupOperator = id);
                  _revalidateSignupPhone();
                },
                avatar: OperatorDot(id: id, size: 8),
                label: Text(operatorById(id).short),
                selectedColor: AppColors.primary.withValues(alpha: 0.14),
                labelStyle: TextStyle(
                  fontWeight: FontWeight.w700,
                  color: selected ? AppColors.primary : AppColors.foreground,
                ),
              );
            }).toList(),
          ),
          const SizedBox(height: 14),
          Text('Numéro principal', style: GoogleFonts.manrope(fontWeight: FontWeight.w700, fontSize: 13)),
          const SizedBox(height: 8),
          TextField(
            controller: _signupPhoneCtrl,
            keyboardType: TextInputType.phone,
            // L'indicatif +229 est affiché mais ne fait PAS partie du champ :
            // impossible à modifier ou à supprimer par l'utilisateur (§2).
            inputFormatters: [
              FilteringTextInputFormatter.digitsOnly,
              LengthLimitingTextInputFormatter(NetworkPrefixes.fripayLength),
            ],
            onChanged: (_) => _revalidateSignupPhone(),
            decoration: InputDecoration(
              hintText: '01 97 00 00 00',
              errorText: _signupPhoneError,
              helperText: _signupPhoneError == null
                  ? 'Commencez par 01, puis le préfixe '
                      '${operatorById(_signupOperator).short}.'
                  : null,
              helperMaxLines: 2,
              errorMaxLines: 3,
            ),
          ),
          const SizedBox(height: 14),
          Text('Code PIN (5 chiffres)', style: GoogleFonts.manrope(fontWeight: FontWeight.w700, fontSize: 13)),
          const SizedBox(height: 8),
          TextField(
            controller: _pinCtrl,
            obscureText: true,
            maxLength: 5,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(hintText: '•••••', counterText: ''),
          ),
          const SizedBox(height: 14),
          Text('Confirmez le code PIN', style: GoogleFonts.manrope(fontWeight: FontWeight.w700, fontSize: 13)),
          const SizedBox(height: 8),
          TextField(
            controller: _pinConfirmCtrl,
            obscureText: true,
            maxLength: 5,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(hintText: '•••••', counterText: ''),
          ),
          if (_registerError != null) ...[
            const SizedBox(height: 6),
            Text(_registerError!, style: const TextStyle(color: AppColors.destructive, fontSize: 12, fontWeight: FontWeight.w600)),
          ],
          const SizedBox(height: 10),
          ElevatedButton(
            onPressed: _registering ? null : _submitRegister,
            child: _registering
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Text('Créer mon compte'),
          ),
          const SizedBox(height: 10),
          const Text(
            'En continuant, vous acceptez les conditions FriPay et la politique de confidentialité.',
            style: TextStyle(color: AppColors.mutedForeground, fontSize: 11.5),
          ),
        ],
      ),
    );
  }
}

class _HeroBanner extends StatelessWidget {
  const _HeroBanner();

  @override
  Widget build(BuildContext context) {
    final height = MediaQuery.of(context).size.height;
    return SizedBox(
      height: height * 0.22, // 22% de la hauteur de l'écran
      child: Stack(
        fit: StackFit.expand,
        children: [
          Image.asset('assets/images/hero-fripay.jpg', fit: BoxFit.cover),
          Container(color: AppColors.primaryDeep.withValues(alpha: 0.45)),
          Positioned(
            left: 20,
            right: 20,
            bottom: 16,
            child: Text(
              'Un compte, tous les opérateurs.',
              style: GoogleFonts.sora(
                color: Colors.white,
                fontSize: 19,
                fontWeight: FontWeight.w800,
                height: 1.2,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
