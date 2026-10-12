<?php

declare(strict_types=1);

namespace ideatic\l10n\Angular;

use ideatic\l10n\LString;
use ideatic\l10n\String\Format\Angular;

/**
 * Strings found while a project is prepared for a native Angular i18n build (Angular::annotate()), indexed by the
 * custom id written in the code.
 *
 * The id is what joins the messages extracted by Angular with the strings of this library, so no text has to be
 * compared. It is a hash of the string key, plus the original markup for template strings: two occurrences of the
 * same text with different markup are different Angular messages.
 */
class Manifest
{
    /** @var array<string, ManifestEntry> */
    public array $entries = [];

    /**
     * Gets the custom id of a string and registers it. Its signature fits the $stamp callback of Angular::annotate().
     *
     * @param 'element'|'attribute'|'code' $kind
     */
    public function stamp(LString $string, string $kind, string $raw): string
    {
        $key = implode("\x1F", [$kind, $string->domainName, $string->context ?? '', $string->fullyQualifiedID(), $kind == 'code' ? '' : $raw]);
        $id = 'l' . substr(hash('xxh128', $key), 0, 20);

        if (!isset($this->entries[$id])) {
            $entry = new ManifestEntry();
            $entry->id = $id;
            $entry->kind = $kind;
            $entry->string = clone $string;
            $entry->string->raw = null;
            $this->entries[$id] = $entry;
        }

        // A $localize string with its own custom id keeps it
        if ($kind == 'code' && $string->id !== $string->text && str_contains($raw, "@@{$string->id}")) {
            $this->entries[$string->id] = $this->entries[$id];
            unset($this->entries[$id]);
            $this->entries[$string->id]->id = $string->id;
            return $string->id;
        }

        return $id;
    }

    /**
     * Serializable summary, useful to debug a build
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->entries as $id => $entry) {
            $result[$id] = [
                'kind' => $entry->kind,
                'domain' => $entry->string->domainName,
                'context' => $entry->string->context,
                'key' => $entry->string->fullyQualifiedID(),
                'file' => $entry->string->file ?? null,
                'placeholders' => array_map(
                    fn(string $name) => Angular::placeholderName($name),
                    array_combine(array_keys($entry->string->placeholders ?? []), array_keys($entry->string->placeholders ?? []))
                ),
            ];
        }

        return $result;
    }
}
