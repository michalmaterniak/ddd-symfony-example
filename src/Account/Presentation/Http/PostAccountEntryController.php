<?php

declare(strict_types=1);

namespace App\Account\Presentation\Http;

use App\Account\Application\Command\AddAccountEntryCreditCommand;
use App\Account\Application\Command\AddAccountEntryDebitCommand;
use App\Account\Domain\Entity\Account;
use App\Account\Domain\Repository\AccountRepository;
use App\Account\Domain\ValueObject\AccountCredit;
use App\Account\Domain\ValueObject\AccountDebit;
use App\Account\Domain\ValueObject\AccountEntryId;
use App\Account\Domain\ValueObject\AccountEntrySide;
use App\Account\Presentation\Http\Request\PostAddAccountEntryRequest;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PostAccountEntryController extends AbstractController
{
    public function __construct(
        private AccountRepository $accountRepository,
        private readonly CommandBus $commandBus,
    ) {
    }

    #[Route('/api/v1/account/{accountId}/account-entry', name: 'api_v1_account_entry_add', methods: ['POST'])]
    public function add(PostAddAccountEntryRequest $request): JsonResponse
    {
        $account = $this->accountRepository->findById($request->getAccountId());
        if (false === $account instanceof Account) {
            throw $this->createNotFoundException(
                \sprintf('Account with id "%s" not found', $request->getAccountId()),
            );
        }

        $accountEntryId = new AccountEntryId();

        $this->commandBus->dispatch(match ($request->getSide()) {
            AccountEntrySide::CREDIT => new AddAccountEntryCreditCommand(
                accountEntryId: $accountEntryId,
                accountId: $request->getAccountId(),
                credit: new AccountCredit($request->getAmount()),
                effectiveAt: $request->getEffectiveAt(),
            ),
            AccountEntrySide::DEBIT => new AddAccountEntryDebitCommand(
                accountEntryId: $accountEntryId,
                accountId: $request->getAccountId(),
                debit: new AccountDebit($request->getAmount()),
                effectiveAt: $request->getEffectiveAt(),
            ),
        });

        return $this->json(
            [
                'id' => $accountEntryId->toString(),
            ],
            Response::HTTP_ACCEPTED,
        );
    }
}
