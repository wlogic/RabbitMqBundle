<?php

namespace OldSound\RabbitMqBundle\Command;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Console\Command\Command;

abstract class BaseRabbitMqCommand extends Command
{
    public function __construct(
        private ContainerInterface $container,
        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    /**
     * @return ContainerInterface
     */
    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }
}
