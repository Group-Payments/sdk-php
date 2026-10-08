<?php


declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    throw new \ErrorException($msg, 0, $no, $file, $line);
});

require __DIR__ . '/../src/ApiError.php';
require __DIR__ . '/../src/Webhook.php';
require __DIR__ . '/../src/Client.php';

use GroupPayments\{ApiError, Client, Webhook};

$cases = [];
function test(string $name, callable $fn): void
{
    $GLOBALS['cases'][$name] = $fn;
}

function ok(bool $cond, string $what): void
{
    if (!$cond) {
        throw new \LogicException("assertion failed: {$what}");
    }
}

function throws(string $class, callable $fn): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw $e;
    }
    throw new \LogicException("expected {$class}");
}

function fake(array $responses, ?\ArrayObject $log = null): Client
{
    $log ??= new \ArrayObject();
    $log['calls'] = [];
    $log['sleeps'] = [];
    return new Client('gp_test_abcdefgh_' . str_repeat('x', 32), [
        'base_url' => 'https://api.test/v1',
        'transport' => static function (string $m, string $u, array $h, ?string $b) use (&$responses, $log): array {
            $log['calls'] = [...$log['calls'], [$m, $u, $h, $b]];
            $r = array_shift($responses) ?? throw new \LogicException('unexpected request');
            if ($r instanceof \Throwable) {
                throw $r;
            }
            return $r;
        },
        'sleep' => static function (float $s) use ($log): void {
            $log['sleeps'] = [...$log['sleeps'], $s];
        },
    ]);
}

function res(int $status, mixed $body = null, array $headers = []): array
{
    return ['status' => $status, 'headers' => $headers, 'body' => $body === null ? '' : json_encode($body)];
}

test('one idempotency key across retries', function () {
    $log = new \ArrayObject();
    $c = fake([res(500, []), res(201, ['id' => 'p1'])], $log);
    ok($c->createPayment(['amount' => '10.00', 'description' => 'd']) === ['id' => 'p1'], 'returns the body');
    [$a, $b] = $log['calls'];
    ok($a[2]['Idempotency-Key'] === $b[2]['Idempotency-Key'], 'same key');
    ok($log['sleeps'] === [0.5], 'backoff 0.5 s');
    ok($a[3] === '{"amount":"10.00","description":"d"}', 'JSON body');
});

test('typed 429 after 3 attempts, Retry-After honoured', function () {
    $log = new \ArrayObject();
    $e429 = res(429, ['error' => ['code' => 'rate_limited', 'message' => 'Too many', 'requestId' => 'req_1']], ['Retry-After' => '2']);
    $e = throws(ApiError::class, fn () => fake([$e429, $e429, $e429], $log)->balance());
    ok([$e->status, $e->errorCode, $e->requestId] === [429, 'rate_limited', 'req_1'], 'error fields');
    ok($log['sleeps'] === [2.0, 2.0], 'slept by Retry-After');
});

test('4xx is not retried; request id falls back to the header', function () {
    $log = new \ArrayObject();
    $e = throws(ApiError::class, fn () => fake([res(404, ['error' => 'odd']), res(200, [])], $log)
        ->getPayment('p 1'));
    ok($e->status === 404 && $e->errorCode === null, 'non-object error tolerated');
    ok(count($log['calls']) === 1 && $log['calls'][0][1] === 'https://api.test/v1/payments/p%201', 'one call, id encoded');
    $e = throws(ApiError::class, fn () => fake([res(400, null, ['GP-Request-Id' => 'req_h'])])->balance());
    ok($e->requestId === 'req_h', 'GP-Request-Id header');
});

test('network errors retried; GET has no idempotency key', function () {
    $log = new \ArrayObject();
    fake([new \RuntimeException('down'), res(200, ['items' => []])], $log)->listPayments(['limit' => 1, 'orderId' => 'o', 'x' => null]);
    $call = $log['calls'][1];
    ok($call[1] === 'https://api.test/v1/payments?limit=1&orderId=o', 'query without nulls');
    ok(!isset($call[2]['Idempotency-Key']) && $call[3] === null, 'no key, no body');
    $e = throws(ApiError::class, fn () => fake([new \RuntimeException('a'), new \RuntimeException('b'), new \RuntimeException('c')])->balance());
    ok($e->errorCode === 'network_error' && $e->status === null, 'network_error after 3 attempts');
});

