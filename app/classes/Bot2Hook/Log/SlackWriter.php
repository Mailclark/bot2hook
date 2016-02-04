<?php

namespace Bot2Hook\Log;

use Curl\Curl;
use Zend\Log\Exception;
use Zend\Log\Filter\Priority;
use Zend\Log\Writer\AbstractWriter;

class SlackWriter extends AbstractWriter
{
    protected $slack_config = [
        'token'       => '',
        'channel'     => '#general',
        'username'    => 'Bot2Hook Logger',
        'icon_emoji'  => 'page_with_curl',
    ];

    protected $channel = null;

    /** @var Curl */
    protected $curl;

    public function __construct($slack_config, $mode = null, $logSeparator = null)
    {
        if (!is_array($slack_config) || !isset($slack_config['token']) || !isset($slack_config['channel'])) {
            throw new Exception\InvalidArgumentException('Slack config must be an array with, at least, keys "token" and "channel"');
        }

        parent::__construct($slack_config);
        $this->slack_config['token'] = $slack_config['token'];
        $this->slack_config['channel'] = $slack_config['channel'];
        if (!empty($slack_config['username'])) {
            $this->slack_config['username'] = $slack_config['username'];
        }
        if (!empty($slack_config['icon_emoji'])) {
            $this->slack_config['icon_emoji'] = $slack_config['icon_emoji'];
        }
        if (!empty($slack_config['priority'])) {
            $filter = new Priority($slack_config['priority']);
            $this->addFilter($filter);
        }

        $this->formatter = new SlackFormatter();
        $this->curl = new Curl();
    }


    protected function doWrite(array $event)
    {
        $data = array_merge($this->slack_config, $this->formatter->format($event));
        $this->curl->post('https://slack.com/api/chat.postMessage', $data);
    }
}
