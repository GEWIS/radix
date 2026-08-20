<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User\Enums\ExternalAppSignature;
use App\Entity\User\ExternalApp;
use App\Entity\User\ExternalAppAuthentication;
use App\Entity\User\User;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function array_map;
use function bin2hex;
use function json_encode;
use function random_bytes;
use function sprintf;
use function strval;

use const JSON_THROW_ON_ERROR;

class ExternalAppService
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $entityManager,
        private readonly JWSBuilder $jwsBuilder,
        private readonly JWKSet $signingKeySet,
    ) {
    }

    /**
     * Mint a short-lived signed token for the external application and return the callback URL the browser should
     * navigate to. Also records the authentication, which drives the reminder and the member's security overview.
     */
    public function callbackWithToken(
        ExternalApp $app,
        User $user,
        ?string $nonce = null,
    ): string {
        $member = $user->getMember();
        $issuedAt = new DateTimeImmutable();

        // A nonce supplied by the application is echoed back so it can bind the token to its request.
        $claims = [
            'iss' => 'https://gewis.nl/',
            'exp' => $issuedAt->modify('+5 minutes')->getTimestamp(),
            'iat' => $issuedAt->getTimestamp(),
            'nonce' => $nonce ?? bin2hex(random_bytes(16)),
        ];

        // The standard `sub` subject claim always identifies the member; the opt-in `lidnr` claim is deprecated and
        // will be removed before the end of 2026.
        $claims['sub'] = strval($member->getLidnr());

        foreach ($app->getClaims() as $claim) {
            $claims[$claim->value] = $claim->getValue($member);
        }

        $this->logAuthentication(
            $app,
            $user,
        );

        return $app->getCallback() . $app->getTokenDelivery()->separator() . $this->sign(
            $app,
            $claims,
        );
    }

    /**
     * Write a registration, whether it is new or an edit to one that already exists: persisting something the entity
     * manager is already tracking does nothing, so the two cases do not need to be told apart here.
     */
    public function save(ExternalApp $app): void
    {
        $this->entityManager->persist($app);
        $this->entityManager->flush();
    }

    /**
     * The public keys external applications fetch from the JWKS endpoint to verify a modern token. Only the public part
     * of each association key is exposed; the shared secrets never live in this set.
     */
    public function publicKeySet(): JWKSet
    {
        return new JWKSet(array_map(
            static fn (JWK $key): JWK => $key->toPublic(),
            $this->signingKeySet->all(),
        ));
    }

    /**
     * @param array<string, bool|int|string|null> $claims
     */
    private function sign(
        ExternalApp $app,
        array $claims,
    ): string {
        $signature = $app->getSignature();

        if ($signature->usesSharedSecret()) {
            $key = JWKFactory::createFromSecret($app->getSecret() ?? '');
            $header = ['alg' => $signature->value];
        } else {
            $key = $this->signingKey($signature);
            $header = [
                'alg' => $signature->value,
                'kid' => $key->get('kid'),
            ];
        }

        $jws = $this->jwsBuilder
            ->create()
            ->withPayload(json_encode($claims, JSON_THROW_ON_ERROR))
            ->addSignature(
                $key,
                $header,
            )
            ->build();

        return new CompactSerializer()->serialize(
            $jws,
            0,
        );
    }

    /**
     * Pick the association key that signs the given algorithm. Every key names the single algorithm it is for, so the
     * match is on `alg`: the library refuses a key whose `alg` is not the one being signed with, which is why PS512
     * and RS512 have an RSA key each rather than sharing one.
     */
    private function signingKey(ExternalAppSignature $signature): JWK
    {
        if ($signature->usesSharedSecret()) {
            throw new RuntimeException(sprintf(
                'Signature "%s" does not sign with an association key.',
                $signature->value,
            ));
        }

        foreach ($this->signingKeySet->all() as $key) {
            if (
                !$key->has('alg')
                || $signature->value !== $key->get('alg')
            ) {
                continue;
            }

            return $key;
        }

        throw new RuntimeException(sprintf(
            'The external application key set has no key for signing algorithm "%s".',
            $signature->value,
        ));
    }

    private function logAuthentication(
        ExternalApp $app,
        User $user,
    ): void {
        $authentication = new ExternalAppAuthentication();
        $authentication->setUser($user);
        $authentication->setExternalApp($app);
        $authentication->setTime(new DateTime());

        $this->entityManager->persist($authentication);
        $this->entityManager->flush();
    }
}
