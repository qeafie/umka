<?php

namespace App\DTOs;

use WendellAdriel\ValidatedDTO\Concerns\EmptyCasts;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class HouseInvitationData extends ValidatedDTO
{
    use EmptyCasts;

    public string $apartment;

    public ?int $entrance;

    public ?int $floor;

    protected function rules(): array
    {
        return [
            'apartment' => ['required', 'string', 'max:20'],
            'entrance' => ['nullable', 'required_with:floor', 'integer', 'min:1', 'max:99'],
            'floor' => ['nullable', 'required_with:entrance', 'integer', 'min:1', 'max:60'],
        ];
    }

    protected function defaults(): array
    {
        return ['entrance' => null, 'floor' => null];
    }
}
