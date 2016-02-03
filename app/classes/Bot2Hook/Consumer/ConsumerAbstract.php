<?php

namespace Bot2Hook\Consumer;

use Bot2Hook\Logger;
use Bot2Hook\Rabbitmq;

abstract class ConsumerAbstract
{
    protected $config = [];

    /** @var Logger */
    protected $logger;

    /** @var Rabbitmq */
    protected $rabbitmq;

    protected function _construct(array $config, Rabbitmq $rabbitmq, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->rabbitmq = $rabbitmq;
    }

    protected function retry(\Exception $e, $queue, $data, $retry)
    {
        $this->logger->err($e->getMessage());
        echo $e->getMessage()."\n";
        echo $e->getTraceAsString()."\n";
        if (is_object($data)) {
            $data->retry = $retry + 1;
        } elseif (is_array($data)) {
            $data['retry'] = $retry + 1;
        } else {
            $temp_data = json_decode($data);
            if (!empty($temp_data)) {
                if (is_object($data)) {
                    $temp_data->retry = $retry + 1;
                } elseif (is_array($data)) {
                    $temp_data['retry'] = $retry + 1;
                }
                $data = json_encode($temp_data);
            }
        }
        try {
            if ($retry <= 5) {
                $delay = 30;
            } elseif ($retry <= 10) {
                $delay = 2 * 60;
            } elseif ($retry <= 15) {
                $delay = 30 * 60;
            } elseif ($retry <= 20) {
                $delay = 4 * 60 * 60;
            } elseif ($retry <= 25) {
                $delay = 24 * 60 * 60;
            } else {
                throw $e;
            }
            $this->rabbitmq->publishStringDelayed($delay, $queue, json_encode($data));
        } catch (\Exception $e) {
            $this->logger->err('Stop retry for consumer queue '.$queue.': '.print_r(json_encode($data), true));
        }
    }
}
