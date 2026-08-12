<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use Exception;
use ideatic\l10n\Domain;
use ideatic\l10n\LString;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\DomainCheck;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Placeholders;
use ideatic\l10n\Tools\Analysis\Severity;

/**
 * Detects the strings a translator cannot resolve from the source text alone.
 *
 * Not every string without a comment is reported (that would be almost all of them), only those where the
 * ambiguity is real: several unexplained placeholders, very short labels, or words marked as ambiguous in the
 * project configuration (e.g. standalone participles, whose gender is invisible in other languages).
 */
class MissingComment extends DomainCheck
{
    /** Length beyond which a label stops being considered ambiguous */
    private const int SHORT_LABEL_LENGTH = 12;

    public function name(): string
    {
        return 'missing-comment';
    }

    public function group(): string
    {
        return 'metadata';
    }

    public function title(): string
    {
        return 'Strings without translator comment';
    }

    protected function analyzeDomain(Domain $domain, Context $context): array
    {
        $issues = [];

        foreach ($domain->strings as $stringID => $strings) {
            if (self::_hasComment($strings)) {
                continue;
            }

            $string = $strings[0];
            $text = mb_trim($string->translatableText());

            // Strings without letters are not translated, and non-localizable already reports them
            if (!preg_match('/\p{L}/u', $text)) {
                continue;
            }

            $reason = self::_reason($string, $text, $context->options->ambiguousWords);

            if ($reason === null) {
                continue;
            }

            $issues[] = new Issue(
                check: $this->name(),
                severity: Severity::Info,
                message: '"' . Context::preview($text) . "\" has no translator comment ({$reason})",
                domain: $domain->name,
                stringID: strval($stringID),
                locations: Context::locations($strings),
                priority: count($strings),
            );
        }

        return $issues;
    }

    /**
     * @param array<LString> $strings
     */
    private static function _hasComment(array $strings): bool
    {
        return array_any($strings, static fn(LString $string): bool => mb_trim($string->comments ?? '') !== '');
    }

    /**
     * @param list<string> $ambiguousWords
     */
    private static function _reason(LString $string, string $text, array $ambiguousWords): ?string
    {
        foreach ($ambiguousWords as $expression) {
            $matches = @preg_match($expression, $text);

            if ($matches === false) { // A malformed expression must never fail silently
                throw new Exception("Invalid regular expression '{$expression}' in the analyze.ambiguousWords configuration: " . preg_last_error_msg());
            }

            if ($matches === 1) {
                return 'matches an ambiguous word pattern';
            }
        }

        if ($string->isICU) {
            return 'ICU pattern';
        }

        // The # symbol is not a placeholder the translator has to understand, ICU substitutes it automatically
        $placeholders = array_diff_key(Placeholders::extract($text, $string->isICU, $string->placeholders ?? []), ['#' => 0]);

        if (count($placeholders) > 1) {
            return count($placeholders) . ' undocumented placeholders: ' . Placeholders::render($placeholders);
        }

        if (mb_strlen($text) <= self::SHORT_LABEL_LENGTH && !str_contains($text, ' ')) {
            return 'short standalone label';
        }

        return null;
    }
}
