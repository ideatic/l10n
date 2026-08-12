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

/**
 * Checks that the ICU patterns respect the limitations of the ICU engine used at runtime.
 *
 * Many projects ship a reduced ICU implementation on the client that does not support text around the expression,
 * several parameters, 'select' or nesting. The constraints are declared in the "analyze" section of the
 * configuration, and the check only runs when they are defined.
 */
class IcuRuntime extends DomainCheck
{
    public function name(): string
    {
        return 'icu-runtime';
    }

    public function group(): string
    {
        return 'icu';
    }

    public function title(): string
    {
        return 'ICU runtime constraints';
    }

    protected function analyzeDomain(Domain $domain, Context $context): array
    {
        $runtime = $context->options->icuRuntime;

        if ($runtime === null) {
            return [];
        }

        $allowSurroundingText = boolval($runtime->allowSurroundingText ?? true);
        $allowNesting = boolval($runtime->allowNesting ?? true);
        $maxParameters = isset($runtime->maxParameters) ? intval($runtime->maxParameters) : 0;
        $allowedTypes = isset($runtime->allowedTypes) ? array_map(strval(...), (array)$runtime->allowedTypes) : [];

        $issues = [];

        foreach ($domain->strings as $stringID => $strings) {
            $string = $strings[0];

            if (!$string->isICU) {
                continue;
            }

            $pattern = Icu::tryParse($string->translatableText());
            if ($pattern === null) { // icu-syntax already reports it
                continue;
            }

            $problems = [];

            if (!$allowSurroundingText && self::_hasSurroundingText($pattern)) {
                $problems[] = 'the runtime does not accept text outside the ICU expression';
            }

            $placeholders = Icu::placeholders($pattern);
            $parameters = array_unique(array_map(static fn(Placeholder $p): string => $p->name, $placeholders));

            if ($maxParameters > 0 && count($parameters) > $maxParameters) {
                $problems[] = 'the runtime accepts at most ' . $maxParameters . ' parameter(s), but this pattern uses ' . count($parameters)
                    . ' (' . implode(', ', $parameters) . ')';
            }

            if ($allowedTypes) {
                foreach ($placeholders as $placeholder) {
                    $type = $placeholder->type;

                    if ($type !== '' && !in_array($type, $allowedTypes, true)) {
                        $problems[] = "the runtime does not support the '{$type}' placeholder type";
                    }
                }
            }

            if (!$allowNesting && self::_isNested($pattern)) {
                $problems[] = 'the runtime does not support nested ICU expressions';
            }

            foreach (array_unique($problems) as $problem) {
                $issues[] = new Issue(
                    check: $this->name(),
                    severity: Severity::Error,
                    message: ucfirst($problem),
                    domain: $domain->name,
                    stringID: strval($stringID),
                    locations: Context::locations($strings),
                    details: ['Move this string to a template, or extend the client ICU implementation'],
                    priority: 200,
                );
            }
        }

        return $issues;
    }

    /**
     * Checks whether the pattern holds text outside the ICU expression
     */
    private static function _hasSurroundingText(Pattern $pattern): bool
    {
        return array_any($pattern->nodes, static fn(mixed $node): bool => is_string($node) && mb_trim($node) !== '');
    }

    /**
     * Checks whether any branch of the pattern holds another ICU expression in turn
     */
    private static function _isNested(Pattern $pattern): bool
    {
        foreach ($pattern->nodes as $node) {
            if (!($node instanceof Placeholder)) {
                continue;
            }

            foreach (Icu::branches($node) as $branch) {
                if (array_any($branch->nodes, static fn(mixed $child): bool => $child instanceof Placeholder)) {
                    return true;
                }
            }
        }

        return false;
    }
}
