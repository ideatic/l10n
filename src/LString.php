<?php

declare(strict_types=1);

namespace ideatic\l10n;

use ideatic\l10n\Utils\ICU\Pattern;
use Throwable;

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
     * Obtiene el texto que realmente se envía a traducir, y contra el que hay que comparar las traducciones.
     *
     * No debe utilizarse $text para esto: en Angular conserva la sintaxis original de la plantilla
     * ({user.points, plural, ...}, {{ expr | i18n:'%x%' }}), mientras que $id ya está normalizado a lo que ve el
     * traductor ({count, plural, ...}, %x%) y es además la clave con la que se guarda la traducción.
     */
    public function translatableText(): string
    {
        $id = $this->id ?? '';
        $text = $this->text ?? '';

        if ($id === '' || $id === $text) {
            return $text !== '' ? $text : $id;
        }

        // Angular normaliza el nombre del argumento ICU al generar la ID, así que salvo por ese nombre la ID y el
        // texto son el mismo mensaje y lo que se traduce es la ID. Si no lo son, la ID es un identificador explícito
        // (i18n="@@miId") que no significa nada para el traductor, y entonces manda el texto.
        if ($this->isICU) {
            $idPattern = self::_tryParse($id);
            $textPattern = self::_tryParse($text);

            if ($idPattern !== null && $textPattern !== null && $idPattern->signature() === $textPattern->signature()) {
                return $id;
            }
        }

        return $text;
    }

    private static function _tryParse(string $pattern): ?Pattern
    {
        try {
            return new Pattern($pattern);
        } catch (Throwable) {
            return null;
        }
    }

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
        $data['domainName'] ??= isset($this->domain) ? $this->domain->name : null;
        unset($data['domain']);
        return $data;
    }
}
