<?php

namespace Bot2Hook\Entity;

use Bot2Hook\WebSocketClient;
use Devristo\Phpws\Framing\WebSocketFrame;
use Devristo\Phpws\Framing\WebSocketOpcode;
use Devristo\Phpws\Protocol\WebSocketTransportHybi;

class Bot implements \JsonSerializable
{
    /** @var string */
    public $id;

    /** @var string */
    public $team_id;

    /** @var string */
    public $bot_id;

    /** @var string */
    public $bot_token;

    /** @var array */
    public $users_token = [];

    /** @var Room[] */
    public $rooms = [];

    /** @var WebSocketClient */
    protected $client;

    /** @var int */
    protected $client_incremental = 1;

    public function __construct($data)
    {
        if (is_array($data)) {
            if (isset($data['team_id']) && isset($data['bot_id'])) {
                $this->setIds($data['team_id'], $data['bot_id']);
            }
            $this->bot_token = $data['bot_token'];
            $this->users_token = isset($data['users_token']) && is_array($data['users_token']) ? $data['users_token'] : [];
            $this->rooms = isset($data['rooms']) && is_array($data['rooms']) ? $data['rooms'] : [];
        } else {
            $this->bot_token = $data;
        }
    }

    public function setIds($team_id, $bot_id)
    {
        $this->id = $team_id.':'.$bot_id;
        $this->team_id = $team_id;
        $this->bot_id = $bot_id;
    }

    public function getRoom($room_id)
    {
        if (isset($this->rooms[$room_id])) {
            return $this->rooms[$room_id];
        }
        return [];
    }

    public function getToken($users = null)
    {
        foreach ($this->users_token as $user_id => $token) {
            if ($users === null || in_array($user_id, $users)) {
                return $token;
            }
        }
        return null;
    }

    public function initRoom($room_id, array $members = [], $latest = null)
    {
        if (!isset($this->rooms[$room_id])) {
            $this->rooms[$room_id] = new Room([
                'members' => $members,
                'latest' => $latest,
            ]);
        }
    }

    public function updateRoomLatest($room_id, $latest)
    {
        $this->initRoom($room_id);
        $this->rooms[$room_id]->latest = $latest;
    }

    public function addRoomMember($room_id, $user_id)
    {
        $this->initRoom($room_id);
        $this->rooms[$room_id]->members[] = $user_id;
    }

    public function removeRoomMember($room_id, $user_id)
    {
        $this->initRoom($room_id);
        $this->rooms[$room_id]->members = array_diff(
            $this->rooms[$room_id]->members,
            [$user_id]
        );
    }

    public function removeUserToken($token)
    {
        foreach ($this->users_token as $user_id => $user_token) {
            if ($user_token == $token) {
                unset($this->users_token[$user_id]);
                return true;
            }
        }
        return false;
    }

    public function isRoomUpToDate($room_id, $latest)
    {
        $this->initRoom($room_id);
        if (!empty($latest) && !empty($this->rooms[$room_id]->latest) &&
            $latest > $this->rooms[$room_id]->latest) {
            return false;
        }
        return true;
    }

    public function setClient(WebSocketClient $slack_client)
    {
        $this->client = $slack_client;
        $this->client_incremental = 1;
    }

    public function closeClient()
    {
        if (!empty($this->client)) {
            $this->client->close();
            $this->client = null;
            $this->client_incremental = 1;
        }
    }

    public function merge(self $old_bot)
    {
        $this->client = $old_bot->client;
        $this->client_incremental = $old_bot->client_incremental;
        $this->users_token = array_merge($old_bot->users_token, $this->users_token);
        $this->rooms = array_merge($old_bot->rooms, $this->rooms);
    }

    public function clientSendPing()
    {
        if (!empty($this->client) && $this->client->getState() == WebSocketClient::STATE_CONNECTED) {
            if($this->client instanceof WebSocketTransportHybi) {
                $this->client->sendFrame(WebSocketFrame::create(WebSocketOpcode::PingFrame));
            } else {
                $this->client->send(json_encode(array_merge(['id' => $this->client_incremental++], [
                    'type' => 'ping',
                ])));
            }
            return true;
        }
        return false;
    }

    static public function fromDb(array $data)
    {
        $users_token = [];
        $rooms = [];
        if (!empty($data['tb_users_token'])) {
            $users_token = json_decode($data['tb_users_token'], true);
        }
        if (!empty($data['tb_rooms'])) {
            $rooms = json_decode($data['tb_rooms'], true);
            foreach ($rooms as $i => $room) {
                $rooms[$i] = new Room($room);
            }
        }
        return new static([
            'team_id' => $data['tb_team_id'],
            'bot_id' => $data['tb_bot_id'],
            'bot_token' => $data['tb_bot_token'],
            'users_token' => $users_token,
            'rooms' => $rooms
        ]);
    }

    public function jsonSerialize()
    {
        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'bot_id' => $this->bot_id,
            'bot_token' => $this->bot_token,
            'users_token' => $this->users_token,
            'rooms' => $this->rooms,
        ];
    }
}
