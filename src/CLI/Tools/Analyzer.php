<?php

declare(strict_types=1);

namespace ideatic\l10n\CLI\Tools;

use Exception;
use ideatic\l10n\CLI\Environment;
use ideatic\l10n\CLI\Output;
use ideatic\l10n\Domain;
use ideatic\l10n\Tools\Analysis\Check;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Options;
use ideatic\l10n\Tools\Analysis\Registry;
use ideatic\l10n\Tools\Analysis\Severity;
use ideatic\l10n\Translation\Provider\Projects;
use stdClass;

/**
 * Herramienta para analizar la calidad de las cadenas localizables de un proyecto y de sus traducciones
 */
class Analyzer
{
    public static function run(Environment $environment): void
    {
        $options = Options::create($environment->config->analyze ?? null, $environment->params);
        $json = $options->format === 'json';

        // En modo JSON la salida estándar queda reservada para el informe
        $output = new Output($json ? false : $options->colors, $json ? STDERR : STDOUT);

        if ($options->listChecks) {
            self::_listChecks($output);
            return;
        }

        $domains = self::_scanDomains($environment, $output, $options);
        $locales = self::_getLocales($environment);

        $translator = new Projects($environment->config);
        foreach ($domains as $domain) {
            $domain->translator = $translator;
        }

        $context = new Context($environment->config, $domains, $locales, $translator, $options);
        $checks = Registry::resolve($options, $locales !== []);

        $output->line(
            'Running ' . count($checks) . ' checks over ' . count($domains) . ' domain(s)'
            . ($locales ? ' and ' . count($locales) . ' locale(s): ' . implode(', ', $locales) : ' (no target locales)')
        );

        $issues = [];
        $truncated = [];
        foreach ($checks as $check) {
            $found = array_filter(
                $check->analyze($context),
                static fn(Issue $issue): bool => $issue->severity->weight() >= $options->minSeverity->weight()
            );

            $issues = [...$issues, ...self::_applyLimit(array_values($found), $options->limit, $truncated)];
        }

        if ($json) {
            self::_reportJson($issues, $checks, $domains, $locales, $truncated);
        } else {
            self::_report($output, $issues, $checks, $domains, $truncated);
        }

        $counts = self::_countBySeverity($issues);
        if ($counts[Severity::Error->value] > 0 || ($options->strict && $counts[Severity::Warning->value] > 0)) {
            $environment->exitCode = 1;
        }
    }

    /**
     * Escanea las cadenas del proyecto, redirigiendo el resumen del escaneo al flujo de salida adecuado
     *
     * @return list<Domain>
     */
    private static function _scanDomains(Environment $environment, Output $output, Options $options): array
    {
        ob_start();
        try {
            $domains = Extractor::scanDomains($environment);
        } finally {
            $output->write(strval(ob_get_clean()));
        }

        if (!$options->domains) {
            return $domains;
        }

        $available = array_column($domains, 'name');

        foreach ($options->domains as $name) {
            if (!in_array($name, $available, true)) {
                throw new Exception("Unknown domain '{$name}'. Available domains: " . implode(', ', $available));
            }
        }

        return array_values(array_filter($domains, static fn(Domain $domain): bool => in_array($domain->name, $options->domains, true)));
    }

    /**
     * Obtiene los idiomas de destino contra los que comprobar las traducciones
     *
     * @return list<string>
     */
    private static function _getLocales(Environment $environment): array
    {
        $config = $environment->config;

        $available = array_map(
            static fn(string|stdClass $info): string => is_object($info) ? strval($info->code ?? '') : $info,
            $config->targetLocales ?? []
        );

        $requested = $environment->params['locale'] ?? $environment->params['language'] ?? $environment->params['lang'] ?? null;

        if ($requested !== null && $requested !== 'all') {
            $locales = array_values(array_filter(array_map(mb_trim(...), explode(',', $requested))));

            foreach ($locales as $locale) {
                if ($available && !in_array($locale, $available, true)) {
                    throw new Exception("Locale '{$locale}' is not declared in the project targetLocales: " . implode(', ', $available));
                }
            }

            $available = $locales;
        }

        $sourceLocale = $config->sourceLocale ?? null;

        return array_values(
            array_filter(array_unique($available), static fn(string $locale): bool => $locale !== '' && $locale !== $sourceLocale)
        );
    }

