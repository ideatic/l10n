<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Domain;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\DomainCheck;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Severity;

/**
 * Checks the quality of the comments aimed at translators
 */
class CommentQuality extends DomainCheck
{
    /** Function words used to detect the language of a comment */
    private const array STOPWORDS = [
        'es' => ['el', 'la', 'los', 'las', 'de', 'del', 'que', 'por', 'para', 'con', 'una', 'un', 'es', 'se', 'no', 'cuando', 'texto', 'cadena', 'número'],
        'en' => ['the', 'of', 'to', 'and', 'for', 'with', 'this', 'that', 'is', 'are', 'when', 'used', 'text', 'string', 'number'],
        'fr' => ['le', 'la', 'les', 'des', 'du', 'que', 'pour', 'avec', 'une', 'est', 'dans', 'texte', 'chaîne'],
        'de' => ['der', 'die', 'das', 'und', 'für', 'mit', 'von', 'ist', 'wenn', 'text', 'zeichenkette'],
        'it' => ['il', 'lo', 'la', 'gli', 'del', 'che', 'per', 'con', 'una', 'è', 'quando', 'testo', 'stringa'],
        'pt' => ['o', 'a', 'os', 'as', 'do', 'da', 'que', 'para', 'com', 'uma', 'é', 'quando', 'texto', 'cadeia'],
    ];

    public function name(): string
    {
        return 'comment-quality';
    }

    public function group(): string
    {
        return 'metadata';
    }

    public function title(): string
    {
        return 'Translator comment quality';
    }

    protected function analyzeDomain(Domain $domain, Context $context): array
    {
        $expectedLanguage = mb_strtolower(mb_substr($context->options->commentLanguage, 0, 2));
        $issues = [];

        foreach ($domain->strings as $stringID => $strings) {
            foreach ($strings as $string) {
                $comment = mb_trim($string->comments ?? '');

                if ($comment === '') {
                    continue;
                }

                $locations = Context::locations([$string]);
                $text = $string->translatableText();

                // Placeholders mentioned in the comment that do not exist in the string
                $mentioned = [];
                if (preg_match_all('/%[a-zA-Z_][\w.]*%|\{[a-zA-Z_][\w.]*\}/u', $comment, $matches)) {
                    foreach (array_unique($matches[0]) as $placeholder) {
                        if (!str_contains($text, $placeholder)) {
                            $mentioned[] = $placeholder;
                        }
                    }
                }

                if ($mentioned) {
                    $issues[] = new Issue(
                        check: $this->name(),
                        severity: Severity::Warning,
                        message: 'Comment documents ' . implode(', ', $mentioned) . ', which the string does not contain',
                        domain: $domain->name,
                        stringID: strval($stringID),
                        locations: $locations,
                        details: ["Comment: \"" . Context::preview($comment, 100) . '"'],
                        priority: 100,
                    );
                }

                if ($expectedLanguage !== '' && isset(self::STOPWORDS[$expectedLanguage])) {
                    $detected = self::_detectLanguage($comment, $expectedLanguage);

                    if ($detected !== null) {
                        $issues[] = new Issue(
                            check: $this->name(),
                            severity: Severity::Info,
                            message: "Comment looks like it is written in '{$detected}' instead of '{$expectedLanguage}'",
                            domain: $domain->name,
                            stringID: strval($stringID),
                            locations: $locations,
                            details: ["Comment: \"" . Context::preview($comment, 100) . '"'],
                            priority: 10,
                        );
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * Detects the language of a comment, returning null when it matches the expected one or there is not enough evidence
     */
    private static function _detectLanguage(string $comment, string $expected): ?string
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($comment), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) < 3) {
            return null;
        }

        $scores = [];
        foreach (self::STOPWORDS as $language => $stopwords) {
            $scores[$language] = count(array_intersect($words, $stopwords));
        }

        arsort($scores);
        $best = array_key_first($scores);

        // Only reported when there is clear evidence of another language and none of the expected one
        return $best !== $expected && $scores[$best] >= 2 && $scores[$expected] === 0 ? strval($best) : null;
    }
}
