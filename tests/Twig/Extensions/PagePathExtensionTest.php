<?php

declare(strict_types=1);

namespace App\Tests\Twig\Extensions;

use App\Entity\Frontpage\FrontpageLocalisedText;
use App\Entity\Frontpage\Page;
use App\Twig\Extensions\PagePathExtension;
use Locale;
use Override;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

use function array_key_exists;
use function implode;

/**
 * A page's address is built from the localised parts of its name. The public link uses the site's locale; the
 * other-language link uses the language it names.
 */
final class PagePathExtensionTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        Locale::setDefault('en');
    }

    public function testTheEnglishPartsAreUsedWhenTheSiteIsInEnglish(): void
    {
        Locale::setDefault('en');

        self::assertSame(
            '/page/en-category/en-sub/en-name',
            $this->extension()->generatePagePath(
                'nl-category',
                'en-category',
                'nl-sub',
                'en-sub',
                'nl-name',
                'en-name',
            ),
        );
    }

    public function testTheDutchPartsAreUsedWhenTheSiteIsInDutch(): void
    {
        Locale::setDefault('nl');

        self::assertSame(
            '/page/nl-category/nl-sub/nl-name',
            $this->extension()->generatePagePath(
                'nl-category',
                'en-category',
                'nl-sub',
                'en-sub',
                'nl-name',
                'en-name',
            ),
        );
    }

    public function testAPartThatIsNotThereIsLeftOutOfTheAddress(): void
    {
        Locale::setDefault('en');

        self::assertSame(
            '/page/en-category',
            $this->extension()->generatePagePath(
                'nl-category',
                'en-category',
            ),
        );

        // A name without a subcategory addresses a page at the root of its category.
        self::assertSame(
            '/page/en-category/en-name',
            $this->extension()->generatePagePath(
                'nl-category',
                'en-category',
                null,
                null,
                'nl-name',
                'en-name',
            ),
        );
    }

    public function testTheLocalisedLinkNamesItsLanguageAndItsOwnWords(): void
    {
        $page = $this->page();

        self::assertSame(
            '/nl/page/nieuws/commissie/naam',
            $this->extension()->generateLocalisedPagePath(
                $page,
                'nl',
            ),
        );
        self::assertSame(
            '/page/news/committee/name',
            $this->extension()->generateLocalisedPagePath(
                $page,
                'en',
            ),
        );
    }

    private function page(): Page
    {
        $page = new Page();
        $page->category = new FrontpageLocalisedText(
            'news',
            'nieuws',
        );
        $page->subCategory = new FrontpageLocalisedText(
            'committee',
            'commissie',
        );
        $page->name = new FrontpageLocalisedText(
            'name',
            'naam',
        );

        return $page;
    }

    private function extension(): PagePathExtension
    {
        return new PagePathExtension($this->urlGenerator());
    }

    /**
     * The real router needs a kernel. The extension decides which words go into which parameter, so the generator
     * only writes the parameters in the route's order.
     */
    private function urlGenerator(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
            }

            #[Override]
            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            #[Override]
            public function getContext(): RequestContext
            {
                return $this->context;
            }

            /**
             * @param array<array-key, mixed> $parameters
             */
            #[Override]
            public function generate(
                string $name,
                array $parameters = [],
                int $referenceType = self::ABSOLUTE_PATH,
            ): string {
                $prefix = match ($parameters['_locale'] ?? null) {
                    'nl' => '/nl',
                    default => '',
                };

                $parts = [
                    $prefix,
                    'page',
                ];
                foreach (['category', 'subCategory', 'name'] as $part) {
                    if (
                        !array_key_exists(
                            $part,
                            $parameters,
                        )
                    ) {
                        continue;
                    }

                    $parts[] = (string) $parameters[$part];
                }

                return implode(
                    '/',
                    $parts,
                );
            }
        };
    }
}
