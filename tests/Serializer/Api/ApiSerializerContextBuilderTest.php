<?php

declare(strict_types=1);

namespace App\Tests\Serializer\Api;

use ApiPlatform\Metadata\Get;
use ApiPlatform\State\SerializerContextBuilderInterface;
use App\ApiResource\Decision\Member;
use App\Entity\User\Enums\ApiPermissions;
use App\Serializer\Api\ApiSerializerContextBuilder;
use App\Serializer\Api\MemberSerializationGroups;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

use function in_array;

/**
 * The API's JSON is written in one shape for every resource, and a member document is narrowed to the properties the
 * principal may actually read.
 */
final class ApiSerializerContextBuilderTest extends TestCase
{
    public function testTheJsonIsWrittenTheSameWayForEveryResource(): void
    {
        $context = $this->builder([])->createFromRequest(
            Request::create('/api/organs'),
            true,
        );

        self::assertSame(
            JsonResponse::DEFAULT_ENCODING_OPTIONS,
            $context[JsonEncode::OPTIONS],
        );
        self::assertFalse($context[AbstractObjectNormalizer::SKIP_NULL_VALUES]);
    }

    public function testAMemberDocumentIsNarrowedToThePermittedProperties(): void
    {
        $context = $this->builder([
            ApiPermissions::MembersPropertyEmail->value,
            ApiPermissions::MembersPropertyBirthDate->value,
        ])->createFromRequest(
            Request::create('/api/members'),
            true,
            ['resource_class' => Member::class],
        );

        self::assertSame(
            [
                Member::GROUP_READ,
                Member::GROUP_EMAIL,
                Member::GROUP_BIRTHDATE,
            ],
            $context['groups'],
        );
    }

    /**
     * A write is not narrowed: what a principal may send is decided by the input metadata, not by these groups.
     */
    public function testADenormalizationKeepsTheDecoratedGroups(): void
    {
        $context = $this->builder([ApiPermissions::MembersPropertyEmail->value])
            ->createFromRequest(
                Request::create('/api/members'),
                false,
                ['resource_class' => Member::class],
            );

        self::assertSame(
            ['decorated'],
            $context['groups'],
        );
    }

    public function testAnotherResourceKeepsTheDecoratedGroups(): void
    {
        $context = $this->builder([ApiPermissions::MembersPropertyEmail->value])
            ->createFromRequest(
                Request::create('/api/organs'),
                true,
                ['resource_class' => stdClass::class],
            );

        self::assertSame(
            ['decorated'],
            $context['groups'],
        );
    }

    public function testTheOperationIsHandedToTheGroupResolver(): void
    {
        $operation = new Get(name: Member::OPERATION_COLLECTION);

        $context = $this->builder([])->createFromRequest(
            Request::create('/api/members'),
            true,
            [
                'resource_class' => Member::class,
                'operation' => $operation,
            ],
        );

        self::assertSame(
            [Member::GROUP_READ],
            $context['groups'],
        );
    }

    /**
     * @param list<string> $granted
     */
    private function builder(array $granted): ApiSerializerContextBuilder
    {
        $decorated = self::createStub(SerializerContextBuilderInterface::class);
        $decorated->method('createFromRequest')->willReturnCallback(
            static fn (Request $request, bool $normalization, ?array $attributes): array => [
                'resource_class' => $attributes['resource_class'] ?? null,
                'operation' => $attributes['operation'] ?? null,
                'groups' => ['decorated'],
            ],
        );

        $checker = self::createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(
            static fn (string $attribute): bool => in_array(
                $attribute,
                $granted,
                true,
            ),
        );

        return new ApiSerializerContextBuilder(
            $decorated,
            new MemberSerializationGroups($checker),
        );
    }
}
