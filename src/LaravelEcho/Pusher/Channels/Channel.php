<?php

namespace BeyondCode\LaravelWebSockets\LaravelEcho\Pusher\Channels;

use BeyondCode\LaravelWebSockets\LaravelEcho\Pusher\Exceptions\InvalidSignatureException;
use Illuminate\Support\Collection;
use Ratchet\ConnectionInterface;
use stdClass;

class Channel
{
    /** @var string */
    protected $channelId;

    /** @var \Ratchet\ConnectionInterface[] */
    protected $subscriptions = [];

    public function __construct(string $channelId)
    {
        $this->channelId = $channelId;
    }

    public function hasConnections(): bool
    {
        return count($this->subscriptions) > 0;
    }

    protected function verifySignature(ConnectionInterface $connection, stdClass $payload)
    {
        $auth = $payload->auth;

        $signature = "{$connection->socketId}:{$this->channelId}";

        if (isset($payload->channel_data)) {
            $signature .= ":{$payload->channel_data}";
        }

        // TODO Have app id specific secrets
        if (str_after($auth, ':') !== hash_hmac('sha256', $signature, config('broadcasting.connections.pusher.secret'))) {
            throw new InvalidSignatureException();
        }
    }

    /*
     * @link https://pusher.com/docs/pusher_protocol#presence-channel-events
     */
    public function subscribe(ConnectionInterface $connection, stdClass $payload)
    {
        $this->saveConnection($connection);

        $connection->send(json_encode([
            'event' => 'pusher_internal:subscription_succeeded',
            'channel' => $this->channelId
        ]));
    }

    public function unsubscribe(ConnectionInterface $connection)
    {
        unset($this->subscriptions[$connection->socketId]);
    }

    protected function saveConnection(ConnectionInterface $connection)
    {
        $this->subscriptions[$connection->socketId] = $connection;
    }

    public function broadcast($payload)
    {
        foreach ($this->subscriptions as $connection) {
            $connection->send(json_encode($payload));
        }
    }

    public function broadcastToEveryoneExcept($payload, ?string $socketId = null)
    {
        Collection::make($this->subscriptions)->reject(function ($existingConnection) use ($socketId) {
            return $existingConnection->socketId === $socketId;
        })->each->send(json_encode($payload));
    }

    public function broadcastToOthers(ConnectionInterface $connection, $payload)
    {
        $this->broadcastToEveryoneExcept($payload, $connection->socketId);
    }

    public function toArray(): array
    {
        return [
            'occupied' => count($this->subscriptions) > 0,
            'subscription_count' => count($this->subscriptions)
        ];
    }
}