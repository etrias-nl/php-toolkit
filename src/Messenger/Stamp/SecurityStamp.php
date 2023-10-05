<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class SecurityStamp implements StampInterface
{
    public function __construct(
        public readonly TokenInterface $token,
        public readonly ?string $userProvider = null,
    ) {}
}
