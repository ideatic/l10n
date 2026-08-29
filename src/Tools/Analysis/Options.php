<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis;

use stdClass;

/**
 * Analyzer run options, combining the command line parameters with the "analyze" section of the project
 * configuration (the command line parameters take precedence)
 */
class Options
{
    /** Minimum similarity percentage for two strings to be considered alike */
    public float $threshold = 90;

    /** @var list<string> Checks or groups to run (empty = all of them) */
    public array $checks = [];

    /** @var list<string> Checks or groups to skip */
    public array $skip = [];

    /** @var list<string> Domains to analyze (empty = all of them) */
    public array $domains = [];

    /**
     * Half of the analysis to run: only the source strings, only their translations, or both.
     *
     * Scope::Source needs no catalog at all, so it can run on every commit even before anything has been sent out
     * to translate.
     */
    public Scope $scope = Scope::Both;

    /** Minimum severity included in the report */
    public Severity $minSeverity = Severity::Info;

    /** Maximum number of issues shown per check (0 = no limit) */
    public int $limit = 20;

    /** Report format: text | json */
    public string $format = 'text';

    /** Return a non-zero exit code for warnings too */
    public bool $strict = false;

    /** Run the expensive checks as well */
    public bool $deep = false;

    /** Force or disable the use of colours (null = auto-detect) */
    public ?bool $colors = null;

    /** Show the catalog of available checks and exit */
    public bool $listChecks = false;

    /** Language expected in the translator comments (empty = do not check) */
    public string $commentLanguage = '';

    /**
     * Largest amount the application can ever display. Plural forms only selected by larger amounts, or by
     * fractions, are reported as a warning instead of an error: they are still missing, but the user will never
     * get to see them.
     */
    public int $pluralRange = 100_000;

    /** @var list<string> Placeholder names that stand for a quantity and suggest using ICU */
    public array $countPlaceholders = [
        'count',
        'num',
        'number',
        'total',
        'amount',
        'quantity',
        'points',
        'days',
        'steps',
        'min',
        'max',
        'rounds',
        'games',
        'items',
        'size',
        'length',
    ];

    /** @var list<string> Regular expressions of ambiguous words that should always carry a comment */
    public array $ambiguousWords = [];

    /**
     * @var list<string> Locales where a translation identical to the source is normal and is not reported. It is
     *      meant for the locales very close to the source one (Galician, Portuguese or Catalan against Spanish),
     *      where a good part of the vocabulary legitimately matches.
     */
    public array $identicalLocales = [];

    /** Constraints of the client ICU engine (null = do not check) */
    public ?stdClass $icuRuntime = null;

    /**
     * @param array<string, string|null> $params Already parsed command line parameters
     */
    public static function create(?object $config, array $params): self
    {
        $options = new self();

        // Values coming from the project configuration
        if ($config !== null) {
            $options->threshold = isset($config->threshold) ? floatval($config->threshold) : $options->threshold;
            $options->skip = self::_toList($config->skip ?? []);
            $options->commentLanguage = strval($config->commentLanguage ?? '');
            $options->pluralRange = isset($config->pluralRange) ? intval($config->pluralRange) : $options->pluralRange;
            $options->countPlaceholders = self::_toList($config->countPlaceholders ?? []) ?: $options->countPlaceholders;
            $options->ambiguousWords = self::_toList($config->ambiguousWords ?? []);
            $options->identicalLocales = self::_toList($config->identicalLocales ?? []);
            $options->scope = isset($config->scope) ? Scope::parse(strval($config->scope)) : $options->scope;
            $options->icuRuntime = isset($config->icuRuntime) ? (object)$config->icuRuntime : null;
        }

        // Values coming from the command line
        $threshold = self::_value($params, 'threshold', 't');
        if ($threshold !== null) {
            $options->threshold = floatval($threshold);
        }

        $options->checks = self::_split(self::_value($params, 'checks', 'check'));

        $skip = self::_split(self::_value($params, 'skip'));
        if ($skip) {
            $options->skip = $skip;
        }

        $domains = self::_value($params, 'domains', 'domain');
        $options->domains = $domains === 'all' ? [] : self::_split($domains);

        $scope = self::_value($params, 'scope');
        if ($scope !== null) {
            $options->scope = Scope::parse($scope);
        }

        $severity = self::_value($params, 'severity');
        if ($severity !== null) {
            $options->minSeverity = Severity::parse($severity);
        }

        $limit = self::_value($params, 'limit');
        if ($limit !== null) {
            $options->limit = max(0, intval($limit));
        }

        $format = self::_value($params, 'format');
        if ($format !== null) {
            $options->format = strtolower($format);

            if (!in_array($options->format, ['text', 'json'], true)) {
                throw new \Exception("Invalid analysis output format '{$format}', expected 'text' or 'json'");
            }
        }

        $options->strict = self::_flag($params, 'strict');
        $options->deep = self::_flag($params, 'deep');
        $options->listChecks = self::_flag($params, 'list-checks', 'list');

        if (self::_flag($params, 'no-color', 'no-colors')) {
            $options->colors = false;
        } elseif (self::_flag($params, 'color', 'colors')) {
            $options->colors = true;
        }

        return $options;
    }

    /**
     * @param array<string, string|null> $params
     */
    private static function _value(array $params, string ...$names): ?string
    {
        foreach ($names as $name) {
            if (isset($params[$name])) {
                return $params[$name];
            }
        }

        return null;
    }

    /**
     * @param array<string, string|null> $params
     */
    private static function _flag(array $params, string ...$names): bool
    {
        foreach ($names as $name) {
            if (array_key_exists($name, $params)) {
                return !in_array(strtolower($params[$name] ?? ''), ['0', 'false', 'no'], true);
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function _split(?string $value): array
    {
        if ($value === null || mb_trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(mb_trim(...), explode(',', $value))));
    }

    /**
     * @return list<string>
     */
    private static function _toList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_map(strval(...), is_array($value) ? $value : [$value]));
    }
}