    /**
     * Aplica el límite de problemas mostrados por comprobación y dominio, conservando los más prioritarios.
     *
     * Los problemas ocultos no se representan como un problema más: se anotan aparte en $truncated para que no
     * alteren el filtro por gravedad, el resumen ni la salida JSON.
     *
     * @param list<Issue>        $issues
     * @param array<string, int> $truncated Nº de problemas ocultos, indexado por "comprobación|dominio"
     *
     * @return list<Issue>
     */
    private static function _applyLimit(array $issues, int $limit, array &$truncated): array
    {
        if ($limit <= 0 || count($issues) <= $limit) {
            return self::_sort($issues);
        }

        $byDomain = [];
        foreach ($issues as $issue) {
            $byDomain[$issue->domain ?? ''][] = $issue;
        }

        $limited = [];
        foreach ($byDomain as $domainName => $domainIssues) {
            $domainIssues = self::_sort($domainIssues);

            if (count($domainIssues) > $limit) {
                $truncated["{$domainIssues[0]->check}|{$domainName}"] = count($domainIssues) - $limit;
                $domainIssues = array_slice($domainIssues, 0, $limit);
            }

            $limited = [...$limited, ...$domainIssues];
        }

        return $limited;
    }

    /**
     * @param list<Issue> $issues
     *
     * @return list<Issue>
     */
    private static function _sort(array $issues): array
    {
        usort(
            $issues,
            static fn(Issue $a, Issue $b): int => [$b->severity->weight(), $b->priority] <=> [$a->severity->weight(), $a->priority]
        );

        return $issues;
    }

    /**
     * @param list<Issue>        $issues
     * @param list<Check>        $checks
     * @param list<Domain>       $domains
     * @param array<string, int> $truncated
     */
    private static function _report(Output $output, array $issues, array $checks, array $domains, array $truncated = []): void
    {
        $checkTitles = [];
        $checkOrder = [];
        foreach ($checks as $position => $check) {
            $checkTitles[$check->name()] = $check->title();
            $checkOrder[$check->name()] = $position;
        }

        // Agrupar por dominio, dejando en primer lugar los problemas que no pertenecen a ninguno
        $byDomain = ['' => []];
        foreach (array_column($domains, 'name') as $name) {
            $byDomain[$name] = [];
        }

        foreach ($issues as $issue) {
            $byDomain[$issue->domain ?? ''][$issue->check][] = $issue;
        }

        foreach ($byDomain as $domainName => $domainIssues) {
            if (!$domainIssues) {
                continue;
            }

            uksort($domainIssues, static fn(string $a, string $b): int => ($checkOrder[$a] ?? 0) <=> ($checkOrder[$b] ?? 0));

            $output->heading($domainName === '' ? 'Project configuration' : "{$domainName} domain", 4);

            foreach ($domainIssues as $checkName => $checkIssues) {
                $output->line($output->color('## ' . ($checkTitles[$checkName] ?? $checkName) . ' (' . count($checkIssues) . ')', 'cyan'));
                $output->line();

                foreach (self::_sort($checkIssues) as $issue) {
                    self::_writeIssue($output, $issue);
                }

                $hidden = $truncated["{$checkName}|{$domainName}"] ?? 0;
                if ($hidden > 0) {
                    $output->line('  ' . $output->color("(+{$hidden} more, use --limit=0 to show them all)", 'gray'));
                    $output->line();
                }
            }
        }

        self::_writeSummary($output, $issues, $checkTitles);
    }

    private static function _writeIssue(Output $output, Issue $issue): void
    {
        $indent = '          ';

        $output->line('  ' . $output->severity($issue->severity) . ' ' . $issue->message);

        $reference = [];
        if ($issue->stringID !== null) {
            $reference[] = 'string: "' . Context::preview($issue->stringID, 80) . '"';
        }
        if ($issue->locale !== null) {
            $reference[] = "locale: {$issue->locale}";
        }

        if ($reference) {
            $output->line($indent . $output->color(implode('   ', $reference), 'gray'));
        }

        if ($issue->locations) {
            $output->line($indent . $output->color('at ' . implode(', ', $issue->locations), 'gray'));
        }

        foreach ($issue->details as $detail) {
            $output->line($indent . str_replace("\n", "\n{$indent}", $detail));
        }

        $output->line();
    }

