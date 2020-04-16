<?php

namespace OldSound\RabbitMqBundle\Command;

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
     * Executes the current command.
     *
     * @param InputInterface $input An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     *
     * @return integer 0 if everything went fine, or an error code
     *
     * @throws \InvalidArgumentException When the number of messages to consume is less than 0
     * @throws \BadFunctionCallException When the pcntl is not installed and option -s is true
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $consumer = $this->getContainer()
            ->get(sprintf($this->getConsumerService(), $input->getArgument('name')));

        [$class, $method] = $consumer->getCallback();

        $msg = base64_decode($input->getArgument('message'));

        return call_user_func([$this->getContainer()->get($class), $method,], $msg);
    }

    protected function getConsumerService()
    {
        return 'old_sound_rabbit_mq.%s_consumer';
    }

}
