<?php

namespace Bot2Hook;

use Devristo\Phpws\Client\WebSocket;

class WebSocketClient extends WebSocket
{
    public function open($timeOut=null)
    {
        $promise = parent::open($timeOut);

        $this->on("connect", function () {
            $this->stream->on('close', function() {
                $this->emit('close');
            });
        });

        return $promise;
    }

    public function getState()
    {
        return $this->state;
    }
}
