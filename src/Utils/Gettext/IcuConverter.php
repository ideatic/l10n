<?php

declare(strict_types=1);


namespace ideatic\l10n\Utils\Gettext;

use ideatic\l10n\Utils\ICU\Pattern;
use ideatic\l10n\Utils\ICU\Placeholder;

/**
 * Utility to convert Gettext Plural Rules into ICU MessageFormat Patterns.
 *
 * This class bridges the gap between Gettext's mathematical plural logic
 * (e.g., "n != 1") and ICU's grammatical categories (e.g., "one", "few", "other").
 */
class IcuConverter
{
    /**
     * Cache for the mapping logic to avoid re-probing 101 times per string.
     * Key = "locale|rule_hash"
     * @var array<string, array{categories: array<string, int>, explicits: array<int, int>, fallbackIndex: int}>
     */
    private static array $_ruleMapCache = [];

    /**
     * Replace the plural forms in an ICU Pattern based on Gettext plural forms and rules.
     *
     * @param Pattern $icuPattern The original/template ICU pattern.
     * @param string $locale The target locale.
     * @param array<int, string> $getTextPluralForms Gettext plural forms, indexed numerically.
     * @param string $pluralRulesExpression The Gettext plural rule (e.g., 'plural=(n != 1);').
     */
    public static function replacePluralsFormsFromGetText(Pattern $icuPattern, string $locale, array $getTextPluralForms, string $pluralRulesExpression): Pattern
    {
        $icuForms = self::getTextPluralToICU($locale, $getTextPluralForms, $pluralRulesExpression);

        $replacedPattern = clone $icuPattern;

        // Ensure nodes exist and are iterable
        if (!isset($replacedPattern->nodes) || !is_array($replacedPattern->nodes)) {
            return $replacedPattern;
        }

        foreach ($replacedPattern->nodes as $index => $node) {
            if ($node instanceof Placeholder && is_array($node->content)) {
                // We overwrite the options with our calculated forms.
                $replacedPattern->nodes[$index] = clone $node;
                $replacedPattern->nodes[$index]->content = $icuForms;
                return $replacedPattern;
            }
        }

        // Fallback: If no complex structure is found, assume the pattern
        // wraps a single plural element at the root.
        if (isset($replacedPattern->nodes[0]) && $replacedPattern->nodes[0] instanceof Placeholder) {
            $replacedPattern->nodes[0] = clone $replacedPattern->nodes[0];
            $replacedPattern->nodes[0]->content = $icuForms;
        }

        return $replacedPattern;
    }

    /**
     * Converts a Gettext plural (0, 1, 2, etc.) format to ICU Formats (zero, one, few, other, etc.).
     *
     * @param string $locale The target locale.
     * @param array<int, string> $getTextPluralForms Gettext plural forms, indexed numerically.
     * @param string $pluralRulesExpression The Gettext plural rule (e.g., 'plural=(n != 1);').
     *
     * @return array<string, Pattern> Map of ICU plural keys to their corresponding Patterns.
     */
    public static function getTextPluralToICU(string $locale, array $getTextPluralForms, string $pluralRulesExpression): array
    {
        // 1. Generate a Mapping Strategy (Cached).
        // This calculates which ICU category (e.g., 'few') maps to which Gettext index (e.g., 2).
        $mapping = self::_getMappingStrategy($locale, $pluralRulesExpression);

        // 2. Build the ICU Forms based on the mapping.
        $icuForms = [];

        // Apply standard categories (zero, one, two, few, many, other).
        foreach ($mapping['categories'] as $category => $gtIndex) {
            if (isset($getTextPluralForms[$gtIndex])) {
                $icuForms[$category] = new Pattern($getTextPluralForms[$gtIndex]);
            }
        }

        // Apply explicit value overrides (e.g., =0, =1).
        // This fixes edge cases where Gettext treats a specific number differently
        // than the language's standard grammar rules (e.g., strictly matching 0).
        foreach ($mapping['explicits'] as $number => $gtIndex) {
            if (isset($getTextPluralForms[$gtIndex])) {
                $icuForms["={$number}"] = new Pattern($getTextPluralForms[$gtIndex]);
            }
        }

        // 3. Ensure a mandatory 'other' fallback exists.
        // ICU requires 'other'. If the mapping didn't produce one, we use the most frequent Gettext form.
        if (!isset($icuForms['other'])) {
            $fallbackIndex = $mapping['fallbackIndex'];
            $icuForms['other'] = new Pattern($getTextPluralForms[$fallbackIndex] ?? '');
        }

        // 4. Sort forms: Explicit values first (=0), then categories in logical order.
        uksort($icuForms, function (string $a, string $b): int {
            $categoryOrder = ['zero', 'one', 'two', 'few', 'many', 'other'];
            $aIsExplicit = str_starts_with($a, '=');
            $bIsExplicit = str_starts_with($b, '=');

            // Explicit values (e.g., =1) always come before keywords.
            if ($aIsExplicit && !$bIsExplicit) {
                return -1;
            } elseif (!$aIsExplicit && $bIsExplicit) {
                return 1;
            } elseif ($aIsExplicit && $bIsExplicit) { // If both are explicit, sort numerically (=0 before =1).
                return (int)substr($a, 1) <=> (int)substr($b, 1);
            }

            // Otherwise, sort by standard category order.
            return array_search($a, $categoryOrder) <=> array_search($b, $categoryOrder);
        });

        return $icuForms;
    }


