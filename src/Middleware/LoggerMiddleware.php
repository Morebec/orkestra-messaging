<?php

namespace Morebec\Orkestra\Messaging\Middleware;

use JsonException;
use Morebec\Orkestra\Messaging\MessageBusResponseInterface;
use Morebec\Orkestra\Messaging\MessageBusResponseStatusCode;
use Morebec\Orkestra\Messaging\MessageHandlerResponse;
use Morebec\Orkestra\Messaging\MessageHeaders;
use Morebec\Orkestra\Messaging\MessageInterface;
use Morebec\Orkestra\Messaging\MultiMessageHandlerResponse;
use Morebec\Orkestra\Messaging\Normalization\MessageNormalizerInterface;
use Morebec\Orkestra\Messaging\Routing\UnhandledMessageResponse;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Message Bus middleware logging messages and responses.
 * TODO: Add tests.
 */
class LoggerMiddleware implements MessageBusMiddlewareInterface
{
    private LoggerInterface $logger;

    private MessageNormalizerInterface $messageNormalizer;

    public function __construct(LoggerInterface $logger, MessageNormalizerInterface $messageNormalizer)
    {
        $this->logger = $logger;
        $this->messageNormalizer = $messageNormalizer;
    }

    public function __invoke(MessageInterface $message, MessageHeaders $headers, callable $next): MessageBusResponseInterface
    {
        $messageContext = $this->buildMessageContext($message, $headers);

        // Message has been received by the bus, but not yet executed.
        $this->logger->info('Received message "{messageTypeName}"', $messageContext);

        $response = $next($message, $headers);

        $this->handleResponse($message, $response, $messageContext);

        return $response;
    }

    /**
     * Builds the logging context for message and header.
     */
    private function buildMessageContext(MessageInterface $message, MessageHeaders $headers): array
    {
        return [
            'messageTypeName' => $headers->get(MessageHeaders::MESSAGE_TYPE_NAME, $message::getTypeName()),
            'messageType' => $headers->get(MessageHeaders::MESSAGE_TYPE),
            'message' => $this->normalizeMessage($message),
            'messageHeaders' => $headers->toArray(),
            'messageId' => $headers->get(MessageHeaders::MESSAGE_ID),
            'causationId' => $headers->get(MessageHeaders::CAUSATION_ID),
            'correlationId' => $headers->get(MessageHeaders::CORRELATION_ID),
        ];
    }

    /**
     * Builds the logging context for a response.
     */
    private function buildResponseContext(MessageBusResponseInterface $response): array
    {
        $context = [
            'responseStatusCode' => (string) $response->getStatusCode(),
        ];

        if ($response instanceof MessageHandlerResponse) {
            $context += [
                'messageHandler' => $response->getHandlerName(),
            ];
        }

        $payload = $response->getPayload();
        if ($payload instanceof Throwable) {
            $context += $this->buildThrowableContext($payload);
        }

        return $context;
    }

    /**
     * Builds the logger context for a throwable.
     */
    private function buildThrowableContext(Throwable $throwable): array
    {
        return [
            'exceptionClass' => \get_class($throwable),
            'exceptionMessage' => $throwable->getMessage(),
            'exceptionFile' => $throwable->getFile(),
            'exceptionLine' => $throwable->getLine(),
        ];
    }

    private function handleResponse(MessageInterface $message, MessageBusResponseInterface $response, array $messageContext): void
    {
        $responseContext = $this->buildResponseContext($response);
        $loggingContext = $messageContext + $responseContext;

        if ($response->getStatusCode()->isEqualTo(MessageBusResponseStatusCode::FAILED())) {
            if ($response instanceof MessageHandlerResponse) {
                $this->logger->error(
                    'Message Handler "{messageHandler}" Failed for message of type - "{messageTypeName}" - "{exceptionMessage}".',
                    $loggingContext
                );
            } elseif ($response instanceof MultiMessageHandlerResponse) {
                foreach ($response->getHandlerResponses() as $handlerResponse) {
                    $this->handleResponse($message, $handlerResponse, $messageContext);
                }
            } else {
                $this->logger->error(
                    'Failed to process message of type - "{messageTypeName}" - "{exceptionMessage}".',
                    $loggingContext
                );
            }
        } else {
            $this->logger->info(
                'Received response "{responseStatusCode}" for message of type - "{messageTypeName}".',
                $loggingContext
            );
        }

        if ($response instanceof UnhandledMessageResponse) {
            $this->logger->warning('Message of type "{messageType}" was not handled.', [
                'messageType' => $message::getTypeName(),
            ]);
        }
    }

    private function normalizeMessage(MessageInterface $message): ?array
    {
        if (method_exists($message, '__toString')) {
            /*
             * Although {@link MessageInterface} does not implement the __toString method,
             * the subclasses might so we check for it.
             */
            try {
                // Also allows json encoded messages to be returned as arrays.
                $messageAsString = (string) $message;

                return json_decode($messageAsString, true, 512, \JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                return [$messageAsString];
            }
        }

        return $this->messageNormalizer->normalize($message);
    }
}
