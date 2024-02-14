<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Etrias\PhpToolkit\Messenger\Stamp\SecurityStamp;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

final class SecurityMiddleware implements MiddlewareInterface
{
    private ?string $currentUserProviderId = null;
    private readonly ?string $defaultUserProviderId;

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        #[Autowire(service: 'security.firewall.map')]
        private readonly FirewallMap $firewallMap,
        private readonly RequestStack $requestStack,
        private readonly ServiceProviderInterface $userProviders,
        #[Target(name: 'messenger.logger')]
        private readonly LoggerInterface $logger,
        ?string $defaultUserProviderId = null,
    ) {
        if (null === $defaultUserProviderId && 1 === \count($userProviderServices = $this->userProviders->getProvidedServices())) {
            $defaultUserProviderId = array_key_first($userProviderServices);
        }

        // @todo default to ChainUserProvider
        $this->defaultUserProviderId = $defaultUserProviderId;
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $prevToken = $this->tokenStorage->getToken();

        if (null === $stamp = $envelope->last(SecurityStamp::class)) {
            $stamped = false;
            $newToken = $prevToken;

            if (null !== $prevToken) {
                $envelope = $envelope->with(new SecurityStamp($prevToken, $this->currentUserProviderId ?? $this->getUserProviderId()));
            }
        } else {
            $stamped = true;
            $newToken = $stamp->token;

            if (null !== $newToken) {
                $userProvider = $this->getUserProvider($stamp->userProvider ?? $this->getUserProviderId());

                try {
                    if (null === $tokenUser = $newToken->getUser()) {
                        $tokenUser = $userProvider->loadUserByIdentifier($newToken->getUserIdentifier());
                    } else {
                        $tokenUser = $userProvider->refreshUser($tokenUser);
                    }

                    $newToken->setUser($tokenUser);
                } catch (\Exception $e) {
                    $this->logger->warning('Cannot load/refresh user for token', ['exception' => $e, 'token' => $newToken::class]);
                }
            }

            $this->currentUserProviderId = $stamp->userProvider;
        }

        $this->authenticate($prevToken, $newToken);

        try {
            $envelope = $stack->next()->handle($envelope, $stack);

            return $stamped ? $envelope : $envelope->withoutAll(SecurityStamp::class);
        } finally {
            $this->authenticate($newToken, $prevToken);
            $this->currentUserProviderId = null;
        }
    }

    private function authenticate(?TokenInterface $prevToken, ?TokenInterface $newToken): void
    {
        if ($newToken === $prevToken) {
            return;
        }

        $this->tokenStorage->setToken($newToken);

        if (null === $newToken) {
            $this->logger->info('Logged out as "{previousUser}"', ['previousUser' => $prevToken?->getUserIdentifier()]);
        } else {
            $this->logger->info('Logged in as "{user}"', ['user' => $newToken->getUserIdentifier(), 'previousUser' => $prevToken?->getUserIdentifier()]);
        }
    }

    private function getUserProviderId(): string
    {
        if (null === $request = $this->requestStack->getMainRequest()) {
            return $this->defaultUserProviderId ?? throw new \RuntimeException('Cannot determine user provider without a request, nor a default.');
        }
        if (null === $firewallConfig = $this->firewallMap->getFirewallConfig($request)) {
            return $this->defaultUserProviderId ?? throw new \RuntimeException('Cannot determine user provider without a firewall config, nor a default.');
        }

        return $firewallConfig->getProvider() ?? $this->defaultUserProviderId ?? throw new \RuntimeException(sprintf('Firewall config "%s" does not have a user provider, nor is a default available.', $firewallConfig->getName()));
    }

    private function getUserProvider(string $id): UserProviderInterface
    {
        if (!str_starts_with($id, $prefix = 'security.user.provider.concrete.')) {
            $id = $prefix.$id;
        }

        return $this->userProviders->get($id) ?? throw new \RuntimeException(sprintf('User provider "%s" not found.', $id));
    }
}
