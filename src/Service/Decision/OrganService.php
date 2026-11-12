<?php

declare(strict_types=1);

namespace App\Service\Decision;

use App\Entity\Decision\Organ as DecisionOrgan;
use App\Entity\Decision\OrganMember as DecisionOrganMember;
use App\Entity\Decision\SubDecision\Abrogation as DecisionAbrogation;
use App\Entity\Decision\SubDecision\Discharge as DecisionDischarge;
use App\Entity\Decision\SubDecision\Foundation as DecisionFoundation;
use App\Entity\Decision\SubDecision\Installation as DecisionInstallation;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class OrganService
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $emWeb,
    ) {
    }

    public function generateFoundation(DecisionFoundation $foundation): DecisionOrgan
    {
        // see if there already is an organ (with a slight hack)
        $rp = new ReflectionProperty(
            DecisionFoundation::class,
            'organ',
        );
        if ($rp->isInitialized($foundation)) {
            $decisionOrgan = $foundation->organ;
        } else {
            $decisionOrgan = null;
        }

        if (null === $decisionOrgan) {
            $decisionOrgan = new DecisionOrgan();
            $decisionOrgan->foundation = $foundation;
            $foundation->organ = $decisionOrgan;
        }

        $decisionOrgan->abbr = $foundation->abbr;
        $decisionOrgan->name = $foundation->name;
        $decisionOrgan->type = $foundation->organType;
        $decisionOrgan->foundationDate = $foundation->decision->meeting->date;

        // To ensure that the subdecision is correctly linked to the organ.
        $decisionOrgan->addSubdecision($foundation);

        $this->emWeb->persist($decisionOrgan);

        return $decisionOrgan;
    }

    public function generateAbrogation(DecisionAbrogation $ref): void
    {
        $rp = new ReflectionProperty(
            DecisionFoundation::class,
            'organ',
        );
        if ($rp->isInitialized($ref->foundation)) {
            $decisionOrgan = $ref->foundation->organ;
        } else {
            $decisionOrgan = null;
        }

        if (null === $decisionOrgan) {
            // Grabbing the organ from the foundation doesn't work when it has not been saved yet
            $repo = $this->emWeb->getRepository(DecisionOrgan::class);
            $decisionOrgan = $repo->findOneBy([
                'foundation' => $ref->foundation,
            ]);

            if (null === $decisionOrgan) {
                throw new LogicException('Abrogation without Organ');
            }
        }

        $abrogationDate = $ref->decision->meeting->date;
        $decisionOrgan->abrogationDate = $abrogationDate;

        // Abolishing an organ discharges the members still in it; there is no separate decision for that.
        foreach ($decisionOrgan->getMembers() as $organMember) {
            if (null !== $organMember->dischargeDate) {
                continue;
            }

            $organMember->dischargeDate = $abrogationDate;
            $this->emWeb->persist($organMember);
        }

        // To ensure that the subdecision is correctly linked to the organ.
        $decisionOrgan->addSubdecision($ref);

        $this->emWeb->persist($decisionOrgan);
    }

    public function generateInstallation(DecisionInstallation $ref): void
    {
        $repo = $this->emWeb->getRepository(DecisionOrgan::class);
        // get full reference
        $rp = new ReflectionProperty(
            DecisionInstallation::class,
            'organMember',
        );
        if ($rp->isInitialized($ref)) {
            $organMember = $ref->organMember;
        } else {
            $organMember = null;
        }

        $rp = new ReflectionProperty(
            DecisionFoundation::class,
            'organ',
        );
        if ($rp->isInitialized($ref->foundation)) {
            $decisionOrgan = $ref->foundation->organ;
        } else {
            $decisionOrgan = null;
        }

        if (null === $decisionOrgan) {
            // Grabbing the organ from the foundation doesn't work when it has not been saved yet
            $decisionOrgan = $repo->findOneBy([
                'foundation' => $ref->foundation,
            ]);

            if (null === $decisionOrgan) {
                throw new LogicException('Installation without Organ');
            }
        }

        if (null === $organMember) {
            $organMember = new DecisionOrganMember();
            // set the ID stuff
            $organMember->organ = $decisionOrgan;
            $organMember->member = $ref->getMember();
            $function = $ref->function;

            $organMember->function = $function;
            $organMember->installDate = $ref->decision->meeting->date;
        }

        $organMember->installation = $ref;
        $ref->organMember = $organMember;
        $decisionOrgan->addMember($organMember);
        $discharge = $ref->discharge;

        if (null !== $discharge) {
            $organMember->dischargeDate = $discharge->decision->meeting->date;

            // also add discharge as related
            $decisionOrgan->addSubdecision($discharge);
        }

        if (
            null !== $decisionOrgan->abrogationDate
            && null === $organMember->dischargeDate
        ) {
            $organMember->dischargeDate = $decisionOrgan->abrogationDate;
        }

        // To ensure that the subdecision is correctly linked to the organ.
        $decisionOrgan->addSubdecision($ref);

        $this->emWeb->persist($organMember);
    }

    public function generateDischarge(DecisionDischarge $ref): void
    {
        // The installation's organMember is the inverse side of the relation; it is only hydrated when the installation
        // is (re)loaded in a fresh session. Within a single session (e.g. seeding, where the install and discharge are
        // processed back-to-back) it is not, so look the OrganMember up by its installation instead.
        $rp = new ReflectionProperty(
            DecisionInstallation::class,
            'organMember',
        );
        if ($rp->isInitialized($ref->installation)) {
            $organMember = $ref->installation->organMember;
        } else {
            $organMember = $this->emWeb->getRepository(DecisionOrganMember::class)
                ->findOneBy(['installation' => $ref->installation]);
        }

        if (null === $organMember) {
            // The installation this discharge undoes never took effect, so there is no member in the body to discharge.
            // That is what the ledger says whenever the installation was annulled before this point.
            return;
        }

        $rp = new ReflectionProperty(
            DecisionFoundation::class,
            'organ',
        );
        if ($rp->isInitialized($organMember->installation->foundation)) {
            $decisionOrgan = $organMember->installation->foundation->organ;
        } else {
            $decisionOrgan = null;
        }

        if (null === $decisionOrgan) {
            // Grabbing the organ from the foundation doesn't work when it has not been saved yet
            $decisionOrgan = $this->emWeb->getRepository(DecisionOrgan::class)->findOneBy([
                'foundation' => $organMember->installation->foundation,
            ]);

            if (null === $decisionOrgan) {
                throw new LogicException('Discharge without Organ');
            }
        }

        $organMember->dischargeDate = $ref->decision->meeting->date;

        // To ensure that the subdecision is correctly linked to the organ.
        $decisionOrgan->addSubdecision($ref);

        $this->emWeb->persist($organMember);
    }
}
