<?php

declare(strict_types=1);

namespace ideatic\l10n\Angular;

use Closure;
use Exception;
use ideatic\l10n\String\Format\Angular;
use ideatic\l10n\Translation\Provider;

/**
 * Writes the translation files of a native Angular i18n build (the JSON format of `@angular/localize`).
 *
 * Input:
 * - the Manifest of the strings stamped with a custom id while the project was prepared (Angular::annotate());
 * - the messages Angular extracted from that code (Xliff2Reader), which give the Angular placeholder names;
 * - the translation Provider used by the classic build, so each project keeps its catalogs and fallback chain.
 *
 * For each stamped message, the source string and the Angular message are walked side by side to learn how their
 * placeholders, tags and ICU expressions map (Mapping). Every translation is then written with the Angular names.
 *
 * Angular moves an ICU expression that sits next to text to a message of its own, with an id it computes itself.
 * Those messages are linked with the string that holds them through the original source of the ICU placeholder.
 *
 * Nothing is lost silently: a string whose structure cannot be mapped keeps its source text and is reported in
 * $issues, and the source of every mapped message is checked against the Angular message ($warnings).
 */
final class TranslationConverter
{
    /** @var (Closure(string $text, ?string $locale): string)|null Applied to every source and translation (e.g. to replace %app%) */
    public ?Closure $filter = null;

    /** @var bool|list<string> Locales whose missing translations stop the build, true for all */
    public bool|array $strictLocales = [];

    /** @var list<string> Messages that could not be mapped and keep their source text */
    public array $issues = [];

    /** @var list<string> Mapped messages whose source differs from the Angular message (other than whitespace) */
    public array $warnings = [];

    /** @var array<string, array{entry: ManifestEntry, message: Message, mapping?: Mapping, source?: list<Token>, pureIcu?: bool, error?: string}> */
    private array $_plans = [];

    /** @var array<string, list<array{parent: string, placeholder: string, mapping: Mapping}>> Nested ICU message id → messages holding it */
    private array $_children = [];

    /** @var array<string, Message> Messages without a stamped id that are not nested ICU messages either */
    private array $_orphans = [];

    /**
     * @param array<string, Message> $_messages
     */
    public function __construct(
        private readonly Manifest $_manifest,
        private readonly array $_messages,
        private readonly Provider $_provider
    ) {
        $this->_plan();
    }

    /**
     * Messages Angular extracted that were not stamped by this library (they keep their source text)
     *
     * @return array<string, Message>
     */
    public function orphans(): array
    {
        return $this->_orphans;
    }

