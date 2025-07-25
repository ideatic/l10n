<?php

declare(strict_types=1);

namespace ideatic\l10n;

use Exception;
use ideatic\l10n\Translation\Provider;

/**
 * Representa una agrupación de cadenas traducibles
 */
class Domain
{
    public string $name;
    /** @var array<array<LString>> */
    public array $strings;
    public Provider $translator;

    /**
     * Agrupa las cadenas recibidas en sus correspondientes dominios
     *
     * @param LString[] $strings
     *
     * @return self[]
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

            // Asignar grupo
            if (isset($domains[$string->domainName])) {
                $domain = $domains[$string->domainName];
            } else {
                $domain = new Domain();
                $domain->name = $string->domainName;
                $domain->strings = [];

                $domains[$string->domainName] = $domain;
            }

            // Agrupar cadenas similares
            $stringID = $string->fullyQualifiedID();

            if (!isset($domain->strings[$stringID])) {
                $domain->strings[$stringID] = [];
            }

            $domain->strings[$stringID][] = $string;

            $string->domain = $domain;
        }

        // Ordenar cadenas de cada dominio según su frecuencia de uso
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

