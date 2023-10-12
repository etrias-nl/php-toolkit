<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Messenger;

use Etrias\PhpToolkit\Messenger\Middleware\SecurityMiddleware;
use Etrias\PhpToolkit\Messenger\Stamp\SecurityStamp;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallContext;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @internal
 */
final class SecurityTest extends TestCase
{
    public function testMiddleware(): void
    {
        $userProviders = new Container();
        $userProviders->set('security.user.provider.concrete.user_provider', new class() implements UserProviderInterface {
            public function refreshUser(UserInterface $user): UserInterface
            {
                return $user;
            }

            public function supportsClass(string $class): bool
            {
                return true;
            }

            public function loadUserByIdentifier(string $identifier): UserInterface
            {
                return new InMemoryUser($identifier, null);
            }
        });
        $requestStack = new RequestStack();
        $firewallConfigs = new Container();
        $firewallConfigs->set('main', new FirewallContext([], null, null, new FirewallConfig('main', 'user_checker', null, true, false, 'user_provider')));
        $firewallMap = new FirewallMap($firewallConfigs, ['main' => new MethodRequestMatcher([])]);
        $tokenStorage = new TokenStorage();
        $tokenStorageRecord = new TokenStorage();
        $securityMiddleware = new SecurityMiddleware($tokenStorage, $firewallMap, $requestStack, $userProviders);
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
        $envelope = $bus->dispatch((object) ['test2' => true], [new SecurityStamp($envelopeToken, 'user_provider')]);

        self::assertNull($tokenStorage->getToken());
        self::assertSame($envelopeToken, $tokenStorageRecord->getToken());
        self::assertSame($envelopeToken, $envelope->last(SecurityStamp::class)?->token);

        $token = new PreAuthenticatedToken(new InMemoryUser('CurrentTestUser', null), 'main');
        $tokenStorage->setToken($token);
        $requestStack->push(new Request([], [], ['_firewall_context' => 'main']));

        $envelope = $bus->dispatch((object) ['test3' => true]);

        self::assertSame($token, $tokenStorage->getToken());
        self::assertSame($token, $tokenStorageRecord->getToken());
        self::assertNull($envelope->last(SecurityStamp::class));

        $envelopeToken = new PreAuthenticatedToken(new InMemoryUser('TestUser', null), 'main');
        $envelope = $bus->dispatch((object) ['test4' => true], [new SecurityStamp($envelopeToken)]);

        self::assertSame($token, $tokenStorage->getToken());
        self::assertSame($envelopeToken, $tokenStorageRecord->getToken());
        self::assertSame($envelopeToken, $envelope->last(SecurityStamp::class)?->token);
    }
}
