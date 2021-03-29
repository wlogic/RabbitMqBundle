<?php


namespace OldSound\RabbitMqBundle\RabbitMq;


use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;

class ProducerManager extends BaseAmqp
{
    private $delayedMessages;

    public function addDelayedMessage($msg, $exchange, $routingKey)
    {
        if (!array_key_exists($exchange, $this->delayedMessages)) {
            $this->delayedMessages[$exchange] = [];
        }
        array_push($this->delayedMessages, ['message' => $msg, 'routing_key' => $routingKey]);
    }


    public function publishDelayedMessages()
    {
        if ($this->autoSetupFabric) {
            try {
                $this->setupFabric();
            } catch (AMQPConnectionClosedException | AMQPChannelClosedException $AMQPConnectionClosedException) {
                // attempt reconnect
                $this->reconnect();
                $this->setupFabric();
            }
        }

        // check connection
        if ($this->getChannel()->getConnection() === null) {
            $this->reconnect();
        }

        // publish
        foreach ($this->delayedMessages as $exchange => $messageDetails) {
            try {
                $this->getChannel()->basic_publish(
                    $messageDetails['message'],
                    $exchange,
                    $messageDetails['routing_key']
                );
            } catch (AMQPConnectionClosedException | AMQPChannelClosedException $AMQPConnectionClosedException) {
                $this->logger->error('Produce Message Failed '.$AMQPConnectionClosedException->getMessage());
                // attempt reconnect
                $this->reconnect();
                // retry
                try {
                    $this->getChannel()->basic_publish(
                        $messageDetails['message'],
                        $exchange,
                        $messageDetails['routing_key']
                    );
                } catch (\Exception $e) {
                    $this->logger->error('Retry Produce Message Failed '.$e->getMessage());
                }
            }
        }
    }
}