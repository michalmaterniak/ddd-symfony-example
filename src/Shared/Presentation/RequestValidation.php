<?php

declare(strict_types=1);

namespace App\Shared\Presentation;

use Symfony\Component\Validator\ConstraintViolationListInterface;

interface RequestValidation
{
    public function validate(): ConstraintViolationListInterface;
}
