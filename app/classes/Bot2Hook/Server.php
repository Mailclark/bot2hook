<?php

namespace Bot2Hook;

use Bot2Hook\Entity\Bot;
use Bot2Hook\Entity\Room;
use Curl\Curl;
use Devristo\Phpws\Client\WebSocket;
use Devristo\Phpws\Messaging\WebSocketMessage;
use Devristo\Phpws\Protocol\WebSocketTransportInterface;
use Devristo\Phpws\Server\WebSocketServer;
use medoo;
use React\EventLoop\Factory;

class Server
{
    protected $config = [];

    /** @var Logger */
    protected $logger;

    /** @var medoo */
    protected $database;

    /** @var Rabbitmq  */
    protected $rabbitmq;

    /** @var Curl */
    protected $curl;

    /** @var Bot[] */
    protected $bots = [];

    /** @var array */
    protected $bots_connected = [];

    /** @var array */
    protected $bots_retrying = [];

    public function __construct(array $config, Rabbitmq $rabbitmq, Logger $logger)
    {
        $this->config = $config;
        $this->rabbitmq = $rabbitmq;
        $this->logger = $logger;

        exec('sqlite3 '.$this->config['sqlite_path'].' < '.DB_FILE);
        $this->database = new medoo([
            'database_type' => 'sqlite',
            'database_file' => $this->config['sqlite_path'],
        ]);

        $this->curl = new Curl();

        $this->loop = Factory::create();
    }

    public function launch()
    {
        $server = new WebSocketServer($this->config['url'], $this->loop, $this->logger);

        $this->loop->addPeriodicTimer($this->config['delay_try_reconnect'], function() {
            foreach ($this->bots_retrying as $tb_id => $true) {
                $this->logger->debug('Try to reconnect client for bot '.$tb_id);
                $this->addSlackClient($this->bots[$tb_id]);
            }
        });
        $this->loop->addPeriodicTimer($this->config['delay_ping'], function() {
            foreach ($this->bots_connected as $tb_id => $connected) {
                if ($connected) {
                    $always_connected = $this->bots[$tb_id]->clientSend([
                        'type' => 'ping',
                    ]);
                    if (!$always_connected) {
                        $this->setToRetry($this->bots[$tb_id]);
                    }
                }
            }
        });
        $server->on('message', function (WebSocketTransportInterface $user, WebSocketMessage $message) {
            $data = json_decode($message->getData(), true);
            $this->logger->debug("Bot2hook websocket server receive message " . $message->getData());
            if (is_array($data) && isset($data['type'])) {
                switch ($data['type']) {
                    case 'add_bot':
                        $this->logger->debug("New bot receive via incomming webhook " . json_encode($data['bot']));
                        $bot = new Bot($data['bot']);
                        $this->addSlackClient($bot);
                        break;

                    case 'status':
                        $user->sendString(json_encode([
                            'bot2hook' => [
                                'bots' => $this->bots,
                                'bots_connected' => $this->bots_connected,
                                'bots_retrying' => $this->bots_retrying,
                            ],
                        ]));
                        break;

                    case 'reporting':
                        $user->sendString(json_encode([
                            'memory' => [
                                'usage' => memory_get_usage(true),
                                'peak_usage' => memory_get_peak_usage(true),
                                'limit' => $this->getMemoryLimit(),
                            ],
                            'bots_count' => [
                                'connected' => count($this->bots_connected),
                                'retrying' => count($this->bots_retrying),
                            ],
                        ]));
                        break;
                }
            }
        });

        $server->bind();

        $this->init();

        $this->loop->run();
    }

    // http://stackoverflow.com/a/28978624/211204
    protected function getMemoryLimit()
    {
        $limit = ini_get('memory_limit');
        $last = strtolower($limit[strlen($limit)-1]);
        switch($last) {
            // The 'G' modifier is available since PHP 5.1.0
            case 'g':
                $limit *= 1024;
            case 'm':
                $limit *= 1024;
            case 'k':
                $limit *= 1024;
        }
        return $limit;
    }

