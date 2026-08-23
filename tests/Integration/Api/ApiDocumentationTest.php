<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use App\OpenApi\ApiOpenApiFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Yaml\Yaml;

use function dirname;
use function file_get_contents;
use function in_array;
use function is_array;
use function str_starts_with;

#[CoversClass(ApiOpenApiFactory::class)]
final class ApiDocumentationTest extends KernelTestCase
{
    private const array UNDOCUMENTED_ROUTES = [
        'api_doc',
        'api_genid',
        'api_validation_errors',
        'api_not_found',
        'api_documentation',
    ];

    public function testTheCommittedDocumentMatchesWhatTheApplicationGenerates(): void
    {
        self::bootKernel();

        $factory = self::getContainer()->get(OpenApiFactoryInterface::class);
        self::assertInstanceOf(
            OpenApiFactoryInterface::class,
            $factory,
        );

        $normalizer = self::getContainer()->get('api_platform.openapi.normalizer');
        self::assertInstanceOf(
            NormalizerInterface::class,
            $normalizer,
        );

        $generated = $normalizer->normalize(
            $factory(['spec_version' => '3.1.0']),
            'json',
            ['spec_version' => '3.1.0'],
        );

        self::assertSame(
            file_get_contents(self::projectDir() . '/openapi.yaml'),
            Yaml::dump(
                $generated,
                10,
                2,
                Yaml::DUMP_OBJECT_AS_MAP
                    | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE
                    | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
                    | Yaml::DUMP_NUMERIC_KEY_AS_STRING,
            ),
            'openapi.yaml is out of date; run `make openapi`',
        );
    }

    public function testEveryApiRouteIsDocumented(): void
    {
        self::bootKernel();

        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(
            RouterInterface::class,
            $router,
        );

        $committed = Yaml::parseFile(self::projectDir() . '/openapi.yaml');
        self::assertTrue(is_array($committed));

        foreach ($router->getRouteCollection() as $name => $route) {
            if (
                !str_starts_with(
                    $route->getPath(),
                    '/api',
                )
            ) {
                continue;
            }

            if (
                in_array(
                    $name,
                    self::UNDOCUMENTED_ROUTES,
                    true,
                )
                || str_starts_with(
                    $name,
                    '_api_',
                )
            ) {
                continue;
            }

            self::assertArrayHasKey(
                $route->getPath(),
                $committed['paths'],
                $name . ' answers under /api but openapi.yaml does not describe it',
            );
        }
    }

    private static function projectDir(): string
    {
        return dirname(
            __DIR__,
            3,
        );
    }
}
