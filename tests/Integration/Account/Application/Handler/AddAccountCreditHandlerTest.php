<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account\Application\Handler;

use App\Account\Application\Command\AddAccountEntryCreditCommand;
use App\Account\Application\Handler\AddAccountCreditHandler;
use App\Account\Domain\Dto\CreateAccountDto;
use App\Account\Domain\Entity\Account;
use App\Account\Domain\Event\AccountCreditUpdated;
use App\Account\Domain\Repository\AccountRepository;
use App\Account\Domain\ValueObject\AccountCredit;
use App\Account\Domain\ValueObject\AccountDescription;
use App\Account\Domain\ValueObject\AccountEntryId;
use App\Account\Domain\ValueObject\AccountId;
use App\Account\Domain\ValueObject\AccountName;
use App\Tests\Support\Messenger\DispatchedMessage;
use App\Tests\Support\Messenger\DispatchedMessagesAssertionTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class AddAccountCreditHandlerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DispatchedMessagesAssertionTrait;

    private AddAccountCreditHandler $handler;
    private AccountRepository $accountRepository;
    private EntityManagerInterface $entityManager;
    private AccountId $accountId;
    private DateTimeImmutable $effectiveAt;

    protected function setUp(): void
    {
        self::mockTime('2026-10-12 10:00:00');
        $this->handler = self::getContainer()->get(AddAccountCreditHandler::class);
        $this->accountRepository = self::getContainer()->get(AccountRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->accountId = new AccountId('9fc62b77-8dc6-4493-b245-6020d30573bd');
        $this->effectiveAt = new DateTimeImmutable('2026-10-01 12:00:00');
    }

    #[Test]
    public function recordsCreditEntryForTheAccount(): void
    {
        // given
        $this->createAccount();
        $entryId = new AccountEntryId();

        // when
        $this->handler->__invoke($this->command(150, $entryId));

        // then
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT account_id, amount, side, effective_at, created_at FROM account_entry WHERE id = :id',
            ['id' => $entryId->toRfc4122()],
        );
        self::assertSame(
            [
                'account_id' => $this->accountId->toRfc4122(),
                'amount' => 150,
                'side' => 'credit',
                'effective_at' => '2026-10-01 10:00:00+00',
                'created_at' => '2026-10-12 08:00:00+00',
            ],
            $row,
        );
    }

    #[Test]
    public function publishesCreditUpdatedEventToRabbit(): void
    {
        // given
        $this->createAccount();
        $entryId = new AccountEntryId();

        // when
        $this->handler->__invoke($this->command(150, $entryId));

        // then
        self::assertDispatchedMessages(
            [
                new DispatchedMessage(
                    new AccountCreditUpdated(
                        $entryId,
                        $this->accountId,
                        new AccountCredit(150),
                        $this->effectiveAt,
                        new DateTimeImmutable('2026-10-12 10:00:00'),
                    ),
                    'rabbit',
                ),
            ],
            'event.bus',
        );
    }

    #[Test]
    public function rejectsCommandForUnknownAccountWithoutRetry(): void
    {
        // given
        $entryId = new AccountEntryId();

        // when
        try {
            $this->handler->__invoke($this->command(150, $entryId));
            self::fail('UnrecoverableMessageHandlingException was not thrown');
        } catch (UnrecoverableMessageHandlingException $exception) {
            // then
            self::assertSame('Account not found', $exception->getMessage());
        }

        self::assertSame([], self::getDispatchedMessages('event.bus'));
        self::assertFalse($this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM account_entry WHERE id = :id',
            ['id' => $entryId->toRfc4122()],
        ));
    }

    private function createAccount(): void
    {
        $this->accountRepository->new(Account::create(
            new CreateAccountDto(
                $this->accountId,
                new AccountName('Savings'),
                new AccountDescription('savings'),
                Clock::get()->now(),
            ),
        ));
        $this->entityManager->clear();
    }

    private function command(int $amount, ?AccountEntryId $entryId = null): AddAccountEntryCreditCommand
    {
        return new AddAccountEntryCreditCommand(
            accountEntryId: $entryId ?? new AccountEntryId(),
            accountId: $this->accountId,
            credit: new AccountCredit($amount),
            effectiveAt: $this->effectiveAt,
        );
    }
}
