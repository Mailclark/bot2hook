<?php

namespace Bot2Hook;

use Bot2Hook\Entity\Bot;
use Bot2Hook\Entity\Room;
use Curl\Curl;
use Devristo\Phpws\Framing\WebSocketFrame;
use Devristo\Phpws\Framing\WebSocketOpcode;
use Devristo\Phpws\Messaging\WebSocketMessage;
use medoo;
use React\EventLoop\Factory;

class Batch
{
    protected $config = [];

    /** @var Logger */
    protected $logger;

    /** @var medoo */
    protected $database;

    /** @var Rabbitmq  */
    protected $rabbitmq;

    /** @var WebSocketClient */
    protected $b2h_client;

    /** @var int */
    protected $launch_at;

    /** @var int */
    protected $batch_id;

    /** @var int */
    protected $batch_count;

    /** @var bool */
    protected $migration_status = false;

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

        $this->database = new medoo([
            'database_type' => 'sqlite',
            'database_file' => $this->config['sqlite_path'],
        ]);
        if (!is_array($this->config['events_excluded'])) {
            $this->config['events_excluded'] = explode(',', $this->config['events_excluded']);
        }

        $this->curl = new Curl();

        $this->loop = Factory::create();
    }

    public function launch()
    {
        $this->loop->addTimer(5, function() {
            $this->logger->debug('Bot2hook batch launch connection to bot2hook server.');
            $this->connectToServer();
        });
        $this->loop->addPeriodicTimer(60, function() {
            if (empty($this->b2h_client) || $this->b2h_client->getState() == WebSocketClient::STATE_CLOSED) {
                $this->logger->debug('Bot2hook batch retry connection to bot2hook server ' . (empty($this->b2h_client) ? 'null' : $this->b2h_client->getState()));
                $this->connectToServer();
            }
        });
        $this->loop->addPeriodicTimer($this->config['delay_ping'], function() {
            if (!empty($this->b2h_client) && $this->b2h_client->getState() == WebSocketClient::STATE_CONNECTED) {
                $this->b2h_client->sendFrame(WebSocketFrame::create(WebSocketOpcode::PingFrame));
            }
            foreach ($this->bots_connected as $tb_id => $connected) {
                if ($connected) {
                    $always_connected = $this->bots[$tb_id]->clientSendPing();
                    if (!$always_connected) {
                        $this->logger->warn('Bot2hook batch '.$this->batch_id.', ping fail set to retry for bot '.$tb_id);
                        $this->setToRetry($this->bots[$tb_id]);
                    }
                }
            }

            $bot_connecting = count($this->bots_connected) - count(array_filter($this->bots_connected));
            if ($bot_connecting < 1) {
                $tb_id = array_shift($this->bots_retrying);
                if (!empty($tb_id)) {
                    $this->logger->debug('Bot2hook batch '.$this->batch_id.', try to reconnect client for bot '.$tb_id);
                    $this->addSlackClient($this->bots[$tb_id]);
                }
            }
        });

        $this->loop->run();
    }

    protected function connectToServer()
    {
        $this->b2h_client = new WebSocketClient($this->config['url_for_client'], $this->loop, $this->logger);
        $this->b2h_client->on("message", function (WebSocketMessage $message)  {
            $data = json_decode($message->getData(), true);
            $this->logger->debug('Bot2hook batch '.$this->batch_id.' receive from bot2hook server message ' . $message->getData());
            if (is_array($data) && isset($data['type'])) {
                switch ($data['type']) {
                    case 'set_id':
                        if (empty($data['batch_id'])) {
                            $this->logger->err('Bot2hook batch receive an empty ID from bot2hook server, old ID='.$this->batch_id);
                            $this->loop->addTimer(30, function() {
                                $this->requestId();
                            });
                            break;
                        }
                        if (!empty($this->batch_id) && $data['batch_id'] != $this->batch_id) {
                            if (!empty($data['batch_id'])) {
                                foreach ($this->bots as $tb_id => $bot) {
                                    $bot->batch_id = $data['batch_id'];
                                    $this->updateTeamBot($bot);
                                }
                            }
                        }
                        $init = empty($this->batch_id) || !empty($data['force_init']);
                        $this->launch_at = $data['launch_at'];
                        $this->batch_id = $data['batch_id'];
                        $this->batch_count = $data['batch_count'];
                        $this->logger->debug('Bot2hook batch receive from bot2hook server is ID '.$this->batch_id.', init :'.$init);
                        $init && $this->initFromDB();
                        break;

                    case 'migration':
                    case 'add_bot':
                        $this->logger->debug('Bot2hook batch '.$this->batch_id.' receive from bot2hook server new bot '.json_encode($data['bot']));
                        if ($data['type'] == 'migration' || $data['bot']['batch_id'] == $this->batch_id) {
                            $bot = new Bot($data['bot']);
                            $this->updateTeamBot($bot);
                            $this->addSlackClient($bot);
                        }
                        break;

                    case 'request_team':
                        $this->batch_id = null;
                        if (!empty($this->bots)) {
                            $bot = array_shift($this->bots);
                            $bot->closeClient();
                            $this->b2h_client->send(json_encode([
                                'type' => 'migration',
                                'bot' => $bot,
                            ]));
                        } else {
                            $this->b2h_client->send(json_encode([
                                'type' => 'migration',
                                'bot' => null,
                            ]));
                        }
                        break;

                    case 'request_status':
                        $this->b2h_client->send(json_encode([
                            'type' => 'status',
                            'batch' => $this->batch_id,
                            'bots' => $this->bots,
                            'bots_connected' => $this->bots_connected,
                            'bots_retrying' => $this->bots_retrying,
                        ]));
                        break;

                    case 'request_reporting':
                        $this->b2h_client->send(json_encode([
                            'type' => 'reporting',
                            'batch' => $this->batch_id,
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

        $this->b2h_client->on("close", function () {
            $this->logger->err('Bot2hook batch '.$this->batch_id.' loose connection to bot2hook server');
            if (empty($this->batch_id)) {
                exit("restart\n");
            } else if ($this->migration_status) {
                exit("restart\n");
            }
        });

        $this->b2h_client->on("connect", function () {
            $this->logger->notice('Bot2hook batch '.$this->batch_id.' connected to bot2hook server');
            $this->requestId();
        });
        $this->b2h_client->open()->otherwise(function() {
            $this->loop->addTimer(5, function() {
                if (empty($this->b2h_client) || $this->b2h_client->getState() == WebSocketClient::STATE_CLOSED) {
                    $this->logger->debug('Bot2hook batch retry connection to bot2hook server ' . (empty($this->b2h_client) ? 'null' : $this->b2h_client->getState()));
                    $this->connectToServer();
                }
            });
        });
    }

    protected function requestId()
    {
        $this->b2h_client->send(json_encode([
            'type' => 'request_id',
            'launch_at' => $this->launch_at,
            'batch_id' => $this->batch_id,
        ]));
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

    protected function initFromDB()
    {
        $tbs = $this->database->select('team_bot', [
            'tb_id',
            'tb_team_id',
            'tb_bot_id',
            'tb_bot_token',
            'tb_batch_id',
            'tb_last_activity',
            'tb_users_token',
            'tb_rooms',
        ], [
            'tb_batch_id' => $this->batch_id,
            'ORDER' => 'tb_last_activity DESC, tb_id DESC',
        ]);
        $end = false;
        $init_bot = function() use (&$tbs, &$init_bot, &$end) {
            if (empty($tbs)) {
                !$end && $this->logger->debug('Bot2hook batch '.$this->batch_id.', end init');
                $end = true;
                return;
            }
            $bot_connecting = count($this->bots_connected) - count(array_filter($this->bots_connected));
            if ($bot_connecting < $this->config['concurrent_connecting_count']) {
                $tb = array_shift($tbs);
                if (!empty($tb)) {
                    $bot = Bot::fromDb($tb);
                    $this->bots[$bot->id] = $bot;
                    $this->logger->debug('Bot2hook batch '.$this->batch_id.', init bot '.$tb['tb_id'].' ('.count($this->bots).' bots, remaining '.count($tbs).')');
                    $this->addSlackClient($bot, $init_bot);
                    $this->loop->addTimer(1, $init_bot);
                } else {
                    $this->logger->debug('Bot2hook batch '.$this->batch_id.', end init');
                }
            } else {
                $this->loop->addTimer(1, $init_bot);
            }
        };
        $init_bot();
    }

    public function addSlackClient(Bot $bot, $connected_callback = null)
    {
        if (!isset($this->bots_connected[$bot->id])) {
            $this->logger->debug('Bot2hook batch '.$this->batch_id.', try to start client for bot ' . $bot->id);

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
                    if (!is_array($data) || !isset($data['type'])) {
                        $this->logger->info('Bot2hook batch '.$this->batch_id.', client for bot '.$bot->id." receive a message without type:\n".$message->getData());
                        return;
                    }
                    if ($data['type'] == 'team_migration_started') {
                        $this->logger->notice('Bot2hook batch '.$this->batch_id.', team migration started for bot '.$bot->id);
                        $this->setToRetry($this->bots[$bot->id]);
                    } elseif ($data['type'] == 'message') {
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

                        $bot->last_activity = time();
                        $this->updateTeamBot($bot);
                    } elseif (!in_array($data['type'], $this->config['events_excluded'])) {
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

                        $bot->last_activity = time();
                        $this->updateTeamBot($bot);
                    }
                });

                $slack_client->on("close", function () use ($bot) {
                    $this->logger->warn('Bot2hook batch '.$this->batch_id.', client closed for bot '.$bot->id);
                    // If bot not in bots array, it's a migration : not retry
                    if (!empty($this->bots[$bot->id])) {
                        $this->setToRetry($bot);
                    }
                });

                $slack_client->on("connect", function () use ($bot, $slack_client, $connected_callback) {
                    $this->logger->notice('Bot2hook batch '.$this->batch_id.', client connected for bot '.$bot->id);
                    $this->bots_connected[$bot->id] = true;
                    $bot->batch_id = $this->batch_id;
                    $this->updateTeamBot($bot);
                    unset($this->bots_retrying[$bot->id]);
                    $bot->setClient($slack_client);
                    if (!empty($connected_callback) && is_callable($connected_callback)) {
                        $connected_callback();
                    }
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

                $slack_client->open(15)->otherwise(function($e) use ($bot) {
                    if ($e instanceof \Exception) {
                        $this->logger->err('Bot2hook batch '.$this->batch_id.', promise exception in Bot '.$bot->id.' during connexion : '.get_class($e).' '.$e->getMessage()."\n".$e->getTraceAsString());
                    } else {
                        $this->logger->err('Bot2hook batch '.$this->batch_id.', promise error for bot '.$bot->id.' during connexion'.$e);
                    }
                    $this->setToRetry($bot);
                });
            } catch (InvalidTokenException $ite) {
                $this->logger->err('Bot2hook batch '.$this->batch_id.', bot '.$bot->id.' removed.');
                $this->publish($bot, [
                    'type' => 'bot_disabled',
                ]);
                $this->removeTeamBot($bot);
                unset($this->bots_connected[$bot->id]);
                unset($this->bots_retrying[$bot->id]);
            } catch (\Exception $e) {
                $this->logger->err('Bot2hook batch '.$this->batch_id.', exception in Bot '.$bot->id.' connexion : '.get_class($e).' '.$e->getMessage()."\n".$e->getTraceAsString());
                $this->setToRetry($bot);
            }
        } else {
            if (!empty($this->bots_connected[$bot->id])) {
                $this->logger->debug('Bot2hook batch '.$this->batch_id.', client already connect for bot ' . $bot->id);
            } else {
                $this->logger->debug('Bot2hook batch '.$this->batch_id.', client connection already in progress for bot ' . $bot->id);
            }
        }
    }

    protected function setToRetry(Bot $bot)
    {
        unset($this->bots_connected[$bot->id]);
        $bot->closeClient();
        $this->bots_retrying[$bot->id] = $bot->id;
    }

    protected function updateTeamBot(Bot $bot)
    {
        if (isset($this->bots[$bot->id])) {
            $bot->merge($this->bots[$bot->id]);
            $this->bots[$bot->id] = $bot;

            $this->database->update('team_bot', [
                'tb_batch_id' => $bot->batch_id,
                'tb_last_activity' => $bot->last_activity,
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
                'tb_batch_id' => $bot->batch_id,
                'tb_last_activity' => $bot->last_activity,
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
        $this->logger->debug('Bot2hook batch '.$this->batch_id.', try auth.test for bot with token ' . $bot_token);

        $response = $this->curl->get('https://slack.com/api/auth.test', [
            'token' => $bot_token,
        ]);
        if (empty($response) || !is_object($response)) {
            throw new \Exception();
        }
        if (!empty($response->ok)) {
            $this->logger->debug('Bot2hook batch '.$this->batch_id.', success auth.test for bot with token ' . $bot_token);
            return $response;
        } else {
            $this->logger->err('Bot2hook batch '.$this->batch_id.', fail auth.test for bot with token '.$bot_token.' error:'.$response->error);
            throw new \Exception();
        }
    }

    protected function rtmStart(Bot $bot)
    {
        $this->logger->debug('Bot2hook batch '.$this->batch_id.', try rtm.start for bot '.$bot->id.' with token ' . $bot->bot_token);

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
            $this->logger->debug('Bot2hook batch '.$this->batch_id.', success rtm.start for bot '.$bot->id.' with token ' . $bot->bot_token);
            return $response;
        } else {
            $this->logger->err('Bot2hook batch '.$this->batch_id.', fail rtm.start for bot '.$bot->id.' with token '.$bot->bot_token.' error:'.$response->error);
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
                    'from_recovery' => true,
                    'last_from_recovery' => true,
                    'event' => $message,
                ]);
                $latest = $message->ts;
            }
            $bot->rooms[$room_id]->latest = $latest;
            $this->updateTeamBot($bot);
        } else {
            $this->logger->err('Bot2hook batch '.$this->batch_id.', fail room history for bot '.$bot->id.' and room '.$room_id.' with token '.$token.' error:'.$response->error);
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
