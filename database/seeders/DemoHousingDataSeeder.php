<?php

namespace Database\Seeders;

use App\Models\House;
use App\Models\Incident;
use App\Models\Meter;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DemoHousingDataSeeder extends Seeder
{
    /**
     * Load a complete demo scenario from the same JSON fixture used in documentation.
     */
    public function run(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/fixtures/housing-pilot.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        DB::transaction(function () use ($fixture): void {
            $house = House::query()->updateOrCreate(
                ['address' => $fixture['house']['address']],
                ['name' => $fixture['house']['name'], 'layout' => $fixture['house']['layout']],
            );
            $members = [];

            foreach ($fixture['members'] as $member) {
                $user = User::query()->updateOrCreate(
                    ['max_user_id' => config('demo.'.$member['maxUserIdConfig'])],
                    [
                        'name' => $member['name'],
                        'email' => $member['email'],
                        'password' => null,
                        'role' => $member['role'],
                    ],
                );
                $members[$member['key']] = $user;

                DB::table('house_memberships')->updateOrInsert(
                    ['house_id' => $house->id, 'user_id' => $user->id],
                    [
                        'role' => $member['role'],
                        'apartment' => $member['apartment'],
                        'entrance' => $member['entrance'] ?? null,
                        'floor' => $member['floor'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }

            $incident = Incident::query()->updateOrCreate(
                [
                    'house_id' => $house->id,
                    'issue_type' => $fixture['incident']['issueType'],
                    'location' => $fixture['incident']['location'],
                ],
                ['status' => $fixture['incident']['status']],
            );

            foreach ($fixture['members'] as $member) {
                if (isset($member['report'])) {
                    $incident->reports()->updateOrCreate(
                        ['user_id' => $members[$member['key']]->id],
                        ['details' => $member['report']],
                    );
                }
            }

            $meter = Meter::query()->updateOrCreate(
                ['user_id' => $members[$fixture['meter']['ownerKey']]->id, 'name' => $fixture['meter']['name']],
                [
                    'service' => $fixture['meter']['service'],
                    'serial_number' => $fixture['meter']['serialNumber'],
                ],
            );
            $meter->readings()->updateOrCreate(
                [
                    'user_id' => $members[$fixture['meter']['ownerKey']]->id,
                    'recorded_at' => Carbon::parse($fixture['meter']['reading']['recordedAt']),
                ],
                ['value' => $fixture['meter']['reading']['value']],
            );
        });
    }
}
