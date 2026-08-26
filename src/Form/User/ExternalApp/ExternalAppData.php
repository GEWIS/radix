<?php

declare(strict_types=1);

namespace App\Form\User\ExternalApp;

use App\Entity\User\Enums\ExternalAppSignature;
use App\Entity\User\Enums\ExternalAppTokenDelivery;
use App\Entity\User\Enums\JWTClaims;
use App\Entity\User\ExternalApp;
use App\Form\Application\Flow\HasFlowStep;
use DateTime;
use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the admin form asks about an external application. The record is written once the last step is accepted.
 */
final class ExternalAppData
{
    use HasFlowStep;

    public const string STEP_APPLICATION = 'application';
    public const string STEP_SIGNING = 'signing';
    public const string STEP_CLAIMS = 'claims';

    #[Assert\NotBlank(
        message: 'Enter an application identifier.',
        groups: [self::STEP_APPLICATION],
    )]
    public ?string $appId = null;

    #[Assert\NotBlank(
        message: 'Enter a callback URL.',
        groups: [self::STEP_APPLICATION],
    )]
    public ?string $callback = null;

    #[Assert\NotBlank(
        message: 'Enter an application URL.',
        groups: [self::STEP_APPLICATION],
    )]
    public ?string $url = null;

    #[Assert\NotNull(groups: [self::STEP_SIGNING])]
    public ?ExternalAppSignature $signature = ExternalAppSignature::EdDSA;

    #[Assert\NotNull(groups: [self::STEP_SIGNING])]
    public ?ExternalAppTokenDelivery $tokenDelivery = ExternalAppTokenDelivery::Fragment;

    public ?string $secret = null;

    /** @var JWTClaims[] */
    public array $claims = [];

    public bool $enabled = true;

    public ?DateTimeImmutable $expiresAt = null;

    public static function fromEntity(ExternalApp $app): self
    {
        $data = new self();
        $data->appId = $app->getAppId();
        $data->callback = $app->getCallback();
        $data->url = $app->getUrl();
        $data->signature = $app->getSignature();
        $data->tokenDelivery = $app->getTokenDelivery();
        $data->secret = $app->getSecret();
        $data->claims = $app->getClaims();
        $data->enabled = $app->isEnabled();
        $data->expiresAt = null !== $app->getExpiresAt()
            ? DateTimeImmutable::createFromInterface($app->getExpiresAt())
            : null;

        return $data;
    }

    public function applyTo(ExternalApp $app): void
    {
        $app->setAppId((string) $this->appId);
        $app->setCallback((string) $this->callback);
        $app->setUrl((string) $this->url);
        $app->setSignature($this->signature ?? ExternalAppSignature::EdDSA);
        $app->setTokenDelivery($this->tokenDelivery ?? ExternalAppTokenDelivery::Fragment);
        $app->setSecret($this->secret);
        $app->setClaims($this->claims);
        $app->setEnabled($this->enabled);
        $app->setExpiresAt(
            null !== $this->expiresAt
                ? DateTime::createFromInterface($this->expiresAt)
                : null,
        );
    }
}