    protected function init()
    {
        $tbs = $this->database->select('team_bot', [
            'tb_id',
            'tb_team_id',
            'tb_bot_id',
            'tb_bot_token',
            'tb_users_token',
            'tb_rooms',
        ]);
        foreach ($tbs as $tb) {
            $bot = Bot::fromDb($tb);
            $this->addSlackClient($bot);
        }
    }

    public function addSlackClient(Bot $bot)
    {
        if (empty($bot->id)) {
            $this->logger->debug('Try to auth test for bot ' . $bot->bot_token);

            try {
                $auth = $this->authTest($bot->bot_token);
                $bot->setIds($auth->team_id, $auth->user_id);
            } catch (\Exception $e) {
                $this->logger->err('Exception in Bot '.$bot->bot_token.' connexion : '.$e->getMessage()."\"".$e->getTraceAsString());
                return;
            }
        }

        $this->updateTeamBot($bot);
        if (!isset($this->bots_connected[$bot->id])) {
            $this->logger->debug('Try to start client for bot ' . $bot->id);

            $this->bots_connected[$bot->id] = false;
            try {
                $start = $this->rtmStart($bot);
                $this->publish($bot, [
                    'type' => 'rtm_start',
                    'event' => $start,
                ]);

                $slack_client = new WebSocketClient($start->url, $this->loop, $this->logger);

                $slack_client->on("message", function (WebSocketMessage $message) use ($bot) {
                    $data = json_decode($message->getData(), true);
                    if (!is_array($data) && !isset($data['type'])) {
                        $this->logger->warn('Client for bot '.$bot->id." recieve a message without type:\n".$message->getData());
                        return;
                    }
                    if ($data['type'] == 'message') {
                        $bot->updateRoomLatest($data['channel'], $data['ts']);
                        if (isset($data['subtype']) && $data['subtype'] == 'group_join') {
                            $bot->addRoomMember($data['channel'], $data['user']);
                        }
                        if (isset($data['subtype']) && $data['subtype'] == 'group_leave') {
                            $bot->removeRoomMember($data['channel'], $data['user']);
                        }
                        $this->publish($bot, [
                            'type' => 'message',
                            'event' => $data,
                        ]);

                        $this->updateTeamBot($bot);
                    } elseif (!in_array($data['type'], ['pong', 'reconnect_url', 'presence_change', 'user_typing', 'hello'])) {
                        if ($data['type'] == 'group_joined') {
                            $bot->rooms[$data['channel']['id']] = new Room(['members' => $data['channel']['members']]);
                        }
                        if ($data['type'] == 'group_left') {
                            unset($bot->rooms[$data['channel']]);
                        }

                        $this->publish($bot, [
                            'type' => $data['type'],
                            'event' => $data,
                        ]);

                        $this->updateTeamBot($bot);
                    }
                });

                $slack_client->on("close", function () use ($bot) {
                    $this->logger->warn("Client closed for bot " . $bot->id);
                    $this->setToRetry($bot);
                });

                $slack_client->on("connect", function () use ($bot, $slack_client) {
                    $this->logger->debug("Client connect for bot " . $bot->id);
                    $this->bots_connected[$bot->id] = true;
                    unset($this->bots_retrying[$bot->id]);
                    $bot->setClient($slack_client);
                });

                foreach ($start->channels as $channel) {
                    if (!$channel->is_archived && $channel->is_member) {
                        if (!$bot->isRoomUpToDate($channel->id, $channel->latest)) {
                            try {
                                $this->channelHistory($bot, $channel->id);
                            } catch (NoMoreTokenException $nmte) {
                                $this->publish($bot, [
                                    'type' => 'channel_recovery',
                                    'channel' => $channel->id,
                                    'latest' => $bot->rooms[$channel->id]->latest,
                                ]);
                            }
                        }
                    }
                }
                foreach ($start->groups as $group) {
                    if (!$group->is_archived) {
                        $bot->initRoom($group->id, $group->members);
                        if (!$bot->isRoomUpToDate($group->id, $group->latest)) {
                            try {
                                $this->groupHistory($bot, $group->id);
                            } catch (NoMoreTokenException $nmte) {
                                $this->publish($bot, [
                                    'type' => 'group_recovery',
                                    'group' => $group->id,
                                    'latest' => $bot->rooms[$group->id]->latest,
                                ]);
                            }
                        }
                    }
                }
                foreach ($start->ims as $im) {
                    if (!$bot->isRoomUpToDate($im->id, $im->latest)) {
                        $this->imHistory($bot, $im->id);
                    }
                }
                foreach ($start->mpims as $mpim) {
                    if (!$bot->isRoomUpToDate($mpim->id, $mpim->latest)) {
                        $this->mpimHistory($bot, $mpim->id);
                    }
                }

                $slack_client->open();
            } catch (InvalidTokenException $ite) {
                $this->logger->err('Bot '.$bot->id.' removed.');
                $this->publish($bot, [
                    'type' => 'bot_disabled',
                ]);
                $this->removeTeamBot($bot);
                unset($this->bots_connected[$bot->id]);
                unset($this->bots_retrying[$bot->id]);
            } catch (\Exception $e) {
                $this->logger->err('Exception in Bot '.$bot->id.' connexion : '.$e->getMessage()."\"".$e->getTraceAsString());
                $this->bots_retrying[$bot->id] = true;
                unset($this->bots_connected[$bot->id]);
            }
        } else {
            if (!empty($this->bots_connected[$bot->id])) {
                $this->logger->debug('Client already connect for bot ' . $bot->id);
            } else {
                $this->logger->debug('Client connection already in progress for bot ' . $bot->id);
            }
        }
    }

