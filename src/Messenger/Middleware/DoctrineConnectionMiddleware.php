<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ConnectionRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;

/**
 * @internal
 */
final class DoctrineConnectionMiddleware implements MiddlewareInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine')]
        private readonly ConnectionRegistry $connectionRegistry,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null === $envelope->last(ConsumedByWorkerStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            /** @var Connection $connection */
            foreach ($this->connectionRegistry->getConnections() as $connection) {
                $connection->close();
            }
        }
    }
}
