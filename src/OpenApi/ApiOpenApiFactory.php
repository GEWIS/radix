<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Contact;
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
use App\Entity\User\Enums\ApiPermissions;
use App\EventListener\Api\VendorAcceptListener;
use App\State\Api\ApiVersion;
use ArrayObject;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

use function array_key_exists;
use function in_array;
use function str_starts_with;

#[AsDecorator(decorates: 'api_platform.openapi.factory')]
final readonly class ApiOpenApiFactory implements OpenApiFactoryInterface
{
    private const string SECURITY_SCHEME = 'api_auth';

    private const string VENDOR_ACCEPT = 'application/vnd.gewis.gewisdb+json;version=4.3.3';

    private const string VERSIONED_ACCEPT = 'application/vnd.gewis.gewisdb+json;version=5.0.0';

    private const array UNVERSIONED_PATHS = [
        '/api/members',
        '/api/members/active',
        '/api/members/{lidnr}',
    ];

    public function __construct(
        #[AutowireDecorated]
        private OpenApiFactoryInterface $decorated,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);

        $openApi = $this->describeApi($openApi);
        $openApi = $this->addComponents($openApi);
        $openApi = $this->enveloped($openApi);

        $this->addControllerPaths($openApi);

        return $openApi;
    }

    private function describeApi(OpenApi $openApi): OpenApi
    {
        $info = $openApi->getInfo();

        return $openApi
            ->withInfo(new Info(
                title: $info->getTitle(),
                version: $info->getVersion(),
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
        $schemas['ResponseStatusEnum'] = [
            'type' => 'string',
            'enum' => [
                'success',
                'error',
                'forbidden',
                'notfound',
            ],
        ];
        $schemas['ResponseError'] = [
            'type' => 'object',
            'required' => [
                'status',
                'error',
            ],
            'properties' => [
                'status' => ['$ref' => '#/components/schemas/ResponseStatusEnum'],
                'error' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => [
                            'type' => 'string',
                            'examples' => ['error-router-no-match'],
                        ],
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
                'status' => ['$ref' => '#/components/schemas/ResponseStatusEnum'],
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

    private function enveloped(OpenApi $openApi): OpenApi
    {
        foreach ($openApi->getPaths()->getPaths() as $path => $pathItem) {
            $operation = $pathItem->getGet();

            if (null === $operation) {
                continue;
            }

            $operation = $this->envelopedOperation($operation);

            if (
                !in_array(
                    (string) $path,
                    self::UNVERSIONED_PATHS,
                    true,
                )
            ) {
                $operation = $this->versionedOperation($operation);
            }

            $openApi->getPaths()->addPath(
                (string) $path,
                $pathItem->withGet($operation),
            );
        }

        return $openApi;
    }

    private function versionedOperation(Operation $operation): Operation
    {
        return $operation
            ->withParameter($this->versionParameter(
                'The contract version this consumer speaks. Required: this endpoint did not exist before the '
                    . 'contract was versioned. `Accept: ' . self::VERSIONED_ACCEPT . '` says the same thing, but '
                    . 'OpenAPI requires tooling to ignore an `Accept` parameter, so it cannot be offered here.',
                true,
            ))
            ->withResponse(
                406,
                $this->errorResponse(
                    'The `Accept` header named no contract version, named one older than '
                        . ApiVersion::CURRENT_WIRE . ', or could not be parsed.',
                ),
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
                ? $this->errorResponse($this->notFoundDescription($response))
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
                $this->errorResponse('The principal does not hold the permission this endpoint needs.'),
            )
            ->withResponse(
                429,
                $this->errorResponse(
                    'The principal asked for more than it is allowed in a minute. `Retry-After` says how long to '
                        . 'wait.',
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
                'status' => ['$ref' => '#/components/schemas/ResponseStatusEnum'],
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
    ): Parameter {
        return new Parameter(
            name: VendorAcceptListener::VERSION_HEADER,
            in: 'header',
            description: $description,
            required: $required,
            schema: [
                'type' => 'string',
                'examples' => ['5.0.0'],
            ],
        );
    }

    private function errorResponse(string $description): Response
    {
        return new Response(
            description: $description,
            content: new ArrayObject([
                'application/json' => new MediaType(
                    new ArrayObject(['$ref' => '#/components/schemas/ResponseError']),
                ),
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
                403 => $this->errorResponse('The principal does not hold the permission this endpoint needs.'),
                500 => $this->errorResponse('Something went wrong. Most likely permanently.'),
            ],
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
                                        'status' => ['$ref' => '#/components/schemas/ResponseStatusEnum'],
                                        'data' => ['$ref' => '#/components/schemas/FunctionList'],
                                    ],
                                ])),
                            ]),
                        ),
                        401 => $this->errorResponse(
                            'No bearer token was given, or it is not known. The body is empty.',
                        ),
                        403 => $this->errorResponse(
                            'The principal does not hold the permission this endpoint needs.',
                        ),
                        406 => $this->errorResponse(
                            'The `Accept` header named no API version, named one this endpoint does not serve, or '
                                . 'could not be parsed.',
                        ),
                        500 => $this->errorResponse('Something went wrong. Most likely permanently.'),
                    ],
                    summary: $summary,
                    description: $description . ' Requires a client version through the `Accept` header.',
                    parameters: [
                        $this->versionParameter(
                            'The contract version this consumer speaks. `Accept: ' . self::VENDOR_ACCEPT . '` says '
                                . 'the same thing and is what consumers of this endpoint have always sent.',
                            true,
                        ),
                    ],
                    security: [[self::SECURITY_SCHEME => [$permission->value]]],
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
                    403 => $this->errorResponse('The principal does not hold the permission this endpoint needs.'),
                    404 => $this->errorResponse(
                        'No such photo, no such rendition, or the album it belongs to is not published.',
                    ),
                ],
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
                    403 => $this->errorResponse('The principal does not hold the permission this endpoint needs.'),
                    404 => $this->errorResponse(
                        'No such album, no cover generated for it, no such rendition, or the album is not published.',
                    ),
                ],
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
                    404 => $this->errorResponse('What any address under `/api` that answers nothing looks like.'),
                ],
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
                    500 => $this->errorResponse('What a failing endpoint looks like.'),
                ],
                summary: 'Example 500',
                description: 'Always throws, so a consumer can confirm it handles a failure the way it intends to.',
            )),
        );
    }
}
