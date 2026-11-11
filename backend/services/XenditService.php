<?php
// ============================================================
// XenditService.php
// Automates GCash / Maya disbursements via Xendit API.
// Docs: https://developers.xendit.co/api-reference/#disbursements
//
// Xendit supports Philippines GCash and Maya as disbursement
// channels. Free tier handles up to 1,000 disbursements/month.
// Production fee: ~1% per transaction (min ₱15).
//
// IMPORTANT: Xendit requires real KYC/business verification
// before live disbursements. Test with sandbox key first.
// ============================================================

declare(strict_types=1);

class XenditService {

    private const API_BASE    = 'https://api.xendit.co';
    private const TIMEOUT_SEC = 30;

    private string $secret_key;

    public function __construct() {
        $this->secret_key = getenv('XENDIT_SECRET_KEY') ?: '';
        if ($this->secret_key === '') {
            throw new RuntimeException('XENDIT_SECRET_KEY is not set in environment');
        }
    }

    // ============================================================
    // DISBURSEMENTS (single payout)
    // ============================================================

    /**
     * Send a GCash or Maya disbursement.
     *
     * @param int    $payout_id     Our internal payout_requests.id
     * @param string $channel       'GCASH' or 'PAYMAYA'
     * @param string $mobile_number Philippine mobile number (09XXXXXXXXX)
     * @param float  $amount_php    Amount in PHP (minimum ₱50)
     * @param string $account_name  Recipient's registered name
     * @return array { success, xendit_id, status, message }
     */
    public function disburse(
        int    $payout_id,
        string $channel,
        string $mobile_number,
        float  $amount_php,
        string $account_name
    ): array {
        // Xendit requires E.164 format for GCash: +639XXXXXXXXX
        $e164_number = $this->toE164($mobile_number);

        $channel_map = [
            'gcash' => 'GCASH',
            'maya'  => 'PAYMAYA',
        ];
        $xendit_channel = $channel_map[strtolower($channel)] ?? strtoupper($channel);

        $payload = [
            'external_id'       => "puzzlink_payout_{$payout_id}",
            'amount'            => (int)round($amount_php),   // Xendit uses integer PHP
            'bank_code'         => $xendit_channel,
            'account_holder_name' => $account_name,
            'account_number'    => $e164_number,
            'description'       => "PuzzLink PH cashout #{$payout_id}",
        ];

        $result = $this->post('/disbursements', $payload);

        if (isset($result['error_code'])) {
            return [
                'success' => false,
                'message' => $result['message'] ?? $result['error_code'],
            ];
        }

        return [
            'success'   => true,
            'xendit_id' => $result['id'] ?? '',
            'status'    => strtolower($result['status'] ?? 'pending'),
            'message'   => 'Disbursement submitted',
        ];
    }

    /**
     * Query the status of an existing disbursement.
     * Returns: pending | completed | failed
     */
    public function getDisbursementStatus(string $xendit_id): string {
        $result = $this->get("/disbursements/{$xendit_id}");
        return strtolower($result['status'] ?? 'unknown');
    }

    /**
     * Validate a disbursement webhook callback from Xendit.
     * Xendit sends POST to your /api/xendit/webhook endpoint.
     */
    public function validateWebhookToken(string $token): bool {
        $expected = getenv('XENDIT_WEBHOOK_TOKEN') ?: '';
        return $expected !== '' && hash_equals($expected, $token);
    }

    // ============================================================
    // PRIVATE TRANSPORT
    // ============================================================

    private function post(string $path, array $body): array {
        return $this->request('POST', $path, $body);
    }

    private function get(string $path): array {
        return $this->request('GET', $path, []);
    }

    private function request(string $method, string $path, array $body): array {
        $ch = curl_init(self::API_BASE . $path);

        $headers = [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($this->secret_key . ':'),
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);

        if ($method === 'POST' && !empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error     = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("Xendit cURL error: {$error}");
            return ['error_code' => 'NETWORK_ERROR', 'message' => $error];
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            error_log("Xendit invalid JSON response (HTTP {$http_code}): {$response}");
            return ['error_code' => 'INVALID_RESPONSE', 'message' => 'Non-JSON response'];
        }

        return $decoded;
    }

    private function toE164(string $number): string {
        // Strip all non-digits
        $digits = preg_replace('/\D/', '', $number);
        // Convert 09XXXXXXXXX → +639XXXXXXXXX
        if (str_starts_with($digits, '09')) {
            return '+63' . substr($digits, 1);
        }
        // Already +639XXXXXXXXX format
        if (str_starts_with($digits, '639')) {
            return '+' . $digits;
        }
        return '+' . $digits;
    }
}
