<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Activity;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\SolveChallengeOptions;
use App\Entity\Activity\ExternalSignup;
use App\Entity\Activity\SignupField;
use App\Entity\Activity\SignupList;
use App\Form\Activity\SignupType;
use App\Repository\Activity\ExternalSignupRepository;
use App\Repository\Activity\SignupListRepository;
use App\Service\Activity\SignupManager;
use App\Service\Application\AltchaSolutionGuard;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Activity\ExternalSignupForm;
use DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function base64_encode;
use function bin2hex;
use function json_encode;
use function random_bytes;
use function random_int;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * The successful guest sign-up, with a captcha that is genuinely solved: the test signs its own challenge with the
 * application's secret, which is what the seeded challenge endpoint would do. That makes the whole submit reachable
 * without a browser, including the resend for an address that already signed up and the rate limit behind it.
 */
final class ExternalSignupFormSuccessTest extends DatabaseTestCase
{
    private const int GUEST_LIST = 3;

    public function testAGuestSignUpCreatesAnUnconfirmedSignup(): void
    {
        $list = $this->openGuestList();
        $email = $this->uniqueEmail();
        $payload = $this->solvedPayload();
        $request = $this->pushRequest();

        $component = $this->form($list);
        $component->formValues = $this->formValues(
            $list,
            $email,
            $payload,
        );

        $response = $component->submit();

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            $this->activityUrl($list),
            $response->getTargetUrl(),
        );
        self::assertContains(
            'Almost there! Check your email to confirm your sign-up.',
            self::flashBag($request)->get('success'),
        );