    /**
     * Gets the translations of a locale, indexed by Angular message id
     *
     * @return array{translations: array<string, string>, untranslated: list<string>, errors: list<string>}
     */
    public function convert(string $locale): array
    {
        $translations = [];
        $untranslated = [];
        $errors = [];
        $nestedIcus = [];

        foreach ($this->_plans as $id => $plan) {
            $string = $plan['entry']->string;

            if ($this->_provider->getTranslation($string, $locale, false) === null) {
                $untranslated[] = $string->fullyQualifiedID();
            }

            $text = $this->_provider->getTranslation($string, $locale)->translation ?? $string->text;
            $text = $this->_filter($text, $locale);

            if ($plan['entry']->kind == 'code') {
                $translations[$id] = $text;
                continue;
            } elseif (!isset($plan['mapping'], $plan['source'])) {
                $translations[$id] = self::_renderAngular($plan['message']->tokens, false);
                continue;
            }

            try {
                $tokens = $this->_tokenize($text, $plan['mapping'], $plan['entry']->kind);
                if ($plan['pureIcu'] ?? false) {
                    $tokens = self::_moveTextIntoIcu($tokens);
                }

                $children = [];
                $used = [];
                $translations[$id] = $this->_render($tokens, $plan['mapping'], false, $children, $used);
                $nestedIcus[$id] = $children;
            } catch (MappingException $err) {
                $errors[] = "{$id} ({$string->fullyQualifiedID()}): {$err->getMessage()}; source text used";
                $translations[$id] = self::_renderAngular($plan['message']->tokens, false);
            }
        }

        // ICU expressions moved by Angular to their own message
        foreach ($this->_children as $childId => $links) {
            $rendered = null;
            foreach ($links as $link) {
                $source = $nestedIcus[$link['parent']][$link['placeholder']] ?? null;
                if ($source === null) {
                    continue;
                }

                try {
                    $children = [];
                    $used = [];
                    $value = $this->_render([$source], $link['mapping'], false, $children, $used);
                } catch (MappingException $err) {
                    $errors[] = "{$childId} (ICU of {$link['parent']}): {$err->getMessage()}";
                    continue;
                }

                if ($rendered === null) {
                    $rendered = $value;
                } elseif ($rendered !== $value) {
                    $errors[] = "{$childId}: the ICU message is shared by strings translated differently, '{$rendered}' used instead of '{$value}' (from {$link['parent']})";
                }
            }

            $translations[$childId] = $rendered ?? self::_renderAngular($this->_messages[$childId]->tokens, false);
        }

        foreach ($this->_orphans as $id => $message) {
            $translations[$id] = self::_renderAngular($message->tokens, false);
        }

        if ($untranslated && ($this->strictLocales === true || (is_array($this->strictLocales) && in_array($locale, $this->strictLocales)))) {
            $list = implode("\n\t", array_slice($untranslated, 0, 30));
            throw new Exception("Unable to find translation for " . count($untranslated) . " strings in locale {$locale}:\n\t{$list}");
        }

        return ['translations' => $translations, 'untranslated' => $untranslated, 'errors' => $errors];
    }

    /**
     * Writes the translation file of a locale, as read by Angular (`i18n.locales.<locale>.translation`)
     *
     * @return array{translations: array<string, string>, untranslated: list<string>, errors: list<string>}
     */
    public function write(string $locale, string $path): array
    {
        $result = $this->convert($locale);

        $json = json_encode(
            ['locale' => $locale, 'translations' => $result['translations']],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true)) {
            throw new Exception("Unable to create directory for {$path}");
        }
        if (!is_file($path) || strcmp(file_get_contents($path), $json) != 0) {
            file_put_contents($path, $json);
        }

