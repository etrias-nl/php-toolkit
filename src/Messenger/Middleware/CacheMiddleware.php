<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Etrias\PhpToolkit\Messenger\MessageCache;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
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

        $handledStamps = $this->messageCache->get($info->key, static function (ItemInterface $item) use ($info, $envelope, $stack): array {
            $result = $stack->next()->handle($envelope, $stack)->all(HandledStamp::class);
            $values = array_map(static fn (HandledStamp $stamp): mixed => $stamp->getResult(), $result);
            $tags = array_map(static fn (\Closure|string $tag): string => \is_string($tag) ? $tag : $tag(...$values), $info->tags);

            $item->tag($tags);

            if ($info->ttl instanceof \DateTimeInterface) {
                $item->expiresAt($info->ttl);
            } else {
                $item->expiresAfter($info->ttl);
            }

            return $result;
        });

        return $envelope->with(...$handledStamps);
    }
}
