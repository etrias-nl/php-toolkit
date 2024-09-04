<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Messenger;

use Etrias\PhpToolkit\Messenger\Middleware\SecurityMiddleware;
use Etrias\PhpToolkit\Messenger\Stamp\SecurityStamp;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallContext;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\ServiceLocator;
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
        $userProviders = new ServiceLocator([
            'security.user.provider.concrete.user_provider' => static fn (): UserProviderInterface => new class implements UserProviderInterface {
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
            },
        ]);
        $requestStack = new RequestStack();
        $firewallMap = new FirewallMap(new ServiceLocator([
            'main' => static fn (): FirewallContext => new FirewallContext([], null, null, new FirewallConfig('main', 'user_checker', null, true, false, 'user_provider')),
        ]), [
            'main' => new MethodRequestMatcher([]),
        ]);
        $tokenStorage = new TokenStorage();
        $tokenStorageRecord = new TokenStorage();
        $logHandler = new TestHandler();
        $securityMiddleware = new SecurityMiddleware($tokenStorage, $firewallMap, $requestStack, $userProviders, new Logger('test', [$logHandler]));
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
        $envelopeStamp = new SecurityStamp($envelopeToken);
        $envelope = $bus->dispatch((object) ['test2' => true], [$envelopeStamp]);

        self::assertNull($tokenStorage->getToken());
        self::assertSame($envelopeToken, $tokenStorageRecord->getToken());
        self::assertSame($envelopeStamp, $envelope->last(SecurityStamp::class));

        $token = new PreAuthenticatedToken(new InMemoryUser('CurrentTestUser', null), 'main');
        $tokenStorage->setToken($token);
        $requestStack->push(new Request([], [], ['_firewall_context' => 'main']));

        $envelope = $bus->dispatch((object) ['test3' => true]);

        self::assertSame($token, $tokenStorage->getToken());
        self::assertSame($token, $tokenStorageRecord->getToken());
        self::assertNull($envelope->last(SecurityStamp::class));

        $envelopeToken = new PreAuthenticatedToken(new InMemoryUser('TestUser', null), 'main');
        $envelopeStamp = new SecurityStamp($envelopeToken);
        $envelope = $bus->dispatch((object) ['test4' => true], [$envelopeStamp]);

        self::assertSame($token, $tokenStorage->getToken());
        self::assertSame($envelopeToken, $tokenStorageRecord->getToken());
        self::assertSame($envelopeStamp, $envelope->last(SecurityStamp::class));

        $envelopeStamp = new SecurityStamp(null);
        $envelope = $bus->dispatch((object) ['test5' => true], [$envelopeStamp]);

        self::assertSame($token, $tokenStorage->getToken());
        self::assertNull($tokenStorageRecord->getToken());
        self::assertSame($envelopeStamp, $envelope->last(SecurityStamp::class));

        self::assertStringMatchesFormat(
            <<<'TXT'
                [%a] test.INFO: Logged in as "{user}" {"user":"TestUser","previousUser":null} []
                [%a] test.INFO: Logged out as "{previousUser}" {"previousUser":"TestUser"} []
                [%a] test.INFO: Logged in as "{user}" {"user":"TestUser","previousUser":"CurrentTestUser"} []
                [%a] test.INFO: Logged in as "{user}" {"user":"CurrentTestUser","previousUser":"TestUser"} []
                [%a] test.INFO: Logged out as "{previousUser}" {"previousUser":"CurrentTestUser"} []
                [%a] test.INFO: Logged in as "{user}" {"user":"CurrentTestUser","previousUser":null} []
                TXT,
            implode("\n", array_map(static fn (LogRecord $record): string => trim((string) $record->formatted), $logHandler->getRecords()))
        );
    }
}
