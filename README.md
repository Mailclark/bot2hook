![Bot2Hook - Turn Slack bots’ Real-Time Messaging events into webhooks — powered by Docker containers and brought to you by the makers of MailClark](https://mailclark.ai/static/img/logos/bot2hook.png)

Ready to create a Slack bot, but not-so-ready to code a Real-Time Messaging app? Wondering how you’re going to maintain a bot across hundreds of teams?<br />
Don’t worry, **no need to change the way you code**, let Bot2Hook take care of RTM for you. Thanks to Bot2Hook, developing a bot is as easy as developing a slash command app.

Bot2Hook is written in PHP and uses RabbitMQ, but **you only need to know Docker and RabbitMQ** to run it.

Bot2Hook is brought to you by the makers of [MailClark](https://mailclark.ai) (we use it in production).<br />
An alternative solution is [Relax](https://github.com/zerobotlabs/relax) by our friends at Nestor.

## Under the hood
Bot2Hook has three main components: 

1. Multiple batches which opens a websocket client connection for each bot. When a bot receives an event, this service pushes it to a RabbitMQ queue.
2. A websocket server connection, used to manage all batches.
3. A consumer which consumes the RabbitMQ queue to add new bots to the system.


## Prerequisites

* composer
* docker
* docker-compose

## Installation

```
git https://github.com/Mailclark/bot2hook.git
cd bot2hook
```

### Storage folders 

Execute these commands in the root folder.

```
mkdir -m 777 -p storage
mkdir -m 777 -p storage/sqlite
mkdir -m 777 -p storage/logs
mkdir -m 777 -p storage/logs/supervisor
touch storage/logs/error.log
chmod 777 storage/logs/error.log
mkdir -m 777 -p storage/rabbitmq
```

### Composer 

Execute `composer install` in the root folder.

## Configuration

We recommend to keep the `sample/` folder for future reference. Duplicate it and rename the new folder.

### Docker-compose file

Edit `your_conf_folder/docker-compose.webhook.yml`.

* Update the `bot2hook_rabbitmq` parameters:
 * User and password to connect to RabbitMQ Management UI;
 * Change the port, if needed (by default http://your.bot2hook.domain:8085).
* Update `CONF_FILE`: pick any name you like.

### PHP config file

Copy `app/config/global.php` in `app/config/env/` and rename it with the `CONF_FILE` value (found in both the docker-compose and Apache config files), keep the `.php` extension.

* Update the `rabbitmq` section with your RabbitMQ configuration.

## API

### Add a new bot

To add a bot to Bot2Hook

```php
[
    'bot' => [
        'bot_id' => 'BOT_ID',
        'bot_token' => 'the-bot-token',
        'team_id' => 'TEAM_ID',
        'users_token' => [
            'FIRST_USER_ID' => 'first-user-token',
            'SECOND_USER_ID' => 'second-user-token',
        ],
    ],
]
```

The `users_token` is used by Bot2Hook to retrieve missing messages, using Slack Web API, when a bot gets disconnected (it does happen). This operation isn't allowed with a bot token. 

### Receiving messages

When a bot receives an event from Slack, Bot2Hook posts it to your webhook URL. 
 
```php
[
    'webhook_event' => [
        'type' => 'event_type',
        'bot' => 'BOT_ID',
        'team' => 'TEAM_ID',
        'event' => [
            //... Same event receive from Slack
        ],
    ]
]
```

For event and type keys, read [Slack API documentation](https://api.slack.com/events).

There are also three Bot2Hook-specific event types:

* `channel_recovery` (5 keys: `type`, `bot`, `team`, `channel` and `latest`) when Bot2Hook has missed messages in a channel and isn’t able to recover them.
* `group_recovery` (5 keys: `type`, `bot`, `team`, `group` and `latest`) when Bot2Hook has missed messages in a group and isn’t able to recover them.
* `bot_disabled` (3 keys: `type`, `bot` and `team`) when Bot2Hook receives an error indicating that the bot token has been revoked.
