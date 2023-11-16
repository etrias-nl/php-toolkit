<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class CacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        #[Target(name: 'messenger.cache')]
        private readonly CacheItemPoolInterface $cache,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        return $stack->next()->handle($envelope, $stack);
    }
}
