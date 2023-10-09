<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Etrias\PhpToolkit\Messenger\Stamp\SecurityStamp;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class SecurityMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        #[Autowire(service: 'security.firewall.map')]
        private readonly FirewallMap $firewallMap,
        private readonly RequestStack $requestStack,
        private readonly ContainerInterface $userProviders,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $prevToken = $this->tokenStorage->getToken();

        if (null === $stamp = $envelope->last(SecurityStamp::class)) {
            $stamped = false;
            $newToken = $prevToken;

            if (null !== $prevToken) {
                $envelope = $envelope->with(new SecurityStamp($prevToken, $this->getFirewallConfig($prevToken)?->getProvider()));
            }
        } else {
            $stamped = true;
            $newToken = $stamp->token;

            if (null !== $userProvider = $stamp->userProvider ?? $this->getFirewallConfig($newToken)?->getProvider()) {
                $userProvider = $this->getUserProvider($userProvider);

                if (null === $tokenUser = $newToken->getUser()) {
                    $tokenUser = $userProvider->loadUserByIdentifier($newToken->getUserIdentifier());
                } else {
                    $tokenUser = $userProvider->refreshUser($tokenUser);
                }

                $newToken->setUser($tokenUser);
            }
        }

        $this->tokenStorage->setToken($newToken);

        try {
            $envelope = $stack->next()->handle($envelope, $stack);

            return $stamped ? $envelope : $envelope->withoutAll(SecurityStamp::class);
        } finally {
            $this->tokenStorage->setToken($prevToken);
        }
    }

    private function getFirewallConfig(TokenInterface $token): ?FirewallConfig
    {
        if (method_exists($token, 'getFirewallName')) {
            return $this->firewallMap->getFirewallConfig(new Request([], [], ['_firewall_context' => 'security.firewall.map.context.'.$token->getFirewallName()]));
        }

        if (null !== $request = $this->requestStack->getMainRequest()) {
            return $this->firewallMap->getFirewallConfig($request);
        }

        return null;
    }

    private function getUserProvider(string $id): UserProviderInterface
    {
        if (!str_starts_with($id, $prefix = 'security.user.provider.concrete.')) {
            $id = $prefix.$id;
        }

        return $this->userProviders->get($id);
    }
}
