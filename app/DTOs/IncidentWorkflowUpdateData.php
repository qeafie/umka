<?php

namespace App\DTOs;

use Illuminate\Validation\Rule;
use WendellAdriel\ValidatedDTO\Concerns\EmptyCasts;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class IncidentWorkflowUpdateData extends ValidatedDTO
{
    use EmptyCasts;

    public string $status;

    public string $assignedTo;

    public string $nextAction;

    public string $nextUpdateAt;

    protected function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['in_progress', 'work_completed'])],
            'assignedTo' => ['required', 'string', 'max:120'],
            'nextAction' => ['required', 'string', 'min:5', 'max:500'],
            'nextUpdateAt' => ['required', 'date'],
        ];
    }

    protected function defaults(): array
    {
        return [];
    }
}
