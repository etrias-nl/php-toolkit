<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\DependencyInjection;

use Etrias\PhpToolkit\Messenger\Attribute\WithTransport;
use Etrias\PhpToolkit\Messenger\MessageMap;
use Etrias\PhpToolkit\Messenger\MessageMonitor;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MessengerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $sendersLocator = $container->getDefinition('messenger.senders_locator');
        $messageMap = $sendersLocator->getArgument(0);
        $transportOptions = [];

        foreach ($messageMap as $messageClass => $transports) {
            foreach ($transports as $transport) {
                $transportOptions[$transport][$messageClass] = [];
            }
        }

        foreach ($container->findTaggedServiceIds('messenger.bus') as $id => $_) {
            $handlersLocator = $container->getDefinition($id.'.messenger.handlers_locator');
            foreach ($handlersLocator->getArgument(0) as $messageClass => $_) {
                $transportAttributes = $container->getReflectionClass($messageClass)?->getAttributes(WithTransport::class) ?? [];

                if (!$transportAttributes) {
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
                    $transportOptions[$messageTransportMetadata->name][$messageClass] = $messageTransportMetadata->options;
                }
            }
        }

        $sendersLocator->replaceArgument(0, $messageMap);

        $container->register(MessageMap::class, MessageMap::class)
            ->setArgument('$mapping', $messageMap)
            ->setArgument('$transportOptions', $transportOptions)
        ;

        $container->autowire(MessageMonitor::class, MessageMonitor::class);
    }
}
