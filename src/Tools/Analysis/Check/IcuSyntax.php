<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Domain;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\DomainCheck;
use ideatic\l10n\Tools\Analysis\Icu;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Severity;
use ideatic\l10n\Utils\ICU\Pattern;
use ideatic\l10n\Utils\ICU\Placeholder;
use ideatic\l10n\Utils\ICU\Plural;

/**
 * Validates the ICU patterns of the source strings: syntax, categories used and presence of the 'other' branch,
 * which ICU requires and is the only guarantee that the string can be rendered in any language.
 */
class IcuSyntax extends DomainCheck
{
    public function name(): string
    {
        return 'icu-syntax';
    }

    public function group(): string
    {
        return 'icu';
    }

    public function title(): string
    {
        return 'ICU syntax';
    }

    protected function analyzeDomain(Domain $domain, Context $context): array
    {
        $issues = [];
        $locale = $context->config->sourceLocale ?? 'en';

        foreach ($domain->strings as $stringID => $strings) {
            $string = $strings[0];
            $text = $string->translatableText();

            if (!$string->isICU || $text === '') {
                continue;
            }

            $locations = Context::locations($strings);

            $error = Icu::parseError($text);
            if ($error !== null) {
                $issues[] = new Issue(
                    check: $this->name(),
                    severity: Severity::Error,
                    message: "Invalid ICU pattern: {$error}",
                    domain: $domain->name,
                    stringID: strval($stringID),
                    locations: $locations,
                    priority: 1000,
                );
                continue;
            }

            $pattern = Icu::tryParse($text);
            if ($pattern === null) {
                continue;
            }

            $problems = [...self::_checkPlaceholders($pattern), ...self::_checkHashes($pattern->nodes, false)];

            // The ICU engine is only consulted once the pattern has passed the other checks, because its error
            // message is far less explanatory than the previous ones
            if (!$problems) {
                $formatError = Icu::formatError($pattern->render(false), $locale);

                if ($formatError !== null) {
                    $problems[] = "Rejected by the ICU formatter: {$formatError}";
                }
            }

            foreach ($problems as $problem) {
                $issues[] = new Issue(
                    check: $this->name(),
                    severity: Severity::Error,
                    message: $problem,
                    domain: $domain->name,
                    stringID: strval($stringID),
                    locations: $locations,
                    priority: 500,
                );
            }
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private static function _checkPlaceholders(Pattern $pattern): array
    {
        $problems = [];

        foreach (Icu::placeholders($pattern) as $placeholder) {
            $type = $placeholder->type;
            $branches = Icu::branches($placeholder);

            if (!in_array($type, Icu::NESTED_TYPES, true)) {
                continue;
            }

            $name = $placeholder->name;

            if (!isset($branches['other'])) {
                $problems[] = "Placeholder '{$name}' ({$type}) has no 'other' branch, which ICU always requires";
            }

            if (in_array($type, Icu::PLURAL_TYPES, true)) {
                foreach (array_keys($branches) as $category) {
                    $category = strval($category);

                    if (!in_array($category, Plural::CATEGORIES, true) && !preg_match('/^=\d+$/', $category)) {
                        $problems[] = "Placeholder '{$name}' ({$type}) uses the unknown plural category '{$category}'";
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * Checks that the # symbol is only used inside a plural branch, which is where ICU replaces it with the number.
     * The symbol remains valid inside a nested 'select', because it refers to the enclosing plural, so the state is
     * propagated down into the child messages.
     *
     * @param array<string|Placeholder> $nodes
     *
     * @return list<string>
     */
    private static function _checkHashes(array $nodes, bool $insidePlural): array
    {
        $problems = [];

        foreach ($nodes as $node) {
            if (is_string($node)) {
                if (!$insidePlural && str_contains($node, '#')) {
                    $problems[] = "The '#' symbol is only replaced by the number inside a plural branch";
                }
                continue;
            }

            $isPlural = in_array($node->type, Icu::PLURAL_TYPES, true);

            foreach (Icu::branches($node) as $branch) {
                $problems = [...$problems, ...self::_checkHashes($branch->nodes, $isPlural || $insidePlural)];
            }
        }

        return array_values(array_unique($problems));
    }
}
