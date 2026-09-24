<?php

declare(strict_types=1);

namespace App\Tests\LinkPreview;

use App\LinkPreview\PageMetadataExtractor;
use App\LinkPreview\UrlResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PageMetadataExtractorTest extends TestCase
{
    private const string PAGE = 'https://shop.example/fr/carnets/voyage.html';

    public function testPrefersJsonLdProduct(): void
    {
        $html = <<<'HTML'
            <html><head>
            <title>Carnet | Boutique</title>
            <meta property="og:title" content="Carnet — Boutique">
            <meta property="og:image" content="/og.jpg">
            <script type="application/ld+json">
            {"@context":"https://schema.org","@graph":[{"@type":"WebPage"},{"@type":"Product","name":"Carnet de voyage relié cuir",
             "image":["https://cdn.shop.example/carnet.jpg"],"offers":{"@type":"Offer","price":"38.00","priceCurrency":"EUR"}}]}
            </script>
            </head></html>
            HTML;

        $metadata = (new PageMetadataExtractor())->extract($html, 'text/html; charset=utf-8', self::PAGE);

        self::assertSame('Carnet de voyage relié cuir', $metadata->title);
        self::assertSame('https://cdn.shop.example/carnet.jpg', $metadata->imageUrl);
        self::assertSame('38.00', $metadata->priceAmount);
        self::assertSame('EUR', $metadata->priceCurrency);
    }

    public function testFallsBackToOpenGraphThenTitle(): void
    {
        $html = <<<'HTML'
            <html><head><title>  Lampe
               de chevet  </title>
            <meta property="og:image" content="img/lampe.png">
            <meta property="product:price:amount" content="1 299,90">
            <meta property="product:price:currency" content="chf">
            </head></html>
            HTML;

        $metadata = (new PageMetadataExtractor())->extract($html, 'text/html', self::PAGE);

        self::assertSame('Lampe de chevet', $metadata->title);
        self::assertSame('https://shop.example/fr/carnets/img/lampe.png', $metadata->imageUrl);
        self::assertSame('1299.90', $metadata->priceAmount);
        self::assertSame('CHF', $metadata->priceCurrency);
    }

    public function testTwitterCardsAndOffersList(): void
    {
        $html = <<<'HTML'
            <html><head>
            <meta name="twitter:title" content="Casque &amp; micro">
            <meta name="twitter:image" content="//cdn.shop.example/casque.webp">
            <script type="application/ld+json">{"@type":"Product","offers":[{"@type":"Offer","price":59.9,"priceCurrency":"USD"}]}</script>
            </head></html>
            HTML;

        $metadata = (new PageMetadataExtractor())->extract($html, 'text/html', self::PAGE);

        self::assertSame('Casque & micro', $metadata->title);
        self::assertSame('https://cdn.shop.example/casque.webp', $metadata->imageUrl);
        self::assertSame('59.90', $metadata->priceAmount);
        self::assertSame('USD', $metadata->priceCurrency);
    }

    public function testReadsLatin1Pages(): void
    {
        $html = mb_convert_encoding('<html><head><title>Pull côtelé</title></head></html>', 'ISO-8859-1', 'UTF-8');

        $metadata = (new PageMetadataExtractor())->extract($html, 'text/html; charset=ISO-8859-1', self::PAGE);

        self::assertSame('Pull côtelé', $metadata->title);
    }

    public function testNothingFoundAndNoCurrencyWithoutPrice(): void
    {
        $metadata = (new PageMetadataExtractor())->extract('<html><body>Rien</body></html>', 'text/html', self::PAGE);

        self::assertNull($metadata->title);
        self::assertNull($metadata->imageUrl);
        self::assertNull($metadata->priceAmount);
        self::assertNull($metadata->priceCurrency);
    }

    public function testTruncatesLongTitlesAndIgnoresNonHttpImages(): void
    {
        $html = '<html><head><meta property="og:title" content="'.str_repeat('a', 200).'"><meta property="og:image" content="javascript:alert(1)"></head></html>';

        $metadata = (new PageMetadataExtractor())->extract($html, 'text/html', self::PAGE);

        self::assertSame(PageMetadataExtractor::TITLE_MAX_LENGTH, mb_strlen((string) $metadata->title));
        self::assertNull($metadata->imageUrl);
    }

    /** @return iterable<array{mixed, ?string}> */
    public static function prices(): iterable
    {
        yield ['38,00', '38.00'];
        yield ['38.5', '38.50'];
        yield ['€ 38', '38.00'];
        yield ['1 299,99 €', '1299.99'];
        yield ['1,299.99', '1299.99'];
        yield ['1.299,99', '1299.99'];
        yield ['1.299', '1299.00'];
        yield ['1,299', '1299.00'];
        yield [12, '12.00'];
        yield [19.999, '20.00'];
        yield ['gratuit', null];
        yield [-5, null];
        yield [null, null];
    }

    #[DataProvider('prices')]
    public function testParsesPrices(mixed $input, ?string $expected): void
    {
        self::assertSame($expected, PageMetadataExtractor::parsePrice($input));
    }

    public function testUrlResolver(): void
    {
        self::assertSame('https://shop.example/a.jpg', UrlResolver::resolve('/a.jpg', self::PAGE));
        self::assertSame('https://shop.example/fr/carnets/a.jpg', UrlResolver::resolve('a.jpg', self::PAGE));
        self::assertSame('http://cdn.example/a.jpg', UrlResolver::resolve('http://cdn.example/a.jpg', self::PAGE));
        self::assertNull(UrlResolver::resolve('data:image/png;base64,AAAA', self::PAGE));
        self::assertNull(UrlResolver::resolve('', self::PAGE));
    }
}
