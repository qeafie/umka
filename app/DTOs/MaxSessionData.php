<?php

namespace App\DTOs;

use WendellAdriel\ValidatedDTO\Concerns\EmptyCasts;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class MaxSessionData extends ValidatedDTO
{
    use EmptyCasts;

    public string $initData;

    protected function rules(): array
    {
        return [
            'initData' => ['required', 'string', 'max:8192'],
        ];
    }

    protected function defaults(): array
    {
        return [];
    }
}
