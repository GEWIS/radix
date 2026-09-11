<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Entity\Decision\Organ as ReportOrgan;
use App\Entity\Decision\OrganMember as ReportOrganMember;
use App\Entity\Decision\SubDecision\Abrogation as ReportAbrogation;
use App\Entity\Decision\SubDecision\Discharge as ReportDischarge;
use App\Entity\Decision\SubDecision\Foundation as ReportFoundation;
use App\Entity\Decision\SubDecision\Installation as ReportInstallation;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class OrganService
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $emReport,
    ) {
    }

    public function generateFoundation(ReportFoundation $foundation): ReportOrgan
    {
        // see if there already is an organ (with a slight hack)
        $rp = new ReflectionProperty(
            ReportFoundation::class,
            'organ',
        );
        if ($rp->isInitialized($foundation)) {
            $repOrgan = $foundation->organ;
        } else {
            $repOrgan = null;
        }

        if (null === $repOrgan) {
            $repOrgan = new ReportOrgan();
            $repOrgan->foundation = $foundation;
            $foundation->organ = $repOrgan;
        }

        $repOrgan->abbr = $foundation->abbr;
        $repOrgan->name = $foundation->name;
        $repOrgan->type = $foundation->organType;
        $repOrgan->foundationDate = $foundation->decision->meeting->date;

        // To ensure that the subdecision is correctly linked to the organ.
        $repOrgan->addSubdecision($foundation);

        $this->emReport->persist($repOrgan);

        return $repOrgan;
    }

    public function generateAbrogation(ReportAbrogation $ref): void
    {
        $rp = new ReflectionProperty(
            ReportFoundation::class,
            'organ',
        );
        if ($rp->isInitialized($ref->foundation)) {
            $repOrgan = $ref->foundation->organ;
        } else {
            $repOrgan = null;
        }

        if (null === $repOrgan) {
            // Grabbing the organ from the foundation doesn't work when it has not been saved yet
            $repo = $this->emReport->getRepository(ReportOrgan::class);
            $repOrgan = $repo->findOneBy([
                'foundation' => $ref->foundation,
            ]);

            if (null === $repOrgan) {
                throw new LogicException('Abrogation without Organ');
            }
        }

        $abrogationDate = $ref->decision->meeting->date;
        $repOrgan->abrogationDate = $abrogationDate;

        // Abolishing an organ discharges whoever is still in it; there is no separate decision for that.
        foreach ($repOrgan->getMembers() as $organMember) {
            if (null !== $organMember->dischargeDate) {
                continue;
            }

            $organMember->dischargeDate = $abrogationDate;
            $this->emReport->persist($organMember);
        }

        // To ensure that the subdecision is correctly linked to the organ.
        $repOrgan->addSubdecision($ref);

        $this->emReport->persist($repOrgan);
    }

    public function generateInstallation(ReportInstallation $ref): void
    {
        $repo = $this->emReport->getRepository(ReportOrgan::class);
        // get full reference
        $rp = new ReflectionProperty(
            ReportInstallation::class,
            'organMember',
        );
        if ($rp->isInitialized($ref)) {
            $organMember = $ref->organMember;
        } else {
            $organMember = null;
        }

        $rp = new ReflectionProperty(
            ReportFoundation::class,
            'organ',
        );
        if ($rp->isInitialized($ref->foundation)) {
            $repOrgan = $ref->foundation->organ;
        } else {
            $repOrgan = null;
        }

        if (null === $repOrgan) {
            // Grabbing the organ from the foundation doesn't work when it has not been saved yet
            $repOrgan = $repo->findOneBy([
                'foundation' => $ref->foundation,
            ]);

            if (null === $repOrgan) {
                throw new LogicException('Installation without Organ');
            }
        }

        if (null === $organMember) {
            $organMember = new ReportOrganMember();
            // set the ID stuff
            $organMember->organ = $repOrgan;
            $organMember->member = $ref->getMember();
            $function = $ref->function;

            $organMember->function = $function;
            $organMember->installDate = $ref->decision->meeting->date;
        }

        $organMember->installation = $ref;
        $ref->organMember = $organMember;
        $repOrgan->addMember($organMember);
        $discharge = $ref->discharge;

        if (null !== $discharge) {
            $organMember->dischargeDate = $discharge->decision->meeting->date;

            // also add discharge as related
            $repOrgan->addSubdecision($discharge);
        }

        if (
            null !== $repOrgan->abrogationDate
            && null === $organMember->dischargeDate
        ) {
            $organMember->dischargeDate = $repOrgan->abrogationDate;
        }

        // To ensure that the subdecision is correctly linked to the organ.
        $repOrgan->addSubdecision($ref);

        $this->emReport->persist($organMember);
    }

    public function generateDischarge(ReportDischarge $ref): void
    {
        // The installation's organMember is the inverse side of the relation; it is only hydrated when the installation
        // is (re)loaded in a fresh session. Within a single session (e.g. seeding, where the install and discharge are
        // processed back-to-back) it is not, so look the OrganMember up by its installation instead.
        $rp = new ReflectionProperty(
            ReportInstallation::class,
            'organMember',
        );
        if ($rp->isInitialized($ref->installation)) {
            $organMember = $ref->installation->organMember;
        } else {
            $organMember = $this->emReport->getRepository(ReportOrganMember::class)
                ->findOneBy(['installation' => $ref->installation]);
        }

        if (null === $organMember) {
            // The installation this discharge undoes never took effect, so there is nobody in the body to discharge.
            // That is what the ledger says whenever the installation was annulled before this point.
            return;
        }

        $rp = new ReflectionProperty(
            ReportFoundation::class,
            'organ',
        );
        if ($rp->isInitialized($organMember->installation->foundation)) {
            $repOrgan = $organMember->installation->foundation->organ;
        } else {
            $repOrgan = null;
        }

        if (null === $repOrgan) {
            // Grabbing the organ from the foundation doesn't work when it has not been saved yet
            $repOrgan = $this->emReport->getRepository(ReportOrgan::class)->findOneBy([
                'foundation' => $organMember->installation->foundation,
            ]);

            if (null === $repOrgan) {
                throw new LogicException('Discharge without Organ');
            }
        }

        $organMember->dischargeDate = $ref->decision->meeting->date;

        // To ensure that the subdecision is correctly linked to the organ.
        $repOrgan->addSubdecision($ref);

        $this->emReport->persist($organMember);
    }
}
