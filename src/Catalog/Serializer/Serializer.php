<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Serializer;

use ideatic\l10n\Domain;
use ideatic\l10n\Project;
use InvalidArgumentException;
use stdClass;

/**
 * Writes the strings of one or more domains as a translation document.
 *
 * It is the counterpart of Loader: each subclass produces one format, and the same set of domains can be exported to
 * every one of them without the caller knowing the details.
 */
abstract class Serializer
{
    /**
     * Main locale of the document. When not given, a document with the source strings is generated
     */
    public string|null $locale;

    /**
     * Comments attached to this document
     */
    public string $comments = '';

    /**
     * Locale used as a reference for the translation
     * @param string|list<string>|null $referenceTranslation
     */
    public string|array|null $referenceTranslation;

    /**
     * Include the source code locations of the strings in the translation document
     */
    public bool $includeLocations = true;

    public string|null $fileExtension = null;

    public static function factory(string $name): self
    {
        if ($name == 'po') {
            return new PO();
        } elseif ($name == 'json') {
            return new JSON();
        } elseif ($name == 'json-extended') {
            $provider = new JSON();
            $provider->extended = true;
            return $provider;
        } elseif ($name == 'php') {
            return new PHP();
        } elseif ($name == 'xliff') {
            return new XLIFF();
        } else {
            throw new InvalidArgumentException("Invalid serializer format '{$name}'");
        }
    }

    /**
     * Guesses the serializer format from the extension of a file name.
     *
     * Returns null when the extension is unknown or the name carries no extension at all.
     */
    public static function formatFromFileName(string $fileName): string|null
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return match ($extension) {
            'po', 'pot' => 'po',
            'json' => 'json',
            'php' => 'php',
            'xlf', 'xliff' => 'xliff',
            default => null,
        };
    }

    /**
     * Generates a localization file for the given groups
     *
     * @param list<Domain> $domains
     */
    public abstract function generate(array $domains, stdClass|Project|null $config = null): string;

    /**
     * Merges the comments of the different occurrences of a string with those of the previous catalog.
     *
     * The comparison is line by line and not block by block: the comment coming from the catalog is itself the
     * result of previous merges, so comparing it whole would never match the individual lines composing it and the
     * comment would grow by one copy on every export.
     *
     * @param list<string|null> $comments
     */
    protected static function mergeComments(array $comments): string
    {
        $lines = [];

        foreach ($comments as $comment) {
            foreach (preg_split('/\R/u', $comment ?? '') ?: [] as $line) {
                $line = mb_trim($line);

                if ($line !== '' && !in_array($line, $lines, true)) {
                    $lines[] = $line;
                }
            }
        }

        return implode(PHP_EOL, $lines);
    }
}