    protected function setToRetry(Bot $bot)
    {
        unset($this->bots_connected[$bot->id]);
        $bot->closeClient();
        $this->bots_retrying[$bot->id] = true;
    }

    protected function updateTeamBot(Bot $bot)
    {
        if (isset($this->bots[$bot->id])) {
            $bot->users_token = array_merge($this->bots[$bot->id]->users_token, $bot->users_token);
            $bot->rooms = array_merge($this->bots[$bot->id]->rooms, $bot->rooms);
            $this->bots[$bot->id] = $bot;

            $this->database->update('team_bot', [
                'tb_users_token' => json_encode($bot->users_token),
                'tb_rooms' => json_encode($bot->rooms),
            ], [
                'tb_id' => $bot->id,
            ]);
        } else {
            $this->bots[$bot->id] = $bot;
            $this->database->insert('team_bot', [
                'tb_id' => $bot->id,
                'tb_team_id' => $bot->team_id,
                'tb_bot_id' => $bot->bot_id,
                'tb_bot_token' => $bot->bot_token,
                'tb_users_token' => json_encode($bot->users_token),
                'tb_rooms' => json_encode($bot->rooms),
            ]);
        }
    }

    protected function removeTeamBot(Bot $bot)
    {
        unset($this->bots[$bot->id]);
        $this->database->delete('team_bot', [
            'tb_id' => $bot->id,
        ]);
    }

    protected function publish(Bot $bot, $data)
    {
        $this->rabbitmq->publishString(
            $this->config['rabbit_outgoing_queue'],
            json_encode(
                array_merge([
                    'bot' => $bot->bot_id,
                    'team' =>$bot->team_id,
                ], $data)
            )
        );
    }

    protected function authTest($bot_token)
    {
        $this->logger->debug('Try auth.test for bot with token ' . $bot_token);

        $response = $this->curl->get('https://slack.com/api/auth.test', [
            'token' => $bot_token,
        ]);
        if (empty($response) || !is_object($response)) {
            throw new \Exception();
        }
        if (!empty($response->ok)) {
            $this->logger->debug('Success auth.test for bot with token ' . $bot_token);
            return $response;
        } else {
            $this->logger->err('Fail auth.test for bot with token '.$bot_token.' error:'.$response->error);
            throw new \Exception();
        }
    }

