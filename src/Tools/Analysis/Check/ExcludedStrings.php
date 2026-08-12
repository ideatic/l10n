<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis\Check;

use ideatic\l10n\LString;
use ideatic\l10n\Project;
use ideatic\l10n\String\DirectoryProvider;
use ideatic\l10n\Tools\Analysis\Check;
use ideatic\l10n\Tools\Analysis\Context;
use ideatic\l10n\Tools\Analysis\Issue;
use ideatic\l10n\Tools\Analysis\Severity;
use ideatic\l10n\Tools\Extractor;
use ideatic\l10n\Utils\IO;
use stdClass;
use Throwable;

/**
 * Looks for strings marked as translatable inside the excluded paths of each project.
 *
 * These are strings the developer explicitly marked for translation but the extractor never gets to see, so they
 * appear in no catalog and in no report: they are always shown untranslated.
 */
class ExcludedStrings extends Check
{
    /** Dependency or build directories that never hold code of your own */
    private const array IGNORED = ['node_modules', 'vendor', '.git', '.svn', 'dist', 'build', 'coverage', '.angular', '.next', '.cache'];

    /** Number of sample strings included in the detail */
    private const int SAMPLE_SIZE = 10;

    public function name(): string
    {
        return 'excluded-strings';
    }

    public function group(): string
    {
        return 'pipeline';
    }

    public function title(): string
    {
        return 'Localizable strings in excluded paths';
    }

    public function isExpensive(): bool
    {
        return true;
    }

    public function analyze(Context $context): array
    {
        $issues = [];

        /** @var Project|stdClass $project */
        foreach ($context->config->projects as $project) {
            try {
                $formats = Extractor::getProjectProvider($project)->extensions();
            } catch (Throwable) { // Unrecognised project type, extraction already fails
                continue;
            }

            foreach ($project->exclude ?? [] as $excluded) {
                $path = IO::normalizePath(IO::combinePaths($project->path, strval($excluded)));

                if (!is_dir($path) || in_array(basename($path), self::IGNORED, true)) {
                    continue;
                }

                $provider = new DirectoryProvider($path);
                foreach ($formats as $extension => $extensionFormats) {
                    foreach ($extensionFormats as $format) {
                        $provider->addFormat(strval($extension), $format);
                    }
                }

                foreach (self::IGNORED as $ignored) {
                    // The ** wildcard becomes ".*" between two separators, so it never matches a direct child:
                    // that case has to be excluded separately
                    $provider->excludePath(IO::normalizePath(IO::combinePaths($path, $ignored)));
                    $provider->excludePath(IO::normalizePath(IO::combinePaths($path, '**', $ignored)));
                }

                try {
                    $strings = $provider->getStrings();
                } catch (Throwable $e) {
                    $issues[] = new Issue(
                        check: $this->name(),
                        severity: Severity::Info,
                        message: "Unable to scan the excluded path '{$excluded}' of project '{$project->name}': {$e->getMessage()}",
                        priority: 1,
                    );
                    continue;
                }

                if (!$strings) {
                    continue;
                }

                $sample = array_map(
                    static fn(LString $string): string => '"' . Context::preview($string->translatableText(), 60) . '"',
                    array_slice($strings, 0, self::SAMPLE_SIZE)
                );

                if (count($strings) > self::SAMPLE_SIZE) {
                    $sample[] = '(+' . (count($strings) - self::SAMPLE_SIZE) . ' more)';
                }

                $issues[] = new Issue(
                    check: $this->name(),
                    severity: Severity::Info,
                    message: count($strings) . " localizable strings found in the excluded path '{$excluded}' of project '{$project->name}'",
                    details: [...$sample, 'Either stop excluding this path, or remove the i18n markers from it'],
                    priority: count($strings),
                );
            }
        }

        return $issues;
    }
}