    /**
     * Probes the Locale and Gettext Rule to determine the mapping strategy.
     *
     * It runs numbers 0 through 100 through both the Gettext logic and the ICU formatter
     * to see which Gettext index aligns with which ICU category.
     *
     * @param string $locale The target locale.
     * @param string $expression The Gettext plural expression.
     *
     * @return array{categories: array<string, int>, explicits: array<int, int>, fallbackIndex: int}
     * @throws \Exception
     */
    private static function _getMappingStrategy(string $locale, string $expression): array
    {
        // Normalize expression
        $expression = str_replace(['plural=', ';'], '', $expression);
        $cacheKey = $locale . '|' . md5($expression);

        if (isset(self::$_ruleMapCache[$cacheKey])) {
            return self::$_ruleMapCache[$cacheKey];
        }

        // Prepare the tools
        $pluralRules = new PluralRule($expression);

        // Oracle pattern: asks ICU "What is this number?" (zero, one, few, etc.)
        $oraclePattern = '{n, plural, zero {zero} one {one} two {two} few {few} many {many} other {other}}';
        $formatter = new \MessageFormatter($locale, $oraclePattern);

        if ($formatter->getErrorCode() != U_ZERO_ERROR) {
            // MessageFormatter constructor returns null/false on failure depending on PHP version/context
            throw new \Exception("Invalid locale '{$locale}' or unable to create MessageFormatter: " . $formatter->getErrorMessage());
        }

        // Temporary storage
        $categoryHits = [];  // $categoryHits[icu_category][gettext_index] = frequency
        $numberToIndex = []; // $numberToIndex[number] = ['cat' => '...', 'idx' => int]
        $indexFrequency = []; // Tracks which Gettext index is used most overall

        // Probe numbers 0 to 100 to detect patterns
        for ($i = 0; $i <= 100; $i++) {
            $gtIndex = $pluralRules->get($i);
            $icuCategory = $formatter->format(['n' => $i]);

            if ($icuCategory === false) {
                continue;
            }

            $numberToIndex[$i] = ['cat' => $icuCategory, 'idx' => $gtIndex];

            // Track frequency to find the "dominant" index for this ICU category
            if (!isset($categoryHits[$icuCategory][$gtIndex])) {
                $categoryHits[$icuCategory][$gtIndex] = 0;
            }
            $categoryHits[$icuCategory][$gtIndex]++;

            // Overall frequency for global fallback
            if (!isset($indexFrequency[$gtIndex])) {
                $indexFrequency[$gtIndex] = 0;
            }
            $indexFrequency[$gtIndex]++;
        }

        // Determine the "Dominant" Index for each Category.
        // Example: If ICU 'other' maps to Gettext Index 1 (90 times) and Index 0 (2 times),
        // we assume Index 1 is the intended translation for 'other'.
        $finalCategories = [];
        $dominantIndices = []; // Key: Category, Value: Index

        foreach ($categoryHits as $cat => $counts) {
            // Sort by frequency descending
            arsort($counts);

            $keys = array_keys($counts);
            if (count($keys) > 1 && $counts[$keys[0]] === $counts[$keys[1]]) {
                sort($keys); // Preferir índice numérico más bajo (0) en caso de empate de frecuencia
                $dominantIndex = $keys[0];
            } else {
                $dominantIndex = array_key_first($counts);
            }

            $finalCategories[$cat] = $dominantIndex;
            $dominantIndices[$cat] = $dominantIndex;
        }

        // Identify Explicit Overrides.
        // If a specific number maps to a Gettext index that is NOT the dominant index
        // for its linguistic category, we must treat it as an explicit value (e.g. =0).
        $explicits = [];
        foreach ($numberToIndex as $num => $data) {
            $cat = $data['cat'];
            $idx = $data['idx'];

            if (isset($dominantIndices[$cat]) && $dominantIndices[$cat] !== $idx) {
                $explicits[$num] = $idx;
            }
        }

        // Determine best global fallback (usually the most frequent index)
        arsort($indexFrequency);
        $fallbackIndex = array_key_first($indexFrequency) ?? 0;

        $result = [
            'categories' => $finalCategories,
            'explicits' => $explicits,
            'fallbackIndex' => $fallbackIndex,
        ];

        self::$_ruleMapCache[$cacheKey] = $result;

        return $result;
    }
}
