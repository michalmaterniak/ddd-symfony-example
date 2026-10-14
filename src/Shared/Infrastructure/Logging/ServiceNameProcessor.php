<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lets logs of this service be told apart from other services' logs sharing the same correlation id.
 */
#[AsMonologProcessor]
readonly class ServiceNameProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(env: 'APP_SERVICE_NAME')]
        private string $serviceName,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['service'] = $this->serviceName;

        return $record;
    }
}
