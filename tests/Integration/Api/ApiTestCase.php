<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Database\User\ApiPrincipal;
use App\Entity\User\Enums\ApiPermissions;
use App\Repository\User\ApiPrincipalRepository;
use App\Tests\Integration\DatabaseTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function array_map;
use function http_build_query;
use function is_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

abstract class ApiTestCase extends DatabaseTestCase
{
    protected const string VERSIONED_ACCEPT = 'application/vnd.gewis.gewisdb+json;version=5.0.0';

    private EntityManagerInterface $ledger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $ledger = self::getContainer()->get('doctrine')->getManager('default');
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $ledger,
        );

        $this->ledger = $ledger;
    }

    /**
     * @param ApiPermissions[] $permissions
     */
    protected function principalWith(array $permissions): string
    {
        $principal = new ApiPrincipal();
        $principal->setDescription('Test principal for ' . static::class);
        $principal->setPermissions($permissions);
        $token = $principal->generateToken();

        $this->ledger->persist($principal);
        $this->ledger->flush();

        return $token;
    }

    protected function principalFor(string $token): ApiPrincipal
    {
        $this->ledger->clear();

        $principal = self::getContainer()->get(ApiPrincipalRepository::class)->findByToken($token);
        self::assertInstanceOf(
            ApiPrincipal::class,
            $principal,
        );

        return $principal;
    }

    protected function saveLedger(): void
    {
        $this->ledger->flush();
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, string> $headers
     */
    protected function get(
        string $path,
        ?string $token = null,
        array $query = [],
        array $headers = [],
        bool $withVersion = true,
    ): Response {
        $server = array_map(
            static fn (string $value): string => $value,
            $headers,
        );

        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        $request = Request::create(
            [] === $query ? $path : $path . '?' . http_build_query($query),
            'GET',
            server: $server,
        );

        if (!isset($headers['HTTP_ACCEPT'])) {
            if ($withVersion) {
                $request->headers->set(
                    'Accept',
                    self::VERSIONED_ACCEPT,
                );
            } else {
                $request->headers->remove('Accept');
            }
        }

        $kernel = self::$kernel;
        self::assertInstanceOf(
            HttpKernelInterface::class,
            $kernel,
        );

        return $kernel->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(Response $response): array
    {
        $content = $response->getContent();
        self::assertIsString($content);

        $decoded = json_decode(
            $content,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertTrue(is_array($decoded));

        return $decoded;
    }
}