        $signup = $this->signupFor(
            $list,
            $email,
        );
        self::assertNotNull(
            $signup,
        );
        self::assertNull(
            $signup->verifiedAt,
            'A guest sign-up is pending until the emailed link is used.',
        );
        self::assertSame(
            'Guest visitor',
            $signup->getFullName(),
        );
    }

    /**
     * An address that already signed up gets the confirmation again rather than a dead end, and the answer is the
     * same as for a fresh one so resubmitting never reveals that the address was used.
     */
    public function testAnAddressThatAlreadySignedUpIsResentTheConfirmation(): void
    {
        $list = $this->openGuestList();
        $email = $this->uniqueEmail();
        $request = $this->pushRequest();

        $first = $this->form($list);
        $first->formValues = $this->formValues(
            $list,
            $email,
            $this->solvedPayload(),
        );
        $first->submit();

        $second = $this->form($list);
        $second->formValues = $this->formValues(
            $list,
            $email,
            $this->solvedPayload(),
        );
        $response = $second->submit();

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertCount(
            1,
            self::getContainer()->get(ExternalSignupRepository::class)
                ->findBy([
                    'signupList' => $list,
                    'email' => $email,
                ]),
        );
    }

    /**
     * The captcha is single-use: replaying the same solved payload is refused even though its signature is still
     * valid, which is what the guard exists for.
     */
    public function testAReplayedPayloadIsRefused(): void
    {
        $list = $this->openGuestList();
        $email = $this->uniqueEmail();
        $payload = $this->solvedPayload();
        $this->pushRequest();

        $first = $this->form($list);
        $first->formValues = $this->formValues(
            $list,
            $email,
            $payload,
        );
        self::assertInstanceOf(
            RedirectResponse::class,
            $first->submit(),
        );

        $replay = $this->form($list);
        $replay->formValues = $this->formValues(
            $list,
            $email,
            $payload,
        );

        self::assertNull(
            $replay->submit(),
        );
        self::assertSame(
            'Please complete the verification again and resubmit.',
            $replay->feedback,
        );
    }

    /**
     * Three submissions per address per hour; the fourth stays in the modal with the warning instead of navigating
     * away.
     */
    public function testTheEmailRateLimitKeepsTheFormOpen(): void
    {
        $list = $this->openGuestList();
        $email = $this->uniqueEmail();
        $this->pushRequest($this->uniqueIp());

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $component = $this->form($list);
            $component->formValues = $this->formValues(
                $list,
                $email,
                $this->solvedPayload(),
            );
            self::assertInstanceOf(
                RedirectResponse::class,
                $component->submit(),
                sprintf(
                    'Attempt %d is within the limit.',
                    $attempt,
                ),
            );
        }

        $limited = $this->form($list);
        $limited->formValues = $this->formValues(
            $list,
            $email,
            $this->solvedPayload(),
        );

        self::assertNull(
            $limited->submit(),
        );
        self::assertSame(
            'Too many sign-up attempts. Please try again later.',
            $limited->feedback,
        );
    }

    private function form(SignupList $list): ExternalSignupForm
    {
        $component = new ExternalSignupForm(
            security: self::getContainer()->get(Security::class),
            formFactory: self::getContainer()->get(FormFactoryInterface::class),
            signupManager: self::getContainer()->get(SignupManager::class),
            altchaSolutionGuard: self::getContainer()->get(AltchaSolutionGuard::class),
            signupListRepository: self::getContainer()->get(SignupListRepository::class),
            externalSignupRepository: self::getContainer()->get(ExternalSignupRepository::class),
            messageBus: self::getContainer()->get(MessageBusInterface::class),
            urlGenerator: self::getContainer()->get(UrlGeneratorInterface::class),
            requestStack: self::getContainer()->get('request_stack'),
            translator: self::getContainer()->get(TranslatorInterface::class),
            externalSignupIpLimiter: self::getContainer()->get('limiter.external_signup_ip'),
            externalSignupEmailLimiter: self::getContainer()->get('limiter.external_signup_email'),
        );
        $component->mount((int) $list->id);

        return $component;
    }

    /**
     * @return array<string, mixed>
     */
    private function formValues(
        SignupList $list,
        string $email,
        string $payload,
    ): array {
        $values = [
            'fullName' => 'Guest visitor',
            'email' => $email,
            'security' => $payload,
            'agree' => '1',
        ];

        foreach ($list->getFields() as $field) {
            $values[SignupType::fieldKey((int) $field->id)] = $this->answerFor($field);
        }

        return $values;
    }

    private function answerFor(SignupField $field): string
    {
        $option = $field->getOptions()->first();

        return false === $option
            ? 'A guest answer'
            : (string) $option->id;
    }

    private function openGuestList(): SignupList
    {
        $list = self::getContainer()->get(SignupListRepository::class)->find(self::GUEST_LIST);
        self::assertInstanceOf(
            SignupList::class,
            $list,
        );
        $list->openDate = new DateTimeImmutable('-1 hour');
        $list->closeDate = new DateTimeImmutable('+1 day');
        $this->entityManager->flush();

        return $list;
    }

    private function signupFor(
        SignupList $list,
        string $email,
    ): ?ExternalSignup {
        $signup = self::getContainer()->get(ExternalSignupRepository::class)
            ->findOneByListAndEmail(
                $list,
                $email,
            );
        if (null === $signup) {
            return null;
        }

        self::assertInstanceOf(
            ExternalSignup::class,
            $signup,
        );

        return $signup;
    }

    private function activityUrl(SignupList $list): string
    {
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(
            RouterInterface::class,
            $router,
        );

        return $router->generate(
            'activity/view',
            ['activity' => $list->getActivity()->id],
        );
    }

    private function pushRequest(?string $ip = null): Request
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        // A unique address by default: the limiters live in the filesystem cache, which outlives the test run, so a
        // shared 127.0.0.1 would count this run against the next.
        $request = new Request(server: ['REMOTE_ADDR' => $ip ?? $this->uniqueIp()]);
        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function uniqueEmail(): string
    {
        return sprintf(
            'guest-%s@example.com',
            bin2hex(random_bytes(6)),
        );
    }

    private function uniqueIp(): string
    {
        return '10.' . random_int(
            0,
            255,
        ) . '.' . random_int(
            0,
            255,
        ) . '.' . random_int(
            1,
            254,
        );
    }

    /**
     * Sign a challenge the way the bundle's challenge endpoint does, solve it, and base64 the pair the widget writes
     * into the form.
     */
    private function solvedPayload(): string
    {
        $algorithm = new Pbkdf2();
        $altcha = new Altcha(hmacSignatureSecret: (string) self::getContainer()->getParameter('altcha.hmacSignature'));
        $challenge = $altcha->createChallenge(new CreateChallengeOptions(
            algorithm: $algorithm,
            cost: 10,
            counter: 0,
        ));
        $solution = $altcha->solveChallenge(new SolveChallengeOptions(
            challenge: $challenge,
            algorithm: $algorithm,
            timeout: 10.0,
        ));
        self::assertNotNull($solution);

        return base64_encode(json_encode(
            [
                'challenge' => $challenge->toArray(),
                'solution' => [
                    'counter' => $solution->counter,
                    'derivedKey' => $solution->derivedKey,
                    'time' => $solution->time,
                ],
            ],
            JSON_THROW_ON_ERROR,
        ));
    }
}
