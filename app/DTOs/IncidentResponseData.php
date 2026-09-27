<?php

namespace App\DTOs;

use Illuminate\Validation\Rule;
use WendellAdriel\ValidatedDTO\Concerns\EmptyCasts;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class IncidentResponseData extends ValidatedDTO
{
    use EmptyCasts;

    public string $stage;

    public string $answer;

    protected function rules(): array
    {
        return [
            'stage' => ['required', 'string', Rule::in(['scope', 'recovery'])],
            'answer' => ['required', 'string', Rule::in([
                'problem_present', 'service_working', 'cannot_check', 'restored', 'problem_remains',
            ])],
        ];
    }

    protected function defaults(): array
    {
        return [];
    }
}
