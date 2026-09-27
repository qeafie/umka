<?php

namespace App\Http\Controllers;

use App\DTOs\HousingRequestDraftData;
use Illuminate\Http\JsonResponse;

class HousingRequestDraftController extends Controller
{
    public function __invoke(HousingRequestDraftData $data): JsonResponse
    {
        $issueTitle = collect(config('emergency-guides'))
            ->firstWhere('id', $data->issueType)['title'];

        $lines = [
            'В управляющую организацию (УК/ТСЖ)',
            '',
        ];

        if ($data->residentName !== null) {
            $lines[] = 'От: '.$data->residentName;
        }

        $lines[] = 'Адрес: '.$data->address;

        if ($data->apartment !== null) {
            $lines[] = 'Квартира: '.$data->apartment;
        }

        $lines = [
            ...$lines,
            'Тема: '.$issueTitle,
            '',
            'Прошу зарегистрировать обращение и проверить ситуацию по указанному адресу.',
            '',
            'Описание ситуации:',
            $data->details,
            '',
            'Прошу сообщить номер обращения и дальнейший порядок действий.',
        ];

        return response()->json([
            'draft' => implode("\n", $lines),
            'deliveryStatus' => 'draft_only',
        ]);
    }
}
