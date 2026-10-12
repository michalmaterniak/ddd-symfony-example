<?php

declare(strict_types=1);

namespace App\Account\Domain\Entity;

use App\Account\Domain\Dto\CreateAccountEntryDto;
use App\Account\Domain\ValueObject\AccountCredit;
use App\Account\Domain\ValueObject\AccountDebit;
use App\Account\Domain\ValueObject\AccountEntryId;
use App\Account\Domain\ValueObject\AccountEntrySide;
use App\Account\Domain\ValueObject\AccountId;
use App\Shared\Domain\ValueObject\Amount;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use RuntimeException;

#[ORM\Entity]
#[ORM\Index(name: 'idx_account_entry_account_id', columns: ['account_id'])]
class AccountEntry
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'account_entry_id', unique: true)]
    private AccountEntryId $id;

    #[ORM\Column(name: 'account_id', type: 'account_id')]
    private AccountId $accountId;

    #[ORM\Column(name: 'amount', type: 'amount')]
    private Amount $amount;

    #[ORM\Column(name: 'side', type: Types::ENUM, enumType: AccountEntrySide::class)]
    private AccountEntrySide $side;

    #[ORM\Column(name: 'effective_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $effectiveAt;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    private function __construct(
        AccountEntryId $id,
        AccountId $accountId,
        DateTimeImmutable $effectiveAt,
        Amount $amount,
        DateTimeImmutable $createdAt,
        AccountEntrySide $side,
    ) {
        $this->id = $id;
        $this->accountId = $accountId;
        $this->effectiveAt = $effectiveAt;
        $this->amount = $amount;
        $this->side = $side;
        $this->createdAt = $createdAt;
    }

    public static function createDebit(CreateAccountEntryDto $dto): self
    {
        return new self(
            $dto->accountEntryId,
            $dto->accountId,
            $dto->effectiveAt,
            AccountDebit::copy($dto->amount),
            $dto->createdAt,
            AccountEntrySide::DEBIT,
        );
    }

    public static function createCredit(CreateAccountEntryDto $dto): self
    {
        return new self(
            $dto->accountEntryId,
            $dto->accountId,
            $dto->effectiveAt,
            $dto->amount,
            $dto->createdAt,
            AccountEntrySide::CREDIT,
        );
    }

    public function accountId(): AccountId
    {
        return $this->accountId;
    }

    public function debit(): AccountDebit
    {
        if (AccountEntrySide::CREDIT === $this->side) {
            throw new RuntimeException('Account entry is a credit');
        }

        return AccountDebit::copy($this->amount);
    }

    public function credit(): AccountCredit
    {
        if (AccountEntrySide::DEBIT === $this->side) {
            throw new RuntimeException('Account entry is a debit');
        }

        return AccountCredit::copy($this->amount);
    }

    public function effectiveAt(): DateTimeImmutable
    {
        return $this->effectiveAt;
    }

    public function side(): AccountEntrySide
    {
        return $this->side;
    }
}
