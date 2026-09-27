<?php

namespace App\DTOs;

use Illuminate\Validation\Rule;
use WendellAdriel\ValidatedDTO\Concerns\EmptyCasts;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class HousingIncidentData extends ValidatedDTO
{
    use EmptyCasts;

    public string $issueType;

    public string $location;

    public string $details;

    public ?int $incidentId;

    protected function rules(): array
    {
        return [
            'issueType' => ['required', 'string', Rule::in(['water', 'electricity', 'gas', 'heating'])],
            'location' => ['required', 'string', 'max:120'],
            'details' => ['required', 'string', 'min:10', 'max:2000'],
            'incidentId' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function defaults(): array
    {
        return ['incidentId' => null];
    }
}
