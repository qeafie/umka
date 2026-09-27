<?php

namespace App\Services\Max;

final readonly class MaxLaunchIdentity
{
    public function __construct(public string $userId, public string $name) {}
}
