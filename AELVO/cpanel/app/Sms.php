<?php
declare(strict_types=1);
namespace Aelvo;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * BulkSMSBD OTP transport, selected for AELVO. Submission is NOT delivery or
 * phone verification. Missing/invalid credentials and ambiguous replies fail closed.
 * The only network destination is the provider's documented HTTPS single-SMS API.
 */
final class Sms {
    private const ENDPOINT = 'https://bulksmsbd.net/api/smsapi';
    private const NOT_CONFIGURED = 'Phone verification is not configured.';
    private const SEND_FAILED = 'Unable to send your verification code. Please try again later.';

    public function __construct(private PDO $db, private string $keyHex) {}

    /** Internal server-side use only. Never render the returned key to a client. */
    public static function loadCredentials(PDO $db, string $keyHex): array {
        if (!function_exists('sodium_crypto_secretbox_open') ||
            !preg_match('/^[a-f0-9]{64}$/D', $keyHex)) {
            throw new RuntimeException(self::NOT_CONFIGURED);
        }
        $q = $db->prepare('SELECT ciphertext FROM aelvo_provider_secrets WHERE provider=?');
        $q->execute(['bulksmsbd']);
        $encoded = $q->fetchColumn();
        $raw = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException(self::NOT_CONFIGURED);
        }
        $plaintext = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            hex2bin($keyHex)
        );
        if ($plaintext === false) throw new RuntimeException(self::NOT_CONFIGURED);
        try { $data = json_decode($plaintext, true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new RuntimeException(self::NOT_CONFIGURED); }
        if (!is_array($data) ||
            !is_string($data['api_key'] ?? null) || trim($data['api_key']) === '' ||
            !is_string($data['senderid'] ?? null) ||
            !preg_match('/^[A-Za-z0-9]{3,30}$/D', $data['senderid']) ||
            !in_array($data['otp_language'] ?? null, ['bn', 'en'], true)) {
            throw new RuntimeException(self::NOT_CONFIGURED);
        }
        return $data;
    }

    /** Settings UI may show only non-secret information, never a key or a test verdict. */
    public static function status(PDO $db, string $keyHex): array {
        try {
            $data = self::loadCredentials($db, $keyHex);
            return ['configured' => true, 'senderid' => $data['senderid'], 'otp_language' => $data['otp_language']];
        } catch (RuntimeException $e) {
            return ['configured' => false, 'senderid' => '', 'otp_language' => 'bn'];
        }
    }

    /** Pure parser: 202 means SUBMITTED, never delivered or OTP-verified. */
    public static function acceptedResponse(string $body): bool {
        if (strlen($body) > 16384) return false;
        $response = json_decode($body, true);
        if (is_array($response) && !array_is_list($response)) {
            return in_array($response['response_code'] ?? null, [202, '202'], true);
        }
        // Some legacy installations return the documented API code as a bare integer.
        return in_array($response, [202, '202'], true);
    }

    public function send(string $phone, string $code): void {
        // Normalize to the international 8801... format required by BulkSMSBD.
        $number = '88' . Input::phone($phone);
        if (!preg_match('/^[0-9]{6}$/D', $code)) throw new InvalidArgumentException('Invalid verification code.');
        $data = self::loadCredentials($this->db, $this->keyHex);
        if (!function_exists('curl_init')) throw new RuntimeException('Phone verification is temporarily unavailable.');
        $message = $data['otp_language'] === 'bn'
            ? 'AELVO যাচাইকরণ কোড: ' . $code . '। মেয়াদ ৫ মিনিট। কোডটি কাউকে বলবেন না।'
            : 'AELVO verification code: ' . $code . '. Valid for 5 minutes. Do not share.';
        $fields = [
            'api_key' => $data['api_key'],
            'senderid' => $data['senderid'],
            'number' => $number,
            'message' => $message,
        ];
        $curl = curl_init(self::ENDPOINT);
        if ($curl === false) throw new RuntimeException(self::SEND_FAILED);
        $response = '';
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > 16384) return 0;
                $response .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) $options[CURLOPT_PROTOCOLS_STR] = 'https';
        else $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        try {
            if (!curl_setopt_array($curl, $options)) throw new RuntimeException(self::SEND_FAILED);
            $ok = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($curl);
        }
        // Do not log or expose the provider reply: it may contain identifying data.
        if ($ok === false || $status < 200 || $status >= 300 || !self::acceptedResponse($response)) {
            throw new RuntimeException(self::SEND_FAILED);
        }
    }
}
