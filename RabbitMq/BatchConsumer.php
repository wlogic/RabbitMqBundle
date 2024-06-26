<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\Heartbeat\PCNTLHeartbeatSender;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

class BatchConsumer extends BaseAmqp implements DequeuerInterface
{
    /**
     * @var \Closure|callable
     */
    protected $callback;

    /**
     * @var bool
     */
    protected $forceStop = false;

    /**
     * @var int
     */
    protected $idleTimeout = 0;
    /**
     * @var int
     */
    protected $idleTimeoutExitCode;
    /**
     * @var int
     */
    protected $memoryLimit = null;
    /**
     * @var int
     */
    protected $prefetchCount;
    /**
     * @var int
     */
    protected $timeoutWait = 3;
    /**
     * @var array
     */
    protected $messages = [];
    /**
     * @var int
     */
    protected $batchCounter = 0;
    /**
     * @var \DateTime|null DateTime after which the consumer will gracefully exit. "Gracefully" means, that
     *      any currently running consumption will not be interrupted.
     */
    protected $gracefulMaxExecutionDateTime;
    /**
     * @var int number of seconds before graceful exit
     */
    protected $gracefulMaxExecutionSeconds = 0;
    /**
     * @var bool
     */
    private $keepAlive = false;

    /**
     * @param int $secondsInTheFuture
     */
    public function setGracefulMaxExecutionDateTimeFromSecondsInTheFuture($secondsInTheFuture)
    {
        $this->gracefulMaxExecutionSeconds = $secondsInTheFuture;
        $this->setGracefulMaxExecutionDateTime(new \DateTime("+{$secondsInTheFuture} seconds"));
    }

    /**
     * @param \DateTime|null $dateTime
     */
    public function setGracefulMaxExecutionDateTime(\DateTime $dateTime = null)
    {
        $this->gracefulMaxExecutionDateTime = $dateTime;
    }

    /**
     * @return callable
     */
    public function getCallback()
    {
        return $this->callback;
    }

    /**
     * @param \Closure|callable $callback
     *
     * @return  $this
     */
    public function setCallback($callback)
    {
        $this->callback = $callback;

        return $this;
    }

    public function consume()
    {
        $this->setupConsumer();

        while (count($this->getChannel()->callbacks)) {
            if ($this->isCompleteBatch()) {
                $this->batchConsume();
            }

            $this->checkGracefulMaxExecutionDateTime();
            $this->maybeStopConsumer();

            $timeout = $this->isEmptyBatch() ? $this->getIdleTimeout() : $this->getTimeoutWait();

            try {
                $this->getChannel()->wait(null, false, $timeout);
            } catch (AMQPTimeoutException $e) {
                if (!$this->isEmptyBatch()) {
                    $this->batchConsume();
                } elseif ($this->keepAlive === true) {
                    continue;
                } elseif (null !== $this->getIdleTimeoutExitCode()) {
                    return $this->getIdleTimeoutExitCode();
                } else {
                    throw $e;
                }
            }
        }
    }

    /**
     * @return  void
     */
    protected function setupConsumer()
    {
        if ($this->autoSetupFabric) {
            $this->setupFabric();
        }

        $this->getChannel()->basic_consume(
            $this->queueOptions['name'],
            $this->getConsumerTag(),
            false,
            false,
            false,
            false,
            [$this, 'processMessage']
        );
    }

    /**
     * @return  string
     */
    public function getConsumerTag()
    {
        return $this->consumerTag;
    }

    /**
     * @return  bool
     */
    protected function isCompleteBatch()
    {
        return $this->batchCounter === $this->prefetchCount;
    }

    private function batchConsume()
    {
        try {
            $processFlags = call_user_func($this->callback, $this->messages);
            $this->handleProcessMessages($processFlags);
            $this->logger->debug(
                'Queue message processed',
                [
                    'amqp' => [
                        'queue' => $this->queueOptions['name'],
                        'messages' => $this->messages,
                        'return_codes' => $processFlags,
                    ],
                ]
            );
        } catch (Exception\StopConsumerException $e) {
            $this->logger->info(
                'Consumer requested restart',
                [
                    'amqp' => [
                        'queue' => $this->queueOptions['name'],
                        'message' => $this->messages,
                        'stacktrace' => $e->getTraceAsString(),
                    ],
                ]
            );
            $this->resetBatch();
            $this->stopConsuming();
        } catch (\Exception $e) {
            $this->logger->error(
                $e->getMessage(),
                [
                    'amqp' => [
                        'queue' => $this->queueOptions['name'],
                        'message' => $this->messages,
                        'stacktrace' => $e->getTraceAsString(),
                    ],
                ]
            );
            $this->resetBatch();
            throw $e;
        } catch (\Error $e) {
            $this->logger->error(
                $e->getMessage(),
                [
                    'amqp' => [
                        'queue' => $this->queueOptions['name'],
                        'message' => $this->messages,
                        'stacktrace' => $e->getTraceAsString(),
                    ],
                ]
            );
            $this->resetBatch();
            throw $e;
        }

        $this->resetBatch();
    }

