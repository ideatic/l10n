<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Domain;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\DomainCheck;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Placeholders;
use ideatic\l10n\Tools\Analysis\Severity;

/**
 * Compares the placeholders of the source text with those of each translation.
 *
 * An extra placeholder in the translation is the most severe problem detectable here: it shows up when the source
 * text is rewritten without updating the translations, and it makes the user literally see the placeholder
 * ('%league%', '%app%'...) on screen. A missing placeholder loses the value that was meant to be displayed.
 */
class PlaceholderMismatch extends DomainCheck
{
    public function name(): string
    {
        return 'placeholder-mismatch';
    }

    public function group(): string
    {
        return 'translations';
    }

    public function title(): string
    {
        return 'Placeholder mismatch';
    }

    public function needsTranslations(): bool
    {
        return true;
    }

    protected function analyzeDomain(Domain $domain, Context $context): array
    {
        $issues = [];

        foreach ($domain->strings as $stringID => $strings) {
            $string = $strings[0];
            $text = $string->translatableText();

            if (mb_trim($text) === '') {
                continue;
            }

            $known = $string->placeholders ?? [];
            $expected = Placeholders::extract($text, $string->isICU, $known);

            foreach ($context->locales as $locale) {
                $translation = $context->getTranslation($string, $locale);

                if ($translation === null || mb_trim($translation->translation) === '') {
                    continue;
                }

                $actual = Placeholders::extract($translation->translation, $string->isICU, $known);
                ['missing' => $missing, 'extra' => $extra] = Placeholders::diff($expected, $actual);

                if (!$missing && !$extra) {
                    continue;
                }

                $details = ['Source: "' . Context::preview($text, 100) . '"', 'Translation: "' . Context::preview($translation->translation, 100) . '"'];

                if ($extra) {
                    $issues[] = new Issue(
                        check: $this->name(),
                        severity: Severity::Error,
                        message: 'Translation uses placeholders the source does not provide: ' . Placeholders::render($extra),
                        domain: $domain->name,
                        stringID: strval($stringID),
                        locale: $locale,
                        locations: Context::locations($strings, 2),
                        details: [...$details, 'These are rendered literally to the user'],
                        priority: 1000 + count($extra) * 10,
                    );
                }

                if ($missing) {
                    $issues[] = new Issue(
                        check: $this->name(),
                        severity: Severity::Warning,
                        message: 'Translation drops the placeholders: ' . Placeholders::render($missing),
                        domain: $domain->name,
                        stringID: strval($stringID),
                        locale: $locale,
                        locations: Context::locations($strings, 2),
                        details: $details,
                        priority: 500 + count($missing) * 10,
                    );
                }
            }
        }

        return $issues;
    }
}
