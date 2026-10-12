<?php
declare(strict_types=1);

use ideatic\l10n\String\Format\Angular;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The metadata block of $localize (`:meaning|description@@customId:`) must be read exactly like Angular's
 * parseMetadata(): without `|` the block is the description, and only the meaning (the l10n context) is part of the id.
 */
class FormatAngularLocalizeTest extends TestCase
{
    /**
     * @return array<string, array{string, string, ?string, ?string, string, string}>
     */
    public static function metadataProvider(): array
    {
        // [code, id, context, comments, domain, full id]
        return [
            'no metadata' => ['$localize`Torneo`', 'Torneo', null, null, 'app', 'Torneo'],
            'description only' => ['$localize`:noun:Torneo`', 'Torneo', null, 'noun', 'app', 'Torneo'],
            'description sentence' => ['$localize`:Title of the page:Torneo`', 'Torneo', null, 'Title of the page', 'app', 'Torneo'],
            'meaning and description' => ['$localize`:noun|The tournament:Torneo`', 'Torneo', 'noun', 'The tournament', 'app', 'Torneo@noun'],
            'meaning only' => ['$localize`:noun|:Torneo`', 'Torneo', 'noun', null, 'app', 'Torneo@noun'],
            'empty meaning' => ['$localize`:|The tournament:Torneo`', 'Torneo', null, 'The tournament', 'app', 'Torneo'],
            'empty block' => ['$localize`::Torneo`', 'Torneo', null, null, 'app', 'Torneo'],
            'custom id' => ['$localize`:@@tournament:Torneo`', 'tournament', null, null, 'app', 'tournament'],
            'description and custom id' => ['$localize`:The tournament@@tournament:Torneo`', 'tournament', null, 'The tournament', 'app', 'tournament'],
            'full block' => ['$localize`:noun|The tournament@@tournament:Torneo`', 'tournament', 'noun', 'The tournament', 'app', 'tournament@noun'],
            'pipe after custom id' => ['$localize`:a@@b|c:Torneo`', 'b|c', null, 'a', 'app', 'b|c'],
            'extra pipes are dropped' => ['$localize`:a|b|c:Torneo`', 'Torneo', 'a', 'b', 'app', 'Torneo@a'],
            'legacy ids are ignored' => ["\$localize`:noun|desc@@id\u{241F}legacy1\u{241F}legacy2:Torneo`", 'id', 'noun', 'desc', 'app', 'id@noun'],
            'domain' => ['$localize`:##extra:Torneo`', 'Torneo', null, null, 'extra', 'Torneo'],
            'domain with empty meaning' => ['$localize`:|##extra:Torneo`', 'Torneo', null, null, 'extra', 'Torneo'],
            'domain with description' => ['$localize`:The tournament##extra:Torneo`', 'Torneo', null, 'The tournament', 'extra', 'Torneo'],
            'domain with meaning' => ['$localize`:noun|##extra:Torneo`', 'Torneo', 'noun', null, 'extra', 'Torneo@noun'],
            'domain with full block' => ['$localize`:noun|The tournament##extra@@tournament:Torneo`', 'tournament', 'noun', 'The tournament', 'extra', 'tournament@noun'],
        ];
    }

    #[DataProvider('metadataProvider')]
    public function testMetadata(string $code, string $id, ?string $context, ?string $comments, string $domain, string $fullId): void
    {
        $strings = new Angular()->getStrings("const label = {$code};", 'file.ts');

        $this->assertCount(1, $strings);
        $string = $strings[0];
        $this->assertSame('Torneo', $string->text);
        $this->assertSame($id, $string->id);
        $this->assertSame($context, $string->context);
        $this->assertSame($comments, $string->comments);
        $this->assertSame($domain, $string->domainName);
        $this->assertSame($fullId, $string->fullyQualifiedID());
    }

    public function testTemplateAttributeIsNotAffected(): void
    {
        $strings = new Angular()->getStrings('<p i18n="noun">Torneo</p><p i18n="noun|The tournament">Porra</p>', 'file.html');

        $this->assertCount(2, $strings);
        $this->assertNull($strings[0]->context);
        $this->assertSame('noun', $strings[0]->comments);
        $this->assertSame('noun', $strings[1]->context);
        $this->assertSame('The tournament', $strings[1]->comments);
    }
}
