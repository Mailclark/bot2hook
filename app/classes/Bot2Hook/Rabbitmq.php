<?php

namespace Bot2Hook;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class Rabbitmq
{
    /** @var AMQPChannel */
    protected $channel = null;

    protected $config = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    protected function getChannel()
    {
        if (empty($this->channel)) {
            try {
                $connection = new AMQPStreamConnection(
                    $this->config['host'],
                    $this->config['port'],
                    $this->config['user'],
                    $this->config['password']
                );
            } catch (\ErrorException $ee) {
                sleep(2);
                return $this->getChannel();
            }

            $this->channel = $connection->channel();
        }

        return $this->channel;
    }

    public function publishString($where, $string)
    {
        $msg = $this->getMessage($string);
        $key = $this->getQueue($where);
        $this->getChannel()->basic_publish($msg, $key, $key);
    }

    public function publishStringDelayed($delay, $where, $string)
    {
        $msg = $this->getMessage($string);
        $key = $this->getQueue($where, $delay);
        $this->getChannel()->basic_publish($msg, $key, $key);
    }

    public function consume($where, $callback)
    {
        // Create queue if needed
        $this->getQueue($where);

        $this->getChannel()->basic_qos(null, 1, null);

        return $this->getChannel()->basic_consume($where, '', false, false, false, false, $callback);
    }

    public function ack(AMQPMessage $msg)
    {
        $this->getChannel()->basic_ack($msg->delivery_info['delivery_tag']);
    }

    public function waitLoop()
    {
        while (count($this->getChannel()->callbacks)) {
            $this->getChannel()->wait();
        }
    }

    /**
     * @param $string
     *
     * @return AMQPMessage
     */
    protected function getMessage($string)
    {
        return new AMQPMessage($string, ['delivery_mode' => 2]);
    }

    /**
     * @param string   $key
     * @param int|null $delay
     *
     * @return string
     */
    protected function getQueue($key, $delay = null)
    {
        // regular queue
        $this->getChannel()->exchange_declare($key, 'direct', false, true, false);
        $this->getChannel()->queue_declare($key, false, true, false, false);
        $this->getChannel()->queue_bind($key, $key, $key);

        // delayed queue
        if (!empty($delay)) {
            $key_delay = $key.'_delay_'.$delay;

            $this->getChannel()->exchange_declare($key_delay, 'direct', false, true, false);
            $this->getChannel()->queue_declare($key_delay, false, true, false, false, false, [
                    'x-message-ttl' => ['I', (int) ($delay * 1000)],
                    'x-dead-letter-exchange' => ['S', $key],
                    'x-dead-letter-routing-key' => ['S', $key],
                ]
            );
            $this->getChannel()->queue_bind($key_delay, $key_delay, $key_delay);

            return $key_delay;
        }

        return $key;
    }
}
