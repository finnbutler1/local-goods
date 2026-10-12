<?php

namespace Tests\Feature;

use Tests\TestCase;

class StyleguideTest extends TestCase
{
    public function test_styleguide_is_hidden_outside_local(): void
    {
        $this->get('/styleguide')->assertNotFound();

        $this->app['env'] = 'production';
        $this->get('/styleguide')->assertNotFound();
    }

    public function test_styleguide_shows_every_token_and_component_locally(): void
    {
        $this->app['env'] = 'local';

        $this->get('/styleguide')
            ->assertOk()
            ->assertSee('--color-forest')
            ->assertSee('--color-amber-ink')
            ->assertSeeInOrder(['Heading 1', 'Heading 2', 'Heading 3', 'Heading 4'])
            ->assertSee('Buttons')
            ->assertSee('Cards')
            ->assertSee('Form inputs')
            ->assertSee('Badges')
            ->assertSee('Tables')
            ->assertSee('aria-invalid="true"', false);
    }
}
