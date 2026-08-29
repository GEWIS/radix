<?php

declare(strict_types=1);

namespace App\DataFixtures\Database;

use App\DataFixtures\Member\MemberPopulationFixture;
use App\Entity\Database\Decision;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Database\Meeting;
use App\Entity\Database\Member;
use App\Entity\Database\SubDecision\Abrogation;
use App\Entity\Database\SubDecision\Annulment;
use App\Entity\Database\SubDecision\Discharge;
use App\Entity\Database\SubDecision\Foundation;
use App\Entity\Database\SubDecision\Installation;
use App\Entity\Database\SubDecision\Other;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Override;

use function assert;
use function range;
use function sprintf;

final class OrganDecisionFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    /**
     * The board decision a virtual meeting says again further down, which is what the two halves of this fixture
     * hand between themselves.
     */
    private const string REPEATED_DECISION = 'ledger-decision-repeated';

    #[Override]
    public function load(ObjectManager $manager): void
    {
        // Installment of GETÉST, at the oldest BM.
        $decision = new Decision();
        $decision->setMeeting($this->getReference('ledger-meeting-BV-1800', Meeting::class));
        $decision->setPoint(1);
        $decision->setNumber(1);

        $manager->persist($decision);
        $this->addReference(
            'decision-BV-1800-' . $decision->getPoint() . '-' . $decision->getNumber(),
            $decision,
        );

        $sequence = 1;
        $iSubdecisions = [];

        $foundation = new Foundation();
        $foundation->setAbbr('GETÉST');
        $foundation->setName('GEWIS\'ers Testen Éigenlijk Structureel Te-weinig');
        $foundation->setOrganType(OrganTypes::Committee);
        $foundation->setDecision($decision);
        $foundation->setSequence($sequence);

        $manager->persist($foundation);
        $iSubdecisions[] = $foundation;
        $this->addReference(
            'foundation-' . $foundation->getSequence(),
            $foundation,
        );

        // phpcs:disable SlevomatCodingStandard.ControlStructures.EarlyExit.EarlyExitNotUsed
        foreach (
            range(
                8005,
                8024,
            ) as $lidnr
        ) {
            $sequence++;
            $iSubdecisions[] = $this->createInstallation(
                InstallationFunctions::Member,
                $lidnr,
                $sequence,
                $foundation,
                $decision,
                $manager,
            );

            // Additional roles for specific members.
            if (8005 === $lidnr) {
                $sequence++;
                $iSubdecisions[] = $this->createInstallation(
                    InstallationFunctions::Chair,
                    $lidnr,
                    $sequence,
                    $foundation,
                    $decision,
                    $manager,
                );
            }

            if (8006 === $lidnr) {
                $sequence++;
                $iSubdecisions[] = $this->createInstallation(
                    InstallationFunctions::Secretary,
                    $lidnr,
                    $sequence,
                    $foundation,
                    $decision,
                    $manager,
                );
            }

            // Will be discharged.
            if (8020 === $lidnr) {
                $sequence++;
                $iSubdecisions[] = $this->createInstallation(
                    InstallationFunctions::Treasurer,
                    $lidnr,
                    $sequence,
                    $foundation,
                    $decision,
                    $manager,
                );
            }
        }

        // phpcs:enable SlevomatCodingStandard.ControlStructures.EarlyExit.EarlyExitNotUsed

        $manager->persist($decision);

        $manager->flush();

        // Discharge of members of GETEST, a few weeks later.
        $decision = new Decision();
        $decision->setMeeting($this->getReference('ledger-meeting-BV-1806', Meeting::class));
        $decision->setPoint(1);
        $decision->setNumber(1);

        $manager->persist($decision);
        $this->addReference(
            'decision-BV-1806-' . $decision->getPoint() . '-' . $decision->getNumber(),
            $decision,
        );

        $sequence = 1;
        $dSubdecisions = [];

        foreach (
            range(
                8020,
                8024,
            ) as $lidnr
        ) {
            // Order of discharge matters, the discharge from a special function comes before `Lid`.
            if (8020 === $lidnr) {
                $dSubdecisions[] = $this->createDischarge(
                    $sequence,
                    $sequence + 18, // TODO: find a better way to calculate this.
                    $decision,
                    $manager,
                );
                $sequence++;
            }

            $dSubdecisions[] = $this->createDischarge(
                $sequence,
                $sequence + 18, // TODO: find a better way to calculate this.
                $decision,
                $manager,
            );
            $sequence++;
        }

        $manager->persist($decision);

        $manager->flush();

        $this->loadSecondOrgan($manager);
        $this->loadFormerOrgan($manager);
        $this->loadBoardDecisions($manager);
        $this->loadMeetingTextDecisions($manager);
    }

    /**
     * A small second committee (chair, secretary, one member), so organ-scoped access can be told apart between
     * organs. Built like GETÉST but with distinct members from GETÉST.
     */
    private function loadSecondOrgan(ObjectManager $manager): void
    {
        $decision = new Decision();
        $decision->setMeeting($this->getReference('ledger-meeting-BV-1800', Meeting::class));
        $decision->setPoint(2);
        $decision->setNumber(1);

        $manager->persist($decision);

        $foundation = new Foundation();
        $foundation->setAbbr('KEUR');
        $foundation->setName('Keuringscommissie');
        $foundation->setOrganType(OrganTypes::Committee);
        $foundation->setDecision($decision);
        $foundation->setSequence(1);

        $manager->persist($foundation);

        $functions = [
            8025 => InstallationFunctions::Chair,
            8026 => InstallationFunctions::Secretary,
            8027 => InstallationFunctions::Member,
        ];

        $installations = [];
        $sequence = 1;
        foreach ($functions as $lidnr => $function) {
            $sequence++;
            $installation = new Installation();
            $installation->setFunction($function);
            $installation->setMember($this->getReference('ledger-member-' . $lidnr, Member::class));
            $installation->setSequence($sequence);
            $installation->setFoundation($foundation);
            $installation->setDecision($decision);

            $manager->persist($installation);
            $installations[] = $installation;
        }

        $manager->persist($decision);

        $manager->flush();

        $manager->flush();
    }

    private function loadFormerOrgan(ObjectManager $manager): void
    {
        $decision = new Decision();
        $decision->setMeeting($this->getReference('ledger-meeting-BV-1800', Meeting::class));
        $decision->setPoint(3);
        $decision->setNumber(1);

        $manager->persist($decision);

        $foundation = new Foundation();
        $foundation->setAbbr('GETÉST');
        $foundation->setName('GEWIS\'ers Testten Éigenlijk Structureel Te-weinig');
        $foundation->setOrganType(OrganTypes::Committee);
        $foundation->setDecision($decision);
        $foundation->setSequence(1);

        $manager->persist($foundation);

        // Abrogated, which is what makes this the former body of that name rather than a second one standing beside
        // the first. In the ledger that is a decision like any other, not a date written onto the body.
        $closing = new Decision();
        $closing->setMeeting($this->getReference(
            'ledger-meeting-BV-1806',
            Meeting::class,
        ));
        $closing->setPoint(4);
        $closing->setNumber(1);

        $manager->persist($closing);

        $abrogation = new Abrogation();
        $abrogation->setFoundation($foundation);
        $abrogation->setSequence(1);
        $abrogation->setDecision($closing);
        $closing->addSubdecision($abrogation);

        $manager->persist($abrogation);
        $manager->flush();
    }

    private function loadBoardDecisions(ObjectManager $manager): void
    {
        $keyGrantee = $this->getReference(
            'ledger-member-8010',
            Member::class,
        );

        // The English half is left out of a handful on purpose, or the translation page has nothing to show.
        $texts = [
            [
                'ledger-meeting-BV-1801',
                1,
                1,
                'Het bestuur besluit de begroting van de wisselactiviteit van GETÉST ter hoogte van € 250,00 goed'
                . ' te keuren.',
                'The board decides to approve the budget of the exchange activity of GETÉST amounting to € 250.00.',
            ],
            [
                'ledger-meeting-BV-1802',
                1,
                1,
                sprintf(
                    'Het bestuur besluit %s sleutelrechten toe te kennen tot het einde van het verenigingsjaar.',
                    $keyGrantee->getFullName(),
                ),
                sprintf(
                    'The board decides to grant %s key rights until the end of the association year.',
                    $keyGrantee->getFullName(),
                ),
            ],
            [
                'ledger-meeting-BV-1803',
                1,
                1,
                'Het bestuur besluit de notulen van de vorige bestuursvergadering vast te stellen.',
                'The board decides to adopt the minutes of the previous board meeting.',
            ],
            [
                'ledger-meeting-BV-1803',
                1,
                2,
                'Het bestuur besluit het activiteitenbeleid ter instemming voor te leggen aan de ALV.',
                null,
            ],
            [
                'ledger-meeting-BV-1805',
                1,
                1,
                'Het bestuur besluit de begroting van het introductieweekend ter hoogte van € 1.250,00 goed te keuren.',
                'The board decides to approve the budget of the introduction weekend amounting to € 1,250.00.',
            ],
            [
                'ledger-meeting-BV-1807',
                2,
                1,
                'Het bestuur besluit de samenwerkingsovereenkomst met de faculteit te bekrachtigen.',
                'The board decides to ratify the cooperation agreement with the department.',
            ],
            [
                'ledger-meeting-BV-1808',
                1,
                1,
                'Het bestuur besluit een bijdrage van € 75,00 toe te kennen aan de constitutieborrel van KEUR.',
                null,
            ],
            [
                'ledger-meeting-BV-1810',
                1,
                1,
                'Het bestuur besluit de declaratierichtlijn per direct te actualiseren.',
                'The board decides to update the expense claim guideline with immediate effect.',
            ],
            [
                'ledger-meeting-BV-1811',
                1,
                1,
                'Het bestuur besluit de jaarplanning van GETÉST vast te stellen.',
                null,
            ],
        ];

        $annulmentTarget = null;

        foreach ($texts as [$meetingReference, $point, $number, $contentNL, $contentEN]) {
            $decision = $this->createTextDecision(
                $manager,
                $meetingReference,
                $point,
                $number,
                $contentNL,
                $contentEN,
            );

            // Said again by a virtual meeting further down, which is what the counterpart there points back at.
            if ('ledger-meeting-BV-1805' === $meetingReference) {
                $this->addReference(
                    self::REPEATED_DECISION,
                    $decision,
                );
            }

            if ('ledger-meeting-BV-1801' !== $meetingReference) {
                continue;
            }

            $annulmentTarget = $decision;
        }

        $decision = new Decision();
        $decision->setMeeting($this->getReference('ledger-meeting-BV-1804', Meeting::class));
        $decision->setPoint(1);
        $decision->setNumber(1);
        $manager->persist($decision);

        assert($annulmentTarget instanceof Decision);

        $annulment = new Annulment();
        $annulment->setTarget($annulmentTarget);
        $annulment->setSequence(1);
        $annulment->setDecision($decision);
        $manager->persist($annulment);

        $manager->persist($decision);

        $manager->flush();
    }

    /**
     * Decisions of the complete GMM, lined up with (and deliberately once without) its agenda points, plus a
     * correction recorded in a virtual meeting. CMs take no decisions.
     */
    private function loadMeetingTextDecisions(ObjectManager $manager): void
    {
        $gmmTexts = [
            [
                2,
                1,
                'De agenda van de vergadering wordt vastgesteld.',
                'The agenda of the meeting is adopted.',
            ],
            [
                3,
                1,
                'De notulen van de vorige ALV worden goedgekeurd.',
                'The minutes of the previous GMM are approved.',
            ],
            [
                5,
                1,
                'De motie van orde over de vergaderduur wordt aangenomen.',
                null,
            ],
            [
                7,
                1,
                'De begroting voor het komende verenigingsjaar wordt vastgesteld.',
                'The budget for the coming association year is adopted.',
            ],
        ];

        foreach ($gmmTexts as [$point, $number, $contentNL, $contentEN]) {
            $this->createTextDecision(
                $manager,
                'ledger-meeting-gmm-complete',
                $point,
                $number,
                $contentNL,
                $contentEN,
            );
        }

        $this->createTextDecision(
            $manager,
            'ledger-meeting-Virt-2',
            1,
            1,
            'Rectificatie: de in BV 1805.1.1 genoemde begroting betreft het introductieweekend van het komende'
            . ' verenigingsjaar.',
            'Correction: the budget mentioned in BV 1805.1.1 concerns the introduction weekend of the coming'
            . ' association year.',
        );

        // A virtual meeting saying again what a board meeting decided, and naming the decision it repeats. Without
        // that link the two are two answers to the same search, which is what the seed is here to show.
        $repeat = $this->createTextDecision(
            $manager,
            'ledger-meeting-Virt-1',
            1,
            1,
            'Het bestuur besluit de begroting van het introductieweekend ter hoogte van € 1.250,00 goed te keuren.',
            'The board decides to approve the budget of the introduction weekend amounting to € 1,250.00.',
        );
        $repeat->setCounterpart($this->getReference(
            self::REPEATED_DECISION,
            Decision::class,
        ));

        $manager->flush();
    }

    private function createTextDecision(
        ObjectManager $manager,
        string $meetingReference,
        int $point,
        int $number,
        string $contentNL,
        ?string $contentEN = null,
    ): Decision {
        $decision = new Decision();
        $decision->setMeeting($this->getReference(
            $meetingReference,
            Meeting::class,
        ));
        $decision->setPoint($point);
        $decision->setNumber($number);

        // A decision says what its subdecisions say, so free text is a subdecision rather than a field on the
        // decision; the replay reads the projection's content off it.
        $other = new Other();
        $other->setContentNL($contentNL);
        $other->setContentEN($contentEN);
        $other->setSequence(1);
        $other->setDecision($decision);
        $decision->addSubdecision($other);

        $manager->persist($decision);
        $manager->persist($other);

        return $decision;
    }

    private function createInstallation(
        InstallationFunctions $function,
        int $lidnr,
        int $sequence,
        Foundation $foundation,
        Decision $decision,
        ObjectManager $manager,
    ): Installation {
        $installation = new Installation();
        $installation->setFunction($function);
        $installation->setMember($this->getReference('ledger-member-' . $lidnr, Member::class));
        $installation->setSequence($sequence);
        $installation->setFoundation($foundation);
        $installation->setDecision($decision);

        $manager->persist($installation);
        $this->addReference(
            'installation-' . $installation->getSequence(),
            $installation,
        );

        return $installation;
    }

    private function createDischarge(
        int $sequence,
        int $installationSequence,
        Decision $decision,
        ObjectManager $manager,
    ): Discharge {
        $discharge = new Discharge();
        $discharge->setInstallation($this->getReference('installation-' . $installationSequence, Installation::class));
        $discharge->setSequence($sequence);
        $discharge->setDecision($decision);

        $manager->persist($discharge);
        $this->addReference(
            'discharge-' . $discharge->getSequence(),
            $discharge,
        );

        return $discharge;
    }

    /**
     * @return class-string<Fixture>[]
     */
    #[Override]
    public function getDependencies(): array
    {
        return [
            MeetingScheduleFixture::class,
            MemberPopulationFixture::class,
        ];
    }

    /**
     * @return string[]
     */
    #[Override]
    public static function getGroups(): array
    {
        return ['ledger'];
    }
}
