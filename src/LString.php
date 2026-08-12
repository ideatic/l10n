<?php

declare(strict_types=1);

namespace ideatic\l10n;

use ideatic\l10n\Utils\ICU\Pattern;
use Throwable;

/**
 * Represents a localization string
 */
class LString
{
    /** Identifier of this string */
    public string $id;

    /** Translatable text */
    public string $text;

    /**  Full identifier of the string, including its context */
    public string $fullID;

    /** Name of the domain this string belongs to */
    public string $domainName = 'app';
    public Domain $domain;
    public ?string $context = null;
    public bool $isICU = false;
    public ?string $file;
    public int $line;
    public int $offset;

    /** Comment attached to the text string */
    public ?string $comments = null;

    /**
     * Placeholders present in the translation string
     * @var array<string, string>
     */
    public array $placeholders;

    public ?string $requestedLocale;

    /** @var mixed Element where this string was found (PHP function call, HTML element, etc.) */
    public mixed $raw;

    /**
     * Gets the text that is actually sent out to be translated, and the one translations must be compared against.
     *
     * $text must not be used for this: in Angular it keeps the original template syntax
     * ({user.points, plural, ...}, {{ expr | i18n:'%x%' }}), while $id is already normalised to what the translator
     * sees ({count, plural, ...}, %x%) and is also the key the translation is stored under.
     */
    public function translatableText(): string
    {
        $id = $this->id ?? '';
        $text = $this->text ?? '';

        if ($id === '' || $id === $text) {
            return $text !== '' ? $text : $id;
        }

        // Angular normalises the name of the ICU argument when generating the id, so apart from that name the id
        // and the text are the same message and what gets translated is the id. When they are not, the id is an
        // explicit identifier (i18n="@@myId") that means nothing to the translator, and then the text wins.
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
     * Builds the full id used by default
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
