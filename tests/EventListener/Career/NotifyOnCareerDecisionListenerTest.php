<?php

declare(strict_types=1);

namespace App\Tests\EventListener\Career;

use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\Career\CareerLocalisedText;
use App\Entity\Career\Company;
use App\Entity\Career\CompanyRevision;
use App\Entity\Career\Vacancy;
use App\Entity\Career\VacancyRevision;
use App\EventListener\Career\NotifyOnCareerDecisionListener;
use App\Message\Career\CareerReviewDecisionEmail;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\Event\EnteredEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;

/**
 * A company's representatives have no notification centre of their own, so a decision goes out as plain email to
 * everybody who can act for the company.
 */
final class NotifyOnCareerDecisionListenerTest extends TestCase
{
    private ?CareerReviewDecisionEmail $dispatched = null;

    public function testACompanyDecisionIsEmailedToItsRepresentatives(): void
    {
        $revision = self::createStub(CompanyRevision::class);
        $revision->company = $this->company(7);
        $revision->method('getStatus')->willReturn(RevisionStatus::Approved);

        $this->listener()($this->event($revision));

        self::assertNotNull($this->dispatched);
        self::assertSame(
            7,
            $this->dispatched->getCompanyId(),
        );
        self::assertSame(
            RevisionStatus::Approved,
            $this->dispatched->getOutcome(),
        );
        self::assertSame(
            'A company',
            $this->dispatched->getSubjectName(),
        );
        self::assertFalse($this->dispatched->isVacancy());
    }

    public function testAVacancyDecisionNamesTheVacancyRatherThanTheCompany(): void
    {
        $company = $this->company(7);

        $vacancy = self::createStub(Vacancy::class);
        $vacancy->method('getCompany')->willReturn($company);

        $name = self::createStub(CareerLocalisedText::class);
        $name->method('getText')->willReturn('A vacancy');

        $revision = self::createStub(VacancyRevision::class);
        $revision->vacancy = $vacancy;
        $revision->name = $name;
        $revision->method('getStatus')->willReturn(RevisionStatus::Rejected);

        $this->listener()($this->event($revision));

        self::assertNotNull($this->dispatched);
        self::assertSame(
            7,
            $this->dispatched->getCompanyId(),
        );
        self::assertSame(
            RevisionStatus::Rejected,
            $this->dispatched->getOutcome(),
        );
        self::assertSame(
            'A vacancy',
            $this->dispatched->getSubjectName(),
        );
        self::assertTrue($this->dispatched->isVacancy());
    }

    /**
     * A revision that is not a company's or a vacancy's has no representatives to write to.
     */
    public function testAnotherRevisionIsIgnored(): void
    {
        $this->listener()($this->event(new stdClass()));

        self::assertNull($this->dispatched);
    }

    /**
     * A company that was never stored has no identifier to address the email to.
     */
    public function testACompanyWithoutAnIdentifierIsIgnored(): void
    {
        $revision = self::createStub(CompanyRevision::class);
        $revision->company = $this->company(null);
        $revision->method('getStatus')->willReturn(RevisionStatus::Approved);

        $this->listener()($this->event($revision));

        self::assertNull($this->dispatched);
    }

    private function listener(): NotifyOnCareerDecisionListener
    {
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            function (object $message): Envelope {
                if ($message instanceof CareerReviewDecisionEmail) {
                    $this->dispatched = $message;
                }

                return new Envelope($message);
            },
        );

        return new NotifyOnCareerDecisionListener($bus);
    }

    private function company(?int $id): Company
    {
        $company = self::createStub(Company::class);
        $company->name = 'A company';

        if (null !== $id) {
            new ReflectionProperty(
                Company::class,
                'id',
            )->setValue(
                $company,
                $id,
            );
        }

        return $company;
    }

    /**
     * @return EnteredEvent<object>
     */
    private function event(object $subject): EnteredEvent
    {
        return new EnteredEvent(
            $subject,
            new Marking(),
            new Transition(
                'approve',
                'from',
                'to',
            ),
        );
    }
}
