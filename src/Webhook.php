<?php

declare(strict_types=1);

namespace GroupPayments;

final class Webhook
{
    public static function verify(string $body, array $headers, string $secret, int $tolerance = 300, ?int $now = null): array
    {
        $h = array_map(
            static fn ($v) => is_array($v) ? implode(',', $v) : (string) $v,
            array_change_key_case($headers, CASE_LOWER),
        );
        $v2 = $h['gp-signature-v2'] ?? '';
        if ($v2 !== '') {
            $t = null;
            $sigs = [];
            foreach (explode(',', $v2) as $part) {
                [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
                if ($k === 't') {
                    $t = $v;
                } elseif ($k === 'v1') {
                    $sigs[] = $v;
                }
            }
            if ($t === null || !preg_match('/^\d+$/D', $t) || !$sigs) {
                throw new \UnexpectedValueException('Malformed GP-Signature-V2 header');
            }
            if (abs(($now ?? time()) - (int) $t) > $tolerance) {
                throw new \UnexpectedValueException('Timestamp is outside the tolerance window');
            }
            $expected = hash_hmac('sha256', $t . '.' . $body, $secret);
            $ok = false;
            foreach ($sigs as $s) {
                $ok = $ok || hash_equals($expected, $s);
            }
            if (!$ok) {
                throw new \UnexpectedValueException('Signature mismatch');
            }
        } elseif (($h['gp-signature'] ?? '') !== '') {
            if (!hash_equals(hash_hmac('sha256', $body, $secret), $h['gp-signature'])) {
                throw new \UnexpectedValueException('Signature mismatch');
            }
        } else {
            throw new \UnexpectedValueException('No signature headers');
        }
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function sign(string $body, string $secret, ?int $t = null): array
    {
        $t ??= time();
        return [
            'GP-Signature' => hash_hmac('sha256', $body, $secret),
            'GP-Signature-V2' => "t={$t},v1=" . hash_hmac('sha256', $t . '.' . $body, $secret),
        ];
    }
}
