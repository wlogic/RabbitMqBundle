<?php

namespace OldSound\RabbitMqBundle\RabbitMq\Connection;


use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Connection\Heartbeat\PCNTLHeartbeatSender;

class AMQPLazyHeartbeatConnection extends AMQPStreamConnection
{

    protected $heartbeatEnabled = false;

    protected function connect()
    {
        $return = parent::connect();
        $this->enableHeartbeat();


        return $return;
    }

    protected function enableHeartbeat()
    {
        if (!$this->heartbeatEnabled) {
            $sender = new PCNTLHeartbeatSender($this);
            $sender->register();

            $this->heartbeatEnabled = true;
        }
    }

    /**
     * @inheritDoc
     */
    public function connectOnConstruct(): bool
    {
        return false;
    }

}