    /**
     * @param mixed $processFlags
     *
     * @return  void
     */
    protected function handleProcessMessages($processFlags = null)
    {
        $processFlags = $this->analyzeProcessFlags($processFlags);
        foreach ($processFlags as $deliveryTag => $processFlag) {
            $this->handleProcessFlag($deliveryTag, $processFlag);
        }
    }

    /**
     * @param mixed $processFlags
     *
     * @return  array
     */
    private function analyzeProcessFlags($processFlags = null)
    {
        if (is_array($processFlags)) {
            if (count($processFlags) !== $this->batchCounter) {
                throw new AMQPRuntimeException(
                    'Method batchExecute() should return an array with elements equal with the number of messages processed'
                );
            }

            return $processFlags;
        }

        $response = [];
        foreach ($this->messages as $deliveryTag => $message) {
            $response[$deliveryTag] = $processFlags;
        }

        return $response;
    }

    /**
     * @param int $deliveryTag
     * @param mixed $processFlag
     *
     * @return  void
     */
    private function handleProcessFlag($deliveryTag, $processFlag)
    {
        if ($processFlag === ConsumerInterface::MSG_REJECT_REQUEUE || false === $processFlag) {
            // Reject and requeue message to RabbitMQ
            $this->getMessageChannel($deliveryTag)->basic_reject($deliveryTag, true);
        } else {
            if ($processFlag === ConsumerInterface::MSG_SINGLE_NACK_REQUEUE) {
                // NACK and requeue message to RabbitMQ
                $this->getMessageChannel($deliveryTag)->basic_nack($deliveryTag, false, true);
            } else {
                if ($processFlag === ConsumerInterface::MSG_REJECT) {
                    // Reject and drop
                    $this->getMessageChannel($deliveryTag)->basic_reject($deliveryTag, false);
                } else {
                    // Remove message from queue only if callback return not false
                    $this->getMessageChannel($deliveryTag)->basic_ack($deliveryTag);
                }
            }
        }
    }

    /**
     * @param int $deliveryTag
     *
     * @return  AMQPChannel
     *
     * @throws  AMQPRuntimeException
     */
    private function getMessageChannel($deliveryTag)
    {
        $message = $this->getMessage($deliveryTag);
        if (!$message) {
            throw new AMQPRuntimeException(sprintf('Unknown delivery_tag %d!', $deliveryTag));
        }

        return $message->delivery_info['channel'];
    }

    /**
     * @param int $deliveryTag
     *
     * @return  AMQPMessage
     */
    private function getMessage($deliveryTag)
    {
        return isset($this->messages[$deliveryTag])
            ? $this->messages[$deliveryTag]
            : null;
    }

    /**
     * @return  void
     */
    private function resetBatch()
    {
        $this->messages = [];
        $this->batchCounter = 0;
    }

    /**
     * @return  void
     */
    public function stopConsuming()
    {
        if (!$this->isEmptyBatch()) {
            $this->batchConsume();
        }

        $this->getChannel()->basic_cancel($this->getConsumerTag());
    }

    /**
     * @return  bool
     */
    protected function isEmptyBatch()
    {
        return $this->batchCounter === 0;
    }

    /**
     * Check graceful max execution date time and stop if limit is reached
     *
     * @return void
     */
    private function checkGracefulMaxExecutionDateTime()
    {
        if (!$this->gracefulMaxExecutionDateTime) {
            return;
        }

        $now = new \DateTime();

        if ($this->gracefulMaxExecutionDateTime > $now) {
            return;
        }

        $this->forceStopConsumer();
    }

    /**
     * @return  void
     */
    public function forceStopConsumer()
    {
        $this->forceStop = true;
    }

