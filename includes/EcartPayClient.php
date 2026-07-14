<?php

class EcartPayClient
{
    private string $baseUrl;

    /**
     * @param string        $publicKey  Public API key (read from DB)
     * @param string        $privateKey Private API key (read from DB)
     * @param bool          $sandbox    true = sandbox, false = production
     * @param callable|null $cacheGet   fn(): ?string  — returns cached token or null
     * @param callable|null $cacheSet   fn(string $token, int $expiresAt): void — persists token
     */
    public function __construct(
        private string $publicKey,
        private string $privateKey,
        private bool $sandbox = false,
        private ?\Closure $cacheGet = null,
        private ?\Closure $cacheSet = null,
    ) {
        $this->baseUrl = $sandbox
            ? 'https://sandbox.ecartpay.com'
            : 'https://ecartpay.com';
    }

    /**
     * Create a payment order in EcartPay.
     *
     * $data keys:
     *   email, first_name, last_name, phone,
     *   notify_url, redirect_url,
     *   items => [ ['name', 'quantity', 'price', 'discount'?, 'is_service'?], ... ]
     *
     * Returns ['id' => string, 'pay_link' => string] or false on failure.
     */
    public function createOrder(array $data): array|false
    {
        $token = $this->getToken();
        if (!$token) {
            return false;
        }

        $items = array_map(fn($item) => [
            'name'       => $item['name'],
            'quantity'   => (int) $item['quantity'],
            'price'      => round((float) $item['price'], 2),
            'discount'   => $item['discount'] ?? 0,
            'is_service' => $item['is_service'] ?? false,
        ], $data['items'] ?? []);

        $payload = [
            'currency'     => $data['currency'] ?? 'MXN',
            'email'        => $data['email'],
            'first_name'   => $data['first_name'],
            'last_name'    => $data['last_name'],
            'phone'        => $data['phone'] ?? '',
            'items'        => $items,
            'notify_url'   => $data['notify_url'],
            'redirect_url' => $data['redirect_url'],
        ];

        $response = $this->post('/api/orders', $payload, $token);
        if ($response === false) {
            return false;
        }

        if (empty($response['id']) || empty($response['pay_link'])) {
            return false;
        }

        return [
            'id'       => $response['id'],
            'pay_link' => $response['pay_link'],
        ];
    }

    /**
     * Fetch an order from EcartPay (use this in your webhook to verify status).
     *
     * Returns the decoded order array or false on failure.
     */
    public function getOrder(string $ecartpayOrderId): array|false
    {
        $token = $this->getToken();
        if (!$token) {
            return false;
        }

        $ch = curl_init($this->baseUrl . '/api/orders/' . rawurlencode($ecartpayOrderId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$body) {
            return false;
        }

        return json_decode($body, true) ?? false;
    }

    /**
     * Obtain a Bearer token, using cache if available.
     */
    public function getToken(): string|false
    {
        if (!$this->publicKey || !$this->privateKey) {
            return false;
        }

        if ($this->cacheGet) {
            $cached = ($this->cacheGet)();
            if ($cached) {
                return $cached;
            }
        }

        $basicAuth = base64_encode($this->publicKey . ':' . $this->privateKey);

        $ch = curl_init($this->baseUrl . '/api/authorizations/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $basicAuth,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$body) {
            return false;
        }

        $decoded = json_decode($body, true);
        $token   = $decoded['token'] ?? '';
        if (!$token) {
            return false;
        }

        // Token valid 60 min, cache for 55
        if ($this->cacheSet) {
            ($this->cacheSet)($token, time() + 3300);
        }

        return $token;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function post(string $path, array $payload, string $token): array|false
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300 || !$body) {
            return false;
        }

        return json_decode($body, true) ?? false;
    }
}
