<?php

declare(strict_types=1);

use ideatic\l10n\Utils\ICU\Plural;
use PHPUnit\Framework\TestCase;

class AnalysisPluralTest extends TestCase
{
    public function testEnglishCategories(): void
    {
        $this->assertSame(['one', 'other'], Plural::getCategories('en'));
    }

    public function testJapaneseHasASingleCategory(): void
    {
        $this->assertSame(['other'], Plural::getCategories('ja'));
    }

    public function testRussianCategories(): void
    {
        $this->assertSame(['one', 'few', 'many', 'other'], Plural::getCategories('ru'));
    }

    public function testArabicUsesEveryCategory(): void
    {
        $this->assertSame(['zero', 'one', 'two', 'few', 'many', 'other'], Plural::getCategories('ar'));
    }

    /**
     * French 'many' only shows up from a million onwards, so probing integers from 0 to 100 alone
     * would not find it
     */
    public function testFrenchIncludesTheManyCategory(): void
    {
        $this->assertContains('many', Plural::getCategories('fr'));
    }

    public function testCategoriesAreReturnedInCanonicalOrder(): void
    {
        foreach (['en', 'es', 'ru', 'ar', 'pl', 'cy'] as $locale) {
            $categories = Plural::getCategories($locale);
            $expected = array_values(array_intersect(Plural::CATEGORIES, $categories));

            $this->assertSame($expected, $categories, "Categories for '{$locale}' are not in canonical order");
        }
    }

    public function testOrdinalCategoriesDifferFromCardinalOnes(): void
    {
        $this->assertSame(['one', 'two', 'few', 'other'], Plural::getCategories('en', ordinal: true));
    }

    public function testDetectsUnknownLocales(): void
    {
        $this->assertTrue(Plural::isKnownLocale('es'));
        $this->assertTrue(Plural::isKnownLocale('es-419'));
        $this->assertTrue(Plural::isKnownLocale('pt_BR'));
        $this->assertFalse(Plural::isKnownLocale('xx-YY'));
        $this->assertFalse(Plural::isKnownLocale(''));
    }
}
