<?php

declare(strict_types=1);

use ideatic\l10n\Catalog\Serializer\JSON;
use ideatic\l10n\Catalog\Serializer\Serializer;
use ideatic\l10n\Domain;
use ideatic\l10n\Project;
use PHPUnit\Framework\TestCase;

require_once 'AnalysisFixtures.php';

/**
 * The comments of a string are merged on every export with those already coming from the catalog. If that merge
 * compares whole blocks instead of lines, the previous block never matches the individual lines composing it and
 * the comment grows by one copy per export.
 */
class SerializerCommentsTest extends TestCase
{
    use AnalysisFixtures;

    public function testRepeatedLinesAreMergedIntoOne(): void
    {
        $merged = CommentMerger::merge([
            'Pendiente de respuesta',
            'Pendiente de respuesta',
            'Pendiente de respuesta',
        ]);

        $this->assertSame('Pendiente de respuesta', $merged);
    }

    public function testTheCatalogBlockIsComparedLineByLine(): void
    {
        // What the catalog returns is the result of previous merges
        $previous = "Pendiente de respuesta\nIndica que una invitación está sin contestar";

        $merged = CommentMerger::merge(['Pendiente de respuesta', $previous]);

        $this->assertSame($previous, $merged, 'The block must not be appended again as a whole');
    }

    public function testDistinctCommentsAreAllKept(): void
    {
        $merged = CommentMerger::merge(['Botón de la barra', null, 'Aparece en el menú', '']);

        $this->assertSame("Botón de la barra\nAparece en el menú", $merged);
    }

    public function testDifferentLineEndingsAndPaddingDoNotCreateDuplicates(): void
    {
        $merged = CommentMerger::merge(["Una nota\r\nOtra nota", '  Una nota  ', "Otra nota\n"]);

        $this->assertSame("Una nota\nOtra nota", $merged);
    }

    /** Ten occurrences of the same string with the same comment are not ten comments */
    public function testExportingTwiceDoesNotGrowTheComment(): void
    {
        $comment = 'Pendiente de respuesta';

        $rows = $this->_export($comment);

        $this->assertSame($comment, $rows[0]['comments']);

        // Second export: what was written before comes back in through the catalog
        $again = $this->_export($comment, $rows[0]['comments']);

        $this->assertSame($comment, $again[0]['comments']);
    }

    /**
     * Exports three occurrences of the same string, pretending the previous catalog already carried a comment
     *
     * @return list<array<string, mixed>>
     */
    private function _export(string $comment, ?string $previousComment = null): array
    {
        $strings = [];

        foreach (range(1, 3) as $line) {
            $strings[] = $this->_string('Pendiente', ['comments' => $comment, 'line' => $line]);
        }

        $catalog = $this->_catalog('en', ['Pendiente' => 'Pending']);

        if ($previousComment !== null) {
            $catalog->getEntries()['Pendiente']->metadata->comments = $previousComment;
        }

        $translator = new FixtureTranslator(['app@en' => $catalog]);
        $domains = Domain::generate($strings);

        foreach ($domains as $domain) {
            $domain->translator = $translator;
        }

        $serializer = new JSON();
        $serializer->extended = true;
        $serializer->locale = 'en';
        $serializer->includeLocations = false;
        $serializer->referenceTranslation = null;

        return $serializer->_generateExtended($domains);
    }
}

/**
 * Exposes the protected merge method, which is where the rule lives
 */
class CommentMerger extends Serializer
{
    /**
     * @param list<string|null> $comments
     */
    public static function merge(array $comments): string
    {
        return self::mergeComments($comments);
    }

    public function generate(array $domains, stdClass|Project|null $config = null): string
    {
        return '';
    }
}
