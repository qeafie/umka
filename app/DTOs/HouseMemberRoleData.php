<?php

namespace App\DTOs;

use Illuminate\Validation\Rule;
use WendellAdriel\ValidatedDTO\Concerns\EmptyCasts;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class HouseMemberRoleData extends ValidatedDTO
{
    use EmptyCasts;

    public string $role;

    protected function rules(): array
    {
        return ['role' => ['required', 'string', Rule::in(['resident', 'dispatcher', 'moderator'])]];
    }

    protected function defaults(): array
    {
        return [];
    }
}
