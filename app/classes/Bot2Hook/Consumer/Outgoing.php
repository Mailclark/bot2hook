<?php

namespace Bot2Hook\Consumer;

use Bot2Hook\Logger;
use Bot2Hook\Rabbitmq;
use Bot2Hook\Signature;
use Curl\Curl;

class Outgoing extends ConsumerAbstract
{
    /** @var string */
    protected $signature_key;

    public function __construct(array $config, Rabbitmq $rabbitmq, Logger $logger, $signature_key)
    {
        $this->signature_key = $signature_key;
        $this->_construct($config, $rabbitmq, $logger);
    }

    public function process($body, $retry = 0)
    {
        try {
            $data = ['webhook_event' => $body];
            $signature = new Signature($this->signature_key);
            $curl = new Curl();
            $curl->setHeader('X-BOT2HOOK-SIGNATURE', $signature->generate($this->config['webhook_url'], $data));
            $response = $curl->post($this->config['webhook_url'], $data);
            if (empty($response) || !is_object($response) || empty($response->ok)) {
                $error = "Outgoing webhook error. ";
                if (empty($response)) {
                    $error .= 'Curl: '.$curl->curlErrorCode.' - '.$curl->curlErrorMessage."\n";
                } else {
                    $error .= 'Response: '.print_r($response, true)."\n";
                }
                throw new \Exception($error);
            }
        } catch (\Exception $e) {
            $this->retry($e, 'b2h_outgoing', $body, $retry);
        }
    }
}
