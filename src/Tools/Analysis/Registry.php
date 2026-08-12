<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis;

use Exception;
use ideatic\l10n\Tools\Analysis\Check\CommentQuality;
use ideatic\l10n\Tools\Analysis\Check\ConfigHygiene;
use ideatic\l10n\Tools\Analysis\Check\ContextFormat;
use ideatic\l10n\Tools\Analysis\Check\CrossDomainDuplicates;
use ideatic\l10n\Tools\Analysis\Check\DuplicatedText;
use ideatic\l10n\Tools\Analysis\Check\EmptyCatalog;
use ideatic\l10n\Tools\Analysis\Check\EmptyStrings;
use ideatic\l10n\Tools\Analysis\Check\ExcludedStrings;
use ideatic\l10n\Tools\Analysis\Check\FragmentStrings;
use ideatic\l10n\Tools\Analysis\Check\IcuConsistency;
use ideatic\l10n\Tools\Analysis\Check\IcuIdCollision;
use ideatic\l10n\Tools\Analysis\Check\IcuPluralForms;
use ideatic\l10n\Tools\Analysis\Check\IcuRuntime;
use ideatic\l10n\Tools\Analysis\Check\IcuSuperseded;
use ideatic\l10n\Tools\Analysis\Check\IcuSyntax;
use ideatic\l10n\Tools\Analysis\Check\IdenticalTranslation;
use ideatic\l10n\Tools\Analysis\Check\MarkupMismatch;
use ideatic\l10n\Tools\Analysis\Check\MissingComment;
use ideatic\l10n\Tools\Analysis\Check\MissingTranslations;
use ideatic\l10n\Tools\Analysis\Check\NonLocalizable;
use ideatic\l10n\Tools\Analysis\Check\ObsoleteTranslations;
use ideatic\l10n\Tools\Analysis\Check\PlaceholderConvention;
use ideatic\l10n\Tools\Analysis\Check\PlaceholderMismatch;
use ideatic\l10n\Tools\Analysis\Check\PluralCandidate;
use ideatic\l10n\Tools\Analysis\Check\SimilarStrings;
use ideatic\l10n\Tools\Analysis\Check\SourceFormatting;
use ideatic\l10n\Tools\Analysis\Check\TranslationSyntax;
use ideatic\l10n\Tools\Analysis\Check\UnconfiguredDomain;

/**
 * Catalog of the available checks
 */
abstract class Registry
{
    /** Order in which the groups are shown in the report */
    public const array GROUPS = ['pipeline', 'source', 'icu', 'metadata', 'translations'];

    /**
     * Gets every available check
     *
     * @return list<Check>
     */
    public static function all(): array
    {
        return [
            // Project configuration
            new ConfigHygiene(),
            new UnconfiguredDomain(),
            new EmptyCatalog(),
            new ExcludedStrings(),

            // Source strings
            new EmptyStrings(),
            new SourceFormatting(),
            new SimilarStrings(),
            new DuplicatedText(),
            new CrossDomainDuplicates(),
            new NonLocalizable(),
            new FragmentStrings(),

            // ICU
            new IcuSyntax(),
            new IcuIdCollision(),
            new IcuRuntime(),
            new PluralCandidate(),
            new IcuSuperseded(),
            new IcuConsistency(),

            // Metadata
            new CommentQuality(),
            new ContextFormat(),
            new MissingComment(),
            new PlaceholderConvention(),

            // Translations
            new PlaceholderMismatch(),
            new IcuPluralForms(),
            new TranslationSyntax(),
            new MarkupMismatch(),
            new ObsoleteTranslations(),
            new MissingTranslations(),
            new IdenticalTranslation(),
        ];
    }

    /**
     * Gets the checks that should run for the given options
     *
     * @param bool $hasTranslations Whether there are target locales to check the translations against
     *
     * @return list<Check>
     */
    public static function resolve(Options $options, bool $hasTranslations = true): array
    {
        $checks = self::all();

        self::_validate($checks, [...$options->checks, ...$options->skip]);

        return array_values(
            array_filter(
                $checks,
                static function (Check $check) use ($options, $hasTranslations): bool {
                    if ($options->checks && !self::_matches($check, $options->checks)) {
                        return false;
                    }

                    if (self::_matches($check, $options->skip)) {
                        return false;
                    }

                    if ($check->needsTranslations() && !$hasTranslations) {
                        return false;
                    }

                    return !$check->isExpensive() || $options->deep;
                }
            )
        );
    }

    /**
     * @param list<Check>  $checks
     * @param list<string> $names
     */
    private static function _validate(array $checks, array $names): void
    {
        $available = [...array_map(static fn(Check $check): string => $check->name(), $checks), ...self::GROUPS];

        foreach ($names as $name) {
            if (!in_array($name, $available, true)) {
                throw new Exception("Unknown analysis check or group '{$name}'. Run the analyze tool with --list-checks to see the available ones.");
            }
        }
    }

    /**
     * @param list<string> $names
     */
    private static function _matches(Check $check, array $names): bool
    {
        return in_array($check->name(), $names, true) || in_array($check->group(), $names, true);
    }
}
