<?php

namespace Bot2Hook\Log;

use Zend\Log\Formatter\FormatterInterface;
use Zend\Log\Logger;

class SlackFormatter implements FormatterInterface
{
    public function format($event)
    {
        $dataArray = array(
            'text'        => '',
            'attachments' => array()
        );

        $attachment = array(
            'fallback' => $event['message'],
            'color'    => $this->getAttachmentColor($event['priority'])
        );

        $attachment['fields'] = array(
            array(
                'title' => $event['priorityName'],
                'value' => $event['message'],
                'short' => false
            )
        );

        if (!empty($record['extra'])) {
            $attachment['fields'][] = array(
                'title' => "Extra",
                'value' => print_r($record['extra'], true),
                'short' => false
            );
        }

        if (!empty($record['context'])) {
            $attachment['fields'][] = array(
                'title' => "Context",
                'value' => print_r($record['context'], true),
                'short' => false
            );
        }

        $dataArray['attachments'] = json_encode(array($attachment));

        return $dataArray;
    }

    protected function getAttachmentColor($priority)
    {
        switch (true) {
            case $priority <= Logger::ERR:
                return 'danger';
            case $priority <= Logger::WARN:
                return 'warning';
            case $priority <= Logger::INFO:
                return 'good';
            default:
                return '#e3e4e6';
        }
    }

    public function getDateTimeFormat()
    {
        return '';
    }

    public function setDateTimeFormat($dateTimeFormat)
    {
        return $this;
    }
}
