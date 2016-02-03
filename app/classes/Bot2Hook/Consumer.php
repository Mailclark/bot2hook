<?php

namespace Bot2Hook;

use PhpAmqpLib\Exception\AMQPRuntimeException;

class Consumer
{
    protected $config = [];

    /** @var Rabbitmq */
    protected $rabbitmq;

    /** @var string */
    protected $cmd_php;

    public function __construct(array $config, Rabbitmq $rabbitmq, $cmd_php)
    {
        $this->config = $config;
        $this->rabbitmq = $rabbitmq;
        $this->cmd_php = $cmd_php;
    }

    public function launch()
    {
        if (!empty($this->config['webhook_url'])) {
            $this->rabbitmq->consume($this->config['rabbit_outgoing_queue'], array($this, 'outgoing'));
        }
        if (!empty($this->config['rabbit_incoming_queue'])) {
            $this->rabbitmq->consume($this->config['rabbit_incoming_queue'], array($this, 'incoming'));
        }
        try {
            $this->rabbitmq->waitLoop();
        } catch (AMQPRuntimeException $amqprre) {
            echo "AMQPRuntimeException:".$amqprre->getMessage()."\n";
        }
    }

    public function outgoing($msg)
    {
        $body = json_decode($msg->body);
        $retry = empty($body->retry) ? 0 : $body->retry;

        $this->rabbitmq->ack($msg);

        $this->execute('b2h_outgoing', $msg->body, $retry);
    }

    public function incoming($msg)
    {
        $body = json_decode($msg->body);
        $retry = empty($body->retry) ? 0 : $body->retry;

        $this->rabbitmq->ack($msg);

        $this->execute('b2h_incoming', $msg->body, $retry);
    }

    protected function execute()
    {
        $func_args = func_get_args();
        $task = reset($func_args);

        $cmd_php = $this->cmd_php;
        $args = implode(' ', array_map('escapeshellarg', $func_args));

        echo "$cmd_php /http/cli/cli.php $args\n";

        $stdout_logfile = escapeshellarg(DIR_LOGS.'/consumer-'.$task.'.log');
        $stderr_logfile = escapeshellarg(DIR_LOGS.'/consumer-error-cli.log');

        // Capturing stderr in one file and stderr and stdout combined in another file
        // http://www.softpanorama.org/Tools/tee.shtml
        $command = "$cmd_php /http/cli/cli.php $args";
        $command = "($command 3>&2 2>&1 1>&3 | tee -a $stderr_logfile)";
        $command = "($command 3>&2 2>&1 1>&3) >> $stdout_logfile 2>&1";
        exec("$command &");
    }
}
