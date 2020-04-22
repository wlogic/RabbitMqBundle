<?php

namespace OldSound\RabbitMqBundle\Command;

use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
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

        $amqpMessages = [];
        echo $input->getArgument('message').PHP_EOL;
        $rawArray = json_decode($input->getArgument('message'), true);
        foreach ($rawArray as $rawMessage) {
            $amqpMessage = new AMQPMessage($rawMessage['Body']);
            $amqpMessage->delivery_info['delivery_tag'] = $rawMessage['DeliveryTag'];
            $amqpMessages[] = $amqpMessage;
        }

        $resultArray = call_user_func([$this->getContainer()->get($class), $method,], $amqpMessages);

        $response = [];
        foreach ($resultArray as $deliveryTag => $result) {
            $response[] = [
                'DeliveryTag' => $deliveryTag,
                "Result" => $this->processResponse($result),
            ];
        }

        // very shit way of doing this - but dont have another way - PaulM
        // prefix the response with string and output to stdout - go app knows to grab it and not log it
        echo PHP_EOL."#BP2RESULT#".json_encode($response);
        // go application is expecting an exit code
        exit(0);
    }

    protected function getConsumerService()
    {
        return 'old_sound_rabbit_mq.%s_batch';
    }
}
