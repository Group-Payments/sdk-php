<?php

declare(strict_types=1);

namespace GroupPayments;

final class Client
{
    public const VERSION = '0.1.2';
    private const RETRY = [429, 500, 502, 503, 504];

    private string $baseUrl;
    private int $maxAttempts;
    private $transport;
    private $sleep;

    public function __construct(private string $apiKey, array $opts = [])
    {
        if (!preg_match('/^gp_(test|live)_/', $apiKey)) {
            throw new \InvalidArgumentException('apiKey must start with gp_test_ or gp_live_');
        }
        $url = $opts['base_url'] ?? (getenv('GP_BASE_URL') ?: '');
        if ($url === '') {
            throw new \InvalidArgumentException('base_url is required (or set GP_BASE_URL)');
        }
        $this->baseUrl = rtrim($url, '/');
        $this->maxAttempts = $opts['max_attempts'] ?? 3;
        $this->transport = $opts['transport'] ?? [self::class, 'curl'];
        $this->sleep = $opts['sleep'] ?? static fn (float $s) => usleep((int) ($s * 1e6));
    }

    public static function retryDelay(int $attempt, array $headers = []): float
    {
        $ra = array_change_key_case($headers, CASE_LOWER)['retry-after'] ?? null;
        if (is_numeric($ra)) {
            return max(0.0, min((float) $ra, 30.0));
        }
        return min(0.5 * 2 ** ($attempt - 1), 8.0);
    }

    public function request(string $method, string $path, ?array $body = null, array $query = [], ?string $idempotencyKey = null): mixed
    {
        $query = array_filter($query, static fn ($v) => $v !== null);
        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'group-payments-php/' . self::VERSION,
        ];
        $payload = $body === null ? null : json_encode($body ?: new \stdClass(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($method === 'POST') {
            $headers['Idempotency-Key'] = $idempotencyKey ?? self::uuid();
        }
        for ($attempt = 1; ; $attempt++) {
            try {
                $res = ($this->transport)($method, $url, $headers, $payload);
            } catch (\RuntimeException $e) {
                if ($attempt >= $this->maxAttempts) {
                    throw new ApiError('Network error: ' . $e->getMessage(), null, 'network_error');
                }
                ($this->sleep)(self::retryDelay($attempt));
                continue;
            }
            $data = $res['body'] === '' ? null : json_decode($res['body'], true);
            if ($res['status'] < 400) {
                return $data ?? [];
            }
            if (!in_array($res['status'], self::RETRY, true) || $attempt >= $this->maxAttempts) {
                $err = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];
                $rid = $err['requestId'] ?? (array_change_key_case($res['headers'], CASE_LOWER)['gp-request-id'] ?? null);
                throw new ApiError($err['message'] ?? 'HTTP ' . $res['status'], $res['status'], $err['code'] ?? null,
                    $rid, $err['param'] ?? null, $err['docUrl'] ?? null, $err);
            }
            ($this->sleep)(self::retryDelay($attempt, $res['headers']));
        }
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
        $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public static function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        $out = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => array_map(static fn ($k, $v) => "$k: $v", array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$out): int {
                $p = explode(':', $line, 2);
                if (count($p) === 2) {
                    $out[trim($p[0])] = trim($p[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            throw new \RuntimeException(curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return ['status' => $status, 'headers' => $out, 'body' => (string) $resp];
    }

    private static function id(string $id): string
    {
        return rawurlencode($id);
    }

    public function createPayment(array $body, ?string $idem = null): array { return $this->request('POST', '/payments', $body, [], $idem); }
    public function getPayment(string $id): array { return $this->request('GET', '/payments/' . self::id($id)); }
    public function listPayments(array $query = []): array { return $this->request('GET', '/payments', null, $query); }
    public function cancelPayment(string $id, ?string $idem = null): array { return $this->request('POST', '/payments/' . self::id($id) . '/cancel', null, [], $idem); }
    public function refundPayment(string $id, array $body = [], ?string $idem = null): array { return $this->request('POST', '/payments/' . self::id($id) . '/refunds', $body, [], $idem); }
    public function getRefund(string $id): array { return $this->request('GET', '/refunds/' . self::id($id)); }
    public function listRefunds(array $query = []): array { return $this->request('GET', '/refunds', null, $query); }
    public function createPaymentLink(array $body, ?string $idem = null): array { return $this->request('POST', '/payment-links', $body, [], $idem); }
    public function getPaymentLink(string $id): array { return $this->request('GET', '/payment-links/' . self::id($id)); }
    public function listPaymentLinks(array $query = []): array { return $this->request('GET', '/payment-links', null, $query); }
    public function deactivatePaymentLink(string $id, ?string $idem = null): array { return $this->request('POST', '/payment-links/' . self::id($id) . '/deactivate', null, [], $idem); }
    public function createPayout(array $body, ?string $idem = null): array { return $this->request('POST', '/payouts', $body, [], $idem); }
    public function createPayoutBatch(array $items, ?string $idem = null): array { return $this->request('POST', '/payouts/batch', ['items' => $items], [], $idem); }
    public function getPayout(string $id): array { return $this->request('GET', '/payouts/' . self::id($id)); }
    public function getPayoutBatch(string $id): array { return $this->request('GET', '/payouts/batch/' . self::id($id)); }
    public function listPayouts(array $query = []): array { return $this->request('GET', '/payouts', null, $query); }
    public function payoutFees(): array { return $this->request('GET', '/payouts/fees'); }
    public function payoutRates(): array { return $this->request('GET', '/payouts/rates'); }
    public function balance(): array { return $this->request('GET', '/balance'); }
    public function balanceTransactions(array $query = []): array { return $this->request('GET', '/balance/transactions', null, $query); }
    public function listEvents(array $query = []): array { return $this->request('GET', '/events', null, $query); }
    public function getEvent(string $id): array { return $this->request('GET', '/events/' . self::id($id)); }
    public function resendEvent(string $id, ?string $endpointId = null): array { return $this->request('POST', '/events/' . self::id($id) . '/resend', $endpointId ? ['endpointId' => $endpointId] : []); }
    public function trigger(string $event): array { return $this->request('POST', '/test/trigger', ['event' => $event]); }
}
