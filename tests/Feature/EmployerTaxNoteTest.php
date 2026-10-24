<?php

namespace Tests\Feature;

use App\Enums\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The employer dashboard's one note on how credits are taxed for employees
 * (ADR 0027): present, short, and with no numbers, so it can never read as
 * tax advice or a figure.
 */
class EmployerTaxNoteTest extends TestCase
{
    use RefreshDatabase;

    private const NOTE = 'Credits that work like cash are generally taxable wages for employees. Confirm the treatment with your tax adviser.';

    public function test_the_note_is_on_the_employer_dashboard_with_no_numbers(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach ([Role::EmployerAdmin, Role::Judge] as $role) {
            $html = $this->actingAs($this->signIn($role))->get('/employer')->assertOk()->assertSee(self::NOTE)->getContent();

            preg_match('/<p[^>]*data-tax-note[^>]*>(.*?)<\/p>/s', $html, $note);
            $this->assertSame(self::NOTE, trim($note[1] ?? ''));
            $this->assertDoesNotMatchRegularExpression('/[0-9$%]/', $note[1]);
        }
    }
}