    /**
     * @param list<Issue>           $issues
     * @param array<string, string> $checkTitles
     */
    private static function _writeSummary(Output $output, array $issues, array $checkTitles): void
    {
        $output->heading('Summary', 4);

        if (!$issues) {
            $output->line('  ' . $output->color('No issues found', 'green'));
            $output->line();
            return;
        }

        $perCheck = [];
        foreach ($issues as $issue) {
            $perCheck[$issue->check][$issue->severity->value] ??= 0;
            $perCheck[$issue->check][$issue->severity->value]++;
        }

        uksort($perCheck, static fn(string $a, string $b): int => array_search($a, array_keys($checkTitles), true) <=> array_search($b, array_keys($checkTitles), true));

        $rows = [];
        foreach ($perCheck as $checkName => $counts) {
            $rows[] = [
                strval($checkName),
                strval($counts[Severity::Error->value] ?? 0),
                strval($counts[Severity::Warning->value] ?? 0),
                strval($counts[Severity::Info->value] ?? 0),
            ];
        }

        $totals = self::_countBySeverity($issues);
        $rows[] = [
            'TOTAL',
            strval($totals[Severity::Error->value]),
            strval($totals[Severity::Warning->value]),
            strval($totals[Severity::Info->value]),
        ];

        $output->table(['check', 'errors', 'warnings', 'info'], $rows, [1, 2, 3]);
        $output->line();
    }

    /**
     * @param list<Issue>        $issues
     * @param list<Check>        $checks
     * @param list<Domain>       $domains
     * @param list<string>       $locales
     * @param array<string, int> $truncated
     */
    private static function _reportJson(array $issues, array $checks, array $domains, array $locales, array $truncated = []): void
    {
        $counts = self::_countBySeverity($issues);

        echo json_encode(
            [
                'summary' => [
                    'checks' => array_map(static fn(Check $check): string => $check->name(), $checks),
                    'domains' => array_column($domains, 'name'),
                    'locales' => $locales,
                    'issues' => count($issues),
                    ...$counts,
                    'hidden' => array_sum($truncated),
                    'hiddenByCheck' => self::_hiddenByCheck($truncated),
                ],
                'issues' => array_map(static fn(Issue $issue): array => $issue->toArray(), self::_sort($issues)),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    /**
     * Agrupa por comprobación los problemas ocultos por el límite, para que un consumidor del JSON sepa qué parte
     * del informe le falta y no confunda el recuento con el total real
     *
     * @param array<string, int> $truncated Indexado por "comprobación|dominio"
     *
     * @return array<string, int>
     */
    private static function _hiddenByCheck(array $truncated): array
    {
        $byCheck = [];

        foreach ($truncated as $key => $hidden) {
            $check = explode('|', $key, 2)[0];
            $byCheck[$check] = ($byCheck[$check] ?? 0) + $hidden;
        }

        return $byCheck;
    }

    /**
     * @param list<Issue> $issues
     *
     * @return array<string, int>
     */
    private static function _countBySeverity(array $issues): array
    {
        $counts = [Severity::Error->value => 0, Severity::Warning->value => 0, Severity::Info->value => 0];

        foreach ($issues as $issue) {
            $counts[$issue->severity->value]++;
        }

        return $counts;
    }

    private static function _listChecks(Output $output): void
    {
        $output->heading('Available analysis checks', 2);

        $rows = [];
        foreach (Registry::all() as $check) {
            $flags = [];
            if ($check->needsTranslations()) {
                $flags[] = 'needs translations';
            }
            if ($check->isExpensive()) {
                $flags[] = '--deep only';
            }

            $rows[] = [$check->name(), $check->group(), $check->title(), implode(', ', $flags)];
        }

        $output->table(['check', 'group', 'description', ''], $rows);

        $output->line();
        $output->line('  Groups: ' . implode(', ', Registry::GROUPS));
        $output->line('  Use --checks=name,group to run only some of them, or --skip=name,group to exclude them.');
        $output->line();
    }
}
