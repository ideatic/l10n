<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Domain;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\DomainCheck;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Severity;

/**
 * Detects source strings very similar to each other, candidates to be unified.
 *
 * The groups are sorted by priority: first the ones differing only in case or punctuation (always a real error),
 * and within each kind, the longest and most used strings first.
 */
class SimilarStrings extends DomainCheck
{
    /** Weight of each kind of relation, used to prioritise the results */
    private const array KIND_WEIGHT = [
        'case' => 4,        // Differ only in letter case
        'whitespace' => 3,  // Differ only in spacing or trailing punctuation
        'normalized' => 2,  // Differ in case + spacing + punctuation
        'fuzzy' => 1,       // Similitud aproximada
    ];

    private const array KIND_LABEL = [
        'case' => 'case only',
        'whitespace' => 'spacing/punctuation only',
        'normalized' => 'case + punctuation',
        'fuzzy' => 'similar',
    ];

    public function name(): string
    {
        return 'similar-strings';
    }

    public function group(): string
    {
        return 'source';
    }

    public function title(): string
    {
        return 'Similar strings';
    }

    protected function analyzeDomain(Domain $domain, Context $context): array
    {
        $entries = self::_collectEntries($domain);
        $count = count($entries);

        if ($count < 2) {
            return [];
        }

        $parent = range(0, $count - 1);

        /** @var list<array{0: int, 1: int, 2: string, 3: float}> $relations */
        $relations = [];

        // First pass, O(n): group by normalised keys, without comparing strings with each other
        foreach (['case', 'whitespace', 'normalized'] as $kind) {
            $buckets = [];

            foreach ($entries as $index => $entry) {
                $buckets[self::_key($entry['text'], $kind)][] = $index;
            }

            foreach ($buckets as $bucket) {
                for ($i = 1, $total = count($bucket); $i < $total; $i++) {
                    $relations[] = [$bucket[0], $bucket[$i], $kind, 100.0];
                    self::_union($parent, $bucket[0], $bucket[$i]);
                }
            }
        }

        // Second pass: fuzzy comparison, sorted by length so that pairs can be discarded in advance
        $order = array_keys($entries);
        usort($order, static fn(int $a, int $b): int => $entries[$a]['bytes'] <=> $entries[$b]['bytes']);

        $threshold = $context->options->threshold;

        for ($a = 0; $a < $count; $a++) {
            $i = $order[$a];

            for ($b = $a + 1; $b < $count; $b++) {
                $j = $order[$b];

                // Exact upper bound of the percentage returned by similar_text(): percent = 2*sim/(len1+len2)*100
                // and sim <= min(len1, len2). Since the list is sorted by length, no remaining pair can beat
                // the threshold either.
                $bound = 200 * min($entries[$i]['bytes'], $entries[$j]['bytes']) / ($entries[$i]['bytes'] + $entries[$j]['bytes']);

                if ($bound <= $threshold) {
                    break;
                }

                if (self::_find($parent, $i) === self::_find($parent, $j)) {
                    continue;
                }

                $percent = 0.0;
                similar_text($entries[$i]['text'], $entries[$j]['text'], $percent);

                if ($percent > $threshold) {
                    $relations[] = [$i, $j, 'fuzzy', $percent];
                    self::_union($parent, $i, $j);
                }
            }
        }

        return self::_buildIssues($domain, $entries, $parent, $relations);
    }

    /**
     * Groups the strings of the domain by their source text, discarding the empty ones
     *
     * @return list<array{text: string, ids: list<string>, strings: list<\ideatic\l10n\LString>, chars: int, bytes: int}>
     */
    private static function _collectEntries(Domain $domain): array
    {
        $byText = [];

        foreach ($domain->strings as $stringID => $strings) {
            $text = $strings[0]->translatableText();

            if (mb_trim($text) === '') {
                continue;
            }

            if (isset($byText[$text])) {
                $byText[$text]['ids'][] = strval($stringID);
                $byText[$text]['strings'] = [...$byText[$text]['strings'], ...array_values($strings)];
            } else {
                $byText[$text] = [
                    'text' => $text,
                    'ids' => [strval($stringID)],
                    'strings' => array_values($strings),
                    'chars' => mb_strlen($text),
                    'bytes' => strlen($text),
                ];
            }
        }

        return array_values($byText);
    }

