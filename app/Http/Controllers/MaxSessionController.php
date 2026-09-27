<?php

namespace App\Http\Controllers;

use App\DTOs\MaxSessionData;
use App\Models\User;
use App\Services\Max\MaxInitDataValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MaxSessionController extends Controller
{
    public function __invoke(MaxSessionData $data, MaxInitDataValidator $validator, Request $request): JsonResponse
    {
        if (! is_string(config('services.max.bot_token')) || config('services.max.bot_token') === '') {
            return response()->json(['message' => 'Вход через MAX пока не настроен.'], 503);
        }

        $identity = $validator->validate($data->initData);

        if ($identity === null) {
            return response()->json(['message' => 'Не удалось подтвердить запуск из MAX.'], 401);
        }

        $user = DB::transaction(function () use ($identity): User {
            $user = User::query()->where('max_user_id', $identity->userId)->lockForUpdate()->first();

            if ($user === null) {
                $user = new User([
                    'max_user_id' => $identity->userId,
                    'role' => 'resident',
                ]);
            }

            $user->name = $identity->name;
            $user->save();

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'csrfToken' => csrf_token(),
            'user' => [
                'name' => $user->name,
                'role' => $user->role,
            ],
        ]);
    }
}
