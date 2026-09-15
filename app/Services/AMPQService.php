<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class AMPQService
{
    protected AMQPStreamConnection $connection;

    protected AMQPChannel $channel;

    public function publishMessage(): void
    {
        $this->establishConnection();

        $msg = new AMQPMessage(
            json_encode(['status' => 'success']),
            ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]
        );

        $this->channel->basic_publish($msg, 'my_exchange', 'my_routing_key'); // TODO set values from app

        $this->closeConnection();
    }

    protected function establishConnection(): void
    {
        $this->channel = $this->connection->channel();
        $this->channel->exchange_declare('my_exchange', 'direct', false, true, false);
        $this->channel->queue_declare('my_queue', false, true, false, false);
        $this->channel->queue_bind('my_queue', 'my_exchange', 'my_routing_key'); // TODO set values from app
    }

    protected function closeConnection(): void
    {
        $this->channel->close();
        try {
            $this->connection->close();
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }
    }
}