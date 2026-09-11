<?php

declare(strict_types=1);

namespace App\ViewModel\Checker\Error;

use App\Entity\Database\SubDecision\Key\Withdrawal as KeyWithdrawalModel;
use App\ViewModel\Checker\Error;
use Override;

use function sprintf;

/**
 * Error for when a key code is withdrawn when the original granting already expired.
 *
 * @extends Error<KeyWithdrawalModel>
 */
class KeyWithdrawnPastOriginalGranting extends Error
{
    public function __construct(KeyWithdrawalModel $withdrawal)
    {
        parent::__construct(
            $withdrawal->decision->meeting,
            $withdrawal,
        );
    }

    /**
     * Get the withdrawal.
     */
    private function getWithdrawal(): KeyWithdrawalModel
    {
        return $this->getSubDecision();
    }

    #[Override]
    public function asText(): string
    {
        return sprintf(
            'Key code of %s withdrawn per %s, this is after the original expiration %s.',
            $this->getWithdrawal()->granting->getMember()->getFullName(),
            $this->getWithdrawal()->withdrawnOn->format('Y-m-d'),
            $this->getWithdrawal()->granting->until->format('Y-m-d'),
        );
    }
}
