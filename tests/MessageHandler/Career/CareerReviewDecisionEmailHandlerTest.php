<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Career;

use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\Career\Company as CompanyModel;
use App\Entity\User\CompanyUser;
use App\Message\Career\CareerReviewDecisionEmail;
use App\MessageHandler\Career\CareerReviewDecisionEmailHandler;
use App\Repository\Career\CompanyRepository;
use App\Repository\User\CompanyUserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_map;

/**
 * The decision belongs to the company, so every representative who can still act is mailed. The outcome states the
 * current revision status, and the portal link points at the profile or the vacancies.
 */
final class CareerReviewDecisionEmailHandlerTest extends TestCase
{
    /**
     * @return array<string, array{RevisionStatus, string}>
     */
    public static function outcomes(): array
    {
        return [
            'approved' => [
                RevisionStatus::Approved,
                'Vacancy X: approved',
            ],
            'changes requested' => [
                RevisionStatus::ChangesRequested,
                'Vacancy X: changes requested',
            ],
            'rejected' => [
                RevisionStatus::Rejected,
                'Vacancy X: not approved',
            ],
        ];
    }

    #[DataProvider('outcomes')]
    public function testTheOutcomeIsNamedInTheSubject(
        RevisionStatus $outcome,
        string $subject,
    ): void {
        $sent = [];
        $this->handler($sent, [$this->representative()])($this->message($outcome));

        self::assertCount(
            1,
            $sent,
        );
        self::assertSame(
            $subject,
            $sent[0]->getSubject(),
        );
    }

    public function testEveryActiveRepresentativeIsWrittenTo(): void
    {
        $sent = [];
        $this->handler(
            $sent,
            [
                $this->representative(
                    'first@example.com',
                    'First',
                ),
                $this->representative(
                    'second@example.com',
                    'Second',
                ),
            ],
        )($this->message(RevisionStatus::Approved));

        self::assertSame(
            [
                'first@example.com',
                'second@example.com',
            ],
            array_map(
                static fn (TemplatedEmail $email): string => $email->getTo()[0]->getAddress(),
                $sent,
            ),
        );
        self::assertSame(
            [
                'First',
                'Second',
            ],
            array_map(
                static fn (TemplatedEmail $email): mixed => $email->getContext()['fullName'],
                $sent,
            ),
        );
    }

    public function testVacanciesAndProfilesLinkToTheirOwnScreen(): void
    {
        $sent = [];
        $urlGenerator = $this->urlGenerator();
        $this->handler(
            $sent,
            [$this->representative()],
            $urlGenerator,
        )(
            $this->message(
                RevisionStatus::Approved,
                isVacancy: true,
            ),
        );
        $this->handler(
            $sent,
            [$this->representative()],
            $urlGenerator,
        )(
            $this->message(
                RevisionStatus::ChangesRequested,
                isVacancy: false,
            ),
        );

        self::assertSame(
            'https://gewis.nl/company/vacancies',
            $sent[0]->getContext()['portalUrl'],
        );
        self::assertSame(
            'https://gewis.nl/company/profile/status',
            $sent[1]->getContext()['portalUrl'],
        );
        self::assertTrue($sent[0]->getContext()['isVacancy']);
        self::assertFalse($sent[1]->getContext()['isVacancy']);
    }

    public function testACompanyWithNobodyLeftToActIsNotMailed(): void
    {
        $sent = [];
        $this->handler($sent, [])($this->message(RevisionStatus::Approved));

        self::assertSame(
            [],
            $sent,
        );
    }

    public function testACompanyThatWasRemovedIsNotMailed(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $companies = self::createStub(CompanyRepository::class);
        $companies->method('find')->willReturn(null);

        $companyUsers = $this->createMock(CompanyUserRepository::class);
        $companyUsers->expects(self::never())->method('findActiveForCompany');

        $handler = new CareerReviewDecisionEmailHandler(
            $mailer,
            $companies,
            $companyUsers,
            $this->urlGenerator(),
        );
        $handler(new CareerReviewDecisionEmail(
            404,
            RevisionStatus::Approved,
            'Vacancy X',
            true,
        ));
    }

    /**
     * @param list<TemplatedEmail> $sent
     * @param list<CompanyUser>    $representatives
     */
    private function handler(
        array &$sent,
        array $representatives = [],
        ?UrlGeneratorInterface $urlGenerator = null,
    ): CareerReviewDecisionEmailHandler {
        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (TemplatedEmail $email) use (&$sent): void {
                $sent[] = $email;
            },
        );

        $companies = self::createStub(CompanyRepository::class);
        $companies->method('find')->willReturnCallback(
            static function (int $id): ?CompanyModel {
                if (404 === $id) {
                    return null;
                }

                $company = new CompanyModel();
                $company->name = 'ACME';
                $company->slugName = 'acme';

                return $company;
            },
        );

        $companyUsers = self::createStub(CompanyUserRepository::class);
        $companyUsers->method('findActiveForCompany')->willReturn($representatives);

        return new CareerReviewDecisionEmailHandler(
            $mailer,
            $companies,
            $companyUsers,
            $urlGenerator ?? $this->urlGenerator(),
        );
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        $urlGenerator = self::createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route): string => 'https://gewis.nl/' . $route,
        );

        return $urlGenerator;
    }

    private function message(
        RevisionStatus $outcome,
        bool $isVacancy = true,
    ): CareerReviewDecisionEmail {
        return new CareerReviewDecisionEmail(
            3,
            $outcome,
            'Vacancy X',
            $isVacancy,
        );
    }

    private function representative(
        string $email = 'rep@example.com',
        string $name = 'Representative',
    ): CompanyUser {
        $companyUser = new CompanyUser();
        $companyUser->email = $email;
        $companyUser->name = $name;
        $companyUser->company = new CompanyModel();

        return $companyUser;
    }
}
