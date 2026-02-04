<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Etrias\PhpToolkit\Messenger\MessageMap;
use Etrias\PhpToolkit\Messenger\Stamp\NewRelicStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

final class NewRelicMiddleware implements MiddlewareInterface
{
    private bool $transactionActive = false;

    public function __construct(
        private readonly MessageMap $messageMap,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($this->transactionActive || null === $envelope->last(ConsumedByWorkerStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $newRelic = $this->messageMap->getStamp($envelope, NewRelicStamp::class) ?? new NewRelicStamp();

        if (!$newRelic->enabled) {
            return $stack->next()->handle($envelope, $stack);
        }

        try {
            newrelic_start_transaction((string) \ini_get('newrelic.appname'));
            newrelic_name_transaction($envelope->getMessage()::class);
            newrelic_background_job();
            newrelic_add_custom_parameter('message_id', $envelope->last(TransportMessageIdStamp::class)?->getId() ?? spl_object_hash($envelope));

            $this->transactionActive = true;

            return $stack->next()->handle($envelope, $stack);
        } finally {
            newrelic_end_transaction();
            $this->transactionActive = false;
        }
    }
}
