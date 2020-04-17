<?php

namespace OldSound\RabbitMqBundle\Command;

use OldSound\RabbitMqBundle\RabbitMq\ConsumerInterface;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GoBatchConsumerCommand extends BaseGoConsumerCommand
{

    protected function configure()
    {
        $this
            ->setName('rabbitmq:go:batch-consumer')
            ->addArgument('message', InputArgument::REQUIRED, 'Message (JSON format)')
            ->addArgument('name', InputArgument::REQUIRED, 'Consumer Name')
            ->addOption('debug', 'd', InputOption::VALUE_NONE, 'Enable Debugging')
            ->setDescription('Executes a Go Batch Consumer');
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

        $messageArray = json_decode($input->getArgument('message'),true);
        var_dump($messageArray);
        die();

//        $msgs = new AMQPMessage(base64_decode($input->getArgument('message')));
//        $msgs->

        $response = call_user_func([$this->getContainer()->get($class), $method,], $msg);

        // go application is expecting an exit code that is translated into ack / nack
        exit($this->processResponse($response));
    }

    protected function getConsumerService()
    {
        return 'old_sound_rabbit_mq.%s_batch';
    }
}
