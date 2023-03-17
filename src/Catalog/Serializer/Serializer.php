<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Serializer;

use ideatic\l10n\Domain;
use InvalidArgumentException;

abstract class Serializer
{
    /**
     * Locale principal del documento. Si no se indica o es false, se generará un documento con las cadenas originales
     */
    public string|null $locale;

    /**
     * Comentarios asociados a este documento
     */
    public string $comments = '';

    /**
     * Locale utilizado como referencia para la traducción
     */
    public string|null $referenceTranslation;

    /**
     * Incluir ubicaciones de las cadenas en el código fuente de la aplicación en el documento de traducción
     */
    public bool $includeLocations = true;

    public static function factory(string $name): self
    {
        if ($name == 'po') {
            return new PO();
        } elseif ($name == 'json') {
            return new JSON();
        } elseif ($name == 'php') {
            return new PHP();
        } else {
            throw new InvalidArgumentException("Invalid serializer format '{$name}'");
        }
    }

    /**
     * Genera un archivo de localización para los grupos indicados
     *
     * @param Domain[] $domains
     *
     * @return string
     */
    public abstract function generate(array $domains): string;
}

