<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\Tools\Analysis\Check;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Scope;
use ideatic\l10n\Tools\Analysis\Severity;

/**
 * Detects domains whose strings are extracted but never actually get translated.
 *
 * If a domain appears neither in "sources" nor in any "exports" destiny, its strings are walked on every run and
 * simply discarded: they are never sent out to be translated nor are their translations imported, and the end
 * user always sees them in the source language without anything warning about it.
 */
class UnconfiguredDomain extends Check
{
    public function name(): string
    {
        return 'unconfigured-domain';
    }

    /** A configuration problem is worth knowing about whichever half is being audited */
    public function scope(): Scope
    {
        return Scope::Both;
    }

    public function group(): string
    {
        return 'pipeline';
    }

    public function title(): string
    {
        return 'Domains missing from the translation pipeline';
    }

    public function analyze(Context $context): array
    {
        $issues = [];

        foreach ($context->domains as $domain) {
            ['imported' => $imported, 'exported' => $exported] = $context->pipeline($domain);

            if ($imported && $exported) {
                continue;
            }

            $problems = [];
            if (!$exported) {
                $problems[] = 'no "exports" destiny includes it, so its strings are never sent to translation';
            }
            if (!$imported) {
                $problems[] = 'no "sources" entry covers it, so its translations are never imported';
            }

            $issues[] = new Issue(
                check: $this->name(),
                severity: Severity::Warning,
                message: "Domain '{$domain->name}' has " . number_format(count($domain->strings)) . ' extracted strings but ' . implode(' and ', $problems),
                domain: $domain->name,
                details: ['Add it to l10n.json, or move its strings into a configured domain'],
                priority: count($domain->strings),
            );
        }

        return $issues;
    }
}
