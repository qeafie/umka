<?php

namespace App\DTOs;

use WendellAdriel\ValidatedDTO\Concerns\EmptyCasts;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class HouseLayoutData extends ValidatedDTO
{
    use EmptyCasts;

    /** @var array<int, array{entrance: int, floors: int}> */
    public array $layout;

    protected function rules(): array
    {
        return [
            'layout' => ['present', 'array', 'max:20'],
            'layout.*' => ['array:entrance,floors'],
            'layout.*.entrance' => ['required', 'integer', 'min:1', 'max:99', 'distinct'],
            'layout.*.floors' => ['required', 'integer', 'min:1', 'max:60'],
        ];
    }

    protected function defaults(): array
    {
        return [];
    }
}
