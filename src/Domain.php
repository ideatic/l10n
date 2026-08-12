<?php

declare(strict_types=1);

namespace ideatic\l10n;

use Exception;
use ideatic\l10n\Translation\Provider;

/**
 * Represents a group of translatable strings
 */
class Domain
{
    public string $name;
    /** @var array<array<LString>> */
    public array $strings;
    public Provider $translator;

    /**
     * Groups the received strings into their matching domains
     *
     * @param LString[] $strings
     *
     * @return list<self>
     */
    public static function generate(array $strings): array
    {
        $domains = [];

        foreach ($strings as $string) {
            /** @phpstan-ignore instanceof.alwaysTrue */
            if (!($string instanceof LString)) {
                throw new Exception("Invalid localizable string received, instance of " . LString::class . " expected");
            } elseif (isset($string->domain)) {
                throw new Exception("String '{$string->id}' already belongs to a domain!");
            }

            // Assign the group
            if (isset($domains[$string->domainName])) {
                $domain = $domains[$string->domainName];
            } else {
                $domain = new Domain();
                $domain->name = $string->domainName;
                $domain->strings = [];

                $domains[$string->domainName] = $domain;
            }

            // Group similar strings
            $stringID = $string->fullyQualifiedID();
            $domain->strings[$stringID] ??= [];

            // Check that the same id holds the same content
            foreach ($domain->strings[$stringID] as $otherString) {
                if ($otherString->text !== $string->text && !$otherString->isICU && !$string->isICU) {
                    throw new Exception("String '{$stringID}' already exists in domain '{$domain->name}' with different content! @ {$string->file}");
                }
            }

            $domain->strings[$stringID][] = $string;

            $string->domain = $domain;
        }

        // Sort the strings of each domain by how often they are used
        foreach ($domains as $domain) {
            uasort(
                $domain->strings,
                function (array $stringsA, array $stringsB) {
                    /** @var LString[] $stringsA */
                    /** @var LString[] $stringsB */

                    $freqDiff = count($stringsB) - count($stringsA);
                    $lengthDiff = mb_strlen($stringsA[0]->id) - mb_strlen($stringsB[0]->id);

                    if ($freqDiff != 0) {
                        return $freqDiff;
                    } elseif ($lengthDiff != 0) {
                        return $lengthDiff;
                    } else {
                        return strcmp($stringsA[0]->id, $stringsB[0]->id);
                    }
                }
            );
        }

        return array_values($domains);
    }
}

