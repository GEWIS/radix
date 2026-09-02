<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Contact;
use ApiPlatform\OpenApi\Model\Example as OpenApiExample;
use ApiPlatform\OpenApi\Model\Info;
use ApiPlatform\OpenApi\Model\License;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\Model\SecurityScheme;
use ApiPlatform\OpenApi\Model\Server;
use ApiPlatform\OpenApi\OpenApi;
use App\Entity\Activity\Enums\ActivityCategories;
use App\Entity\Application\Enums\ApiResponseStatuses;
use App\Entity\Database\Enums\BoardFunctions;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Enums\OrganTypes;
use App\Entity\User\Enums\ApiPermissions;
use App\EventListener\Api\VendorAcceptListener;
use App\Exception\Report\VersionExpected;
use App\Exception\Report\VersionFormat;
use App\Exception\Report\VersionIncompatible;
use App\Exception\User\NotAllowed;
use App\Security\Api\ApiExceptionListener;
use App\Service\Report\ApiService;
use App\State\Api\ApiVersion;
use ArrayObject;
use BackedEnum;
use LogicException;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

use function array_column;
use function array_key_exists;
use function is_string;
use function str_starts_with;
use function version_compare;

#[AsDecorator(decorates: 'api_platform.openapi.factory')]
final readonly class ApiOpenApiFactory implements OpenApiFactoryInterface
{
    private const string SECURITY_SCHEME = 'api_auth';

    private const string ACCEPT = 'application/vnd.gewis.gewisdb+json;version=';

    /** The bound the two function lists enforce, which predates the versioned contract. */
    private const string VENDOR_ACCEPT = self::ACCEPT . ApiVersion::V4_3_3->value;

    private const array ERROR_STATUSES = [
        ApiResponseStatuses::Error,
        ApiResponseStatuses::Forbidden,
        ApiResponseStatuses::NotFound,
    ];

    public function __construct(
        #[AutowireDecorated]
        private OpenApiFactoryInterface $decorated,
        // The bounds are declared on the resource operations and API Platform does not carry `extraProperties` into
        // the document, so they are read back from the metadata here rather than restated beside the attribute.
        private ResourceNameCollectionFactoryInterface $resourceNameCollectionFactory,
        private ResourceMetadataCollectionFactoryInterface $resourceMetadataCollectionFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);

        $bounds = $this->versionBounds();
        // What versions this document is the contract it describes, which is the newest version any operation in it
        // requires. It moves when an endpoint is added or changes shape, and at no other time; the release the
        // deployment runs is a fact about the build and is not stated here.
        $contract = $this->contractVersion($bounds);

        $openApi = $this->describeApi(
            $openApi,
            $contract,
        );
        $openApi = $this->addComponents($openApi);
        $openApi = $this->enveloped(
            $openApi,
            $bounds,
        );

        $this->addControllerPaths($openApi);

        return $openApi;
    }

    private function describeApi(
        OpenApi $openApi,
        ApiVersion $contract,
    ): OpenApi {
        $info = $openApi->getInfo();

        return $openApi
            ->withInfo(new Info(
                title: $info->getTitle(),
                version: $contract->value,
                description: 'The API of GEWIS: the association\'s register of members, bodies and decisions, and '
                    . 'the activities and photos of its website. Every path is read with a bearer token belonging '
                    . 'to an API principal, and what that principal may read is decided per permission.',
                termsOfService: 'https://gewis.nl',
                contact: new Contact(email: 'abc@gewis.nl'),
                license: new License(
                    name: 'GNU GENERAL PUBLIC LICENSE Version 3',
                    url: 'https://github.com/GEWIS/radix/blob/main/LICENSE.txt',
                ),
            ))
            ->withServers([
                new Server(
                    url: 'https://gewis.nl',
                    description: 'Production environment',
                ),
                new Server(
                    url: 'https://test.gewis.nl',
                    description: 'Test environment',
                ),
                new Server(
                    url: 'http://localhost',
                    description: 'Local environment',
                ),
            ])
            ->withExternalDocs([
                'description' => 'Contribute to this API',
                'url' => 'https://github.com/GEWIS/radix',
            ])
            ->withSecurity([[self::SECURITY_SCHEME => []]]);
    }

    private function addComponents(OpenApi $openApi): OpenApi
    {
        $components = $openApi->getComponents();

        $schemas = $components->getSchemas() ?? new ArrayObject();
        // The enums the payloads carry. They are named here, from the PHP enums themselves, rather than repeated as
        // a literal `enum` in each `openapiContext`: an attribute argument is a constant expression and cannot call
        // `cases()`, so a resource that exposes one refers to it by `$ref` and a new case reaches the document on
        // its own.
        $schemas['ActivityCategoryEnum'] = $this->enumSchema(ActivityCategories::class);
        $schemas['BodyTypeEnum'] = $this->enumSchema(OrganTypes::class);
        $schemas['MembershipTypeEnum'] = $this->enumSchema(MembershipTypes::class);
        $schemas['BoardFunctionEnum'] = $this->enumSchema(BoardFunctions::class);
        $schemas['OrganFunctionEnum'] = $this->enumSchema(InstallationFunctions::class);
        foreach (self::ERROR_STATUSES as $status) {
            $schemas[$this->errorSchemaName($status)] = $this->errorSchema($status);
        }

        $schemas['PaginationMeta'] = [
            'type' => 'object',
            'required' => [
                'page',
                'itemsPerPage',
                'totalItems',
                'totalPages',
            ],
            'properties' => [
                'page' => ['type' => 'integer'],
                'itemsPerPage' => ['type' => 'integer'],
                'totalItems' => ['type' => 'integer'],
                'totalPages' => ['type' => 'integer'],
            ],
        ];
        $schemas['Health'] = [
            'type' => 'object',
            'required' => [
                'status',
                'healthy',
                'sync_paused',
            ],
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => [ApiResponseStatuses::Success->value],
                ],
                'healthy' => ['type' => 'boolean'],
                'sync_paused' => [
                    'type' => 'boolean',
                    'description' => 'The register is being modified; consumers that sync in the background are '
                        . 'asked to hold off.',
                ],
            ],
        ];
        $schemas['FunctionList'] = [
            'type' => 'object',
            'additionalProperties' => [
                'type' => 'object',
                'properties' => [
                    'translations' => [
                        'type' => 'object',
                        'required' => [
                            'en',
                            'nl',
                        ],
                        'properties' => [
                            'en' => ['type' => 'string'],
                            'nl' => ['type' => 'string'],
                        ],
                    ],
                    'isAdministrative' => ['type' => 'boolean'],
                    'isLegacy' => ['type' => 'boolean'],
                ],
            ],
        ];

        // API Platform's own error model, which this API never answers: everything under `/api` answers the
        // envelope. Left in place it is a component nothing refers to, and a dead exported type in every client.
        unset($schemas['Error']);

        $securitySchemes = $components->getSecuritySchemes() ?? new ArrayObject();
        $securitySchemes[self::SECURITY_SCHEME] = new SecurityScheme(
            type: 'http',
            description: 'The token of a GEWIS API principal.',
            scheme: 'bearer',
        );

        return $openApi->withComponents(
            $components
                ->withSchemas($schemas)
                ->withSecuritySchemes($securitySchemes),
        );
    }

    /**
     * @param array<string, array{minimum: ?ApiVersion, maximum: ?ApiVersion, deprecated: ?ApiVersion}> $bounds
     */
    private function enveloped(
        OpenApi $openApi,
        array $bounds,
    ): OpenApi {
        $reached = [];

        foreach ($openApi->getPaths()->getPaths() as $path => $pathItem) {
            $operation = $pathItem->getGet();

            if (null === $operation) {
                continue;
            }

            $operation = $this->envelopedOperation($operation);

            $operationId = $operation->getOperationId();
            $declared = null === $operationId
                ? []
                : $bounds[$operationId] ?? [];

            // `MinimumVersionProvider` enforces nothing for an operation that declares no minimum, so the document
            // does not claim one either: the two disagreeing is what would send a consumer at a 406 that never
            // comes, or leave one thinking an endpoint is gated when it is not. The member endpoints that predate
            // the versioned contract declare none, which is the whole reason they answer without a version.
            $minimum = $declared['minimum'] ?? null;
            $deprecated = $declared['deprecated'] ?? null;

            if (null !== $minimum) {
                $operation = $this->versionedOperation(
                    $operation,
                    $minimum,
                    $declared['maximum'] ?? null,
                );
            }

            // Deprecation is applied whether or not the operation is versioned. The endpoints most likely to be
            // deprecated are the ones predating the versioned contract, which declare no bound and never will.
            if (null !== $deprecated) {
                $operation = $this->deprecatedOperation(
                    $operation,
                    $deprecated,
                );
            }

            if (null !== $operationId) {
                $reached[$operationId] = true;
            }

            $openApi->getPaths()->addPath(
                (string) $path,
                $pathItem->withGet($operation),
            );
        }

        // A bound is looked up by the operation id the document names it with, which API Platform derives from the
        // operation's name. An operation named implicitly gets a different one, and its bound would then be
        // enforced while the document said nothing of it. Refuse rather than publish that.
        foreach ($bounds as $name => $bound) {
            if (
                null === $bound['minimum']
                && null === $bound['maximum']
                && null === $bound['deprecated']
            ) {
                continue;
            }

            if (
                array_key_exists(
                    $name,
                    $reached,
                )
            ) {
                continue;
            }

            throw new LogicException(
                $name . ' declares a version bound that reached no operation in the document. The document names '
                    . 'operations by their id, so the operation needs a `name:` matching it.',
            );
        }

        return $openApi;
    }

    /**
     * The version bounds of every resource operation, by the operation id the document names it with.
     *
     * @return array<string, array{minimum: ?ApiVersion, maximum: ?ApiVersion, deprecated: ?ApiVersion}>
     */
    private function versionBounds(): array
    {
        $bounds = [];

        foreach ($this->resourceNameCollectionFactory->create() as $resourceClass) {
            foreach ($this->resourceMetadataCollectionFactory->create($resourceClass) as $resource) {
                foreach ($resource->getOperations() ?? [] as $name => $operation) {
                    $extra = $operation->getExtraProperties();
                    $bounds[$name] = [
                        'minimum' => $this->bound(
                            $name,
                            'a minimum',
                            $extra[ApiVersion::MINIMUM] ?? null,
                        ),
                        'maximum' => $this->bound(
                            $name,
                            'a maximum',
                            $extra[ApiVersion::MAXIMUM] ?? null,
                        ),
                        'deprecated' => $this->bound(
                            $name,
                            'a deprecation',
                            $extra[ApiVersion::DEPRECATED] ?? null,
                        ),
                    ];
                }
            }
        }

        return $bounds;
    }

    private function bound(
        string $operation,
        string $key,
        mixed $bound,
    ): ?ApiVersion {
        if (
            null === $bound
            || $bound instanceof ApiVersion
        ) {
            return $bound;
        }

        // `MinimumVersionProvider` matches on the type too, so anything else is an endpoint with no gate and
        // nothing saying so. It is refused here, where the document is built, rather than left to be noticed.
        throw new LogicException(
            $operation . ' declares ' . $key . ' as something other than an ' . ApiVersion::class . ' case, which '
                . 'is a bound neither the provider enforces nor the document states.',
        );
    }

    /**
     * The newest version any operation requires, which is what a consumer generated from this document states.
     *
     * @param array<string, array{minimum: ?ApiVersion, maximum: ?ApiVersion, deprecated: ?ApiVersion}> $bounds
     */
    private function contractVersion(array $bounds): ApiVersion
    {
        // Seeded with the bound the function lists enforce rather than with a number written here: they are the
        // oldest thing under `/api` that still checks a version, and they are not resources, so they are the one
        // bound this walk cannot reach.
        $newest = ApiService::FUNCTIONS_MINIMUM_VERSION;

        foreach ($bounds as $bound) {
            $minimum = $bound['minimum'];

            if (
                null === $minimum
                || version_compare(
                    $minimum->value,
                    $newest->value,
                    '<=',
                )
            ) {
                continue;
            }

            $newest = $minimum;
        }

        foreach ($bounds as $name => $bound) {
            $maximum = $bound['maximum'];

            if (
                null === $maximum
                || version_compare(
                    $maximum->value,
                    $newest->value,
                    '>=',
                )
            ) {
                continue;
            }

            // No single version would speak to the whole document, so a client generated from it cannot state one.
            // Saying so here names the operation; leaving it to the client names a Node script instead.
            throw new LogicException(
                $name . ' answers only up to ' . $maximum->value . ', which is older than the ' . $newest->value
                    . ' another operation requires. No one version reaches every endpoint of this document.',
            );
        }

        return $newest;
    }

    private function versionedOperation(
        Operation $operation,
        ApiVersion $minimum,
        ?ApiVersion $maximum,
    ): Operation {
        $served = null === $maximum
            ? 'answers ' . $minimum->value . ' and every release after it'
            : 'answers ' . $minimum->value . ' up to and including ' . $maximum->value;

        $refused = null === $maximum
            ? 'named one older than ' . $minimum->value
            : 'named one outside ' . $minimum->value . ' to ' . $maximum->value;

        $operation = $operation
            ->withParameter($this->versionParameter(
                'The contract this consumer was written against, which is a property of the consumer rather than '
                    . 'of this endpoint. This one ' . $served . '. Required: this endpoint did not exist before the '
                    . 'contract was versioned. `Accept: ' . self::ACCEPT . $minimum->value . '` says the same '
                    . 'thing, but '
                    . 'OpenAPI requires tooling to ignore an `Accept` parameter, so it cannot be offered here.',
                true,
                $minimum->value,
            ))
            // Published so a generated client can read the bounds instead of the prose above: it is what lets one
            // state the contract it speaks without anybody writing a version out by hand.
            ->withExtensionProperty(
                'x-gewis-version-minimum',
                $minimum->value,
            )
            ->withResponse(
                406,
                $this->versionRefusedResponse(
                    'The `Accept` header named no contract version, ' . $refused . ', or could not be parsed.',
                ),
            );

        if (null !== $maximum) {
            $operation = $operation->withExtensionProperty(
                'x-gewis-version-maximum',
                $maximum->value,
            );
        }

        return $operation;
    }

    /**
     * The failures every path under `/api` can answer, whatever builds it. `RateLimitListener` throttles on the
     * prefix alone, so an endpoint that does not document 429 leaves a consumer no typed branch to read
     * `Retry-After` from, and it retries straight back into the limiter.
     *
     * @return array<int, Response>
     */
    private function commonFailures(): array
    {
        return [
            429 => $this->errorResponse(
                'The principal asked for more than it is allowed in a minute. `Retry-After` says how long to wait.',
                type: ApiExceptionListener::TYPE_RATE_LIMITED,
            ),
            500 => $this->errorResponse('Something went wrong. Most likely permanently.'),
        ];
    }

    /**
     * Stated rather than enforced: a deprecated endpoint keeps answering, and the document is where a consumer is
     * told to move off it.
     */
    private function deprecatedOperation(
        Operation $operation,
        ApiVersion $deprecated,
    ): Operation {
        return $operation
            ->withExtensionProperty(
                'x-gewis-version-deprecated',
                $deprecated->value,
            )
            ->withDeprecated(true)
            ->withDescription(
                $operation->getDescription() . ' Deprecated since ' . $deprecated->value . '.',
            );
    }

    private function envelopedOperation(Operation $operation): Operation
    {
        $responses = [];
        $declared = $operation->getResponses() ?? [];
        // An operation that answers absence with 204 never answers 404; API Platform adds one to every item Get.
        $absenceIsEmpty = array_key_exists(
            204,
            $declared,
        );

        foreach ($declared as $status => $response) {
            if (
                $absenceIsEmpty
                && 404 === (int) $status
            ) {
                continue;
            }

            if (
                str_starts_with(
                    (string) $status,
                    '2',
                )
            ) {
                $responses[$status] = $this->envelopedResponse($response);

                continue;
            }

            // API Platform's own 404 advertises a `problem+json` document this API never produces.
            $responses[$status] = 404 === (int) $status
                ? $this->errorResponse(
                    $this->notFoundDescription($response),
                    ApiResponseStatuses::NotFound,
                    ApiExceptionListener::TYPE_NOT_FOUND,
                )
                : $response;
        }

        return $operation
            ->withResponses($responses)
            ->withResponse(
                401,
                $this->challengeResponse(),
            )
            ->withResponse(
                403,
                $this->errorResponse(
                    'The principal does not hold the permission this endpoint needs.',
                    ApiResponseStatuses::Forbidden,
                    ApiExceptionListener::TYPES[NotAllowed::class],
                ),
            )
            ->withResponse(
                429,
                $this->errorResponse(
                    'The principal asked for more than it is allowed in a minute. `Retry-After` says how long to '
                        . 'wait.',
                    type: ApiExceptionListener::TYPE_RATE_LIMITED,
                ),
            )
            ->withResponse(
                500,
                $this->errorResponse('Something went wrong. Most likely permanently.'),
            );
    }

    private function notFoundDescription(Response $response): string
    {
        $described = $response->getDescription();

        return null === $described
            || '' === $described
            || 'Not found' === $described
            ? 'No such resource, or none the principal may see.'
            : $described;
    }

    private function envelopedResponse(Response $response): Response
    {
        $content = $response->getContent();

        if (null === $content) {
            return $response;
        }

        foreach ($content as $mediaTypeName => $mediaType) {
            if (!$mediaType instanceof MediaType) {
                continue;
            }

            $schema = $mediaType->getSchema();

            if (null === $schema) {
                continue;
            }

            $data = $schema->getArrayCopy();
            $properties = [
                'status' => [
                    'type' => 'string',
                    'enum' => [ApiResponseStatuses::Success->value],
                ],
                'data' => $data,
            ];

            if ('array' === ($data['type'] ?? null)) {
                $properties['meta'] = ['$ref' => '#/components/schemas/PaginationMeta'];
            }

            $content[$mediaTypeName] = $mediaType->withSchema(new ArrayObject([
                'type' => 'object',
                'required' => [
                    'status',
                    'data',
                ],
                'properties' => $properties,
            ]));
        }

        return $response->withContent($content);
    }

    private function challengeResponse(): Response
    {
        return new Response(
            description: 'No bearer token was given, or it is not known. The body is empty.',
            headers: new ArrayObject([
                'WWW-Authenticate' => [
                    'description' => 'The challenge, always `Bearer realm="/api"`.',
                    'schema' => ['type' => 'string'],
                ],
            ]),
        );
    }

    private function versionParameter(
        string $description,
        bool $required,
        string $example,
    ): Parameter {
        return new Parameter(
            name: VendorAcceptListener::VERSION_HEADER,
            in: 'header',
            description: $description,
            required: $required,
            schema: [
                'type' => 'string',
                'examples' => [$example],
            ],
        );
    }

    /**
     * The envelope a failure with this status is documented as. `Forbidden` and `NotFound` are the only statuses
     * with one of their own; every other failure, whatever its code, answers `error`.
     */
    private function errorSchemaName(ApiResponseStatuses $status): string
    {
        return match ($status) {
            ApiResponseStatuses::Forbidden => 'ResponseErrorForbidden',
            ApiResponseStatuses::NotFound => 'ResponseErrorNotFound',
            default => 'ResponseError',
        };
    }

    /**
     * The failure envelope, for the one status it carries.
     *
     * Pinned per status rather than sharing the whole {@see ApiResponseStatuses}: an enum of every value leaves
     * documentation tooling rendering `success` as the example of a failure, and leaves a generated client unable
     * to tell the three failures apart.
     *
     * @return array<string, mixed>
     */
    private function errorSchema(ApiResponseStatuses $status): array
    {
        return [
            'type' => 'object',
            'required' => [
                'status',
                'error',
            ],
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => [$status->value],
                ],
                'error' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string'],
                        'exception' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param class-string<BackedEnum> $enum
     *
     * @return array{type: string, enum: non-empty-list<string>}
     */
    private function enumSchema(string $enum): array
    {
        $values = array_column(
            $enum::cases(),
            'value',
        );

        // Every enum published here is string-backed, and an int-backed one would need a `type` of its own rather
        // than this one. Saying so out loud beats emitting `type: string` over integers, which no validator accepts.
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new LogicException($enum . ' is not string-backed, so it cannot be published as one.');
            }
        }

        if ([] === $values) {
            throw new LogicException($enum . ' has no cases, so it would publish an enum nothing satisfies.');
        }

        return [
            'type' => 'string',
            'enum' => $values,
        ];
    }

    private function errorResponse(
        string $description,
        ApiResponseStatuses $status = ApiResponseStatuses::Error,
        ?string $type = null,
    ): Response {
        return new Response(
            description: $description,
            content: new ArrayObject([
                'application/json' => new MediaType(
                    new ArrayObject([
                        '$ref' => '#/components/schemas/' . $this->errorSchemaName($status),
                    ]),
                    null === $type ? null : [
                        'status' => $status->value,
                        'error' => ['type' => $type],
                    ],
                ),
            ]),
        );
    }

    /**
     * The 406, whose three causes carry three different names. `error.type` is a bare string in the schema, so an
     * example is the only place a consumer learns them, and one example would document a third of the answer.
     */
    private function versionRefusedResponse(string $description): Response
    {
        /** @var ArrayObject<string, OpenApiExample> $examples */
        $examples = new ArrayObject();

        foreach (
            [
                'stated none' => VersionExpected::class,
                'stated one this endpoint does not serve' => VersionIncompatible::class,
                'stated one that could not be parsed' => VersionFormat::class,
            ] as $summary => $exception
        ) {
            $examples[$exception] = new OpenApiExample(
                summary: 'The consumer ' . $summary . '.',
                value: [
                    'status' => ApiResponseStatuses::Error->value,
                    'error' => ['type' => ApiExceptionListener::TYPES[$exception]],
                ],
            );
        }

        return new Response(
            description: $description,
            content: new ArrayObject([
                'application/json' => new MediaType(
                    new ArrayObject([
                        '$ref' => '#/components/schemas/'
                            . $this->errorSchemaName(ApiResponseStatuses::Error),
                    ]),
                    null,
                    $examples,
                ),
            ]),
        );
    }

    /** Plain text rather than the envelope: the 200 is raw WebP, so these consumers already handle both. */
    private function pendingRenditionResponse(): Response
    {
        return new Response(
            description: 'The rendition exists but is not generated yet; its generation has been queued. '
                . 'Retry after the number of seconds in the `Retry-After` header.',
            content: new ArrayObject([
                'text/plain' => new MediaType(new ArrayObject([
                    'type' => 'string',
                ])),
            ]),
        );
    }

    private function addControllerPaths(OpenApi $openApi): void
    {
        $paths = $openApi->getPaths();

        $health = new Operation(
            operationId: 'getHealth',
            tags: ['Health'],
            responses: [
                200 => new Response(
                    description: 'The API is answering.',
                    content: new ArrayObject([
                        'application/json' => new MediaType(
                            new ArrayObject(['$ref' => '#/components/schemas/Health']),
                        ),
                    ]),
                ),
                401 => $this->challengeResponse(),
                403 => $this->errorResponse(
                    'The principal does not hold the permission this endpoint needs.',
                    ApiResponseStatuses::Forbidden,
                    ApiExceptionListener::TYPES[NotAllowed::class],
                ),
            ] + $this->commonFailures(),
            summary: 'Health endpoint',
            description: 'Whether the API is answering, and whether consumers should pause their synchronisation. '
                . 'The only endpoint whose envelope has no `data`.',
            security: [[self::SECURITY_SCHEME => [ApiPermissions::HealthR->value]]],
        );

        $paths->addPath(
            '/api/health',
            new PathItem(get: $health),
        );
        $paths->addPath(
            '/api',
            new PathItem(
                get: $health
                    ->withOperationId('getHealthAtRoot')
                    ->withDeprecated(true)
                    ->withDescription('The same as `/api/health`, at the address it answered on first.'),
            ),
        );

        foreach (
            [
                '/api/organFunctions' => [
                    'operationId' => 'getOrganFunctions',
                    'permission' => ApiPermissions::OrganFunctionsListR,
                    'summary' => 'Get body functions',
                    'description' => 'Every function a member can be installed in, with its Dutch and English name.',
                ],
                '/api/boardFunctions' => [
                    'operationId' => 'getBoardFunctions',
                    'permission' => ApiPermissions::BoardFunctionsListR,
                    'summary' => 'Get board functions',
                    'description' => 'Every function a board member can be installed in, with its Dutch and English '
                        . 'name.',
                ],
            ] as $path => $definition
        ) {
            $operationId = $definition['operationId'];
            $permission = $definition['permission'];
            $summary = $definition['summary'];
            $description = $definition['description'];

            $paths->addPath(
                $path,
                new PathItem(get: new Operation(
                    operationId: $operationId,
                    tags: ['Function'],
                    responses: [
                        200 => new Response(
                            description: 'The functions and their translations, keyed by the name the register '
                                . 'stores.',
                            content: new ArrayObject([
                                'application/json' => new MediaType(new ArrayObject([
                                    'type' => 'object',
                                    'required' => [
                                        'status',
                                        'data',
                                    ],
                                    'properties' => [
                                        'status' => [
                                            'type' => 'string',
                                            'enum' => [ApiResponseStatuses::Success->value],
                                        ],
                                        'data' => ['$ref' => '#/components/schemas/FunctionList'],
                                    ],
                                ])),
                            ]),
                        ),
                        401 => $this->challengeResponse(),
                        403 => $this->errorResponse(
                            'The principal does not hold the permission this endpoint needs.',
                            ApiResponseStatuses::Forbidden,
                            ApiExceptionListener::TYPES[NotAllowed::class],
                        ),
                        406 => $this->versionRefusedResponse(
                            'The `Accept` header named no API version, named one this endpoint does not serve, or '
                                . 'could not be parsed.',
                        ),
                    ] + $this->commonFailures(),
                    summary: $summary,
                    description: $description . ' Requires a client version through the `Accept` header.',
                    parameters: [
                        $this->versionParameter(
                            'The contract version this consumer speaks. `Accept: ' . self::VENDOR_ACCEPT . '` says '
                                . 'the same thing and is what consumers of this endpoint have always sent.',
                            true,
                            ApiService::FUNCTIONS_MINIMUM_VERSION->value,
                        ),
                    ],
                    security: [[self::SECURITY_SCHEME => [$permission->value]]],
                )->withExtensionProperty(
                    'x-gewis-version-minimum',
                    ApiService::FUNCTIONS_MINIMUM_VERSION->value,
                )),
            );
        }

        $paths->addPath(
            '/api/photos/{id}/image/{variant}',
            new PathItem(get: new Operation(
                operationId: 'getPhotoImage',
                tags: ['Photo'],
                responses: [
                    200 => new Response(
                        description: 'The rendition, as WebP.',
                        content: new ArrayObject([
                            'image/webp' => new MediaType(new ArrayObject([
                                'type' => 'string',
                                'format' => 'binary',
                            ])),
                        ]),
                    ),
                    401 => $this->challengeResponse(),
                    403 => $this->errorResponse(
                        'The principal does not hold the permission this endpoint needs.',
                        ApiResponseStatuses::Forbidden,
                        ApiExceptionListener::TYPES[NotAllowed::class],
                    ),
                    404 => $this->errorResponse(
                        'No such photo, no such rendition, or the album it belongs to is not published.',
                        ApiResponseStatuses::NotFound,
                        ApiExceptionListener::TYPE_NOT_FOUND,
                    ),
                    503 => $this->pendingRenditionResponse(),
                ] + $this->commonFailures(),
                summary: 'Get a rendition of a photo',
                description: 'Album originals are private and are served signed at `/img`, which is outside this '
                    . 'API\'s firewall, so a bearer token cannot fetch one there. This serves the same bytes where '
                    . 'the token does authenticate.',
                parameters: [
                    new Parameter(
                        name: 'id',
                        in: 'path',
                        description: 'The photo.',
                        required: true,
                        schema: ['type' => 'integer'],
                    ),
                    new Parameter(
                        name: 'variant',
                        in: 'path',
                        description: 'The rendition, e.g. `w320`, `w1920`, `square` or `cover`.',
                        required: true,
                        schema: ['type' => 'string'],
                    ),
                ],
                security: [[self::SECURITY_SCHEME => [ApiPermissions::PhotoAlbumsR->value]]],
            )),
        );

        $paths->addPath(
            '/api/photos/albums/{id}/cover/{variant}',
            new PathItem(get: new Operation(
                operationId: 'getAlbumCover',
                tags: ['PhotoAlbum'],
                responses: [
                    200 => new Response(
                        description: 'The rendition, as WebP.',
                        content: new ArrayObject([
                            'image/webp' => new MediaType(new ArrayObject([
                                'type' => 'string',
                                'format' => 'binary',
                            ])),
                        ]),
                    ),
                    401 => $this->challengeResponse(),
                    403 => $this->errorResponse(
                        'The principal does not hold the permission this endpoint needs.',
                        ApiResponseStatuses::Forbidden,
                        ApiExceptionListener::TYPES[NotAllowed::class],
                    ),
                    404 => $this->errorResponse(
                        'No such album, no cover generated for it, no such rendition, or the album is not published.',
                        ApiResponseStatuses::NotFound,
                        ApiExceptionListener::TYPE_NOT_FOUND,
                    ),
                    503 => $this->pendingRenditionResponse(),
                ] + $this->commonFailures(),
                summary: 'Get a rendition of an album cover',
                description: 'A cover is a mosaic generated from the album rather than one of its photos, so it has '
                    . 'no address among them. Served here for the same reason the photo renditions are: `/img` is '
                    . 'outside this API\'s firewall.',
                parameters: [
                    new Parameter(
                        name: 'id',
                        in: 'path',
                        description: 'The album.',
                        required: true,
                        schema: ['type' => 'integer'],
                    ),
                    new Parameter(
                        name: 'variant',
                        in: 'path',
                        description: 'The rendition, e.g. `cover`, `cover2x`, `square` or `w640`.',
                        required: true,
                        schema: ['type' => 'string'],
                    ),
                ],
                security: [[self::SECURITY_SCHEME => [ApiPermissions::PhotoAlbumsR->value]]],
            )),
        );

        $paths->addPath(
            '/api/example404',
            new PathItem(get: new Operation(
                operationId: 'example404',
                tags: ['Error'],
                responses: [
                    401 => $this->challengeResponse(),
                    404 => $this->errorResponse(
                        'What any address under `/api` that answers nothing looks like.',
                        ApiResponseStatuses::NotFound,
                        ApiExceptionListener::TYPE_NO_ROUTE,
                    ),
                ] + $this->commonFailures(),
                summary: 'Example 404',
                description: 'Nothing is stored here; it exists so a consumer can see what a wrong address returns.',
            )),
        );
        $paths->addPath(
            '/api/example500',
            new PathItem(get: new Operation(
                operationId: 'example500',
                tags: ['Error'],
                responses: [
                    401 => $this->challengeResponse(),
                    429 => $this->errorResponse(
                        'The principal asked for more than it is allowed in a minute. `Retry-After` says how long '
                            . 'to wait.',
                        type: ApiExceptionListener::TYPE_RATE_LIMITED,
                    ),
                    500 => $this->errorResponse('What a failing endpoint looks like.'),
                ],
                summary: 'Example 500',
                description: 'Always throws, so a consumer can confirm it handles a failure the way it intends to.',
            )),
        );
    }
}
