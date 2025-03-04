<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Test;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

#[AsMessageHandler]
final class DummyMessageHandler
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly ?EntityManagerInterface $entityManager = null,
    ) {}

    public function __invoke(DummyMessage $message): void
    {
        $this->logger->info('HANDLING MESSAGE: '.json_encode($message->payload, JSON_THROW_ON_ERROR));

        if ($message->sleep > 0) {
            $this->logger->info('Sleeping for '.$message->sleep.'s');
            $ms = (int) ($message->sleep * 1_000_000);
            $start = microtime(true);

            while ((microtime(true) - $start) * 1_000_000 < $ms);
        }

        if (null !== $this->entityManager) {
            $this->logger->info('SQL NOW(): '.$this->entityManager->getConnection()->executeQuery('select now()')->fetchOne());
        } else {
            $this->logger->info('SQL USAGE NOT TESTED');
        }

        if ($message->nest) {
            $this->logger->info('Dispatching nested message');
            $this->messageBus->dispatch(
                new DummyMessage(['NESTED' => $message->payload], $message->sleep, $message->nestFailure),
                $message->nestSync ? [new TransportNamesStamp('sync')] : []
            );
        }

        if ($message->failure) {
            throw new \RuntimeException(json_encode($message->payload, JSON_THROW_ON_ERROR));
        }

        $this->logger->info('DONE');
    }
}
