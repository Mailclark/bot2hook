<?php

namespace Bot2Hook;

use Bot2Hook\Entity\Bot;
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

    /** @var WebSocketServer */
    protected $server;

    /** @var array */
    protected $batchs;

    /** @var int */
    protected $batch_count_total;

    /** @var int */
    protected $batch_count_active;

    /** @var array */
    protected $status;

    /** @var WebSocketTransportInterface */
    protected $status_client;

    /** @var array */
    protected $reporting;

    /** @var WebSocketTransportInterface */
    protected $reporting_client;

    /** @var array */
    protected $to_migrate;

    /** @var WebSocketTransportInterface */
    protected $current_migrate_from;

    /** @var WebSocketTransportInterface */
    protected $current_migrate_to;

    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->batch_count_total = $config['batch_count_total'];
        $this->batch_count_active = $config['batch_count_active'];

        if (is_file($this->config['sqlite_path'])) {
            exec('sqlite3 '.$this->config['sqlite_path'].' < '.DB_FILE_MIGRATION);
        } else {
            exec('sqlite3 '.$this->config['sqlite_path'].' < '.DB_FILE);
        }
        $this->database = new medoo([
            'database_type' => 'sqlite',
            'database_file' => $this->config['sqlite_path'],
        ]);
        $tbs = $this->database->select('team_bot', [
            'tb_id',
            'tb_team_id',
            'tb_batch_id',
        ], [
            'tb_batch_id' => null,
        ]);
        foreach ($tbs as $tb) {
            $this->database->update('team_bot', [
                'tb_batch_id' => $this->getBatchIdFromTeam($tb['tb_team_id']),
            ], [
                'tb_id' => $tb['tb_id'],
            ]);
        }
        $this->logger->debug('Bot2hook server end upgrade DB');

        $this->loop = Factory::create();

        for ($i = 1; $i <= $this->batch_count_total; $i++) {
            $this->batchs[$i] = null;
        }
    }

    public function launch()
    {
        $this->server = new WebSocketServer($this->config['url_for_server'], $this->loop, $this->logger);

        $this->server->on('message', function (WebSocketTransportInterface $client, WebSocketMessage $message) {
            $data = json_decode($message->getData(), true);
            $this->logger->debug('Bot2hook server receive message ' . $message->getData());
            if (is_array($data) && isset($data['type'])) {
                switch ($data['type']) {
                    case 'add_bot':
                        $this->logger->debug("Bot2hook server, new bot receive via incoming webhook " . json_encode($data['bot']));
                        $data['bot']['batch_id'] = $this->getBatchIdFromTeam($data['bot']['team_id']);
                        foreach($this->server->getConnections() as $client_connexion) {
                            $client_connexion->sendString($message->getData());
                        }
                        break;

                    case 'migration':
                        if (empty($data['bot'])) {
                            $this->logger->debug("Bot2hook server, migration end");
                            $this->current_migrate_from = null;
                            $this->migration();
                            break;
                        }
                        if (!empty($this->current_migrate_to)) {
                            $this->logger->debug("Bot2hook server, migration bot receive " . json_encode($data['bot']));
                            $this->current_migrate_to->sendString($message->getData());
                        } else {
                            $this->logger->err("Bot2hook server, migration bot receive but not have a batch to migrate " . json_encode($data['bot']));
                        }
                        break;

                    case 'request_status':
                        $this->status = [];
                        $this->status_client = $client;
                        foreach($this->server->getConnections() as $client_connexion) {
                            $client_connexion->sendString($message->getData());
                        }
                        break;

                    case 'request_reporting':
                        $this->reporting = [];
                        $this->reporting_client = $client;
                        foreach($this->server->getConnections() as $client_connexion) {
                            $client_connexion->sendString($message->getData());
                        }
                        break;

                    case 'request_id':
                        $batch_id = null;
                        $launch_at = time();
                        if (!empty($data['batch_id']) && empty($this->batchs[$data['batch_id']])) {
                            $batch_id = $data['batch_id'];
                            $launch_at = $data['launch_at'];
                            $this->batchs[$data['batch_id']] = [
                                'launch_at' => $launch_at,
                                'client' => $client,
                            ];
                        } else {
                            for ($i = 1; $i <= $this->batch_count_total; $i++) {
                                if (empty($this->batchs[$i])) {
                                    $batch_id = $i;
                                    $this->batchs[$i] = [
                                        'launch_at' => $launch_at,
                                        'client' => $client,
                                    ];
                                    break;
                                }
                            }
                        }

                        $client->sendString(json_encode([
                            'type' => 'set_id',
                            'launch_at' => $launch_at,
                            'batch_id' => $batch_id,
                            'batch_count' => $this->batch_count_active,
                        ]));
                        break;

                    case 'request_migration':
                        if (!empty($data['batch_id'])) {
                            if ($data['batch_id'] <= $this->batch_count_active) {
                                $this->to_migrate[] = $data['batch_id'];
                            }
                        } else {
                            for ($i = 1; $i <= $this->batch_count_active; $i++) {
                                $this->to_migrate[] = $data['batch_id'];
                            }
                        }
                        array_unique($this->to_migrate);
                        $this->migration();
                        break;

                    case 'reporting':
                        //@todo Update reporting cron
                        $this->reporting[] = $data;
                        if (count($this->reporting) == $this->batch_count_total) {
                            $this->reporting_client->sendString(json_encode($this->reporting));
                        }
                        break;

                    case 'status':
                        //@todo Update status page
                        $this->status[] = $data;
                        if (count($this->status) == $this->batch_count_total) {
                            $this->status_client->sendString(json_encode($this->status));
                        }
                        break;
                }
            }
        });

        $this->server->on('disconnect', function(WebSocketTransportInterface $client) {
            $batch_id = null;
            for ($i = 1; $i <= $this->batch_count_total; $i++) {
                if (!empty($this->batchs[$i]) && $this->batchs[$i]['client'] == $client) {
                    $batch_id = $i;
                }
            }
            if (!empty($batch_id)) {
                $this->logger->warn('Bot2hook server, batch '.$batch_id.' disconnect');
                $this->batchs[$batch_id] = null;
                for ($i = $this->batch_count_active + 1; $i <= $this->batch_count_total; $i++) {
                    if (!empty($this->batchs[$i])) {
                        $this->batchs[$batch_id] = $this->batchs[$i];
                        $this->batchs[$i] = null;
                        $this->logger->warn('Bot2hook server, diconnected batch '.$batch_id.' assign to batch '.$i);
                        $this->batchs[$batch_id]['client']->sendString(json_encode([
                            'type' => 'set_id',
                            'launch_at' => time(),
                            'batch_id' => $batch_id,
                            'force_init' => true,
                            'batch_count' => $this->batch_count_active,
                        ]));
                        break;
                    }
                }
            } else {
                $this->logger->warn('Bot2hook server, client '.$client->getId().' disconnect but not found in batchs array');
            }
        });

        $this->server->bind();

        $this->loop->addPeriodicTimer(60 * 60, function() {
            if (date('N') > 5 && date('G') != 8) {
                return;
            }
            $oldest_batch_id = null;
            for ($i = 1; $i <= $this->batch_count_active; $i++) {
                if (empty($this->batchs[$i])) {
                    continue;
                }
                if (empty($oldest_batch_id) || $this->batchs[$i]['launch_at'] < $this->batchs[$oldest_batch_id]['launch_at']) {
                    $oldest_batch_id = $i;
                }
            }
            $this->to_migrate[] = $oldest_batch_id;
            array_unique($this->to_migrate);
            $this->migration();
        });

        $this->loop->run();
    }

    protected function migration()
    {
        if (empty($this->current_migrate_from) && !empty($this->to_migrate)) {
            $batch_id = array_shift($this->to_migrate);
            if (!empty($this->batchs[$batch_id])) {
                $this->current_migrate_to = null;
                for ($i = $this->batch_count_active + 1; $i <= $this->batch_count_total; $i++) {
                    if (!empty($this->batchs[$i])) {
                        $this->current_migrate_to = $this->batchs[$i]['client'];
                        $this->logger->err('Bot2hook server, migrate batch '.$batch_id.' to '.$i);
                        break;
                    }
                }
                if (!empty($this->current_migrate_to)) {
                    $this->current_migrate_to->sendString(json_encode([
                        'type' => 'set_id',
                        'launch_at' => time(),
                        'batch_id' => $batch_id,
                        'batch_count' => $this->batch_count_active,
                    ]));
                    $this->current_migrate_from = $this->batchs[$batch_id]['client'];
                    $this->current_migrate_from->sendString(json_encode([
                        'type' => 'request_team',
                    ]));
                    $this->batchs[$batch_id] =  [
                        'launch_at' => time(),
                        'client' => $this->current_migrate_to,
                    ];
                } else {
                    array_unshift($this->to_migrate, $batch_id);
                    array_unique($this->to_migrate);
                    $this->loop->addTimer(60, function() {
                        $this->migration();
                    });
                    $this->logger->err('Bot2hook server, not found a inactive batch to migrate');
                }
            } else {
                $this->migration();
            }
        }
    }

    protected function getBatchIdFromTeam($team_id)
    {
        return base_convert(substr($team_id, 1), 36, 10) % $this->batch_count_active + 1;
    }
}
