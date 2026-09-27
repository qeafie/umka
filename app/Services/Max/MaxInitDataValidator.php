<?php

namespace App\Services\Max;

class MaxInitDataValidator
{
    public function validate(string $initData): ?MaxLaunchIdentity
    {
        $botToken = config('services.max.bot_token');

        if (! is_string($botToken) || $botToken === '' || $initData === '' || strlen($initData) > 8192) {
            return null;
        }

        $parameters = $this->parseParameters($initData);

        if ($parameters === null || ! isset($parameters['hash'], $parameters['auth_date'], $parameters['user'])) {
            return null;
        }

        $receivedHash = strtolower($parameters['hash']);

        if (! preg_match('/\A[a-f0-9]{64}\z/', $receivedHash)) {
            return null;
        }

        if (! ctype_digit($parameters['auth_date'])) {
            return null;
        }

        $authDate = (int) $parameters['auth_date'];
        $now = now()->timestamp;

        if ($authDate > $now + (int) config('services.max.clock_skew_seconds', 60)
            || $authDate < $now - (int) config('services.max.init_data_ttl_seconds', 86400)) {
            return null;
        }

        unset($parameters['hash']);
        ksort($parameters, SORT_STRING);

        $dataCheckString = collect($parameters)
            ->map(fn (string $value, string $key): string => $key.'='.$value)
            ->implode("\n");
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (! hash_equals($calculatedHash, $receivedHash)) {
            return null;
        }

        $user = json_decode($parameters['user'], true, 512, JSON_BIGINT_AS_STRING);

        if (! is_array($user) || (! is_int($user['id'] ?? null) && ! is_string($user['id'] ?? null))) {
            return null;
        }

        $userId = trim((string) $user['id']);

        if ($userId === '' || strlen($userId) > 255 || ! ctype_digit($userId)) {
            return null;
        }

        $name = trim(implode(' ', array_filter([
            is_string($user['first_name'] ?? null) ? trim($user['first_name']) : '',
            is_string($user['last_name'] ?? null) ? trim($user['last_name']) : '',
        ])));

        return new MaxLaunchIdentity(
            userId: $userId,
            name: $name !== '' ? mb_substr($name, 0, 255) : 'Житель MAX',
        );
    }

    /**
     * @return array<string, string>|null
     */
    private function parseParameters(string $initData): ?array
    {
        $parameters = [];

        foreach (explode('&', $initData) as $pair) {
            if ($pair === '' || ! str_contains($pair, '=')) {
                return null;
            }

            [$rawKey, $rawValue] = explode('=', $pair, 2);
            $key = urldecode($rawKey);

            if ($key === '' || array_key_exists($key, $parameters)) {
                return null;
            }

            $parameters[$key] = urldecode($rawValue);
        }

        return $parameters;
    }
}
