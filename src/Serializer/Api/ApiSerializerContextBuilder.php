<?php

declare(strict_types=1);

namespace App\Serializer\Api;

use ApiPlatform\State\SerializerContextBuilderInterface;
use App\ApiResource\Decision\Member;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

#[AsDecorator(decorates: 'api_platform.serializer.context_builder')]
final readonly class ApiSerializerContextBuilder implements SerializerContextBuilderInterface
{
    public function __construct(
        #[AutowireDecorated]
        private SerializerContextBuilderInterface $decorated,
        private MemberSerializationGroups $memberGroups,
    ) {
    }

    /**
     * @param array<string, mixed>|null $extractedAttributes
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function createFromRequest(
        Request $request,
        bool $normalization,
        ?array $extractedAttributes = null,
    ): array {
        $context = $this->decorated->createFromRequest(
            $request,
            $normalization,
            $extractedAttributes,
        );

        $context[JsonEncode::OPTIONS] = JsonResponse::DEFAULT_ENCODING_OPTIONS;
        $context[AbstractObjectNormalizer::SKIP_NULL_VALUES] = false;

        if (
            !$normalization
            || Member::class !== ($context['resource_class'] ?? null)
        ) {
            return $context;
        }

        $context['groups'] = $this->memberGroups->for(
            $request,
            $context['operation'] ?? null,
        );

        return $context;
    }
}
