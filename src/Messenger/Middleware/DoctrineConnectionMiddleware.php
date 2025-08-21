<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Etrias\PhpToolkit\Messenger\MessageMap;
use Etrias\PhpToolkit\Messenger\Stamp\TransactionalStamp;
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
    /**
     * @see CommandLongRunningListener::__construct()
     */
    public function __construct(
        private readonly MessageMap $messageMap,
        private readonly ManagerRegistry $managerRegistry,
        private readonly int $waitTimeout = 28800,
        private readonly int $maxExecutionTime = 3600,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null === $envelope->last(ConsumedByWorkerStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $transactional = $this->messageMap->getStamp($envelope, TransactionalStamp::class) ?? new TransactionalStamp();

        if (!$transactional->enabled) {
            $this->setLimits();

            return $stack->next()->handle($envelope, $stack);
        }

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $this->managerRegistry->getManager($transactional->entityManagerName);
        $connection = $entityManager->getConnection();

        $connection->beginTransaction();
        $this->setLimits();

        $successful = false;

        try {
            $envelope = $stack->next()->handle($envelope, $stack);
            $entityManager->flush();
            $connection->commit();

            $successful = true;

            return $envelope;
        } catch (HandlerFailedException $exception) {
            // Remove all HandledStamp from the envelope so the retry will execute all handlers again.
            // When a handler fails, the queries of allegedly successful previous handlers just got rolled back.
            throw new HandlerFailedException($exception->getEnvelope()->withoutAll(HandledStamp::class), $exception->getWrappedExceptions());
        } finally {
            if (!$successful && $connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    /**
     * @see CommandLongRunningListener::setLimits()
     */
    private function setLimits(): void
    {
        /** @var Connection $connection */
        foreach ($this->managerRegistry->getConnections() as $connection) {
            foreach ([
                'SET SESSION wait_timeout = '.$this->waitTimeout,
                'SET SESSION interactive_timeout = '.$this->waitTimeout,
                'SET SESSION max_execution_time = '.($this->maxExecutionTime * 1000),
            ] as $query) {
                $connection->executeQuery($query);
            }
        }
    }
}
