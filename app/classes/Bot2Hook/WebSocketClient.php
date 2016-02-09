<?php

namespace Bot2Hook;

use Devristo\Phpws\Client\WebSocket;

class WebSocketClient extends WebSocket
{
    public function getState()
    {
        return $this->state;
    }
}
