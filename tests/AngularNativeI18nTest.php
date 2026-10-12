<?php
declare(strict_types=1);

use ideatic\l10n\Angular\Manifest;
use ideatic\l10n\Angular\Token;
use ideatic\l10n\Angular\Tokenizer;
use ideatic\l10n\Angular\TranslationConverter;
use ideatic\l10n\Angular\Xliff2Reader;
use ideatic\l10n\Catalog\Translation;
use ideatic\l10n\LString;
use ideatic\l10n\String\Format\Angular;
use ideatic\l10n\Translation\Provider;
use PHPUnit\Framework\TestCase;

/**
 * Native Angular i18n: the sources are stamped with custom ids (annotate), Angular extracts the messages, and the
 * translations of this library are converted to Angular's format (TranslationConverter)
 */
class AngularNativeI18nTest extends TestCase
{
    private const string TEMPLATE = <<<'HTML'
        <p i18n>Hola <b>{{ n | i18n:'%name%' }}</b> y <a href="x">enlace</a></p>
        <p i18n>{count, plural, one {1 punto} other {# puntos}} en total</p>
        <p i18n>Penaliza con <strong>{count, plural, one {1 punto} other {{{ count | i18n:'#' }} puntos}}</strong></p>
        <span i18n>{count, plural, one {1 punto} other {# puntos}}</span>
        HTML;

    public function testTemplateAnnotation(): void
    {
        $manifest = new Manifest();
        $html = '<p i18n="Saludo">Hola <b>{{ user.name | i18n:\'%name%\' }}</b><!-- nota --></p>'
            . '<img alt="Foto de {{ name | i18n:\'%name%\' }}" i18n-alt>'
            . '<p i18n>Ver <strong i18nPlaceholder="#">{{ total }}</strong> equipos</p>';
        $output = new Angular()->annotate($html, $manifest->stamp(...), 'x.component.html');

        [$greeting, $alt, $teams] = array_keys($manifest->entries);
        $this->assertSame(
            "<p i18n=\"@@{$greeting}\">Hola <b>{{ user.name // i18n(ph=\"I18N_NAME\") }}</b></p>"
            . "<img alt=\"Foto de {{ name // i18n(ph='I18N_NAME') }}\" i18n-alt=\"@@{$alt}\">"
            . "<p i18n=\"@@{$teams}\">Ver <strong>{{ total }}</strong> equipos</p>",
            $output
        );
        $this->assertSame(['element', 'attribute', 'element'], array_map(fn($entry) => $entry->kind, array_values($manifest->entries)));
        $this->assertSame('Hola <b>%name%</b>', $manifest->entries[$greeting]->string->fullyQualifiedID());
        $this->assertSame('Ver # equipos', $manifest->entries[$teams]->string->fullyQualifiedID());
    }

    public function testIcuPlaceholdersCarryTheStringId(): void
    {
        $manifest = new Manifest();
        $output = new Angular()->annotate('<span i18n>{count, plural, one {1 punto} other {# puntos}}</span>', $manifest->stamp(...), 'x.component.html');

        $id = array_key_first($manifest->entries);
        $suffix = Angular::placeholderSuffix($id);
        $this->assertMatchesRegularExpression('/^__L[0-9A-F]{8}$/', $suffix);
        $this->assertSame(
            "<span i18n=\"@@{$id}\">{count, plural, one {1 punto} other {{{ count | number // i18n(ph=\"I18N_HASH{$suffix}\") }} puntos}}</span>",
            $output
        );
        $this->assertSame('I18N_HASH', Angular::placeholderBaseName("I18N_HASH{$suffix}"));
        $this->assertSame('I18N_TEAM_NAME', Angular::placeholderBaseName('I18N_TEAM_NAME'));
    }

    public function testCodeAnnotation(): void
    {
        $manifest = new Manifest();
        $ts = <<<'TS'
            @Component({selector: 'x', template: '<p>{{ __("Hola mundo") }}</p>'})
            export class X {
              public a = __('Adiós %name%').replace('%name%', n);
              public b = $localize`:noun|:Torneo`;
              public c = _x('Libre', 'adjective');
              public d = $localize`:@@custom:Propio`;
            }
            TS;
        $output = new Angular()->annotate($ts, $manifest->stamp(...), 'x.component.ts');

        $ids = array_flip(array_map(fn($entry) => $entry->string->fullyQualifiedID(), $manifest->entries));
        $this->assertSame(
            <<<TS
            @Component({selector: 'x', template: "<p>{{ __i18n_{$ids['Hola mundo']} }}</p>"})
            export class X {
              protected readonly __i18n_{$ids['Hola mundo']} = \$localize`:@@{$ids['Hola mundo']}:Hola mundo`;
              public a = \$localize`:@@{$ids['Adiós %name%']}:Adiós %name%`.replace('%name%', n);
              public b = \$localize`:noun|@@{$ids['Torneo@noun']}:Torneo`;
              public c = \$localize`:adjective|@@{$ids['Libre@adjective']}:Libre`;
              public d = \$localize`:@@custom:Propio`;
            }
            TS,
            $output
        );
        $this->assertArrayHasKey('custom', $manifest->entries);
    }

    public function testTemplateFieldsAreDeclaredInEveryComponent(): void
    {
        $manifest = new Manifest();
        $ts = <<<'TS'
            @Component({selector: 'a', template: '<p>{{ __("Guardar") }}</p>'})
            export class A {
            }

            @Component({selector: 'b', template: '<p>{{ __("Guardar") }}</p>'})
            export class B {
            }
            TS;
        $output = new Angular()->annotate($ts, $manifest->stamp(...), 'x.component.ts');

        $id = array_key_first($manifest->entries);
        $declaration = "protected readonly __i18n_{$id} = \$localize`:@@{$id}:Guardar`;";
        $this->assertSame(2, substr_count($output, $declaration));
        $this->assertMatchesRegularExpression('/class A \{\s*' . preg_quote($declaration, '/') . '\s*\}.*class B \{\s*' . preg_quote($declaration, '/') . '\s*\}/s', $output);
    }

    public function testIdsAreStable(): void
    {
        $ids = [];
        foreach ([1, 2] as $run) {
            $manifest = new Manifest();
            new Angular()->annotate('<p i18n>Hola</p><p i18n="noun|">Hola</p>', $manifest->stamp(...), 'x.component.html');
            $ids[$run] = array_keys($manifest->entries);
        }

        $this->assertSame($ids[1], $ids[2]);
        $this->assertCount(2, array_unique($ids[1]), 'A context gives another id');
    }

    public function testExternalTemplateTranslationCallsAreRejected(): void
    {
        $this->expectExceptionMessageIsOrContains('not supported by the native i18n mode');
        new Angular()->annotate('<p>{{ __("Hola") }}</p>', new Manifest()->stamp(...), 'x.component.html');
    }

    public function testTokenizer(): void
    {
        $tokens = Tokenizer::l10n('Hola <b>%name%</b><br> {count, plural, one {1 x} other {# x}}', ['%name%'], true);

        $this->assertSame([Token::TEXT, Token::OPEN, Token::PH, Token::CLOSE, Token::VOID, Token::TEXT, Token::ICU], array_map(fn(Token $token) => $token->type, $tokens));
        $this->assertSame('%name%', $tokens[2]->value);
        $this->assertSame([Token::HASH, Token::TEXT], array_map(fn(Token $token) => $token->type, $tokens[6]->cases['other']));
    }

    public function testConversion(): void
    {
        [$converter, $ids] = $this->_converter([
            'en' => [
                'Hola <b>%name%</b> y <a href="x">enlace</a>' => '<a>Link</a> and hello <b>%name%</b> &amp; co',
                '{count, plural, one {1 punto} other {# puntos}} en total' => '{count, plural, one {1 point} other {# points}} in total',
                'Penaliza con <strong>{count, plural, one {1 punto} other {# puntos}}</strong>' => 'Penalty of <strong>{count, plural, one {1 point} other {# points}}</strong>',
                '{count, plural, one {1 punto} other {# puntos}}' => 'You have {count, plural, one {1 point} other {# points}}',
                'Adiós' => 'Bye',
            ],
        ]);

        $this->assertSame([], $converter->issues);
        $this->assertSame([], $converter->warnings);
        $this->assertSame([999], array_keys($converter->orphans()), 'Angular ids are numeric array keys');

        $result = $converter->convert('en');
        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['untranslated']);
        $suffix = fn(string $id) => Angular::placeholderSuffix($id);
        $this->assertSame(
            [
                $ids[0] => '{$START_LINK}Link{$CLOSE_LINK} and hello {$START_BOLD_TEXT}{$I18N_NAME}{$CLOSE_BOLD_TEXT} & co',
                $ids[1] => '{$ICU} in total',
                '101' => "{VAR_PLURAL, plural, one {1 point} other {{I18N_HASH{$suffix($ids[1])}} points}}",
                $ids[2] => 'Penalty of {$START_BOLD_TEXT}{$ICU}{$CLOSE_BOLD_TEXT}',
                '102' => "{VAR_PLURAL, plural, one {1 point} other {{I18N_HASH{$suffix($ids[2])}} points}}",
                $ids[3] => "{VAR_PLURAL, plural, one {You have 1 point} other {You have {I18N_HASH{$suffix($ids[3])}} points}}",
                $ids[4] => 'Bye',
                '999' => '{VAR_SELECT, select, a {Uno} other {Otro}}',
            ],
            self::_sorted($result['translations'], $ids)
        );
    }

    public function testEqualIcusOfDifferentStringsKeepTheirTranslations(): void
    {
        [$converter, $ids] = $this->_converter([
            'ru' => [
                '{count, plural, one {1 punto} other {# puntos}} en total' => '{count, plural, one {# очко} few {# очка} many {# очков} other {# очков}} всего',
                'Penaliza con <strong>{count, plural, one {1 punto} other {# puntos}}</strong>' => 'Штраф <strong>{count, plural, one {# очком} few {# очками} many {# очками} other {# очками}}</strong>',
            ],
        ]);

        $translations = $converter->convert('ru')['translations'];
        $this->assertStringContainsString('очков', $translations['101']);
        $this->assertStringContainsString('очками', $translations['102']);
        $this->assertStringContainsString('few {{I18N_HASH' . Angular::placeholderSuffix($ids[1]) . '} очка}', $translations['101']);
    }

    public function testEqualIcusWithoutPlaceholdersKeepTheirTranslations(): void
    {
        $manifest = new Manifest();
        $output = new Angular()->annotate(
            '<span i18n>Mover {gender, select, male {invitado} other {invitada}}</span><span i18n>Eliminar {gender, select, male {invitado} other {invitada}}</span>',
            $manifest->stamp(...),
            'x.component.html'
        );
        [$move, $remove] = array_keys($manifest->entries);
        [$moveId, $removeId] = [Angular::ICU_ID_PLACEHOLDER . Angular::placeholderSuffix($move), Angular::ICU_ID_PLACEHOLDER . Angular::placeholderSuffix($remove)];
        $this->assertStringContainsString("male {{{ '' // i18n(ph=\"{$moveId}\") }}invitado}", $output);

        $child = fn(string $placeholder) => "{VAR_SELECT, select, male {<ph id=\"0\" equiv=\"{$placeholder}\" disp=\"{{ '' }}\"/>invitado} other {invitada}}";
        $parent = fn(string $id, string $verb) => "<unit id=\"{$id}\"><segment><source>{$verb} <ph id=\"0\" equiv=\"ICU\" disp=\"{gender, select, male {{{ '' }}invitado} other {invitada}}\"/></source></segment></unit>";
        $xliff = "<?xml version=\"1.0\"?><xliff version=\"2.0\" xmlns=\"urn:oasis:names:tc:xliff:document:2.0\" srcLang=\"es\"><file id=\"ngi18n\">"
            . $parent($move, 'Mover') . "<unit id=\"201\"><segment><source>{$child($moveId)}</source></segment></unit>"
            . $parent($remove, 'Eliminar') . "<unit id=\"202\"><segment><source>{$child($removeId)}</source></segment></unit>"
            . '</file></xliff>';
        $provider = new class implements Provider {
            public function getTranslation(LString $string, string $locale, bool $allowFallback = true): ?Translation
            {
                return new Translation(match ($string->fullyQualifiedID()) {
                    'Mover {gender, select, male {invitado} other {invitada}}' => 'Færa {gender, select, male {gest} other {gest}}',
                    default => 'Eyða {gender, select, male {gesti} other {gesti}}',
                });
            }
        };
        $converter = new TranslationConverter($manifest, Xliff2Reader::read($xliff), $provider);

        $this->assertSame([], $converter->issues);
        $this->assertSame([], $converter->warnings, 'The empty placeholder is not part of the text');
        $result = $converter->convert('is');
        $this->assertSame([], $result['errors']);
        $this->assertSame('{VAR_SELECT, select, male {gest} other {gest}}', $result['translations'][201]);
        $this->assertSame('{VAR_SELECT, select, male {gesti} other {gesti}}', $result['translations'][202]);
    }

    public function testTagsInTranslatedAttributesAreText(): void
    {
        $manifest = new Manifest();
        new Angular()->annotate('<card description="Estudia la <em>clave</em>" i18n-description></card>', $manifest->stamp(...), 'x.component.html');
        $id = array_key_first($manifest->entries);

        $xliff = "<?xml version=\"1.0\"?><xliff version=\"2.0\" xmlns=\"urn:oasis:names:tc:xliff:document:2.0\" srcLang=\"es\"><file id=\"ngi18n\">"
            . "<unit id=\"{$id}\"><segment><source>Estudia la &lt;em&gt;clave&lt;/em&gt;</source></segment></unit></file></xliff>";
        $provider = new class implements Provider {
            public function getTranslation(LString $string, string $locale, bool $allowFallback = true): ?Translation
            {
                return new Translation('Learn the <em>clave</em>');
            }
        };
        $converter = new TranslationConverter($manifest, Xliff2Reader::read($xliff), $provider);

        $this->assertSame([], $converter->issues);
        $this->assertSame('Learn the <em>clave</em>', $converter->convert('en')['translations'][$id]);
    }

    public function testStrictLocalesFailOnUntranslatedStrings(): void
    {
        [$converter] = $this->_converter(['en' => ['Adiós' => 'Bye']]);
        $converter->strictLocales = ['en'];

        $this->expectExceptionMessageIsOrContains('Hola <b>%name%</b>');
        $converter->convert('en');
    }

    public function testFilterAppliesToTranslations(): void
    {
        [$converter, $ids] = $this->_converter(['en' => ['Adiós' => 'Bye from %app%']]);
        $converter->filter = fn(string $text) => str_replace('%app%', 'Biwenger', $text);

        $this->assertSame('Bye from Biwenger', $converter->convert('en')['translations'][$ids[4]]);
    }

    /**
     * Annotates TEMPLATE and a $localize call, and reads the XLIFF 2 file Angular extracts from them
     *
     * @param array<string, array<string, string>> $translations Locale → l10n id → translation
     * @return array{TranslationConverter, list<string>}
     */
    private function _converter(array $translations): array
    {
        $manifest = new Manifest();
        new Angular()->annotate(self::TEMPLATE, $manifest->stamp(...), 'x.component.html');
        new Angular()->annotate("const a = __('Adiós');", $manifest->stamp(...), 'x.ts');
        $ids = array_keys($manifest->entries);
        $s = array_map(fn(string $id) => Angular::placeholderSuffix($id), $ids);

        $icu = fn(string $placeholder) => "{VAR_PLURAL, plural, one {1 punto} other {<ph id=\"0\" equiv=\"{$placeholder}\" disp=\"{{ count }}\"/> puntos}}";
        $display = '{count, plural, one {1 punto} other {{{ count }} puntos}}';
        $xliff = <<<XML
            <?xml version="1.0" encoding="UTF-8" ?>
            <xliff version="2.0" xmlns="urn:oasis:names:tc:xliff:document:2.0" srcLang="es">
              <file id="ngi18n" original="ng.template">
                <unit id="{$ids[0]}"><notes><note category="location">x.component.html:1</note></notes><segment><source>Hola <pc id="0" equivStart="START_BOLD_TEXT" equivEnd="CLOSE_BOLD_TEXT" type="fmt" dispStart="&lt;b&gt;" dispEnd="&lt;/b&gt;"><ph id="1" equiv="I18N_NAME" disp="{{ n }}"/></pc> y <pc id="2" equivStart="START_LINK" equivEnd="CLOSE_LINK" type="link" dispStart="&lt;a href=&quot;x&quot;&gt;" dispEnd="&lt;/a&gt;">enlace</pc></source></segment></unit>
                <unit id="{$ids[1]}"><notes><note category="location">x.component.html:2</note></notes><segment><source><ph id="0" equiv="ICU" disp="{$display}"/> en total</source></segment></unit>
                <unit id="101"><notes><note category="location">x.component.html:2</note></notes><segment><source>{$icu("I18N_HASH{$s[1]}")}</source></segment></unit>
                <unit id="{$ids[2]}"><notes><note category="location">x.component.html:3</note></notes><segment><source>Penaliza con <pc id="0" equivStart="START_BOLD_TEXT" equivEnd="CLOSE_BOLD_TEXT" type="fmt" dispStart="&lt;strong&gt;" dispEnd="&lt;/strong&gt;"><ph id="1" equiv="ICU" disp="{$display}"/></pc></source></segment></unit>
                <unit id="102"><notes><note category="location">x.component.html:3</note></notes><segment><source>{$icu("I18N_HASH{$s[2]}")}</source></segment></unit>
                <unit id="{$ids[3]}"><notes><note category="location">x.component.html:4</note></notes><segment><source>{$icu("I18N_HASH{$s[3]}")}</source></segment></unit>
                <unit id="{$ids[4]}"><notes><note category="location">x.ts:1</note></notes><segment><source>Adiós</source></segment></unit>
                <unit id="999"><notes><note category="location">y.component.html:1</note></notes><segment><source>{VAR_SELECT, select, a {Uno} other {Otro}}</source></segment></unit>
              </file>
            </xliff>
            XML;

        $provider = new class($translations) implements Provider {
            /** @param array<string, array<string, string>> $translations */
            public function __construct(private readonly array $translations) {}

            public function getTranslation(LString $string, string $locale, bool $allowFallback = true): ?Translation
            {
                $translation = $this->translations[$locale][$string->fullyQualifiedID()] ?? null;
                return $translation === null ? null : new Translation($translation);
            }
        };

        return [new TranslationConverter($manifest, Xliff2Reader::read($xliff), $provider), $ids];
    }

    /**
     * @param array<string, string> $translations
     * @param list<string>          $ids
     * @return array<string, string>
     */
    private static function _sorted(array $translations, array $ids): array
    {
        $order = [$ids[0], $ids[1], '101', $ids[2], '102', $ids[3], $ids[4], '999'];
        return array_replace(array_intersect_key(array_flip($order), $translations), $translations);
    }
}
