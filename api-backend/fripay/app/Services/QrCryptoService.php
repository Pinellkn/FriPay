<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Service cryptographique pour les QR Codes FriPay.
 *
 * Supporte deux modes de paiement :
 * - MPM (Merchant Present Mode) : le client scanne le QR du marchand
 * - CPM (Customer Present Mode)  : le marchand scanne le QR du client
 *
 * Et deux types de QR :
 * - Static  : identité du marchand uniquement, montant saisi manuellement
 * - Dynamic : montant pré-rempli, expire automatiquement
 *
 * Utilise Sodium (Ed25519) pour la signature asymétrique.
 */
class QrCryptoService
{
    // ── Key Generation ────────────────────────────────────────────────

    /**
     * Génère une paire de clés Ed25519 pour un utilisateur.
     *
     * @return array{secret_key: string, public_key: string, keypair_b64: string}
     */
    public function generateKeyPair(): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        return [
            'secret_key'   => $secretKey,
            'public_key'   => $publicKey,
            'keypair_b64'  => base64_encode($keypair),
        ];
    }

    // ── Signed Payloads ───────────────────────────────────────────────

    /**
     * Crée et signe un paquet de données pour un QR Code dynamique
     * (montant pré-rempli).
     *
     * @param int         $amount         Montant en FCFA
     * @param string      $currency       Devise (XOF par défaut)
     * @param string      $secretKey      Clé secrète Ed25519
     * @param string      $publicKey      Clé publique Ed25519
     * @param string|null $recipientHint  Indice sur le destinataire
     * @param string|null $expiresAt      Date d'expiration ISO 8601
     * @param string      $mode           cpm ou mpm
     * @param string|null $description    Description du paiement
     * @return array{payload: string, signature: string, uuid: string, qr_content: string}
     */
    public function createSignedPayload(
        int $amount,
        string $currency,
        string $secretKey,
        string $publicKey,
        ?string $recipientHint = null,
        ?string $expiresAt = null,
        string $mode = 'mpm',
        ?string $description = null,
    ): array {
        $uuid = (string) Str::uuid();
        $timestamp = now()->toIso8601String();

        $data = [
            'version'        => '1.0',
            'uuid'           => $uuid,
            'type'           => 'dynamic',
            'mode'           => $mode,
            'amount'         => $amount,
            'currency'       => $currency,
            'sender_pubkey'  => base64_encode($publicKey),
            'timestamp'      => $timestamp,
            'recipient_hint' => $recipientHint,
            'expires_at'     => $expiresAt,
            'description'    => $description,
        ];

        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $signature = $this->sign($payload, $secretKey);
        $qrContent = $this->buildQrContent($payload, $signature);

        return [
            'payload'     => $payload,
            'signature'   => $signature,
            'uuid'        => $uuid,
            'qr_content'  => $qrContent,
        ];
    }

    /**
     * Crée et signe un paquet pour un QR Code statique
     * (pas de montant — le payeur le saisira).
     *
     * @param string      $currency       Devise (XOF par défaut)
     * @param string      $secretKey      Clé secrète Ed25519
     * @param string      $publicKey      Clé publique Ed25519
     * @param string|null $recipientHint  Indice sur le destinataire
     * @param string|null $expiresAt      Date d'expiration ISO 8601
     * @param string      $mode           cpm ou mpm
     * @param string|null $description    Description du paiement
     * @return array{payload: string, signature: string, uuid: string, qr_content: string}
     */
    public function createStaticPayload(
        string $currency,
        string $secretKey,
        string $publicKey,
        ?string $recipientHint = null,
        ?string $expiresAt = null,
        string $mode = 'mpm',
        ?string $description = null,
    ): array {
        $uuid = (string) Str::uuid();
        $timestamp = now()->toIso8601String();

        $data = [
            'version'        => '1.0',
            'uuid'           => $uuid,
            'type'           => 'static',
            'mode'           => $mode,
            'currency'       => $currency,
            'sender_pubkey'  => base64_encode($publicKey),
            'timestamp'      => $timestamp,
            'recipient_hint' => $recipientHint,
            'expires_at'     => $expiresAt,
            'description'    => $description,
        ];

        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $signature = $this->sign($payload, $secretKey);
        $qrContent = $this->buildQrContent($payload, $signature);

        return [
            'payload'     => $payload,
            'signature'   => $signature,
            'uuid'        => $uuid,
            'qr_content'  => $qrContent,
        ];
    }

    // ── Signature Verification ────────────────────────────────────────

    /**
     * Vérifie la signature Ed25519 d'un QR Code.
     *
     * @param string $payload   Le payload JSON
     * @param string $signature La signature base64
     * @param string $publicKey La clé publique Ed25519 (raw 32 bytes ou base64)
     * @return bool
     */
    public function verifySignature(string $payload, string $signature, string $publicKey): bool
    {
        $signatureDecoded = base64_decode($signature, true);
        if ($signatureDecoded === false) {
            return false;
        }

        // Accepter la clé publique en raw ou en base64
        $publicKeyRaw = base64_decode($publicKey, true);
        if ($publicKeyRaw !== false && strlen($publicKeyRaw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            $publicKeyDecoded = $publicKeyRaw;
        } else {
            $publicKeyDecoded = $publicKey;
        }

        if (strlen($publicKeyDecoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        try {
            $result = sodium_crypto_sign_open($signatureDecoded . $payload, $publicKeyDecoded);
            return $result !== false;
        } catch (\SodiumException) {
            return false;
        }
    }

    /**
     * Vérifie l'intégrité complète d'un QR Code.
     *
     * @param string $qrPayload Le payload JSON complet (format FriPay)
     * @return array{valid: bool, data: ?array, error: ?string}
     */
    public function verifyQrIntegrity(string $qrPayload): array
    {
        $data = json_decode($qrPayload, true);
        if (!$data || !isset($data['payload'], $data['signature'])) {
            return ['valid' => false, 'data' => null, 'error' => 'Format de QR Code invalide'];
        }

        // Vérifier le magic number de l'app
        if (($data['app'] ?? '') !== 'fripay') {
            return ['valid' => false, 'data' => null, 'error' => 'QR Code non reconnu (app inconnue)'];
        }

        $payloadData = json_decode($data['payload'], true);
        if (!$payloadData) {
            return ['valid' => false, 'data' => null, 'error' => 'Payload invalide'];
        }

        // Vérifier la signature
        $valid = $this->verifySignature(
            $data['payload'],
            $data['signature'],
            base64_decode($payloadData['sender_pubkey'] ?? '', true) ?: ($payloadData['sender_pubkey'] ?? '')
        );

        if (!$valid) {
            return ['valid' => false, 'data' => $payloadData, 'error' => 'Signature invalide'];
        }

        // Vérifier l'expiration (si applicable)
        if (isset($payloadData['expires_at']) && $payloadData['expires_at']) {
            $expiresAt = \Carbon\Carbon::parse($payloadData['expires_at']);
            if ($expiresAt->isPast()) {
                return ['valid' => false, 'data' => $payloadData, 'error' => 'QR Code expiré'];
            }
        }

        return ['valid' => true, 'data' => $payloadData, 'error' => null];
    }

    // ── QR Content Building ───────────────────────────────────────────

    /**
     * Génère le QR code complet (payload signé) à encoder en image.
     *
     * @param string $payload   Le payload JSON
     * @param string $signature La signature base64
     * @return string Le JSON complet à encoder en QR
     */
    public function buildQrContent(string $payload, string $signature): string
    {
        return json_encode([
            'app'       => 'fripay',
            'version'   => '1.0',
            'type'      => 'offline_qr',
            'payload'   => $payload,
            'signature' => $signature,
        ], JSON_UNESCAPED_SLASHES);
    }

    // ── QR Image Encoding ─────────────────────────────────────────────

    /**
     * Encode des données en QR code (PNG base64).
     *
     * @param string $data Données à encoder
     * @param int    $size Taille en pixels (défaut 300)
     * @return string      Base64 PNG ou données textuelles
     */
    public function encodeToQrImage(string $data, int $size = 300): string
    {
        if (class_exists(\chillerlan\QRCode\QRCode::class)) {
            $qrcode = new \chillerlan\QRCode\QRCode(new \chillerlan\QRCode\QROptions([
                'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,
                'eccLevel'   => \chillerlan\QRCode\QRCode::ECC_M,
                'scale'      => max(1, intdiv($size, 50)),
            ]));
            return $qrcode->render($data);
        }

        return $data;
    }

    /**
     * Encode des données en QR code en texte (pour debug/terminal).
     *
     * @param string $data Données à encoder
     * @return string      QR en ASCII art
     */
    public function encodeToQrAscii(string $data): string
    {
        if (class_exists(\chillerlan\QRCode\QRCode::class)) {
            $qrcode = new \chillerlan\QRCode\QRCode(new \chillerlan\QRCode\QROptions([
                'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_MARKDOWN_TABLE,
                'eccLevel'   => \chillerlan\QRCode\QRCode::ECC_M,
            ]));
            return $qrcode->render($data);
        }

        return '[QR lib not installed - use frontend qrcode.js]';
    }

    // ── Key Utilities ─────────────────────────────────────────────────

    /**
     * Convertit une clé publique raw en base64 pour stockage/transport.
     */
    public function publicKeyToBase64(string $publicKey): string
    {
        return base64_encode($publicKey);
    }

    /**
     * Convertit une clé publique base64 en raw.
     */
    public function publicKeyFromBase64(string $publicKeyB64): string
    {
        $raw = base64_decode($publicKeyB64, true);
        return $raw !== false ? $raw : $publicKeyB64;
    }

    /**
     * Nettoie les clés sensibles de la mémoire.
     */
    public function wipeKey(string &$key): void
    {
        sodium_memzero($key);
        $key = '';
    }

    // ── Private Helpers ───────────────────────────────────────────────

    /**
     * Signe des données avec une clé secrète Ed25519.
     *
     * @param string $data      Données à signer
     * @param string $secretKey Clé secrète Ed25519 (64 bytes)
     * @return string           Signature en base64
     */
    private function sign(string $data, string $secretKey): string
    {
        if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException(
                'Clé secrète invalide: attendu ' . SODIUM_CRYPTO_SIGN_SECRETKEYBYTES .
                ' bytes, reçu ' . strlen($secretKey)
            );
        }

        $signature = sodium_crypto_sign($data, $secretKey);

        // sodium_crypto_sign retourne signature || message, on ne garde que la signature
        $signatureOnly = substr($signature, 0, SODIUM_CRYPTO_SIGN_BYTES);

        sodium_memzero($signature);

        return base64_encode($signatureOnly);
    }
}
