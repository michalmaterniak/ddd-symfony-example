<?php

declare(strict_types=1);

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context): Kernel {
    /** @var array{APP_ENV: string, APP_DEBUG: bool|string} $context */
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
