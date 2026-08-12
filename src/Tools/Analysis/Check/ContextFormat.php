<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Domain;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\DomainCheck;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Severity;

/**
 * Checks how the context of the strings is used.
 *
 * The context is part of the translation key (LString::fullyQualifiedID() appends "@context"), so an empty
 * context produces keys ending in '@', and a context used as free-form commentary produces huge, unstable keys:
 * any change to the text of that "comment" loses every translation of the string.
 */
class ContextFormat extends DomainCheck
{
    /** Maximum number of words a context may have before it is treated as a comment */
    private const int MAX_WORDS = 3;

    /** Maximum length of a context before it is treated as a comment */
    private const int MAX_LENGTH = 30;

    public function name(): string
    {
        return 'context-format';
    }

    public function group(): string
    {
        return 'metadata';
    }

    public function title(): string
    {
        return 'String context format';
    }

    protected function analyzeDomain(Domain $domain, Context $context): array
    {
        $issues = [];

        foreach ($domain->strings as $stringID => $strings) {
            $string = $strings[0];
            $stringContext = $string->context;

            if ($stringContext === null) {
                continue;
            }

            $locations = Context::locations($strings);

            if (mb_trim($stringContext) === '') {
                $issues[] = new Issue(
                    check: $this->name(),
                    severity: Severity::Error,
                    message: 'Empty context, producing a translation key that ends in a bare \'@\'',
                    domain: $domain->name,
                    stringID: strval($stringID),
                    locations: $locations,
                    details: ['Remove the context entirely, or give it a real value'],
                    priority: 200,
                );
                continue;
            }

            $words = preg_split('/\s+/u', mb_trim($stringContext), flags: PREG_SPLIT_NO_EMPTY) ?: [];

            if (count($words) > self::MAX_WORDS || mb_strlen($stringContext) > self::MAX_LENGTH || preg_match('/[.:]$/u', $stringContext)) {
                $issues[] = new Issue(
                    check: $this->name(),
                    severity: Severity::Warning,
                    message: "Context \"" . Context::preview($stringContext, 50) . '" reads like a comment rather than a short slug',
                    domain: $domain->name,
                    stringID: strval($stringID),
                    locations: $locations,
                    details: ['The context is part of the translation key: use a short slug and move the explanation to a comment'],
                    priority: 50,
                );
            }
        }

        return $issues;
    }
}
