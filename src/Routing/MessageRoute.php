<?php

namespace Morebec\Orkestra\Messaging\Routing;

use Morebec\Orkestra\Messaging\MessageHeaders;
use Morebec\Orkestra\Messaging\MessageInterface;

/**
 * Represents a route for a given {@link MessageInterface} to a {@link MessageHandlerInterface}.
 */
class MessageRoute implements MessageRouteInterface
{
    private string $messageTypeName;

    private string $messageHandlerClassName;

    private string $messageHandlerMethodName;

    public function __construct(
        string $messageTypeName,
        string $messageHandlerClassName,
        string $messageHandlerMethodName
    ) {
        $this->messageTypeName = $messageTypeName;
        $this->messageHandlerClassName = $messageHandlerClassName;
        $this->messageHandlerMethodName = $messageHandlerMethodName;
    }

    public function __toString(): string
    {
        return $this->getId();
    }

    /**
     * Indicates if this route matches a certain message.
     */
    public function matches(MessageInterface $message, MessageHeaders $headers): bool
    {
        return $this->messageTypeName === $message::getTypeName() || $this->messageTypeName === MessageInterface::class;
    }

    public function getId(): string
    {
        return "{$this->messageTypeName} => {$this->messageHandlerClassName}::{$this->messageHandlerMethodName}";
    }

    public function getMessageTypeName(): string
    {
        return $this->messageTypeName;
    }

    public function getMessageHandlerMethodName(): string
    {
        return $this->messageHandlerMethodName;
    }

    public function getMessageHandlerClassName(): string
    {
        return $this->messageHandlerClassName;
    }
}
