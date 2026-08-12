<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Domain;
use ideatic\l10n\LString;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\DomainCheck;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Severity;

/**
 * Detects strings sharing the same text but a different identifier within a domain, usually because one of them
 * defines an unnecessary context or explicit id. They mean duplicated work for the translators.
 *
 * Two strings matching in the source language does not mean one is redundant: "Plano" is 'Flat' or 'Floor plan'
 * in English depending on what is being talked about. What this looks for is a gratuitous split, so before
 * reporting it checks for evidence that the split is needed: that the catalogs already translate them
 * differently, or that the author declared a context for each one.
 */
class DuplicatedText extends DomainCheck
{
    public function name(): string
    {
        return 'duplicated-text';
    }

    public function group(): string
    {
        return 'source';
    }

    public function title(): string
    {
        return 'Duplicated text';
    }

    protected function analyzeDomain(Domain $domain, Context $context): array
    {
        $byText = [];

        foreach ($domain->strings as $stringID => $strings) {
            $text = $strings[0]->translatableText();

            if (mb_trim($text) === '') {
                continue;
            }

            $byText[$text][strval($stringID)] = array_values($strings);
        }

        $issues = [];

        foreach ($byText as $text => $group) {
            if (count($group) < 2) {
                continue;
            }

            // The catalogs already prove the entries mean different things: there is nothing to merge
            if (self::_translationsDiverge($group, $context)) {
                continue;
            }

            // If they all declare a context the split is deliberate and the translator gets the information they
            // need. The real problem is the entry without a context, since it arrives as a single one to translate
            $deliberate = array_all($group, static fn(array $strings): bool => mb_trim($strings[0]->context ?? '') !== '');

            $details = [];
            $occurrences = 0;

            foreach ($group as $stringID => $strings) {
                $occurrences += count($strings);
                $context_ = $strings[0]->context ?? null;
                $locations = Context::locations($strings, 3);

                $details[] = "{$stringID} " . ($context_ === null ? '(no context)' : "(context: '{$context_}')")
                    . ($locations ? "\n      " . implode(', ', $locations) : '');
            }

            if ($deliberate) {
                $details[] = 'Every entry declares its own context, so this is only worth merging if they really mean the same thing';
            }

            $issues[] = new Issue(
                check: $this->name(),
                severity: $deliberate ? Severity::Info : Severity::Warning,
                // PHP turns numeric-looking array keys into integers
                message: count($group) . ' entries share the text "' . Context::preview(strval($text)) . '"'
                    . ($deliberate ? ', each with a different context' : ''),
                domain: $domain->name,
                stringID: strval(array_key_first($group)),
                details: $details,
                priority: $occurrences,
            );
        }

        return $issues;
    }

    /**
     * Tells whether some locale already translates the entries of the group differently, which proves that splitting
     * them was necessary.
     *
     * Translations are consulted without declaring needsTranslations() so that the check keeps running in the
     * projects that do not have catalogs yet: there, quite simply, there is no evidence to consult.
     *
     * @param array<string, list<LString>> $group
     */
    private static function _translationsDiverge(array $group, Context $context): bool
    {
        foreach ($context->locales as $locale) {
            $translations = [];

            foreach ($group as $strings) {
                $translation = $context->getTranslation($strings[0], $locale);

                if ($translation !== null && mb_trim($translation->translation) !== '') {
                    $translations[] = $translation->translation;
                }
            }

            // A missing translation on one entry proves nothing: at least two are needed to compare
            if (count($translations) > 1 && count(array_unique($translations)) > 1) {
                return true;
            }
        }

        return false;
    }
}
