<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Messenger;

use Etrias\PhpToolkit\Messenger\Middleware\SecurityMiddleware;
use Etrias\PhpToolkit\Messenger\Stamp\SecurityStamp;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * @internal
 */
final class SecurityTest extends TestCase
{
    public function testMiddleware(): void
    {
        $tokenStorage = new TokenStorage();
        $tokenStorageRecord = new TokenStorage();
        $securityMiddleware = new SecurityMiddleware($tokenStorage, new FirewallMap(new Container(), []), new RequestStack(), new Container());
        $envelopeMiddleware = new class($tokenStorage, $tokenStorageRecord) implements MiddlewareInterface {
            public function __construct(
                private readonly TokenStorage $tokenStorage,
                private readonly TokenStorage $tokenStorageRecord,
            ) {}

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $this->tokenStorageRecord->setToken($this->tokenStorage->getToken());

                return $stack->next()->handle($envelope, $stack);
            }
        };
        $bus = new MessageBus([$securityMiddleware, $envelopeMiddleware]);

        $envelope = $bus->dispatch((object) ['test1' => true]);

        self::assertNull($tokenStorage->getToken());
        self::assertNull($tokenStorageRecord->getToken());
        self::assertNull($envelope->last(SecurityStamp::class));

        $envelopeToken = new PreAuthenticatedToken(new InMemoryUser('TestUser', null), 'main');
        $envelope = $bus->dispatch((object) ['test2' => true], [new SecurityStamp($envelopeToken)]);

        self::assertNull($tokenStorage->getToken());
        self::assertSame($envelopeToken, $tokenStorageRecord->getToken());
        self::assertSame($envelopeToken, $envelope->last(SecurityStamp::class)?->token);

        $token = new PreAuthenticatedToken(new InMemoryUser('CurrentTestUser', null), 'main');
        $tokenStorage->setToken($token);

        $envelope = $bus->dispatch((object) ['test3' => true]);

        self::assertSame($token, $tokenStorage->getToken());
        self::assertSame($token, $tokenStorageRecord->getToken());
        self::assertNull($envelope->last(SecurityStamp::class));

        $envelopeToken = new PreAuthenticatedToken(new InMemoryUser('TestUser', null), 'main');
        $envelope = $bus->dispatch((object) ['test2' => true], [new SecurityStamp($envelopeToken)]);

        self::assertSame($token, $tokenStorage->getToken());
        self::assertSame($envelopeToken, $tokenStorageRecord->getToken());
        self::assertSame($envelopeToken, $envelope->last(SecurityStamp::class)?->token);
    }
}
