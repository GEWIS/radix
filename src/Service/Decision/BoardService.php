<?php

declare(strict_types=1);

namespace App\Service\Decision;

use App\Entity\Decision\BoardMember;
use App\Entity\Decision\SubDecision\Board\Discharge as DecisionBoardDischarge;
use App\Entity\Decision\SubDecision\Board\Installation as DecisionBoardInstallation;
use App\Entity\Decision\SubDecision\Board\Release as DecisionBoardRelease;
use Doctrine\ORM\EntityManagerInterface;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class BoardService
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $emWeb,
    ) {
    }

    public function generateInstallation(DecisionBoardInstallation $installation): BoardMember
    {
        $boardMember = $this->findBoardMember($installation);

        if (null === $boardMember) {
            $boardMember = new BoardMember();
            $boardMember->installationDec = $installation;
            $installation->boardMember = $boardMember;
        }

        $boardMember->member = $installation->getMember();
        $boardMember->function = $installation->function;
        $boardMember->installDate = $installation->date;

        $this->emWeb->persist($boardMember);

        return $boardMember;
    }

    public function generateDischarge(DecisionBoardDischarge $discharge): void
    {
        $boardMember = $this->findBoardMember($discharge->installation);

        if (null === $boardMember) {
            // The installation this discharge undoes never took effect, so there is no member on the board to
            // discharge. That is what the ledger says whenever the installation was annulled before this point, and it
            // is also what the older meetings say, from before board membership was recorded the way it is now.
            return;
        }

        $boardMember->dischargeDate = $discharge->decision->meeting->date;

        $this->emWeb->persist($boardMember);
    }

    public function generateRelease(DecisionBoardRelease $release): void
    {
        $boardMember = $this->findBoardMember($release->installation);

        if (null === $boardMember) {
            // See generateDischarge(): there is nothing to release a member from.
            return;
        }

        $boardMember->releaseDate = $release->date;

        $this->emWeb->persist($boardMember);
    }

    /**
     * Find the board member an installation brought into being, if it has one.
     *
     * The installation's board member is the inverse side of the relation; it is only hydrated when the installation
     * is (re)loaded in a fresh session. Within a single session it is kept in step by hand, but an installation that
     * was never installed here has neither, so fall back to looking the board member up by its installation.
     */
    private function findBoardMember(DecisionBoardInstallation $installation): ?BoardMember
    {
        $rp = new ReflectionProperty(
            DecisionBoardInstallation::class,
            'boardMember',
        );

        if ($rp->isInitialized($installation)) {
            return $installation->boardMember;
        }

        return $this->emWeb->getRepository(BoardMember::class)
            ->findOneBy(['installationDec' => $installation]);
    }
}
