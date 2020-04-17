<?php

namespace OldSound\RabbitMqBundle\Command;

use OldSound\RabbitMqBundle\RabbitMq\ConsumerInterface;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GoConsumerCommand extends BaseRabbitMqCommand
{
    protected $consumer;

    protected function configure()
    {
        $this
            ->setName('rabbitmq:go:consumer')
            ->addArgument('message', InputArgument::REQUIRED, 'Message (JSON format)')
            ->addArgument('name', InputArgument::REQUIRED, 'Consumer Name')
            ->addOption('debug', 'd', InputOption::VALUE_NONE, 'Enable Debugging')
            ->setDescription('Executes a Go Consumer');
    }

    /**
     * Executes the a symfony consumer and returns response.
     *
     * @param InputInterface $input An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     *
     * @throws \InvalidArgumentException When the number of messages to consume is less than 0
     * @throws \BadFunctionCallException When the pcntl is not installed and option -s is true
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $consumer = $this->getContainer()->get(sprintf($this->getConsumerService(), $input->getArgument('name')));

        [$class, $method] = $consumer->getCallback();

        $msg = new AMQPMessage(base64_decode($input->getArgument('message')));

        $response = call_user_func([$this->getContainer()->get($class), $method,], $msg);

        // go application is expecting an exit code that is translated into ack / nack
        exit($this->processResponse($response));
    }

    protected function getConsumerService()
    {
        return 'old_sound_rabbit_mq.%s_consumer';
    }

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
}
