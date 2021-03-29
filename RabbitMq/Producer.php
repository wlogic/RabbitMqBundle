<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Producer, that publishes AMQP Messages
 */
class Producer extends BaseAmqp implements ProducerInterface
{
    protected $contentType = 'text/plain';
    protected $deliveryMode = 2;
    private $producerManager;

    public function __construct(
        AbstractConnection $conn,
        AMQPChannel $ch = null,
        $consumerTag = null,
        ProducerManager $producerManager
    ) {
        $this->producerManager = $producerManager;
        parent::__construct($conn, $ch, $consumerTag);
    }

    public function setContentType($contentType)
    {
        $this->contentType = $contentType;

        return $this;
    }

    public function setDeliveryMode($deliveryMode)
    {
        $this->deliveryMode = $deliveryMode;

        return $this;
    }

    /**
     * Adds message to delayed publishing queue
     *
     * @param string $msgBody
     * @param string $routingKey
     * @param array $additionalProperties
     * @param array $headers
     */
    public function delayedPublish($msgBody, $routingKey = '', $additionalProperties = [], array $headers = null)
    {
        $msg = new AMQPMessage((string)$msgBody, array_merge($this->getBasicProperties(), $additionalProperties));

        if (!empty($headers)) {
            $headersTable = new AMQPTable($headers);
            $msg->set('application_headers', $headersTable);
        }

        $this->producerManager->addDelayedMessage($msg, $this->exchangeOptions['name'], (string)$routingKey);

        $this->logger->debug(
            'Delayed message added',
            [
                'amqp' => [
                    'body' => $msgBody,
                    'routingkeys' => $routingKey,
                    'properties' => $additionalProperties,
                    'headers' => $headers,
                ],
            ]
        );
    }

    protected function getBasicProperties()
    {
        return ['content_type' => $this->contentType, 'delivery_mode' => $this->deliveryMode];
    }

    /**
     * Publishes the message and merges additional properties with basic properties
     *
     * @param string $msgBody
     * @param string $routingKey
     * @param array $additionalProperties
     * @param array $headers
     */
    public function publish($msgBody, $routingKey = '', $additionalProperties = [], array $headers = null)
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

        $msg = new AMQPMessage((string)$msgBody, array_merge($this->getBasicProperties(), $additionalProperties));

        if (!empty($headers)) {
            $headersTable = new AMQPTable($headers);
            $msg->set('application_headers', $headersTable);
        }

        // check connection
        if ($this->getChannel()->getConnection() === null) {
            $this->reconnect();
        }

        // publish
        try {
            $this->getChannel()->basic_publish($msg, $this->exchangeOptions['name'], (string)$routingKey);
        } catch (AMQPConnectionClosedException | AMQPChannelClosedException $AMQPConnectionClosedException) {
            $this->logger->error('Produce Message Failed '.$AMQPConnectionClosedException->getMessage());
            // attempt reconnect
            $this->reconnect();
            // retry
            try {
                $this->getChannel()->basic_publish($msg, $this->exchangeOptions['name'], (string)$routingKey);
            } catch (\Exception $e) {
                $this->logger->error('Retry Produce Message Failed '.$e->getMessage());
            }
        }

        $this->logger->debug(
            'AMQP message published',
            [
                'amqp' => [
                    'body' => $msgBody,
                    'routingkeys' => $routingKey,
                    'properties' => $additionalProperties,
                    'headers' => $headers,
                ],
            ]
        );
    }
}
