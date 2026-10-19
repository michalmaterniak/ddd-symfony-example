<?php

declare(strict_types=1);

namespace App\Account\Presentation\Http\Request;

use App\Account\Domain\ValueObject\AccountEntrySide;
use App\Account\Domain\ValueObject\AccountId;
use App\Shared\Presentation\AbstractRequest;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\DateTime;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Sequentially;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Constraints\Uuid;

/**
 * @phpstan-type Payload array{side: string, amount: array{value: int, currency: string}, effectiveAt: string}
 * @phpstan-type Parameters array{accountId: string}
 *
 * @extends AbstractRequest<Payload, Parameters>
 */
class PostAddAccountEntryRequest extends AbstractRequest
{
    private const array CURRENCIES = ['PLN'];

    private const string PARAMETER_ACCOUNT_ID = 'accountId';

    private const string FIELD_SIDE = 'side';
    private const string FIELD_AMOUNT = 'amount';
    private const string FIELD_AMOUNT_VALUE = 'value';
    private const string FIELD_AMOUNT_CURRENCY = 'currency';
    private const string FIELD_EFFECTIVE_AT = 'effectiveAt';

    public function getAccountId(): AccountId
    {
        return new AccountId($this->parameters[self::PARAMETER_ACCOUNT_ID]);
    }

    public function getAmount(): int
    {
        return $this->payload[self::FIELD_AMOUNT][self::FIELD_AMOUNT_VALUE];
    }

    public function getSide(): AccountEntrySide
    {
        return AccountEntrySide::from($this->payload[self::FIELD_SIDE]);
    }

    public function getEffectiveAt(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->payload[self::FIELD_EFFECTIVE_AT]);
    }

    protected function getPayloadConstraints(): Collection
    {
        return new Collection(
            fields: [
                self::FIELD_SIDE => new Sequentially([
                    new NotBlank(),
                    new Type('string'),
                    new Choice(choices: array_column(AccountEntrySide::cases(), 'value')),
                ]),
                self::FIELD_AMOUNT => new Sequentially([
                    new NotNull(),
                    new Type('array'),
                    new Collection(
                        fields: [
                            self::FIELD_AMOUNT_VALUE => new Sequentially([
                                new NotNull(),
                                new Type('integer'),
                                new Positive(),
                            ]),
                            self::FIELD_AMOUNT_CURRENCY => new Sequentially([
                                new NotBlank(),
                                new Type('string'),
                                new Choice(choices: self::CURRENCIES),
                            ]),
                        ],
                        allowExtraFields: false,
                    ),
                ]),
                self::FIELD_EFFECTIVE_AT => new Sequentially([
                    new NotBlank(),
                    new Type('string'),
                    new DateTime(format: DateTimeInterface::ATOM),
                ]),
            ],
            allowExtraFields: false,
        );
    }

    protected function getParametersConstraints(): Collection
    {
        return new Collection([
            self::PARAMETER_ACCOUNT_ID => new Sequentially([
                new NotBlank(),
                new Type('string'),
                new Uuid(versions: Uuid::V4_RANDOM),
            ]),
        ]);
    }
}
