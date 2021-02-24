<?php

namespace OldSound\RabbitMqBundle\Command;

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
            ->addArgument('filename', InputArgument::REQUIRED, 'Filename of JSON messages file')
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

        // get contents from file
        $jsonString = file_get_contents($input->getArgument('filename'));
        // output the whole contents of the file
        echo "File Contents".PHP_EOL;
        var_dump($jsonString);

        $amqpMessages = [];
        $rawArray = json_decode($jsonString, true);
        foreach ($rawArray as $rawMessage) {
            $amqpMessage = new AMQPMessage(base64_decode($rawMessage['Body']));
            $amqpMessage->delivery_info['delivery_tag'] = $rawMessage['DeliveryTag'];
            $amqpMessages[] = $amqpMessage;
        }

        // clear memory
        $rawArray = null;
        $jsonString = null;
        gc_collect_cycles();
        $resultArray = call_user_func([$this->getContainer()->get($class), $method,], $amqpMessages);
        $response = [];
        foreach ($resultArray as $deliveryTag => $result) {
            $response[] = [
                'DeliveryTag' => $deliveryTag,
                "Result" => $this->processResponse($result),
            ];
        }
        file_put_contents($input->getArgument('filename')."_response", json_encode($response));

        // go application is expecting an exit code
        return 0;
    }

    protected function getConsumerService()
    {
        return 'old_sound_rabbit_mq.%s_batch';
    }
}
