<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    public function test_home_page_renders_the_vue_application(): void
    {
        $this->withoutVite();
        config(['app.name' => 'Пульс дома']);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home')
                ->where('appName', 'Пульс дома')
            );
    }

    public function test_inertia_navigation_returns_the_page_payload(): void
    {
        $this->withoutVite();
        config(['app.name' => 'Пульс дома']);
        $version = $this->get('/')->viewData('page')['version'];

        $this->get('/', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version ?? '',
        ])
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'Home')
            ->assertJsonPath('props.appName', 'Пульс дома');
    }

    public function test_health_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }
}
