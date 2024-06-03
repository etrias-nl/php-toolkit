<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\DependencyInjection;

use Etrias\PhpToolkit\Cache\Attribute\WithCacheInfoProvider;
use Etrias\PhpToolkit\Messenger\Attribute\WithTransport;
use Etrias\PhpToolkit\Messenger\MessageCache;
use Etrias\PhpToolkit\Messenger\MessageMap;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Stamp\StampInterface;

final class MessengerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $sendersLocator = $container->getDefinition('messenger.senders_locator');
        $messageMap = $sendersLocator->getArgument(0);
        $messageMapByTransport = [];
        $cacheInfoProviders = [];
        $defaultStamps = [];

        foreach ($messageMap as $messageClass => $transports) {
            foreach ($transports as $transport) {
                $messageMapByTransport[$transport][$messageClass] = [];
            }
        }

        foreach ($container->findTaggedServiceIds('messenger.bus') as $id => $_) {
            $handlersLocator = $container->getDefinition($id.'.messenger.handlers_locator');
            foreach ($handlersLocator->getArgument(0) as $messageClass => $_) {
                $reflectionClass = $container->getReflectionClass($messageClass);
                $cacheInfoProviderAttribute = $reflectionClass?->getAttributes(WithCacheInfoProvider::class)[0] ?? null;

                if ($cacheInfoProviderAttribute) {
                    $cacheInfoProviderMetadata = $cacheInfoProviderAttribute->newInstance();
                    $cacheInfoProviders[$messageClass] = new Reference($cacheInfoProviderMetadata->name);
                }

                foreach ($reflectionClass?->getAttributes(StampInterface::class, \ReflectionAttribute::IS_INSTANCEOF) ?? [] as $defaultStampAttribute) {
                    $defaultStamps[$messageClass][$defaultStampAttribute->getName()][] = new Definition($defaultStampAttribute->getName(), $defaultStampAttribute->getArguments());
                }

                $transportAttributes = $reflectionClass?->getAttributes(WithTransport::class) ?? [];

                if (!$transportAttributes) {
                    $messageMapByTransport[''][$messageClass] = [];

                    continue;
                }

                if (isset($messageMap[$messageClass])) {
                    throw new \LogicException('Existing sender mapping found for "'.$messageClass.'", cannot apply "'.WithTransport::class.'".');
                }

                $messageMap[$messageClass] = [];

                foreach ($transportAttributes as $transportAttribute) {
                    $messageTransportMetadata = $transportAttribute->newInstance();
                    if (\in_array($messageTransportMetadata->name, $messageMap[$messageClass], true)) {
                        throw new \LogicException('Duplicate "'.WithTransport::class.'" found for "'.$messageClass.'" and transport "'.$messageTransportMetadata->name.'".');
                    }

                    $messageMap[$messageClass][] = $messageTransportMetadata->name;
                    $messageMapByTransport[$messageTransportMetadata->name][$messageClass] = $messageTransportMetadata->options;
                }
            }
        }

        $sendersLocator->replaceArgument(0, $messageMap);

        $container->autowire(MessageCache::class, MessageCache::class)
            ->setArgument('$cacheInfoProviders', ServiceLocatorTagPass::register($container, $cacheInfoProviders))
        ;
        $container->autowire(MessageMap::class, MessageMap::class)
            ->setArgument('$mapping', $messageMapByTransport)
            ->setArgument('$defaultStamps', $defaultStamps)
        ;

        if ($container->has($globalFallbackId = 'messenger.transport.fallback')) {
            $globalFallbackDefinition = $container->getDefinition($globalFallbackId);
            $globalFallbackArguments = $globalFallbackDefinition->getArguments();

            foreach ($container->findTaggedServiceIds('messenger.receiver') as $id => $attr) {
                if ($id === $globalFallbackId) {
                    continue;
                }
                $alias = $attr[0]['alias'] ?? $id;
                $fallbackArguments = $globalFallbackArguments;
                $fallbackArguments[1]['queue_name'] = 'fallback_'.$alias;

                $definition = $container->getDefinition($id);
                $options = (array) $definition->getArgument(1);
                $options['fallback_transport'] = $fallbackArguments;

                $definition->setArgument(1, $options);
            }
        }
    }
}
