<?php

namespace App\DTOs;

use WendellAdriel\ValidatedDTO\Concerns\EmptyCasts;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class HouseMemberLocationData extends ValidatedDTO
{
    use EmptyCasts;

    public ?int $entrance;

    public ?int $floor;

    protected function rules(): array
    {
        return [
            'entrance' => ['present', 'nullable', 'required_with:floor', 'integer', 'min:1', 'max:99'],
            'floor' => ['present', 'nullable', 'required_with:entrance', 'integer', 'min:1', 'max:60'],
        ];
    }

    protected function defaults(): array
    {
        return [];
    }
}
