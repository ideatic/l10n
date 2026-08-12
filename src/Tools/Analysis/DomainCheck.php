<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis;

use ideatic\l10n\Domain;

/**
 * A check applied independently to each domain
 */
abstract class DomainCheck extends Check
{
    /**
     * @return list<Issue>
     */
    abstract protected function analyzeDomain(Domain $domain, Context $context): array;

    public function analyze(Context $context): array
    {
        $issues = [];

        foreach ($context->domains as $domain) {
            // A domain outside the pipeline has no translations to check: reporting it once per string and per
            // locale would only repeat what unconfigured-domain already says once
            if ($this->needsTranslations() && !$context->isTranslated($domain)) {
                continue;
            }

            $issues = [...$issues, ...$this->analyzeDomain($domain, $context)];
        }

        return $issues;
    }
}
