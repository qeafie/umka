<?php

namespace App\Services\Max;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MaxBotClient
{
    public function post(string $path, array $query, array $body): Response
    {
        $token = config('services.max.bot_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('MAX is not configured.');
        }

        return Http::baseUrl('https://platform-api2.max.ru')->withHeaders(['Authorization' => $token])
            ->acceptJson()->connectTimeout(3)->timeout(8)->post($path.($query ? '?'.http_build_query($query) : ''), $body);
    }
}
