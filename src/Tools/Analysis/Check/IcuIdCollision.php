<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Domain;
use ideatic\l10n\LString;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\DomainCheck;
use ideatic\l10n\Tools\Analysis\Icu;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Severity;


/**
 * Detects strings with different content sharing the same identifier.
 *
 * Domain::generate() only throws when neither of the two is ICU, so two ICU patterns with the same id are
 * silently accepted and only the first is kept: the remaining texts are lost without any warning.
 *
 * The comparison is made on the translatable content, ignoring the name of the ICU argument: in Angular
 * templates that name is the expression producing the value ({user.points}, {league.user.points}...) and the
 * extractor normalises it on purpose, so several different expressions legitimately share an id and nothing is lost.
 */
class IcuIdCollision extends DomainCheck
{
    public function name(): string
    {
        return 'icu-id-collision';
    }

    public function group(): string
    {
        return 'icu';
    }

    public function title(): string
    {
        return 'Colliding string IDs';
    }

    protected function analyzeDomain(Domain $domain, Context $context): array
    {
        $issues = [];

        foreach ($domain->strings as $stringID => $strings) {
            $variants = [];

            foreach ($strings as $string) {
                $signature = self::_signature($string);
                $variants[$signature] ??= [];
                $variants[$signature][] = $string;
            }

            if (count($variants) < 2) {
                continue;
            }

            $details = [];
            foreach ($variants as $occurrences) {
                $locations = Context::locations($occurrences, 3);
                $details[] = '"' . Context::preview($occurrences[0]->translatableText()) . '"'
                    . ($locations ? "\n      " . implode(', ', $locations) : '');
            }

            $issues[] = new Issue(
                check: $this->name(),
                severity: Severity::Error,
                message: count($variants) . ' different texts share the same string ID, only the first one is kept',
                domain: $domain->name,
                stringID: strval($stringID),
                details: $details,
                priority: count($variants) * 100,
            );
        }

        return $issues;
    }

    /**
     * Gets a comparable representation of the content of a string.
     *
     * This starts from the source text and not from translatableText(): when the id is an explicit identifier
     * (@@myId) it is the same for every conflicting string, which is precisely what has to be told apart. The
     * Angular normalisation is not lost by doing so, because _render() already ignores the argument name.
     */
    private static function _signature(LString $string): string
    {
        $text = $string->text ?? '';

        if (!$string->isICU) {
            return $text;
        }

        $pattern = Icu::tryParse($text);

        return $pattern === null ? $text : $pattern->signature();
    }
}
