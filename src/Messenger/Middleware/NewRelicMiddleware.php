<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Process\Messenger\RunProcessMessage;

final class NewRelicMiddleware implements MiddlewareInterface
{
    private bool $transactionActive = false;

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($this->transactionActive || null === $envelope->last(ConsumedByWorkerStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $message = $envelope->getMessage();

        try {
            newrelic_start_transaction((string) \ini_get('newrelic.appname'));
            newrelic_name_transaction($message::class);
            newrelic_background_job();

            if ($message instanceof RunProcessMessage) {
                newrelic_add_custom_parameter('command', implode(' ', $message->command));
            }

            $this->transactionActive = true;

            return $stack->next()->handle($envelope, $stack);
        } finally {
            newrelic_end_transaction();
            $this->transactionActive = false;
        }
    }
}
