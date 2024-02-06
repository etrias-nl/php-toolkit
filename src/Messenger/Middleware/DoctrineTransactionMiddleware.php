<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Messenger\DoctrineTransactionMiddleware as BaseDoctrineTransactionMiddleware;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * @internal
 *
 * @see https://github.com/symfony/symfony/issues/51993
 * @see https://github.com/symfony/symfony/pull/53809
 */
final class DoctrineTransactionMiddleware extends BaseDoctrineTransactionMiddleware
{
    protected function handleForManager(EntityManagerInterface $entityManager, Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null === $envelope->last(ConsumedByWorkerStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $entityManager->getConnection()->beginTransaction();

        try {
            $envelope = $stack->next()->handle($envelope, $stack);
            $entityManager->flush();
            $entityManager->getConnection()->commit();

            return $envelope;
        } catch (\Throwable $exception) {
            try {
                $entityManager->getConnection()->rollBack();
            } catch (\Throwable) {
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
