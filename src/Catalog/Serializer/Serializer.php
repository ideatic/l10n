<?php

namespace ideatic\l10n\Catalog\Serializer;

use ideatic\l10n\Domain;

abstract class Serializer
{
    /**
     * Locale principal del documento. Si no se indica o es false, se generará un documento con las cadenas originales
     * @var string
     */
    public $locale;

    /**
     * Comentarios asociados a este documento
     * @var string
     */
    public $comments;

    /**
     * Locale utilizado como referencia para la traducción
     * @var string
     */
    public $referenceTranslation;

    /**
     * Incluir ubicaciones de las cadenas en el código fuente de la aplicación en el documento de traducción
     * @var bool
     */
    public $includeLocations = true;

    public static function factory(string $name): self
    {
        if ($name == 'po') {
            return new PO();
        } elseif ($name == 'json') {
            return new JSON();
        } elseif ($name == 'php') {
            return new PHP();
        } else {
            throw new \InvalidArgumentException("Invalid serializer format '{$name}'");
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

