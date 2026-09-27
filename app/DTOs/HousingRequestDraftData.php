<?php

namespace App\DTOs;

use Illuminate\Validation\Rule;
use WendellAdriel\ValidatedDTO\Concerns\EmptyCasts;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class HousingRequestDraftData extends ValidatedDTO
{
    use EmptyCasts;

    public string $issueType;

    public string $address;

    public ?string $apartment;

    public ?string $residentName;

    public string $details;

    protected function rules(): array
    {
        return [
            'issueType' => ['required', 'string', Rule::in(['water', 'electricity', 'gas', 'heating'])],
            'address' => ['required', 'string', 'max:255'],
            'apartment' => ['nullable', 'string', 'max:20'],
            'residentName' => ['nullable', 'string', 'max:120'],
            'details' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    protected function defaults(): array
    {
        return [
            'apartment' => null,
            'residentName' => null,
        ];
    }
}
