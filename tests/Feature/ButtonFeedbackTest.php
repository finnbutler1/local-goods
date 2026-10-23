<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every button shows its state (ADR 0025): pointer, hover, pressed, loading
 * and unavailable. Every form that posts already has data-busy
 * (AccessibilityTest); app.js turns that into a spinner and busy text and
 * stops a double submit. The browser behavior itself is checked by hand
 * (docs/accessibility.md); these tests pin the code that makes it happen.
 */
class ButtonFeedbackTest extends TestCase
{
    public function test_the_button_component_has_every_state(): void
    {
        $html = (string) $this->blade('<x-button>Pay</x-button>');

        foreach (['cursor-pointer', 'hover:bg-primary-hover', 'active:bg-primary-pressed', 'active:translate-y-px',
            'aria-busy:cursor-progress', 'unavailable:bg-disabled-bg', 'unavailable:text-disabled-ink', 'unavailable:cursor-not-allowed'] as $class) {
            $this->assertStringContainsString($class, $html, $class);
        }
        $this->assertStringNotContainsString('opacity-50', $html, 'Unavailable buttons are grey and readable, not see-through.');
        $this->assertStringContainsString('type="button"', $html);
    }

    public function test_every_variant_has_a_hover_state(): void
    {
        foreach (['primary', 'accent', 'secondary', 'ghost', 'danger', 'inverse', 'inverse-outline'] as $variant) {
            $html = (string) $this->blade('<x-button :variant="$v">Go</x-button>', ['v' => $variant]);
            $this->assertMatchesRegularExpression('/\bhover:/', $html, $variant);
        }
    }

    public function test_the_unavailable_look_skips_a_button_that_is_loading(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("@custom-variant unavailable (&:is(:disabled, [aria-disabled='true']):not([aria-busy='true']));", $css);
    }

    public function test_the_spinner_only_turns_when_motion_is_allowed(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression('/\.spinner \{\s*display: none;/', $css, 'Hidden by default: reduced motion shows the busy text alone.');
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: no-preference\) \{\s*\.spinner \{\s*display: inline-block;\s*animation: spin/', $css);
    }

    public function test_a_busy_form_shows_a_spinner_and_cannot_be_sent_twice(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));
        $handler = str($js)->after("document.addEventListener('submit'")->before("window.addEventListener('pageshow'")->toString();

        $this->assertStringContainsString("closest('form[data-busy]')", $handler);
        $this->assertMatchesRegularExpression("/if \(form.hasAttribute\('aria-busy'\)\) \{\s*event.preventDefault\(\);/", $handler, 'A second submit is cancelled.');
        $this->assertStringContainsString("button.setAttribute('aria-busy', 'true')", $handler);
        $this->assertStringContainsString("spinner.className = 'spinner'", $handler);
        $this->assertStringContainsString('button.replaceChildren(spinner, form.dataset.busy)', $handler);
        $this->assertMatchesRegularExpression('/setTimeout\(\(\) => submitButtons\(form\)\.forEach\(\(other\) => \{.*other\.disabled = true;/s', $handler,
            'The submit buttons are disabled one tick later, after the browser has collected the pressed button\'s value.');
    }

    public function test_the_back_button_brings_the_buttons_back(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));
        $restore = str($js)->after("window.addEventListener('pageshow'")->toString();

        $this->assertStringContainsString("button.removeAttribute('aria-busy')", $restore);
        $this->assertStringContainsString('button.disabled = false;', $restore);
    }
}
