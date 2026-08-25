<?php

declare(strict_types=1);

namespace App\Tests\Validator\User;

use App\Validator\User\NotCompromisedPassword;
use App\Validator\User\NotCompromisedPasswordValidator;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

/**
 * @extends ConstraintValidatorTestCase<NotCompromisedPasswordValidator>
 */
#[CoversClass(NotCompromisedPasswordValidator::class)]
class NotCompromisedPasswordValidatorTest extends ConstraintValidatorTestCase
{
    private const string BASE_URI = 'https://pwned-passwords.example/api/';

    /** What the service answers the next time it is asked. */
    private MockResponse $response;

    /** @var list<string> */
    private array $requestedUrls = [];

    private MockHttpClient $client;

    #[Override]
    protected function createValidator(): NotCompromisedPasswordValidator
    {
        $this->response = new MockResponse('0');
        $this->client = new MockHttpClient(
            function (
                string $method,
                string $url,
            ): MockResponse {
                $this->requestedUrls[] = $url;

                return $this->response;
            },
            self::BASE_URI,
        );

        return new NotCompromisedPasswordValidator(
            $this->client,
            true,
        );
    }

    public function testRefusesAPasswordTheServiceKnows(): void
    {
        $this->response = new MockResponse('1');

        $constraint = new NotCompromisedPassword();

        $this->validate(
            'password',
            $constraint,
        );

        $this->buildViolation($constraint->message)
            ->setCode(NotCompromisedPassword::COMPROMISED_PASSWORD_ERROR)
            ->assertRaised();
    }

    public function testAcceptsAPasswordTheServiceDoesNotKnow(): void
    {
        $this->response = new MockResponse('0');

        $this->validate(
            'correct horse battery staple',
            new NotCompromisedPassword(),
        );

        $this->assertNoViolation();
    }

    /**
     * The whole hash, in capitals: the service answers for neither a prefix nor lower case.
     */
    public function testAsksAboutTheWholeHashInCapitals(): void
    {
        $this->validate(
            'password',
            new NotCompromisedPassword(),
        );

        self::assertSame(
            [self::BASE_URI . '5BAA61E4C9B93F3F0682250B6CF8331B7EE68FD8'],
            $this->requestedUrls,
        );
    }

    /**
     * Whether a password was given at all is the NotBlank constraint's business.
     */
    #[DataProvider('inputThisConstraintLeavesAlone')]
    public function testLeavesInputThatIsNotAPasswordAlone(?string $value): void
    {
        $this->validate(
            $value,
            new NotCompromisedPassword(),
        );

        self::assertSame(
            [],
            $this->requestedUrls,
        );
        $this->assertNoViolation();
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function inputThisConstraintLeavesAlone(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
        ];
    }

    /**
     * A service that cannot be reached is no reason to hand somebody an account with a breached password on it, so
     * the lookup failing refuses the password rather than letting it through.
     */
    public function testLetsALookupThatFailedThrough(): void
    {
        $this->response = new MockResponse(
            '',
            ['http_code' => 404],
        );

        $this->expectException(ClientExceptionInterface::class);

        $this->validate(
            'password',
            new NotCompromisedPassword(),
        );
    }

    public function testKeepsQuietAboutAFailedLookupWhenAskedTo(): void
    {
        $this->response = new MockResponse(
            '',
            ['http_code' => 404],
        );

        $this->validate(
            'password',
            new NotCompromisedPassword(skipOnError: true),
        );

        $this->assertNoViolation();
    }

    /**
     * Development and the tests do not call the service at all.
     */
    public function testAsksNothingWhenTheCheckIsOff(): void
    {
        $this->response = new MockResponse('1');

        $this->validator = new NotCompromisedPasswordValidator(
            $this->client,
            false,
        );

        $this->validate(
            'password',
            new NotCompromisedPassword(),
        );

        self::assertSame(
            [],
            $this->requestedUrls,
        );
        $this->assertNoViolation();
    }
}