    /**
     * @param list<array{text: string, ids: list<string>, strings: list<\ideatic\l10n\LString>, chars: int, bytes: int}> $entries
     * @param array<int, int>                                                                                           $parent
     * @param list<array{0: int, 1: int, 2: string, 3: float}>                                                          $relations
     *
     * @return list<Issue>
     */
    private static function _buildIssues(Domain $domain, array $entries, array $parent, array $relations): array
    {
        /** @var array<int, array{members: array<int, bool>, kind: string, percent: float}> $clusters */
        $clusters = [];

        foreach ($relations as [$i, $j, $kind, $percent]) {
            $root = self::_find($parent, $i);

            $clusters[$root] ??= ['members' => [], 'kind' => 'fuzzy', 'percent' => 0.0];
            $clusters[$root]['members'][$i] = true;
            $clusters[$root]['members'][$j] = true;

            if (self::KIND_WEIGHT[$kind] > self::KIND_WEIGHT[$clusters[$root]['kind']]) {
                $clusters[$root]['kind'] = $kind;
            }

            $clusters[$root]['percent'] = max($clusters[$root]['percent'], $percent);
        }

        $issues = [];

        foreach ($clusters as $cluster) {
            $members = array_keys($cluster['members']);

            $shortest = min(array_map(static fn(int $index): int => $entries[$index]['chars'], $members));
            $occurrences = array_sum(array_map(static fn(int $index): int => count($entries[$index]['strings']), $members));

            // Priority: the kind of relation drives the order, and within the same kind the strings shown first are the
            // longest ones and the most used in the code
            $priority = self::KIND_WEIGHT[$cluster['kind']] * 10000
                + $cluster['percent'] * 50
                + min($shortest, 500)
                + min($occurrences, 100) * 5;

            $details = [];
            foreach ($members as $index) {
                $locations = Context::locations($entries[$index]['strings'], 3);
                $details[] = '"' . Context::preview($entries[$index]['text']) . '"' . ($locations ? "\n      " . implode(', ', $locations) : '');
            }

            $label = self::KIND_LABEL[$cluster['kind']];
            $percent = number_format($cluster['percent'], 0);

            $issues[] = new Issue(
                check: 'similar-strings',
                severity: Severity::Warning,
                message: count($members) . " similar strings [{$label} · {$percent}%]",
                domain: $domain->name,
                stringID: $entries[$members[0]]['ids'][0],
                details: $details,
                priority: $priority,
            );
        }

        return $issues;
    }

    private static function _key(string $text, string $kind): string
    {
        return match ($kind) {
            'case' => mb_strtolower($text),
            'whitespace' => self::_collapse($text),
            default => self::_collapse(mb_strtolower($text)),
        };
    }

    /**
     * Normalises the whitespace and trailing punctuation of a string
     */
    private static function _collapse(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', str_replace("\xc2\xa0", ' ', $text)) ?? $text;

        return mb_rtrim(mb_trim($text), " .,:;!?…¡¿");
    }

    /**
     * @param array<int, int> $parent
     */
    private static function _find(array &$parent, int $index): int
    {
        while ($parent[$index] !== $index) {
            $parent[$index] = $parent[$parent[$index]];
            $index = $parent[$index];
        }

        return $index;
    }

    /**
     * @param array<int, int> $parent
     */
    private static function _union(array &$parent, int $a, int $b): void
    {
        $rootA = self::_find($parent, $a);
        $rootB = self::_find($parent, $b);

        if ($rootA !== $rootB) {
            $parent[max($rootA, $rootB)] = min($rootA, $rootB);
        }
    }
}