test('empty bodies are JSON objects, not lists', function () {
    $log = new \ArrayObject();
    $c = fake([res(201, ['id' => 'r1']), res(201, ['deliveries' => []]), res(201, ['id' => 'p'])], $log);
    $c->refundPayment('p1');
    $c->resendEvent('e1');
    $c->cancelPayment('p1');
    ok($log['calls'][0][3] === '{}' && $log['calls'][1][3] === '{}', 'refund and resend send {}');
    ok($log['calls'][2][3] === null && !isset($log['calls'][2][2]['Content-Type']), 'cancel sends no body');
});

test('Retry-After is clamped to 0..30 s', function () {
    ok(Client::retryDelay(1, ['retry-after' => '-5']) === 0.0, 'negative');
    ok(Client::retryDelay(1, ['Retry-After' => '120']) === 30.0, 'too long');
    ok(Client::retryDelay(3, ['Retry-After' => 'Wed, 21 Oct 2015 07:28:00 GMT']) === 2.0, 'date falls back to backoff');
});

test('rejects malformed keys', function () {
    throws(\InvalidArgumentException::class, fn () => new Client('sk_live_123'));
});

$body = '{"event":"payment.succeeded"}';
$v2 = 't=1000,v1=' . hash_hmac('sha256', "1000.{$body}", 'new') . ',v1=' . hash_hmac('sha256', "1000.{$body}", 'old');

test('verify v2 with the previous secret, reject stale and tampered', function () use ($body, $v2) {
    ok(Webhook::verify($body, ['GP-Signature-V2' => $v2], 'old', 300, 1100)['event'] === 'payment.succeeded', 'previous secret');
    throws(\UnexpectedValueException::class, fn () => Webhook::verify($body, ['GP-Signature-V2' => $v2], 'old', 300, 1301));
    throws(\UnexpectedValueException::class, fn () => Webhook::verify($body . ' ', ['GP-Signature-V2' => $v2], 'old', 300, 1000));
    throws(\UnexpectedValueException::class, fn () => Webhook::verify($body, ['GP-Signature-V2' => 't=1e3,v1=x'], 'old', 300, 1000));
    throws(\UnexpectedValueException::class, fn () => Webhook::verify($body, [], 'old'));
});

test('sign round trip and v1 fallback', function () use ($body) {
    ok(Webhook::verify($body, Webhook::sign($body, 's', 5), 's', 300, 5)['event'] === 'payment.succeeded', 'round trip');
    ok(Webhook::verify($body, ['gp-signature' => hash_hmac('sha256', $body, 's')], 's')['event'] === 'payment.succeeded', 'v1');
});

test('backend signature vector (PSR-7 style header lists)', function () {
    $v = json_decode((string) file_get_contents(__DIR__ . '/webhook-vector.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($v['secrets'] as $s) {
        $ev = Webhook::verify($v['body'], ['Gp-Signature-V2' => [$v['headers']['GP-Signature-V2']]], $s, 300, $v['t']);
        ok($ev['payment']['description'] === 'Заказ №1042', 'both rotation secrets verify');
    }
    $signed = Webhook::sign($v['body'], $v['secrets'][0], $v['t']);
    ok($signed['GP-Signature'] === $v['headers']['GP-Signature'], 'v1 header matches the backend');
    ok(str_starts_with($v['headers']['GP-Signature-V2'], $signed['GP-Signature-V2'] . ','), 'v2 header matches the backend');
});

$failed = 0;
foreach ($cases as $name => $fn) {
    try {
        $fn();
        echo "ok   {$name}" . PHP_EOL;
    } catch (\Throwable $e) {
        $failed++;
        echo "FAIL {$name}: " . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' . PHP_EOL;
    }
}
echo count($cases) - $failed . '/' . count($cases) . ' passed' . PHP_EOL;
exit($failed || !$cases ? 1 : 0);
