<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

#[AsMonologProcessor]
readonly class CorrelationIdProcessor implements ProcessorInterface
{
    public function __construct(
        private CorrelationIdStorage $correlationIdStorage,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $correlationId = $this->correlationIdStorage->get();
        if (null !== $correlationId) {
            $record->extra['correlationId'] = $correlationId;
        }

        return $record;
    }
}
