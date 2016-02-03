<?php

namespace Bot2Hook;

use Bot2Hook\Log\SlackWriter;
use Zend\Log\Filter\Priority;
use Zend\Log\Writer\Stream;
use Zend\Stdlib\ArrayUtils;

class Logger extends \Zend\Log\Logger
{

    public function __construct($options = null)
    {
        parent::__construct($options);

        if ($options instanceof \Traversable) {
            $options = ArrayUtils::iteratorToArray($options);
        }

        if (is_array($options)) {
            if (!empty($options['stream'])) {
                if (!is_array($options['stream'])) {
                    $options['stream'] = [
                        'uri' => $options['stream'],
                    ];
                }
                if (!empty($options['stream']['uri'])) {
                    $writer = new Stream($options['stream']['uri']);
                    if (!empty($options['stream']['priority'])) {
                        $filter = new Priority($options['stream']['priority']);
                        $writer->addFilter($filter);
                    }
                    $this->addWriter($writer);
                }
            }
            if (!empty($options['slack'])) {
                $writer = new SlackWriter($options['slack']);
                $this->addWriter($writer);
            }
            if (!empty($options['register_error_handler'])) {
                Logger::registerErrorHandler($this);
            }
            if (!empty($options['register_exception_handler'])) {
                Logger::registerExceptionHandler($this);
            }
        }
    }
}
