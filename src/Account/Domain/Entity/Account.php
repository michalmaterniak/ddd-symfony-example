<?php

declare(strict_types=1);

namespace App\Account\Domain\Entity;

use App\Account\Domain\Dto\AddAccountCreditDto;
use App\Account\Domain\Dto\AddAccountDebitDto;
use App\Account\Domain\Dto\CreateAccountDto;
use App\Account\Domain\Dto\CreateAccountEntryDto;
use App\Account\Domain\Event\AccountCreated;
use App\Account\Domain\Event\AccountCreditUpdated;
use App\Account\Domain\Event\AccountDebitUpdated;
use App\Account\Domain\ValueObject\AccountCredit;
use App\Account\Domain\ValueObject\AccountDebit;
use App\Account\Domain\ValueObject\AccountDescription;
use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\ValueObject\Amount;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\Clock;

#[ORM\Entity]
class Account extends AggregateRoot
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'account_id', unique: true)]
    private AccountId $id;

    #[ORM\Column(name: 'name', type: 'account_name', length: 255, unique: true)]
    private AccountName $name;

    #[ORM\Column(name: 'description', type: 'account_description', length: 25500)]
    private AccountDescription $description;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'credit', type: 'amount', options: ['default' => 0])]
    private Amount $credit;

    #[ORM\Column(name: 'debit', type: 'amount', options: ['default' => 0])]
    private Amount $debit;

    /**
     * Optimistic lock: concurrent updates of the same account fail with OptimisticLockException on flush.
     */
    #[ORM\Version]
    #[ORM\Column(name: 'version', type: Types::INTEGER)]
    private int $version = 1;

    /**
     * @var list<AccountEntry>
     */
    private array $newAccountEntries = [];

    private function __construct(
        AccountId $id,
        AccountName $name,
        AccountDescription $description,
        DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->description = $description;
        $this->createdAt = $createdAt;
        $this->credit = new Amount(0);
        $this->debit = new Amount(0);

        $this->addDomainEvent(
            new AccountCreated(
                $this->id,
                $this->name,
                Clock::get()->now(),
            ),
        );
    }

    public static function create(
        CreateAccountDto $newAccountDto,
    ): self {
        return new self(
            $newAccountDto->id,
            $newAccountDto->name,
            $newAccountDto->description,
            $newAccountDto->createdAt,
        );
    }

    public function recordDebit(AddAccountDebitDto $dto): void
    {
        $accountDebitEntry = AccountEntry::createDebit(
            new CreateAccountEntryDto(
                $dto->accountEntryId,
                $this->id,
                $dto->effectiveAt,
                $dto->debit,
                Clock::get()->now(),
            ),
        );
        $this->debit = $this->debit->addAmount($dto->debit);
        $this->addDomainEvent(
            new AccountDebitUpdated(
                $dto->accountEntryId,
                $this->id,
                $dto->debit,
                $dto->effectiveAt,
                Clock::get()->now(),
            ),
        );

        $this->newAccountEntries[] = $accountDebitEntry;
    }

    public function recordCredit(AddAccountCreditDto $dto): void
    {
        $accountCreditEntry = AccountEntry::createCredit(
            new CreateAccountEntryDto(
                $dto->accountEntryId,
                $this->id,
                $dto->effectiveAt,
                $dto->credit,
                Clock::get()->now(),
            ),
        );
        $this->credit = $this->credit->addAmount($dto->credit);
        $this->addDomainEvent(
            new AccountCreditUpdated(
                $dto->accountEntryId,
                $this->id,
                $dto->credit,
                $dto->effectiveAt,
                Clock::get()->now(),
            ),
        );

        $this->newAccountEntries[] = $accountCreditEntry;
    }

    public function getId(): AccountId
    {
        return $this->id;
    }

    public function credit(): AccountCredit
    {
        return AccountCredit::copy($this->credit);
    }

    public function debit(): AccountDebit
    {
        return AccountDebit::copy($this->debit);
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * @return list<AccountEntry>
     */
    public function getNewAccountEntries(): array
    {
        return $this->newAccountEntries;
    }
}
