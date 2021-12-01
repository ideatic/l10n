<?php

namespace ideatic\l10n;

use ideatic\l10n\Plural\Expression;
use ideatic\l10n\Plural\Format;

/**
 * Representa una cadena de localización
 */
class LString
{
    /** Identificador de esta cadena */
    public string $id;

    /**  Texto traducible */
    public string $text;

    /**  Identificador completo de la cadena, incluyendo su contexto */
    public string $fullID;

    /** Nombre del dominio al que pertenece esta cadena */
    public string $domainName = 'app';
    public string $domain;
    public string $context;
    public bool $isICU = false;
    public ?string $file;
    public int $line;
    public int $offset;

    /** Comentario asociado a la cadena de texto */
    public ?string $comments;

    /**
     * Marcadores de posición incluidos en la cadena de traducción
     * @var array<string, string>
     */
    public array $placeholders;

    public ?string $requestedLocale;

    /** @var mixed Elemento donde se encontró esta cadena (llamada a método PHP, elemento HTML, etc.) */
    public $raw;

    /**
     * Genera la ID completa utilizada por defecto
     */
    public function fullyQualifiedID(): string
    {
        if (isset($this->fullID)) {
            return $this->fullID;
        }

        $id = $this->id ?: $this->text;

        if (isset($this->context)) {
            $id .= '@' . $this->context;
        }

        return $id;
    }
}
