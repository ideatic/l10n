<?php

declare(strict_types=1);

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

    /** Texto traducible */
    public string $text;

    /**  Identificador completo de la cadena, incluyendo su contexto */
    public string $fullID;

    /** Nombre del dominio al que pertenece esta cadena */
    public string $domainName = 'app';
    public Domain $domain;
    public ?string $context = null;
    public bool $isICU = false;
    public ?string $file;
    public int $line;
    public int $offset;

    /** Comentario asociado a la cadena de texto */
    public ?string $comments = null;

    /**
     * Marcadores de posición incluidos en la cadena de traducción
     * @var array<string, string>
     */
    public array $placeholders;

    public ?string $requestedLocale;

    /** @var mixed Elemento donde se encontró esta cadena (llamada a función PHP, elemento HTML, etc.) */
    public mixed $raw;

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
            $id .= "@{$this->context}";
        }

        return $id;
    }

    public function __debugInfo()
    {
        $data = get_object_vars($this);
        $data['domainName'] ??= $this->domain?->name;
        unset($data['domain']);
        return $data;
    }
}
