<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis;

use ideatic\l10n\Utils\ICU\Pattern;
use ideatic\l10n\Utils\ICU\Placeholder;
use IntlException;
use MessageFormatter;
use Throwable;

use const U_ZERO_ERROR;

/**
 * Utilities shared by the checks that work with ICU patterns.
 *
 * The analyzer must never be interrupted by an invalid pattern: parse errors are precisely one of the problems it
 * has to report, so every operation here is fault tolerant.
 */
abstract class Icu
{
    /** Placeholder types that accept nested messages */
    public const array NESTED_TYPES = ['select', 'plural', 'selectordinal'];

    /** Placeholder types resolved through the plural rules of the locale */
    public const array PLURAL_TYPES = ['plural', 'selectordinal'];

    /**
     * Parses an ICU pattern, returning null when it is not valid
     */
    public static function tryParse(string $pattern): ?Pattern
    {
        try {
            return new Pattern($pattern);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Parses an ICU pattern, returning the error message found or null when it is valid
     */
    public static function parseError(string $pattern): ?string
    {
        try {
            new Pattern($pattern);
            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Checks whether the ICU engine accepts the given pattern, returning the error message or null when valid
     */
    public static function formatError(string $pattern, string $locale): ?string
    {
        try {
            $formatter = new MessageFormatter($locale ?: 'en', $pattern);
        } catch (IntlException $e) {
            return $e->getMessage();
        }

        return $formatter->getErrorCode() == U_ZERO_ERROR ? null : $formatter->getErrorMessage();
    }

    /**
     * Gets every placeholder in the pattern, including those nested inside other placeholders
     *
     * @return list<Placeholder>
     */
    public static function placeholders(Pattern $pattern): array
    {
        $found = [];

        foreach ($pattern->nodes as $node) {
            if ($node instanceof Placeholder) {
                $found[] = $node;

                foreach (self::branches($node) as $branch) {
                    $found = [...$found, ...self::placeholders($branch)];
                }
            }
        }

        return $found;
    }

    /**
     * Gets the placeholders resolved through the plural rules of the locale
     *
     * @return list<Placeholder>
     */
    public static function plurals(Pattern $pattern): array
    {
        return array_values(array_filter(self::placeholders($pattern), static fn(Placeholder $p): bool => in_array($p->type, self::PLURAL_TYPES, true)));
    }

    /**
     * Gets the branches (categories) of a placeholder holding nested messages
     *
     * @return array<string, Pattern>
     */
    public static function branches(Placeholder $placeholder): array
    {
        return is_array($placeholder->content) ? $placeholder->content : [];
    }

    /**
     * Checks whether a text looks like an ICU pattern (it holds at least one typed placeholder)
     */
    public static function looksLikeIcu(string $text): bool
    {
        if (!str_contains($text, '{')) {
            return false;
        }

        $pattern = self::tryParse($text);

        return $pattern !== null && array_any($pattern->nodes, static fn(mixed $node): bool => $node instanceof Placeholder);
    }
}
