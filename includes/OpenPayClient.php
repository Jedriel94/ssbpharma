<?php

/**
 * OpenPayClient - Wrapper para la API REST de OpenPay (BBVA México)
 *
 * Docs: https://www.openpay.mx/docs/api/
 * Auth: HTTP Basic — private_key como usuario, password vacío.
 */
class OpenPayClient
{
    private string $baseUrl;

    public function __construct(
        private string $merchantId,
        private string $privateKey,
        private bool   $sandbox = true
    ) {
        $this->baseUrl = $sandbox
            ? "https://sandbox-api.openpay.mx/v1/{$merchantId}"
            : "https://api.openpay.mx/v1/{$merchantId}";
    }

    /**
     * Crear un cargo con tarjeta (usando token_id del SDK JS).
     *
     * $data keys requeridos:
     *   source_id        — token_id generado por OpenPay.js
     *   device_session_id — generado por OpenPay.js deviceData.setup()
     *   amount           — monto en MXN (float, 2 decimales)
     *   description      — descripción del cargo
     *   order_id         — ID único del pedido (p.ej. "PEDIDO_123")
     *   redirect_url     — URL de retorno tras 3DS
     *
     * $data keys opcionales:
     *   currency         — default "MXN"
     *   capture          — default true
     */
    public function crearCargo(array $data): ?array
    {
        $payload = [
            'method'            => 'card',
            'source_id'         => $data['source_id'],
            'device_session_id' => $data['device_session_id'],
            'amount'            => round((float) $data['amount'], 2),
            'currency'          => $data['currency'] ?? 'MXN',
            'description'       => $data['description'],
            'order_id'          => $data['order_id'],
            'redirect_url'      => $data['redirect_url'],
            'capture'           => $data['capture'] ?? true,
        ];

        return $this->request('POST', '/charges', $payload);
    }

    /**
     * Obtener datos de un cargo por su ID.
     */
    public function obtenerCargo(string $chargeId): ?array
    {
        return $this->request('GET', '/charges/' . rawurlencode($chargeId));
    }

    /**
     * Verificar firma HMAC-SHA256 de un webhook de OpenPay.
     * OpenPay envía la firma en el header HTTP_X_OPENPAY_SIGNATURE.
     */
    public function verificarWebhook(string $body, string $signature): bool
    {
        $expected = hash_hmac('sha256', $body, $this->privateKey);
        return hash_equals($expected, $signature);
    }

    // ── Internals ──────────────────────────────────────────────────────

    private function request(string $method, string $endpoint, array $body = []): ?array
    {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $this->privateKey . ':',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("[OpenPay] cURL error: {$curlError}");
            return null;
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $errorCode = $decoded['error_code'] ?? '';
            $errorDesc = $decoded['description'] ?? $response;
            error_log("[OpenPay] API error HTTP {$httpCode}, code {$errorCode}: {$errorDesc}");
            // Devolver el payload de error para que el caller pueda extraer el mensaje
            return $decoded;
        }

        return $decoded;
    }
}
