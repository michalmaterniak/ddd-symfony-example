<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Exception;

use App\Shared\Infrastructure\Http\EventListener\ExceptionListener;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Rendered as 404 with the list of violations by {@see ExceptionListener}.
 */
class UrlParametersValidationException extends NotFoundHttpException
{
    public function __construct(
        private readonly ConstraintViolationListInterface $violations,
    ) {
        $messages = [];
        /** @var ConstraintViolationInterface $violation */
        foreach ($violations as $violation) {
            $messages[] = $violation->getPropertyPath().': '.$violation->getMessage();
        }

        parent::__construct(implode("\n", $messages));
    }

    public function getViolations(): ConstraintViolationListInterface
    {
        return $this->violations;
    }
}
