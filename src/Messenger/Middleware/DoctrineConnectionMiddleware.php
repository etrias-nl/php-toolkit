<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Etrias\PhpToolkit\Messenger\MessageMap;
use Etrias\PhpToolkit\Messenger\Stamp\TransactionalStamp;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * @internal
 */
final class DoctrineConnectionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly MessageMap $messageMap,
        private readonly ManagerRegistry $managerRegistry,
        private readonly int $waitTimeout = 28800,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null === $envelope->last(ConsumedByWorkerStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $transactional = $this->messageMap->getStamp($envelope, TransactionalStamp::class);

        if (null === $transactional || !$transactional->enabled) {
            $this->setWaitTimeout();

            try {
                return $stack->next()->handle($envelope, $stack);
            } finally {
                $this->closeConnections();
            }
        }

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $this->managerRegistry->getManager($transactional->entityManagerName);
        $connection = $entityManager->getConnection();

        $connection->beginTransaction();

        $this->setWaitTimeout();

        try {
            $envelope = $stack->next()->handle($envelope, $stack);
            $entityManager->flush();
            $connection->commit();

            return $envelope;
        } catch (\Throwable $exception) {
            try {
                $connection->rollBack();
            } catch (\Throwable $rollbackException) {
                $this->logger->error('An error occurred while rolling back the transaction', [
                    'exception' => $rollbackException,
                ]);
            }

            if ($exception instanceof HandlerFailedException) {
                // Remove all HandledStamp from the envelope so the retry will execute all handlers again.
                // When a handler fails, the queries of allegedly successful previous handlers just got rolled back.
                throw new HandlerFailedException($exception->getEnvelope()->withoutAll(HandledStamp::class), $exception->getWrappedExceptions());
            }

            throw $exception;
        } finally {
            $this->closeConnections();
        }
    }

    private function setWaitTimeout(): void
    {
        /** @var Connection $connection */
        foreach ($this->managerRegistry->getConnections() as $connection) {
            $connection->executeQuery('SET session wait_timeout = '.$this->waitTimeout);
            $connection->executeQuery('SET session interactive_timeout = '.$this->waitTimeout);
        }
    }

    private function closeConnections(): void
    {
        /** @var Connection $connection */
        foreach ($this->managerRegistry->getConnections() as $connection) {
            $connection->close();
        }
    }
}
