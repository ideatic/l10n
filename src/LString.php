<?php

namespace ideatic\l10n;

use ideatic\l10n\Plural\Expression;
use ideatic\l10n\Plural\Format;

/**
 * Representa una cadena de localización
 */
class LString
{
    /** @var string Identificador de esta cadena */
    public $id;

    /** @var string Texto traducible */
    public $text;

    /** @var string Identificador completo de la cadena, incluyendo su contexto */
    public $fullID;

    /** @var string Nombre del dominio al que pertenece esta cadena */
    public $domainName = 'app';

    /** @var Domain */
    public $domain;

    /** @var string Contexto */
    public $context;

    /** @var bool */
    public $isICU = false;

    /** @var string */
    public $file;

    /** @var int */
    public $line;

    /** @var int */
    public $offset;

    /**
     * Comentario asociado a la cadena de texto
     * @var string
     */
    public $comments;

    /**
     * Marcadores de posición incluidos en la cadena de traducción
     * @var array
     */
    public $placeholders;

    /** @var string|null */
    public $requestedLocale;

    /** @var mixed Elemento donde se encontró esta cadena (llamada a método PHP, elemento HTML, etc.) */
    public $raw;

    /**
     * Genera la ID completa utilizada por defecto
     * @return string
     */
    public function fullyQualifiedID(): string
    {
        if ($this->fullID) {
            return $this->fullID;
        }

        $id = $this->id ? $this->id : $this->text;

        if ($this->context) {
            $id .= '@' . $this->context;
        }

        return $id;
    }
}
