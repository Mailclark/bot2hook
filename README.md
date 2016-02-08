# Bot2Hook

Turn Slack bots’ Real-Time Messaging events into webhooks — powered by Docker containers. Brought to you by the makers of [MailClark](https://mailclark.ai) (Bot2Hook is used in production to power MailClark).

Ready to create a Slack bot, but not-so-ready to code a RTM app? How will you maintain a bot across hundreds of teams?
Don’t worry, code your app as you always do, and let Bot2Hook take care of RTM. Thanks to Bot2Hook, developing a bot is as easy as developing a slash command app.

Bot2Hook is written in PHP and uses RabbitMQ, but **you only need to know Docker** to run it.

An alternative solution is [Relax](https://github.com/zerobotlabs/relax) by our friends at Nestor.

## Under the hood
Bot2Hook has three main components: 

1. An incoming hook called to add new bots to the system. It can either be a webhook (default), or a RabbitMQ queue.
2. A service which opens a websocket client connection for each bot:
 * When a bot receives an event, this service pushes it to a RabbitMQ queue.
 * This service also opens a websocket server connection, used to receive ‘add a new bot’ messages.
3. A consumer which consumes all RabbitMQ queues and launches separate processes.


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
touch storage/logs/error.log
chmod 777 storage/logs/error.log
mkdir -m 777 -p storage/rabbitmq
```

### Composer 

Execute `composer install` in the root folder.

## Testing Boot2Hook

### Define the local domain

Edit the `/etc/hosts` file using your favorite editor, with root permissions. 
Add this line to the file:

```
127.0.0.1       bot2hook.local
```

### Edit the PHP config file

Go to the `sample/config/` folder. Copy the `sample_webhook.php` file into a new `testing_webhook.php`.

Edit the new `testing_webhook.php` file. 

Uncomment the `logger` part.

### Define the Slack team for Logger

Go to https://api.slack.com/web, scroll down to the bottom of the page and create a personal token associated to your team.

Copy and paste this token to the `token` subkey of the `logger->slack` array of the `testing_webhook.php` config file.

In the `channel` subkey, indicate the channel to receive logs (e.g. `#debug` — it has to be an existing channel).

### Start Docker containers

```
docker-compose -f docker/docker-compose.yml -f sample/docker-compose.webhook.yml up -d
```

You’ll receive 3 messages in the log channel:

> Boot2Hook server starting<br />
> Boot2Hook consumer starting<br />
> phpws listening on tcp://0.0.0.0:12345  

Check out [Docker-compose CLI documentation](https://docs.docker.com/compose/reference/overview/) for more commands. 

### Create a test bot for your Slack team

Go to the ‘Configure Bots’ page in Slack App Directory: https://my.slack.com/apps/manage/A0F7YS25R-bots

Install a bot, or configure a new one for your team:

* Choose his name.
* Save the integration.
* Go to http://bot2hook.local/add_bot.php?bot=XXX replacing `XXX` with the new bot API token you’ve just created. 

You’ll receive a wave of new messages in the log channel, including a message beginning with: 

> Sample webhook event received: 

Followed by the JSON of the rtmStart event.

The test bot must now appear as connected in your Slack team. Talk to him, you’ll receive the events corresponding to your messages in the log channel.

## Configuration

We recommend to keep the `sample/` folder for future reference. Duplicate it and rename the new folder.

### Docker-compose file

Edit `your_conf_folder/docker-compose.webhook.yml`.

* Update the `bot2hook_rabbitmq` parameters:
 * User and password to connect to RabbitMQ Management UI;
 * Change the port, if needed (by default http://your.bot2hook.domain:8085).
* Update `volumes`:
 * Replace `sample/` with the path to your files;
 * Remove the third volume pointing to sample/public, only used for testing purposes. 
* Update `CONF_FILE`: pick any name you like.
* Remove `extra_hosts`, only used for testing purposes.

### Apache config file

Edit `your_conf_folder/apache2/bot2hook.conf`. Rename this file if you like—just keep the `.conf` extension.

* Update `CONF_FILE` with the value found in the docker-compose file.
* Update `ServerName` with the domain where you’ll host your Bot2Hook instance.

### PHP config file

Edit `your_conf_folder/config/sample_webhook.php`. Name this file with `CONF_FILE` value (found in both the docker-compose and Apache config files), keep the `.php` extension.

* In production, we recommend you comment the following lines: `error_reporting`, `display_errors`, `debug`.
* Choose a `signature_key` used for encryption purposes. Your pet’s name will do ;)
* Uncomment the `logger` part if — as for local testing — you want to monitor Bot2Hook activity in a Slack channel.
 * Update `priority` to be less notified (e.g. `Logger:CRIT` — check out [Logger documentation](http://framework.zend.com/manual/current/en/modules/zend.log.overview.html#using-built-in-priorities)).
* Uncomment the `server` part and update `webhook_url` with the URL to receive webhooks.

### Signature check

When you send data to the add bot webhook, or when you receive data from Bot2Hook on your external webhook,
you must use a signature to ensure the provenance.

This signature is passed by the header `BOT2HOOK_SIGNATURE`. 
Check `sample/public/webhook.php` and `app/classes/Bot2Hook/Signature.php` files to see how to generate or validate a signature.

## API

### Outgoing message

Each time a bot receive an event from Slack, Bot2Hook post to your webhook URL. 
 
```
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

For event and type keys, check the [Slack API documentation](https://api.slack.com/events).

You can also receive webhook_event with those types:

* `channel_recovery` (5 keys: `type`, `bot`, `team`, `channel` and `latest`) when Bot2Hook has missed message in a channel and can't recover them.
* `group_recovery` (5 keys: `type`, `bot`, `team`, `group` and `latest`) when Bot2Hook has missed message in a group and can't recover them.
* `bot_disabled` (3 keys: `type`, `bot` and `team`) when Bot2Hook receive an error indicate that the bot token has been invalidate.
    

### Add bot

To add a bot in Bot2Hook, 2 choices:

```
[
    'bot' => 'the-bot-token',
]
```

or 

```
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

`users_token` are used by Bot2Hook to retrieve missing messages, with WEB API, when bot is disconnected (could happen). 
Bot token don't have permission for that. 

## Use RabbitMQ only (no webhooks)
 
Bot2Hook use RabbitMQ in background to queue events. You can switch webhook process and only use RabbitMQ. 
[MailClark](https://mailclark.ai) use Bot2Hook in this way.
 
To do that:

* Do you own docker-compose base file, without the `bot2hook_rabbitmq` container
* Don't mount a volume for apache configuration for the `bot2hook_lasp` container
* In the `bot2hook_lasp` container config, link your RabbitMQ container in the section `links`
* For the PHP config file, use the  `sample/config/sample_rabbithook.conf` to write your own
    * Modify the `rabbitmq` part with your RabbitMQ configuration
    * In the `server` part, you can change the name of the queue you want to use to add bot to Bot2Hook
