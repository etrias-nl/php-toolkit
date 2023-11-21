<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Etrias\PhpToolkit\Messenger\MessageCache;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class CacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly MessageCache $messageCache,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null === $info = $this->messageCache->info($envelope)) {
            return $stack->next()->handle($envelope, $stack);
        }

        return $this->messageCache->get($info->key, static function (ItemInterface $item) use ($info, $envelope, $stack): Envelope {
            $item->tag($info->tags);

            if ($info->ttl instanceof \DateTimeInterface) {
                $item->expiresAt($info->ttl);
            } else {
                $item->expiresAfter($info->ttl);
            }

            return $stack->next()->handle($envelope, $stack);
        });
    }
}
