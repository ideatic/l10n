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
 * Checks that the translations keep the HTML tags of the source text.
 *
 * A lost tag removes a link or some formatting, and an unclosed tag can break the layout of the whole page the
 * translation is shown on.
 */
class MarkupMismatch extends DomainCheck
{
    public function name(): string
    {
        return 'markup-mismatch';
    }

    public function group(): string
    {
        return 'translations';
    }

    public function title(): string
    {
        return 'HTML markup mismatch';
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
            $expected = Placeholders::markup($text, $string->isICU);

            foreach ($context->locales as $locale) {
                $translation = $context->getTranslation($string, $locale);

                if ($translation === null || mb_trim($translation->translation) === '') {
                    continue;
                }

                $actual = Placeholders::markup($translation->translation, $string->isICU);

                if (!$expected && !$actual) {
                    continue;
                }

                ['missing' => $missing, 'extra' => $extra] = Placeholders::diff($expected, $actual);

                if (!$missing && !$extra) {
                    continue;
                }

                $problems = [];
                if ($missing) {
                    $problems[] = 'missing ' . Placeholders::render($missing);
                }
                if ($extra) {
                    $problems[] = 'unexpected ' . Placeholders::render($extra);
                }

                $issues[] = new Issue(
                    check: $this->name(),
                    severity: Severity::Error,
                    message: 'Translation markup differs from the source: ' . implode(', ', $problems),
                    domain: $domain->name,
                    stringID: strval($stringID),
                    locale: $locale,
                    locations: Context::locations($strings, 2),
                    details: [
                        'Source: "' . Context::preview($text, 100) . '"',
                        'Translation: "' . Context::preview($translation->translation, 100) . '"',
                    ],
                    priority: 600 + count($missing) + count($extra),
                );
            }
        }

        return $issues;
    }
}
