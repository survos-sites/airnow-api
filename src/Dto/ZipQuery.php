<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ZipQuery
{
    public function __construct(
        #[Assert\Regex('/^[0-9]{5}$/D')] public ?string $zipcode = null,
        #[Assert\Regex('/^[0-9]{5}$/D')] public ?string $zipCode = null,
        #[Assert\Choice(choices: ['application/json'])] public string $format = 'application/json',
    ) {}
}
