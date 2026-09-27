<?php

namespace App\DTOs;

use WendellAdriel\ValidatedDTO\Concerns\EmptyCasts;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class MeterReadingData extends ValidatedDTO
{
    use EmptyCasts;

    public string $reading;

    protected function rules(): array
    {
        return [
            'reading' => ['required', 'string', 'regex:/\A\d{1,9}(?:[.,]\d{1,3})?\z/'],
        ];
    }

    protected function defaults(): array
    {
        return [];
    }
}
