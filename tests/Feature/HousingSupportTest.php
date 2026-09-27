<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HousingSupportTest extends TestCase
{
    public function test_home_page_lists_guides_for_the_four_selected_emergencies(): void
    {
        $this->withoutVite();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home')
                ->has('emergencyGuides', 4)
                ->where('emergencyGuides.0.id', 'water')
                ->where('emergencyGuides.1.id', 'electricity')
                ->where('emergencyGuides.2.id', 'gas')
                ->where('emergencyGuides.3.id', 'heating')
            );
    }

    public function test_resident_can_generate_a_copyable_housing_request_draft(): void
    {
        $response = $this->postJson('/appeals/preview', [
            'issueType' => 'water',
            'address' => 'Казань, улица Примерная, дом 10',
            'apartment' => '24',
            'residentName' => 'Анна Иванова',
            'details' => 'В ванной комнате протекает труба под раковиной.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('deliveryStatus', 'draft_only')
            ->assertJsonPath('draft', fn (string $draft): bool => str_contains($draft, 'Казань, улица Примерная, дом 10')
                && str_contains($draft, 'Квартира: 24')
                && str_contains($draft, 'протекает труба под раковиной')
                && str_contains($draft, 'Анна Иванова')
            );
    }

    public function test_request_draft_requires_a_supported_issue_type_and_description(): void
    {
        $this->postJson('/appeals/preview', [
            'issueType' => 'unknown',
            'address' => 'Казань, улица Примерная, дом 10',
            'details' => 'Плохо.',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['issueType', 'details']);
    }
}
