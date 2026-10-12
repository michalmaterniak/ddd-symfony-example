<?php

declare(strict_types=1);

namespace App\Account\Domain\Exception;

use App\Account\Domain\ValueObject\AccountId;
use App\Shared\Domain\Exception\DomainException;

class AccountCreationException extends DomainException
{
    public function __construct(
        string $message,
        private readonly AccountId $accountId,
    ) {
        parent::__construct($message);
    }

    public function getDomainData(): array
    {
        return [
            'accountId' => $this->accountId->toString(),
        ];
    }

    public static function throw(AccountId $accountId): self
    {
        return new self('Account creation failed', $accountId);
    }
}
