<?php

declare(strict_types=1);

use ideatic\l10n\Catalog\Serializer\JSON;
use ideatic\l10n\Catalog\Serializer\Serializer;
use ideatic\l10n\Domain;
use ideatic\l10n\Project;
use PHPUnit\Framework\TestCase;

require_once 'AnalysisFixtures.php';

/**
 * Los comentarios de una cadena se combinan en cada exportación con los que ya venían del catálogo. Si esa
 * combinación compara bloques enteros en lugar de líneas, el bloque anterior nunca coincide con las líneas sueltas
 * que lo forman y el comentario crece una copia por exportación.
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
        // Lo que devuelve el catálogo es el resultado de combinaciones anteriores
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

    /** Diez apariciones de la misma cadena con el mismo comentario no son diez comentarios */
    public function testExportingTwiceDoesNotGrowTheComment(): void
    {
        $comment = 'Pendiente de respuesta';

        $rows = $this->_export($comment);

        $this->assertSame($comment, $rows[0]['comments']);

        // Segunda exportación: lo que se escribió antes vuelve a entrar a través del catálogo
        $again = $this->_export($comment, $rows[0]['comments']);

        $this->assertSame($comment, $again[0]['comments']);
    }

    /**
     * Exporta tres apariciones de la misma cadena, simulando que el catálogo anterior ya llevaba un comentario
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
 * Da acceso al método protegido de combinación, que es donde vive la regla
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
