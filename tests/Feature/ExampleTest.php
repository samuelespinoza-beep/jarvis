<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_redirects_to_the_assistant()
    {
        $response = $this->get(route('home'));

        $response->assertRedirect('/assistant');
    }

    public function test_the_old_jarvis_address_still_works()
    {
        $this->actingAs(User::factory()->create())
            ->get('/jarvis')
            ->assertRedirect('/assistant');

        $this->get('/assistant')->assertOk();
    }
}
