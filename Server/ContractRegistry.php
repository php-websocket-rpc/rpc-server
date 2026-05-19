<?php

declare(strict_types=1);

namespace PhpWebsocketRpc\RpcServer\Server;

use PhpWebsocketRpc\Rpc\Contract\ContractInvocation;
use PhpWebsocketRpc\Rpc\Contract\ContractPublish;
use PhpWebsocketRpc\Rpc\Contract\ContractResponse;
use PhpWebsocketRpc\Rpc\Contract\ContractSerializer;
use PhpWebsocketRpc\Rpc\Contract\ContractStreamClose;
use PhpWebsocketRpc\Rpc\Contract\ContractStreamInvocation;
use PhpWebsocketRpc\Rpc\Contract\ContractStreamValue;
use PhpWebsocketRpc\Rpc\Exception\RpcDispatchException;
use PhpWebsocketRpc\Rpc\Payload\Error;

/**
 * Server-side registry and dispatcher for contract-based RPC services.
 *
 * Supports three dispatch modes:
 *   1. Call/Response — ContractInvocation → invoke method → ContractResponse
 *   2. Stream — ContractStreamInvocation (Iterator return) → push values via channel
 *   3. Subscribe — ContractStreamInvocation (callable param) → inject push callback
 */
final class ContractRegistry
{
    /**
     * Registered services: interface FQCN → implementation object.
     *
     * @var array<string, object>
     */
    private array $services = [];

    /**
     * Register a service implementation for a given interface.
     *
     * @param string $interface       Fully qualified interface name
     * @param object $implementation  Concrete implementation
     *
     * @throws \InvalidArgumentException if the implementation does not implement the interface
     */
    public function register(string $interface, object $implementation): void
    {
        if (!\is_subclass_of($implementation::class, $interface)) {
            throw new \InvalidArgumentException(\sprintf(
                'Implementation %s does not implement %s',
                $implementation::class,
                $interface,
            ));
        }

        $this->services[$interface] = $implementation;
    }

    /**
     * Check if a service is registered.
     */
    public function has(string $interface): bool
    {
        return isset($this->services[$interface]);
    }

    /**
     * Get a registered service.
     *
     * @throws RpcDispatchException if the service is not found
     */
    public function getService(string $interface): object
    {
        return $this->services[$interface]
            ?? throw new RpcDispatchException(
                \sprintf('No service registered for %s', $interface),
                Error::METHOD_NOT_FOUND,
            );
    }

    /**
     * Dispatch a ContractInvocation (call/response pattern).
     *
     * Calls the registered method and wraps the return value in a ContractResponse.
     */
    public function dispatch(ContractInvocation $invocation): ContractResponse
    {
        $service = $this->getService($invocation->service);
        $methodName = $invocation->method;

        if (!\method_exists($service, $methodName)) {
            throw new RpcDispatchException(
                \sprintf('Method "%s" not found on service %s', $methodName, $invocation->service),
                Error::METHOD_NOT_FOUND,
            );
        }

        $result = $service->$methodName(...$invocation->params);

        return new ContractResponse(
            result: ContractSerializer::encode($result),
        );
    }

    /**
     * Dispatch a ContractStreamInvocation for an Iterator-returning method (stream pattern).
     *
     * Calls the method, iterates the returned Traversable, and pushes each value
     * as a ContractStreamValue on the invocation's channel.
     * Sends a ContractStreamClose when the iteration is complete.
     */
    public function dispatchStream(ContractStreamInvocation $invocation, ClientSession $session): void
    {
        $service = $this->getService($invocation->service);
        $methodName = $invocation->method;

        if (!\method_exists($service, $methodName)) {
            throw new RpcDispatchException(
                \sprintf('Method "%s" not found on service %s', $methodName, $invocation->service),
                Error::METHOD_NOT_FOUND,
            );
        }

        $result = $service->$methodName(...$invocation->params);

        if (!($result instanceof \Traversable)) {
            throw new RpcDispatchException(
                \sprintf('Method "%s" must return Traversable for streaming', $methodName),
                Error::INTERNAL_ERROR,
            );
        }

        $channel = $invocation->channel();

        // Iterate the Traversable in a background fiber, pushing values as they come
        \Amp\async(function () use ($result, $session, $channel): void {
            try {
                foreach ($result as $value) {
                    if ($session->isClosed()) {
                        break;
                    }

                    $streamValue = new ContractStreamValue(
                        value: ContractSerializer::encode($value),
                    );
                    $streamValue->setChannel($channel);
                    $session->send($streamValue);
                }
            } catch (\Throwable $e) {
                // Stream error — push close with error, or just close
            } finally {
                $close = new ContractStreamClose();
                $close->setChannel($channel);
                $session->send($close);

                // Release the iterator
                if ($result instanceof \Generator && $result->valid()) {
                    $result->next(); // force close
                }
            }
        });
    }

    /**
     * Dispatch a ContractStreamInvocation for a callable-param method (subscribe pattern).
     *
     * Injects a server-side push callback at the callable parameter position.
     * The implementation can call this push callback to send data to the subscribed client.
     */
    public function dispatchSubscribe(ContractStreamInvocation $invocation, ClientSession $session): void
    {
        $service = $this->getService($invocation->service);
        $methodName = $invocation->method;

        if (!\method_exists($service, $methodName)) {
            throw new RpcDispatchException(
                \sprintf('Method "%s" not found on service %s', $methodName, $invocation->service),
                Error::METHOD_NOT_FOUND,
            );
        }

        $channel = $invocation->channel();
        $refMethod = new \ReflectionMethod($service, $methodName);

        // Find the callable parameter index
        $callableIndex = null;
        foreach ($refMethod->getParameters() as $i => $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === 'callable') {
                $callableIndex = $i;
                break;
            }
        }

        if ($callableIndex === null) {
            throw new RpcDispatchException(
                \sprintf('Method "%s" has no callable parameter', $methodName),
                Error::INVALID_PARAMS,
            );
        }

        // Create a server-side push callback that encodes values and sends to the client
        $pushCallback = function (mixed $value) use ($session, $channel): void {
            if ($session->isClosed()) {
                return;
            }

            $streamValue = new ContractStreamValue(
                value: ContractSerializer::encode($value),
            );
            $streamValue->setChannel($channel);
            $session->send($streamValue);
        };

        // Build the parameter list with the push callback injected at the right position
        $params = $invocation->params;
        \array_splice($params, $callableIndex, 0, [$pushCallback]);

        $service->$methodName(...$params);
    }

    /**
     * Dispatch a ContractPublish (publish pattern, client → server).
     *
     * Calls the registered method with the decoded data from the client.
     */
    public function dispatchPublish(ContractPublish $publish, ClientSession $session): void
    {
        $service = $this->getService($publish->service);
        $methodName = $publish->method;

        if (!\method_exists($service, $methodName)) {
            throw new RpcDispatchException(
                \sprintf('Method "%s" not found on service %s', $methodName, $publish->service),
                Error::METHOD_NOT_FOUND,
            );
        }

        // Decoded data is a positional array of arguments
        $decodedData = ContractSerializer::decode($publish->data);

        if (\is_array($decodedData)) {
            $service->$methodName(...$decodedData);
        } else {
            $service->$methodName($decodedData);
        }
    }

    /**
     * Get the reflection method and check if it has a callable parameter.
     */
    public function hasCallableParameter(\ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === 'callable') {
                return true;
            }
        }

        return false;
    }
}
