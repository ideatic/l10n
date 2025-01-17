<?php

declare(strict_types=1);

namespace ideatic\l10n\Catalog\Serializer;

use ideatic\l10n\Domain;
use ideatic\l10n\Project;
use InvalidArgumentException;
use stdClass;

abstract class Serializer
{
    /**
     * Locale principal del documento. Si no se indica, se generará un documento con las cadenas originales
     */
    public string|null $locale;

    /**
     * Comentarios asociados a este documento
     */
    public string $comments = '';

    /**
     * Locale utilizado como referencia para la traducción
     * @param string|list<string>|null $referenceTranslation
     */
    public string|array|null $referenceTranslation;

    /**
     * Incluir ubicaciones de las cadenas en el código fuente de la aplicación en el documento de traducción
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
     * Genera un archivo de localización para los grupos indicados
     *
     * @param list<Domain> $domains
     */
    public abstract function generate(array $domains, stdClass|Project $config = null): string;
}

