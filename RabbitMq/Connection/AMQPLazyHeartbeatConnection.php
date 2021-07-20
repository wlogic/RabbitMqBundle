<?php

namespace OldSound\RabbitMqBundle\RabbitMq\Connection;


use PhpAmqpLib\Connection\AMQPLazyConnection;
use PhpAmqpLib\Connection\Heartbeat\PCNTLHeartbeatSender;

class AMQPLazyHeartbeatConnection extends AMQPLazyConnection
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


}