    protected function rtmStart(Bot $bot)
    {
        $this->logger->debug('Try rtm.start for bot '.$bot->id.' with token ' . $bot->bot_token);

        $response = $this->curl->get('https://slack.com/api/rtm.start', [
            'token' => $bot->bot_token,
            'simple_latest' => 1,
            'no_unreads' => 1,
            'mpim_aware' => 1,
        ]);
        if (empty($response) || !is_object($response)) {
            throw new \Exception();
        }
        if (!empty($response->ok)) {
            $this->logger->debug('Success rtm.start for bot '.$bot->id.' with token ' . $bot->bot_token);
            return $response;
        } else {
            $this->logger->err('Fail rtm.start for bot '.$bot->id.' with token '.$bot->bot_token.' error:'.$response->error);
            if ($this->isTokenError($response->error)) {
                throw new InvalidTokenException($response->error);
            }
            throw new \Exception();
        }
    }

    protected function channelHistory(Bot $bot, $channel_id)
    {
        $channel = $bot->rooms[$channel_id];
        $token = $bot->getToken();
        if (empty($token)) {
            throw new NoMoreTokenException();
        }
        $response = $this->curl->get('https://slack.com/api/channels.history', [
            'token' => $token,
            'channel' => $channel_id,
            'oldest' => $channel->latest,
            'inclusive' => 0,
        ]);
        try {
            $this->responseHistory($bot, $channel_id, $token, $response);
        } catch (InvalidTokenException $ite) {
            if ($bot->removeUserToken($token)) {
                $this->updateTeamBot($bot);
            }
            $this->channelHistory($bot, $channel_id);
        }
    }

    protected function groupHistory(Bot $bot, $group_id)
    {
        $group = $bot->rooms[$group_id];
        $token = $bot->getToken($group->members);
        if (empty($token)) {
            throw new NoMoreTokenException();
        }
        $response = $this->curl->get('https://slack.com/api/groups.history', [
            'token' => $token,
            'channel' => $group_id,
            'oldest' => $group->latest,
            'inclusive' => 0,
        ]);
        try {
            $this->responseHistory($bot, $group_id, $token, $response);
        } catch (InvalidTokenException $ite) {
            if ($bot->removeUserToken($token)) {
                $this->updateTeamBot($bot);
            }
            $this->groupHistory($bot, $group_id);
        }
    }

    protected function imHistory(Bot $bot, $im_id)
    {
        $im = $bot->rooms[$im_id];
        $token = $bot->bot_token;
        $response = $this->curl->get('https://slack.com/api/im.history', [
            'token' => $token,
            'channel' => $im_id,
            'oldest' => $im->latest,
            'inclusive' => 0,
        ]);

        $this->responseHistory($bot, $im_id, $token, $response);
    }

    protected function mpimHistory(Bot $bot, $mpim_id)
    {
        $mpim = $bot->rooms[$mpim_id];
        $token = $bot->bot_token;
        $response = $this->curl->get('https://slack.com/api/im.history', [
            'token' => $token,
            'channel' => $mpim_id,
            'oldest' => $mpim->latest,
            'inclusive' => 0,
        ]);

        $this->responseHistory($bot, $mpim_id, $token, $response);
    }

    protected function responseHistory(Bot $bot, $room_id, $token, $response)
    {
        if ($response->ok) {
            $latest = null;
            usort($response->messages, function($a, $b)
            {
                if ($a->ts == $b->ts) {
                    return 0;
                }
                return ($a->ts < $b->ts) ? -1 : 1;
            });
            foreach ($response->messages as $message) {
                $message->channel = $room_id;
                $this->publish($bot, [
                    'type' => 'message',
                    'event' => $message,
                ]);
                $latest = $message->ts;
            }
            $bot->rooms[$room_id]->latest = $latest;
            $this->updateTeamBot($bot);
        } else {
            $this->logger->err("Fail room history for bot ".$bot->id.' and room '.$room_id.' with token '.$token.' error:'.$response->error);
            if ($this->isTokenError($response->error)) {
                throw new InvalidTokenException($response->error);
            }
        }
    }

    protected function isTokenError($error)
    {
        return in_array($error, ['account_inactive', 'invalid_token', 'invalid_auth', 'token_revoked', 'missing_scope']);
    }
}

class NoMoreTokenException extends \Exception
{}
class InvalidTokenException extends \Exception
{}
