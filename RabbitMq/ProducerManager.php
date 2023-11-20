<?php


namespace OldSound\RabbitMqBundle\RabbitMq;

use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPTimeoutException;

class ProducerManager extends BaseAmqp
{
    private $delayedMessages = [];

    public function addDelayedMessage($msg, $exchange, $routingKey)
    {
        if (!array_key_exists($exchange, $this->delayedMessages)) {
            $this->delayedMessages[$exchange] = [];
        }
        array_push($this->delayedMessages[$exchange], ['message' => $msg, 'routing_key' => $routingKey]);
    }


    public function publishDelayedMessages()
    {
        // publish delayed messages
        foreach ($this->delayedMessages as $exchange => $messageDetails) {
            foreach ($messageDetails as $messageDetail) {
                try {
                    $this->getChannel()->basic_publish(
                        $messageDetail['message'],
                        $exchange,
                        $messageDetail['routing_key']
                    );
                } catch (AMQPTimeoutException | AMQPConnectionClosedException | AMQPChannelClosedException $AMQPConnectionClosedException) {
                    $this->logger->error('Produce Message Failed '.$AMQPConnectionClosedException->getMessage());
                    // attempt reconnect
                    $this->reconnect();
                    // retry
                    try {
                        $this->getChannel()->basic_publish(
                            $messageDetail['message'],
                            $exchange,
                            $messageDetail['routing_key']
                        );
                    } catch (\Exception $e) {
                        $this->logger->error('Retry Produce Message Failed '.$e->getMessage());
                    }
                }
            }
        }
        // clear delayed messages
        $this->delayedMessages = [];
    }
}
