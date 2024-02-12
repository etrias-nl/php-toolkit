<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Etrias\PhpToolkit\Messenger\MessageMap;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * @internal
 *
 * @see https://github.com/symfony/symfony/issues/51993
 * @see https://github.com/symfony/symfony/pull/53809
 */
final class DoctrineTransactionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly MessageMap $messageMap,
        private readonly ManagerRegistry $managerRegistry,
        private readonly ?string $entityManagerName = null,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null === $envelope->last(ConsumedByWorkerStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $this->managerRegistry->getManager($this->entityManagerName);
        $connection = $entityManager->getConnection();

        $connection->beginTransaction();

        try {
            $envelope = $stack->next()->handle($envelope, $stack);
            $entityManager->flush();
            $connection->commit();

            return $envelope;
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            if ($exception instanceof HandlerFailedException) {
                // Remove all HandledStamp from the envelope so the retry will execute all handlers again.
                // When a handler fails, the queries of allegedly successful previous handlers just got rolled back.
                throw new HandlerFailedException($exception->getEnvelope()->withoutAll(HandledStamp::class), $exception->getWrappedExceptions());
            }

            throw $exception;
        }
    }
}
