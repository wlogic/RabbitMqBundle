<?php


namespace OldSound\RabbitMqBundle\Command;

abstract class BaseGoConsumerCommand extends BaseRabbitMqCommand
{

    protected $consumer;

    /**
     * Process response from consumer and return correct number
     *
     * EXIT_ACK            = 0
     * EXIT_REJECT         = 3
     * EXIT_REJECT_REQUEUE = 4
     * EXIT_NACK           = 5
     * EXIT_NACK_REQUEUE   = 6
     *
     * @param $response
     * @return int
     */
    protected function processResponse($response)
    {
        if ($response === ConsumerInterface::MSG_REJECT_REQUEUE || false === $response) {
            // Reject and requeue message to RabbitMQ
            return 4;
        } elseif ($response === ConsumerInterface::MSG_SINGLE_NACK_REQUEUE) {
            // NACK and requeue message to RabbitMQ
            return 6;
        } elseif ($response === ConsumerInterface::MSG_REJECT) {
            // Reject and drop
            return 3;
        } else {
            // Remove message from queue only if callback return not false
            return 0;
        }
    }

    abstract protected function getConsumerService();
}
