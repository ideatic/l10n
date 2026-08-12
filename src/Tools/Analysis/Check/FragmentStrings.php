<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Domain;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\DomainCheck;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Severity;

/**
 * Detects sentence fragments that only make sense concatenated with other text at runtime.
 *
 * Word order, gender and prepositions change between languages, so a sentence assembled from pieces is impossible
 * to translate correctly: it has to be extracted as a single complete string.
 */
class FragmentStrings extends DomainCheck
{
    /** Maximum length for a string to be considered a fragment */
    private const int MAX_LENGTH = 40;

    public function name(): string
    {
        return 'fragment-strings';
    }

    public function group(): string
    {
        return 'source';
    }

    public function title(): string
    {
        return 'Sentence fragments';
    }

    protected function analyzeDomain(Domain $domain, Context $context): array
    {
        $issues = [];

        foreach ($domain->strings as $stringID => $strings) {
            $string = $strings[0];
            $text = mb_trim($string->translatableText());

            if ($string->isICU || $text === '' || mb_strlen($text) > self::MAX_LENGTH) {
                continue;
            }

            // Only fragments starting in lowercase are interesting: in practice they are pieces of a sentence
            // meant to be concatenated ("la jornada %number%", "de 100", "ida"...)
            if (!preg_match('/^\p{Ll}/u', $text)) {
                continue;
            }

            // URLs and email addresses start in lowercase but are not sentences (non-localizable reports those)
            if (preg_match('#://|^www\.|^[^@\s]+@[^@\s]+\.\w+$#ui', $text)) {
                continue;
            }

            // A complete lowercase sentence usually ends in a full stop or a closing mark
            if (preg_match('/[.!?…]$/u', $text)) {
                continue;
            }

            $issues[] = new Issue(
                check: $this->name(),
                severity: Severity::Info,
                message: '"' . Context::preview($text) . '" looks like a sentence fragment meant to be concatenated',
                domain: $domain->name,
                stringID: strval($stringID),
                locations: Context::locations($strings),
                details: ['Word order, gender and prepositions differ between languages: extract the whole sentence instead'],
                priority: count($strings),
            );
        }

        return $issues;
    }
}
