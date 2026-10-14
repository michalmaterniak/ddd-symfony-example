<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Infrastructure\Console;

use App\Account\Domain\Event\AccountCreated;
use App\Shared\Infrastructure\Messenger\Stamp\CorrelationIdStamp;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class ConsoleCorrelationIdTest extends KernelTestCase
{
    #[Test]
    public function messagesDispatchedByCommandRunShareItsCorrelationId(): void
    {
        // given: run through the application, so console events are dispatched (unlike CommandTester)
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        // when
        $status = $tester->run(['command' => 'app:account:create', 'name' => 'Savings', 'description' => 'savings']);

        // then: the command was handled synchronously, its event went to rabbit with the run's correlation id
        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $transport = self::getContainer()->get('messenger.transport.rabbit');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $sent = [...$transport->getSent()];
        self::assertCount(1, $sent);
        self::assertInstanceOf(AccountCreated::class, $sent[0]->getMessage());
        $correlationId = $sent[0]->last(CorrelationIdStamp::class)?->correlationId;
        self::assertNotNull($correlationId);
        self::assertTrue(Uuid::isValid($correlationId));
    }
}
