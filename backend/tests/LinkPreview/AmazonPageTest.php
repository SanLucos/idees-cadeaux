<?php

declare(strict_types=1);

namespace App\Tests\LinkPreview;

use App\LinkPreview\AmazonPage;
use App\LinkPreview\PageMetadataExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Amazon product pages, in their own markup (spec §5.5). Excerpts of real amazon.fr pages. */
final class AmazonPageTest extends TestCase
{
    private const string PAGE = 'https://www.amazon.fr/LEGO-Magasin-Bienvenue-au/dp/B07HGHGCW2?ref_=x&th=1';

    public function testReadsTitleHiResImageAndBuyBoxPrice(): void
    {
        $html = <<<'HTML'
            <html><head><title>Lego Magasin - Bienvenue au Magasin : Amazon.fr: Jeux et Jouets</title></head><body>
            <img alt="…" src="https://m.media-amazon.com/images/I/71xl._AC_SY300_.jpg" data-old-hires="https://m.media-amazon.com/images/I/71xl._AC_SL1281_.jpg"
              id="landingImage" data-a-dynamic-image="{&quot;https://m.media-amazon.com/images/I/71xl._AC_SY355_.jpg&quot;:[355,355]}">
            <div id="corePrice_feature_div">
              <span class="a-price a-text-price" data-a-strike="true"><span class="a-offscreen">99,99€</span></span>
              <span class="a-price a-text-normal aok-align-center apex-pricetopay-value"><span class="a-offscreen">79,99€</span><span aria-hidden="true"><span class="a-price-whole">79<span class="a-price-decimal">,</span></span><span class="a-price-fraction">99</span><span class="a-price-symbol">€</span></span></span>
            </div>
            <span id="productTitle" class="a-size-large">        Lego Magasin - Bienvenue au Magasin       </span>
            <div class="carousel"><span class="a-price apex-price-to-pay-value"><span class="a-offscreen">12,00€</span></span></div>
            </body></html>
            HTML;

        $metadata = (new PageMetadataExtractor())->extract($html, 'text/html;charset=UTF-8', self::PAGE);

        self::assertSame('Lego Magasin - Bienvenue au Magasin', $metadata->title);
        self::assertSame('https://m.media-amazon.com/images/I/71xl._AC_SL1281_.jpg', $metadata->imageUrl);
        self::assertSame('79.99', $metadata->priceAmount);
        self::assertSame('EUR', $metadata->priceCurrency);
    }

    public function testBlankOffscreenPriceAndDynamicImage(): void
    {
        $html = <<<'HTML'
            <html><body>
            <img id="landingImage" src="data:image/gif;base64,R0lGOD" data-a-dynamic-image="{&quot;https://m.media-amazon.com/s.jpg&quot;:[355,355],&quot;https://m.media-amazon.com/l.jpg&quot;:[679,679],&quot;https://m.media-amazon.com/m.jpg&quot;:[450,450]}">
            <div id="corePriceDisplay_desktop_feature_div">
              <span class="a-price priceToPay"><span class="a-offscreen"> </span><span aria-hidden="true"><span class="a-price-whole">1&nbsp;299<span class="a-price-decimal">,</span></span><span class="a-price-fraction">90</span><span class="a-price-symbol">€</span></span></span>
            </div>
            </body></html>
            HTML;

        $metadata = (new PageMetadataExtractor())->extract($html, 'text/html', self::PAGE);

        self::assertSame('https://m.media-amazon.com/l.jpg', $metadata->imageUrl);
        self::assertSame('1299.90', $metadata->priceAmount);
        self::assertSame('EUR', $metadata->priceCurrency);
    }

    public function testNoBuyBoxMeansNoPrice(): void
    {
        // Sold by third parties only: the prices on the page are other products'.
        $html = <<<'HTML'
            <html><head><title>LEGO Architecture Notre-Dame : Amazon.fr: Jeux et Jouets</title></head><body>
            <div id="corePrice_desktop"><div class="a-section"></div></div>
            <div class="carousel"><span class="a-price apex-price-to-pay-value"><span class="a-offscreen">89,99€</span></span></div>
            </body></html>
            HTML;

        $metadata = (new PageMetadataExtractor())->extract($html, 'text/html', self::PAGE);

        self::assertSame('LEGO Architecture Notre-Dame', $metadata->title, 'the <title>, without the store and category');
        self::assertNull($metadata->priceAmount);
        self::assertNull($metadata->priceCurrency);
    }

    public function testAnAntiRobotPageFallsBackToTheLink(): void
    {
        $html = '<html><head><title dir="ltr">Amazon.fr</title></head><body><form action="/errors/validateCaptcha"></form></body></html>';

        $metadata = (new PageMetadataExtractor())->extract($html, 'text/html', self::PAGE);

        self::assertSame('LEGO Magasin Bienvenue au', $metadata->title);
        self::assertNull($metadata->imageUrl);
    }

    public function testCurrencyFromTheStore(): void
    {
        $html = '<div id="corePrice_feature_div"><span class="a-price"><span class="a-offscreen">$24.99</span></span></div>';

        $metadata = (new PageMetadataExtractor())->extract($html, 'text/html', 'https://www.amazon.com/dp/B07HGHGCW2');

        self::assertSame('24.99', $metadata->priceAmount);
        self::assertSame('USD', $metadata->priceCurrency);
    }

    /** @return iterable<string, array{string, bool, ?string}> */
    public static function urls(): iterable
    {
        yield 'product link' => ['https://www.amazon.fr/LEGO-Architecture-Notre-Dame-Paris-Architectural/dp/B0CWH1M12W?ref=x', true, 'LEGO Architecture Notre Dame Paris Architectural'];
        yield 'encoded slug' => ['https://www.amazon.fr/LEGO-Ing%C3%A9nieur-Lego/dp/2374931080', true, 'LEGO Ingénieur Lego'];
        yield 'language prefix' => ['https://www.amazon.fr/-/en/LEGO-40313-Bicycles/dp/B07DM3NN6B', true, 'LEGO 40313 Bicycles'];
        yield 'gp/product' => ['https://amazon.co.uk/Kettle-Steel/gp/product/B000000001/', true, 'Kettle Steel'];
        yield 'no slug' => ['https://www.amazon.fr/dp/B0CWH1M12W', true, null];
        yield 'short link' => ['https://amzn.eu/d/abc123', true, null];
        yield 'a.co' => ['https://a.co/d/abc123', true, null];
        yield 'not a product' => ['https://www.amazon.fr/gp/cart/view.html', true, null];
        yield 'look-alike' => ['https://amazon.fr.evil.example/Nom/dp/B0CWH1M12W', false, null];
        yield 'other site' => ['https://shop.example/Nom/dp/B0CWH1M12W', false, null];
    }

    #[DataProvider('urls')]
    public function testUrls(string $url, bool $isAmazon, ?string $title): void
    {
        self::assertSame($isAmazon, AmazonPage::isAmazon($url));
        self::assertSame($title, AmazonPage::titleFromUrl($url));
    }
}