        return $result;
    }

    private function _plan(): void
    {
        foreach ($this->_messages as $id => $message) {
            $entry = $this->_manifest->entries[$id] ?? null;
            if (!$entry) {
                continue;
            }

            $plan = ['entry' => $entry, 'message' => $message];

            if ($entry->kind != 'code') {
                try {
                    $mapping = $this->_newMapping($entry->string->placeholders ?? [], $message);
                    $source = $this->_tokenize($this->_filter($entry->string->translatableText(), null), $mapping, $entry->kind);
                    $this->_align($source, $message->tokens, $mapping);

                    $markers = array_values(array_filter($message->tokens, fn(Token $t) => $t->isMarker() || mb_trim($t->value) !== ''));
                    $plan['pureIcu'] = count($markers) == 1 && $markers[0]->type == Token::ICU;
                    $plan['mapping'] = $mapping;
                    $plan['source'] = $source;

                    // The source written with the Angular names must be the Angular message
                    $children = [];
                    $used = [];
                    $expected = self::_compact(self::_renderAngular($message->tokens, false));
                    $actual = self::_compact($this->_render($source, $mapping, false, $children, $used));
                    if ($expected !== $actual) {
                        $this->warnings[] = "{$id} ({$entry->string->file}): l10n '{$actual}' ≠ Angular '{$expected}'";
                    }
                } catch (MappingException $err) {
                    $plan['error'] = $err->getMessage();
                    unset($plan['mapping'], $plan['source']);
                    $this->issues[] = "{$id} ({$entry->string->file}): {$err->getMessage()} — '{$entry->string->fullyQualifiedID()}'";
                }
            }

            $this->_plans[$id] = $plan;
        }

        // Link the ICU messages Angular moved out of the stamped ones
        $bySkeleton = [];
        foreach ($this->_messages as $id => $message) {
            if (!isset($this->_plans[$id])) {
                $bySkeleton[self::_tokensSkeleton($message->tokens)][] = $id;
            }
        }

        foreach ($this->_plans as $id => $plan) {
            foreach (isset($plan['mapping']) ? $plan['mapping']->icus : [] as $icu) {
                if (!isset($icu['placeholder'])) {
                    continue;
                }

                $display = $plan['message']->displays[$icu['placeholder']] ?? null;
                $candidates = $display !== null ? ($bySkeleton[self::_displaySkeleton($display)] ?? []) : [];
                // The placeholders of an ICU in a template carry the id of its string (see Angular_HTML::_annotateText())
                $suffix = Angular::placeholderSuffix($id);
                $candidates = array_values(array_filter($candidates, fn($c) => in_array(self::_placeholderSuffixes($this->_messages[$c]->tokens), [[], [$suffix]], true)));
                $sameFile = array_values(array_filter($candidates, fn($c) => array_intersect($this->_messages[$c]->files, $plan['message']->files) !== []));
                $candidates = $sameFile ?: $candidates;

                if (!$candidates) {
                    $this->issues[] = "{$id}: Angular message of its ICU expression {$icu['placeholder']} not found";
                    continue;
                }

                // Messages with the same text but other tags or placeholders are candidates too: keep the ones that map
                $failures = [];
                foreach ($candidates as $childId) {
                    $child = $this->_messages[$childId];
                    try {
                        $mapping = $this->_newMapping($plan['entry']->string->placeholders ?? [], $child);
                        $this->_align([$icu['source']], $child->tokens, $mapping);
                        $this->_children[$childId][] = ['parent' => $id, 'placeholder' => $icu['placeholder'], 'mapping' => $mapping];
                        $failures = null;
                    } catch (MappingException $err) {
                        if ($failures !== null) {
                            $failures[] = "{$childId} (ICU of {$id}): {$err->getMessage()}";
                        }
                    }
                }
                array_push($this->issues, ...($failures ?? []));
            }
        }

        foreach ($this->_messages as $id => $message) {
            if (!isset($this->_plans[$id]) && !isset($this->_children[$id])) {
                $this->_orphans[$id] = $message;
            }
        }
    }

    /**
     * @param array<string, string> $placeholders Placeholders of the string, with the code they stand for
     */
    private function _newMapping(array $placeholders, Message $message): Mapping
    {
        $mapping = new Mapping();

        foreach ($placeholders as $name => $code) {
            if (str_starts_with((string)$name, '<')) { // Tag renamed by i18nContent, it is a tag in the text
                continue;
            }
            $mapping->kinds[(string)$name] = str_starts_with(mb_trim($code), '{{') ? 'interpolation' : 'element';
        }

        $collect = function (array $tokens) use (&$collect, $mapping): void {
            foreach ($tokens as $token) {
                if ($token->type == Token::ICU) {
                    foreach ($token->cases as $case) {
                        $collect($case);
                    }
                } elseif ($token->isMarker()) {
                    $mapping->angularNames[Angular::placeholderBaseName($token->value)] = $token->value;
                }
            }
        };
        $collect($message->tokens);

        return $mapping;
    }

    /**
     * @return list<Token>
     */
    private function _tokenize(string $text, Mapping $mapping, string $kind): array
    {
        // # is always read as a token inside ICU branches: it is only mapped when Angular has a placeholder for it
        // The value of a translated attribute is plain text for Angular, tags included
        return Tokenizer::l10n($text, array_keys($mapping->kinds), true, $kind != 'attribute');
    }

    /**
     * Walks a string of this library and its Angular message side by side, filling the mapping
     *
     * @param list<Token> $l10n
     * @param list<Token> $angular
     */
    private function _align(array $l10n, array $angular, Mapping $mapping): void
    {
        $position = 0;
        $count = count($angular);

        // Next Angular token that has to be matched in order: named placeholders are matched by name instead
        $next = function () use (&$position, $angular, $count): ?int {
            while ($position < $count
                && ($angular[$position]->type == Token::TEXT || ($angular[$position]->type == Token::PH && str_starts_with($angular[$position]->value, 'I18N_')))) {
                $position++;
            }
            return $position < $count ? $position : null;
        };

        $expect = function (string $type, string $description) use ($next, $angular): Token {
            $index = $next();
            if ($index === null || $angular[$index]->type != $type) {
                $found = $index === null ? 'nothing' : "{$angular[$index]->type} {$angular[$index]->value}";
                throw new MappingException("{$description} expected in the Angular message, {$found} found");
            }
            return $angular[$index];
        };

        foreach ($l10n as $token) {
            switch ($token->type) {
                case Token::TEXT:
                    break;

                case Token::HASH:
                case Token::PH:
                    $name = $token->type == Token::HASH ? '#' : $token->value;
                    $kind = $mapping->kinds[$name] ?? ($token->type == Token::HASH ? 'interpolation' : null);
                    if ($kind == 'interpolation') {
                        $angularName = Angular::placeholderName($name);
                        if ($token->type == Token::HASH && !isset($mapping->angularNames[$angularName])) { // Plain text
                            break;
                        } elseif (!isset($mapping->angularNames[$angularName])) {
                            throw new MappingException("Placeholder {$angularName} for {$name} not found in the Angular message");
                        }
                        $mapping->placeholders[$name] = $mapping->angularNames[$angularName];
                        break;
                    }

                    // A placeholder standing for a whole element (i18nPlaceholder): its Angular tag and its content
                    $index = $next();
                    if ($index === null) {
                        throw new MappingException("Element for {$name} not found in the Angular message");
                    } elseif ($angular[$index]->type == Token::PH) {
                        $mapping->elements[$name] = [$angular[$index]];
                        $position = $index + 1;
                    } elseif ($angular[$index]->type == Token::OPEN) {
                        $depth = 0;
                        $end = $index;
                        for (; $end < $count; $end++) {
                            if ($angular[$end]->type == Token::OPEN) {
                                $depth++;
                            } elseif ($angular[$end]->type == Token::CLOSE && --$depth == 0) {
                                break;
                            }
                        }
                        if ($end >= $count) {
                            throw new MappingException("Unclosed element for {$name} in the Angular message");
                        }
                        $mapping->elements[$name] = array_slice($angular, $index, $end - $index + 1);
                        $position = $end + 1;
                    } else {
                        throw new MappingException("Element for {$name} expected in the Angular message, {$angular[$index]->type} found");
                    }
                    break;

                case Token::OPEN:
                    $angularToken = $expect(Token::OPEN, "Opening tag {$token->value}");
                    $mapping->open[$token->value] ??= $angularToken->value;
                    $mapping->openByTag[$token->tag][$angularToken->value] = true;
                    $position++;
                    break;

                case Token::CLOSE:
                    $angularToken = $expect(Token::CLOSE, "Closing tag {$token->value}");
                    $mapping->close[$token->tag] ??= $angularToken->value;
                    $position++;
                    break;

                case Token::VOID:
                    $angularToken = $expect(Token::PH, "Tag {$token->value}");
                    $mapping->void[$token->value] ??= $angularToken->value;
                    $mapping->voidByTag[$token->tag][$angularToken->value] = true;
                    $position++;
                    break;

                case Token::ICU:
                    $index = $next();
                    if ($index !== null && $angular[$index]->type == Token::PH) { // Moved to a message of its own
                        $mapping->icus[] = ['source' => $token, 'placeholder' => $angular[$index]->value];
                    } elseif ($index !== null && $angular[$index]->type == Token::ICU) {
                        $angularIcu = $angular[$index];
                        $mapping->icus[] = ['source' => $token, 'variable' => $angularIcu->value];
                        foreach ($token->cases as $category => $case) {
                            if (isset($angularIcu->cases[$category])) {
                                $this->_align($case, $angularIcu->cases[$category], $mapping);
                            }
                        }
                    } else {
                        throw new MappingException("ICU expression {$token->value} not found in the Angular message");
                    }
                    $position = $index + 1;
                    break;
            }
        }

        $index = $next();
        if ($index !== null) {
            throw new MappingException("Angular placeholder {$angular[$index]->value} has no match in the string");
        }
    }

    /**
     * Writes a string of this library (a translation) with the Angular placeholder names
     *
     * @param list<Token>          $tokens
     * @param array<string, Token> $children Receives the ICU expressions Angular moved to their own message
     * @param list<int>            $used     ICU expressions of the mapping already written
     */
    private function _render(array $tokens, Mapping $mapping, bool $inIcu, array &$children, array &$used): string
    {
        $wrap = fn(string $name) => $inIcu ? "{{$name}}" : "{\${$name}}";

        $result = '';
        foreach ($tokens as $token) {
            switch ($token->type) {
                case Token::TEXT:
                    $result .= html_entity_decode($token->value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    break;

                case Token::HASH:
                case Token::PH:
                    $name = $token->type == Token::HASH ? '#' : $token->value;
                    if (isset($mapping->placeholders[$name])) {
                        $result .= $wrap($mapping->placeholders[$name]);
                    } elseif (isset($mapping->elements[$name])) {
                        $result .= self::_renderAngular($mapping->elements[$name], $inIcu);
                    } else { // Not in the source: the classic build leaves it as text too
                        $result .= $name;
                    }
                    break;

                case Token::OPEN:
                    $result .= $wrap($mapping->open[$token->value] ?? self::_single($mapping->openByTag[$token->tag] ?? [], "tag {$token->value}"));
                    break;

                case Token::CLOSE:
                    $result .= $wrap($mapping->close[$token->tag] ?? throw new MappingException("Closing tag {$token->value} not found in the source"));
                    break;

                case Token::VOID:
                    $result .= $wrap($mapping->void[$token->value] ?? self::_single($mapping->voidByTag[$token->tag] ?? [], "tag {$token->value}"));
                    break;

                case Token::ICU:
                    $index = null;
                    foreach ($mapping->icus as $i => $icu) {
                        if (!in_array($i, $used) && $icu['source']->value === $token->value) {
                            $index = $i;
                            break;
                        }
                    }
                    if ($index === null) {
                        foreach ($mapping->icus as $i => $icu) {
                            if (!in_array($i, $used)) {
                                $index = $i;
                                break;
                            }
                        }
                    }
                    if ($index === null) {
                        throw new MappingException("ICU expression {$token->value} not found in the source");
                    }
                    $used[] = $index;
                    $icu = $mapping->icus[$index];

                    if (isset($icu['placeholder'])) {
                        $children[$icu['placeholder']] = $token;
                        $result .= $wrap($icu['placeholder']);
                    } else {
                        $cases = [];
                        foreach ($token->cases as $category => $case) {
                            $cases[] = "{$category} {" . $this->_render($case, $mapping, true, $children, $used) . '}';
                        }
                        $result .= "{{$icu['variable']}, {$token->icuType}, " . implode(' ', $cases) . '}';
                    }
                    break;
            }
        }

        return $result;
    }

    /**
     * @param array<string, true> $names
     */
    private static function _single(array $names, string $description): string
    {
        if (count($names) != 1) {
            throw new MappingException(ucfirst($description) . ' not found in the source');
        }

        return array_key_first($names);
    }

    /**
     * Writes Angular tokens back in the format of the translation files
     *
     * @param list<Token> $tokens
     */
    private static function _renderAngular(array $tokens, bool $inIcu): string
    {
        $result = '';
        foreach ($tokens as $token) {
            if ($token->type == Token::TEXT) {
                $result .= $token->value;
            } elseif ($token->type == Token::ICU) {
                $cases = [];
                foreach ($token->cases as $category => $case) {
                    $cases[] = "{$category} {" . self::_renderAngular($case, true) . '}';
                }
                $result .= "{{$token->value}, {$token->icuType}, " . implode(' ', $cases) . '}';
            } elseif (Angular::placeholderBaseName($token->value) == Angular::ICU_ID_PLACEHOLDER) {
                continue; // Empty, it only gives the ICU expression the id of its string
            } else {
                $result .= $inIcu ? "{{$token->value}}" : "{\${$token->value}}";
            }
        }

        return $result;
    }

    /**
     * Angular keeps a lone ICU expression as the whole message, so text a translation adds around it goes into
     * every branch: "X {n, plural, one {a} other {b}}" → "{n, plural, one {X a} other {X b}}"
     *
     * @param list<Token> $tokens
     *
     * @return list<Token>
     */
    private static function _moveTextIntoIcu(array $tokens): array
    {
        $icus = array_keys(array_filter($tokens, fn(Token $t) => $t->type == Token::ICU));
        if (count($icus) != 1) {
            return $tokens;
        }

        $before = array_slice($tokens, 0, $icus[0]);
        $after = array_slice($tokens, $icus[0] + 1);
        $isEmpty = fn(array $part) => !array_filter($part, fn(Token $t) => $t->isMarker() || mb_trim($t->value) !== '');
        if ($isEmpty($before) && $isEmpty($after)) {
            return [$tokens[$icus[0]]];
        }

        $icu = clone $tokens[$icus[0]];
        foreach ($icu->cases as $category => $case) {
            $icu->cases[$category] = [...$before, ...$case, ...$after];
        }

        return [$icu];
    }

    private function _filter(string $text, ?string $locale): string
    {
        return $this->filter ? ($this->filter)($text, $locale) : $text;
    }

    private static function _compact(string $text): string
    {
        // Angular keeps &ngsp; as U+E500 in the messages and renders it as a space
        return preg_replace('/[\s\x{E500}\x{A0}]+/u', '', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Text of an ICU expression without placeholders, tags, argument names or whitespace
     *
     * @param list<Token> $tokens
     */
    /**
     * Suffixes of the placeholder names of an Angular message added by Angular::placeholderSuffix()
     *
     * @param list<Token> $tokens
     * @return list<string>
     */
    private static function _placeholderSuffixes(array $tokens): array
    {
        $suffixes = [];
        foreach ($tokens as $token) {
            if ($token->type == Token::ICU) {
                foreach ($token->cases as $case) {
                    array_push($suffixes, ...self::_placeholderSuffixes($case));
                }
            } elseif ($token->type == Token::PH && preg_match('/__L[0-9A-F]{8}$/', $token->value, $match)) {
                $suffixes[] = $match[0];
            }
        }

        return array_values(array_unique($suffixes));
    }

    /**
     * @param list<Token> $tokens
     */
    private static function _tokensSkeleton(array $tokens): string
    {
        $result = '';
        foreach ($tokens as $token) {
            if ($token->type == Token::TEXT) {
                $result .= $token->value;
            } elseif ($token->type == Token::ICU) {
                $result .= "{V,{$token->icuType},";
                foreach ($token->cases as $category => $case) {
                    $result .= "{$category}{" . self::_tokensSkeleton($case) . '}';
                }
                $result .= '}';
            }
        }

        return preg_replace('/\s+/u', '', $result);
    }

    /**
     * Same as _tokensSkeleton(), for the original template source of an ICU expression
     */
    private static function _displaySkeleton(string $display): string
    {
        // Inline templates written as JSON strings keep their escapes in the source span
        if (str_contains($display, '\\')) {
            $display = preg_replace_callback(
                '/\\\\(u[0-9a-fA-F]{4}|.)/su',
                fn($m) => strlen($m[1]) == 5 ? mb_chr((int)hexdec(substr($m[1], 1))) : (['n' => "\n", 't' => "\t", 'r' => "\r"][$m[1]] ?? $m[1]),
                $display
            );
        }

        $text = preg_replace('/{{(?!{).*?}}/s', '', $display);
        $text = preg_replace('/<[^>]*>/', '', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\{\s*[^{},]+?\s*,\s*(plural|select|selectordinal)\s*,/u', '{V,$1,', $text);

        return preg_replace('/\s+/u', '', $text);
    }
}
