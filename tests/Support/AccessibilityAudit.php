<?php

namespace Tests\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Static accessibility checks on a rendered page: the things a test can
 * judge from the HTML alone (labels, alt text, names, ids, landmarks).
 * Contrast, focus visibility and layout need a browser; see
 * docs/accessibility.md for how those were checked.
 */
class AccessibilityAudit
{
    private DOMXPath $xpath;

    /** @var array<string, int> */
    private array $ids = [];

    private function __construct(private DOMDocument $doc)
    {
        $this->xpath = new DOMXPath($doc);
        foreach ($this->xpath->query('//*[@id]') as $el) {
            $id = $el->getAttribute('id');
            $this->ids[$id] = ($this->ids[$id] ?? 0) + 1;
        }
    }

    /** @return list<string> Every problem found, empty when the page passes. */
    public static function problems(string $html): array
    {
        $doc = new DOMDocument;
        // libxml doesn't know HTML5 tags (main, dialog, svg…); those warnings are noise.
        @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return (new self($doc))->run();
    }

    /** @return list<string> */
    private function run(): array
    {
        $problems = [];
        $add = function (string $message, ?DOMNode $node = null) use (&$problems) {
            $problems[] = $node instanceof DOMElement ? "{$message}: ".$this->describe($node) : $message;
        };

        if (trim((string) $this->first('//html')?->getAttribute('lang')) === '') {
            $add('<html> has no lang');
        }
        if (trim((string) $this->first('//title')?->textContent) === '') {
            $add('page has no <title>');
        }
        if ($this->xpath->query('//main')->length !== 1) {
            $add('page should have exactly one <main>');
        }
        if (($h1 = $this->xpath->query('//h1')->length) !== 1) {
            $add("page should have exactly one <h1>, has {$h1}");
        }

        foreach ($this->ids as $id => $count) {
            if ($count > 1) {
                $add("id \"{$id}\" is used {$count} times");
            }
        }

        foreach ($this->xpath->query('//*[@aria-describedby or @aria-labelledby or @aria-controls]') as $el) {
            foreach (['aria-describedby', 'aria-labelledby', 'aria-controls'] as $attr) {
                foreach (preg_split('/\s+/', trim($el->getAttribute($attr)), -1, PREG_SPLIT_NO_EMPTY) as $ref) {
                    if (! isset($this->ids[$ref])) {
                        $add("{$attr} points at missing id \"{$ref}\"", $el);
                    }
                }
            }
        }

        foreach ($this->xpath->query('//*[@tabindex]') as $el) {
            if ((int) $el->getAttribute('tabindex') > 0) {
                $add('positive tabindex changes the tab order', $el);
            }
        }

        foreach ($this->xpath->query('//img') as $img) {
            if (! $img->hasAttribute('alt')) {
                $add('<img> has no alt', $img);
            }
        }

        foreach ($this->xpath->query('//input[not(@type="hidden") and not(@type="submit") and not(@type="button") and not(@type="reset")] | //select | //textarea') as $control) {
            if (! $this->controlHasLabel($control)) {
                $add('form control has no label', $control);
            }
        }

        foreach ($this->xpath->query('//button | //a[@href] | //summary | //*[@role="button"]') as $el) {
            if ($this->accessibleName($el) === '') {
                $add('has no accessible name', $el);
            }
        }

        foreach ($this->xpath->query('//svg[not(@aria-hidden="true")]') as $svg) {
            if ($this->insideHidden($svg)) {
                continue;
            }
            $hasName = $svg->getAttribute('aria-label') !== '' || $svg->hasAttribute('aria-labelledby') || $this->first('./title', $svg);
            // A drawing that holds links (the district map's pins) is a named group: an image can't contain links.
            $named = $hasName && ($svg->getAttribute('role') === 'img'
                || ($svg->getAttribute('role') === 'group' && $this->first('.//a[@href]', $svg)));
            if (! $named) {
                $add('<svg> is neither aria-hidden nor a named role="img" (or a named role="group" holding links)', $svg);
            }
        }

        // A box that scrolls sideways must be reachable and named, or keyboard users can't scroll it.
        foreach ($this->xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " scroll-region ") or contains(concat(" ", normalize-space(@class), " "), " overflow-x-auto ")]') as $box) {
            if ($box->getAttribute('tabindex') !== '0' || trim($box->getAttribute('aria-label')) === '') {
                $add('scrolling box needs tabindex="0", role="region" and an aria-label', $box);
            }
        }

        foreach ($this->xpath->query('//table') as $table) {
            if (! $this->first('./caption', $table) && ! $table->hasAttribute('aria-label') && ! $table->hasAttribute('aria-labelledby')) {
                $add('<table> has no caption', $table);
            }
        }

        // Radios sharing a name are one question: it needs a group label.
        $radios = [];
        foreach ($this->xpath->query('//input[@type="radio"]') as $radio) {
            $radios[$radio->getAttribute('name')][] = $radio;
        }
        foreach ($radios as $group) {
            if (count($group) > 1 && ! $this->first('ancestor::fieldset[legend] | ancestor::*[@role="radiogroup"][@aria-label or @aria-labelledby]', $group[0])) {
                $add('radio group is not in a <fieldset> with a <legend>', $group[0]);
            }
        }

        // x-field writes "{id}-hint" and "{id}-error" next to control #{id}; the control must point at them.
        foreach ($this->xpath->query('//*[@id]') as $el) {
            if (! preg_match('/^(.+)-(hint|error)$/', $el->getAttribute('id'), $m)) {
                continue;
            }
            $control = $this->first('//*[@id="'.$m[1].'"][self::input or self::select or self::textarea]');
            if ($control && ! in_array($el->getAttribute('id'), preg_split('/\s+/', $control->getAttribute('aria-describedby')), true)) {
                $add("field does not point at its {$m[2]} \"{$el->getAttribute('id')}\"", $control);
            }
        }

        foreach ($this->xpath->query('//*[@aria-invalid="true"]') as $el) {
            if (! $el->hasAttribute('aria-describedby')) {
                $add('invalid field does not point at its error message', $el);
            }
        }

        return $problems;
    }

    private function controlHasLabel(DOMElement $control): bool
    {
        if (trim($control->getAttribute('aria-label')) !== '' || $control->hasAttribute('aria-labelledby')) {
            return true;
        }
        if ($this->first('ancestor::label', $control)) {
            return true;
        }
        $id = $control->getAttribute('id');

        return $id !== '' && $this->first('//label[@for="'.$id.'"]') !== null;
    }

    /** Text a screen reader would announce, skipping aria-hidden parts. */
    private function accessibleName(DOMElement $el): string
    {
        if (trim($el->getAttribute('aria-label')) !== '') {
            return trim($el->getAttribute('aria-label'));
        }
        if ($el->hasAttribute('aria-labelledby')) {
            return 'labelled';
        }

        return trim(preg_replace('/\s+/', ' ', $this->visibleText($el)));
    }

    private function visibleText(DOMNode $node): string
    {
        if ($node instanceof DOMElement) {
            if ($node->getAttribute('aria-hidden') === 'true') {
                return '';
            }
            if ($node->nodeName === 'img') {
                return $node->getAttribute('alt');
            }
            if ($node->nodeName === 'svg') {
                return $node->getAttribute('aria-label');
            }
        }
        if ($node->nodeType === XML_TEXT_NODE) {
            return $node->textContent;
        }

        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= ' '.$this->visibleText($child);
        }

        return $text;
    }

    private function insideHidden(DOMElement $el): bool
    {
        return $this->first('ancestor-or-self::*[@aria-hidden="true"]', $el) !== null;
    }

    private function first(string $query, ?DOMNode $context = null): ?DOMElement
    {
        $node = $this->xpath->query($query, $context)->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function describe(DOMElement $el): string
    {
        $html = $this->doc->saveHTML($el);
        $open = substr($html, 0, (int) strpos($html, '>') + 1);

        return mb_strimwidth(preg_replace('/\s+/', ' ', $open), 0, 160, '…');
    }
}
