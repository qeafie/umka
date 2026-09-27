<?php

namespace App\DTOs;

use Illuminate\Validation\Rule;
use WendellAdriel\ValidatedDTO\Concerns\EmptyCasts;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class MeterData extends ValidatedDTO
{
    use EmptyCasts;

    public string $name;

    public string $service;

    public ?string $serialNumber;

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'service' => ['required', 'string', Rule::in(array_keys(config('meters.services')))],
            'serialNumber' => ['nullable', 'string', 'max:40'],
        ];
    }

    protected function defaults(): array
    {
        return ['serialNumber' => null];
    }
}
