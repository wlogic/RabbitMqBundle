<?php

namespace OldSound\RabbitMqBundle\Command;

class ConsumerCommand extends BaseConsumerCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Executes a consumer');
        $this->setName('rabbitmq:consumer');
    }

    protected function getConsumerService(): string
    {
        return 'old_sound_rabbit_mq.%s_consumer';
    }
}