    /**
     * @return  void
     *
     * @throws \BadFunctionCallException
     */
    protected function maybeStopConsumer()
    {
        if (extension_loaded('pcntl') && (defined('AMQP_WITHOUT_SIGNALS') ? !AMQP_WITHOUT_SIGNALS : true)) {
            if (!function_exists('pcntl_signal_dispatch')) {
                throw new \BadFunctionCallException(
                    "Function 'pcntl_signal_dispatch' is referenced in the php.ini 'disable_functions' and can't be called."
                );
            }

            pcntl_signal_dispatch();
        }

        if ($this->forceStop) {
            $this->stopConsuming();
        }

        if (null !== $this->getMemoryLimit() && $this->isRamAlmostOverloaded()) {
            $this->stopConsuming();
        }
    }

    /**
     * Get the memory limit
     *
     * @return int
     */
    public function getMemoryLimit()
    {
        return $this->memoryLimit;
    }

    /**
     * Set the memory limit
     *
     * @param int $memoryLimit
     */
    public function setMemoryLimit($memoryLimit)
    {
        $this->memoryLimit = $memoryLimit;
    }

    /**
     * Checks if memory in use is greater or equal than memory allowed for this process
     *
     * @return boolean
     */
    protected function isRamAlmostOverloaded()
    {
        return (memory_get_usage(true) >= ($this->getMemoryLimit() * 1048576));
    }

    /**
     * @return  int
     */
    public function getIdleTimeout()
    {
        return $this->idleTimeout;
    }

    /**
     * @param int $idleTimeout
     *
     * @return  $this
     */
    public function setIdleTimeout($idleTimeout)
    {
        $this->idleTimeout = $idleTimeout;

        return $this;
    }

    /**
     * @return int
     */
    public function getTimeoutWait()
    {
        return $this->timeoutWait;
    }

    /**
     * @param int $timeout
     *
     * @return  $this
     */
    public function setTimeoutWait($timeout)
    {
        $this->timeoutWait = $timeout;

        return $this;
    }

    /**
     * Get exit code to be returned when there is a timeout exception
     *
     * @return  int|null
     */
    public function getIdleTimeoutExitCode()
    {
        return $this->idleTimeoutExitCode;
    }

    /**
     * Set exit code to be returned when there is a timeout exception
     *
     * @param int $idleTimeoutExitCode
     *
     * @return  $this
     */
    public function setIdleTimeoutExitCode($idleTimeoutExitCode)
    {
        $this->idleTimeoutExitCode = $idleTimeoutExitCode;

        return $this;
    }

    /**
     * @param AMQPMessage $msg
     *
     * @return  void
     *
     * @throws  \Error
     * @throws  \Exception
     */
    public function processMessage(AMQPMessage $msg)
    {
        $this->addMessage($msg);

        $this->maybeStopConsumer();
    }

    /**
     * @param AMQPMessage $message
     *
     * @return  void
     */
    private function addMessage(AMQPMessage $message)
    {
        $this->batchCounter++;
        $this->messages[(int)$message->delivery_info['delivery_tag']] = $message;
    }

    /**
     * @param string $tag
     *
     * @return  $this
     */
    public function setConsumerTag($tag)
    {
        $this->consumerTag = $tag;

        return $this;
    }

    /**
     * keepAlive
     *
     * @return $this
     */
    public function keepAlive()
    {
        $this->keepAlive = true;

        return $this;
    }

    /**
     * Purge the queue
     */
    public function purge()
    {
        $this->getChannel()->queue_purge($this->queueOptions['name'], true);
    }

    /**
     * Delete the queue
     */
    public function delete()
    {
        $this->getChannel()->queue_delete($this->queueOptions['name'], true);
    }

    /**
     * Resets the consumed property.
     * Use when you want to call start() or consume() multiple times.
     */
    public function resetConsumed()
    {
        $this->consumed = 0;
    }

    /**
     * @return int
     */
    public function getPrefetchCount()
    {
        return $this->prefetchCount;
    }

    /**
     * @param int $amount
     *
     * @return  $this
     */
    public function setPrefetchCount($amount)
    {
        $this->prefetchCount = $amount;

        return $this;
    }

    /**
     * @return int
     */
    public function getGracefulMaxExecutionSeconds(): int
    {
        return $this->gracefulMaxExecutionSeconds;
    }

}
