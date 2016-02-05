# Bot2Hook

Turn Slack bots’ RTM events into webhooks — powered by Docker containers

## Prerequisites

* composer
* docker
* docker-compose

## Installation

```
git https://github.com/Mailclark/bot2hook.git
cd bot2hook
```

### Storage directories 

Execute these commands in root directory

```
mkdir -m 777 -p storage
mkdir -m 777 -p storage/sqlite
mkdir -m 777 -p storage/logs
touch storage/logs/error.log
chmod 777 storage/logs/error.log
mkdir -m 777 -p storage/rabbitmq
```

### Composer 

Execute `composer install` in root directory

## Local testing

### Define the local domain

Edit the `/etc/hosts` file with your favorite editor and with root permissions. 
Add this line in the file.

```
127.0.0.1       bot2hook.local
```

### PHP Config file

Go into the `sample/config/` directory. Copy the `sample_webhook.php` file into a new `testing_webhook.php`.

Edit the new `testing_webhook.php` file. 

Uncomment the `logger` part.
 

### Define slack team for logger

Go to https://api.slack.com/web. At the bottom of the page, create a personnal token associate to your team.

Copy this token and paste it in the subkey `token` of the `logger->slack` array of the new `testing_webhook.php` file.

In the subkey `channel`, indicate in which channel you ant to receive logs (ex `#debug`). This channel must exist.

### Starts docker containers

```
docker-compose -f docker/docker-compose.yml -f sample/docker-compose.webhook.yml up -d
```

Normally, you should receive 3 messages in your log channel :

> Boot2Hook server starting<br />
> Boot2Hook consumer starting<br />
> phpws listening on tcp://0.0.0.0:12345  

Checkout [Docker-compose CLI documentation](https://docs.docker.com/compose/reference/overview/) for more commands. 

### Create a testing bot for your slack team

Go to the bot configuration page in Slack App Directory: https://my.slack.com/apps/manage/A0F7YS25R-bots

Install a bot or configure a new one for your team:

* First choose his name.
* Save the integration.
* Call this page after change de `XXX`  by the new bot API Token you've just create: http://bot2hook.local/add_bot.php?bot=XXX

Normally, you should receive a wave of new messages in your log channel, and at least have a message begining by 

> Sample webhook event received: 

Following by the json of the `rtm_start` event.

Now your bot must be connected in your team. If you talk to it, you receive event for your messages in the log channel.

## Production

Bot2Hook is used in production to power [MailClark](https://mailclark.ai).

We suggest you to create a directory for your app by copying `sample/`.

#### Docker-compose file

Write your own docker-compose file, helping you with the `sample/docker-compose.webhook.yml`.

* Update the `bot2hook_rabbitmq` config, change the user and password to connect to RabbitMQ Management pages. You can also change the port (by default http://your.bot2hook.domain:8085)
* Update `volumes`, replace `sample/` by the path to your files. Remove the third volume pointing to `sample/public`, only use for testing usage. 
* Change the `CONF_FILE` value and use a label with meaning for you.
* You can remove the `extra_hosts` part, it's only usefull because we use a local domain for testing.

#### Apache conf

Write your own, helping you with the  `sample/apache2/bot2hook.conf`. You can choose any name for this file, just keep the `.conf` extension.

* Change the `CONF_FILE` with the same value that in the docker-compose file.
* Change the `ServerName` with the domain that you choose to host your Bot2Hook instance.

#### PHP configugration file

Write your own, helping you with the  `sample/config/sample_webhook.conf`. Give it the same name that the `CONF_FILE` value, with the `.php` extension.

* For production, we recommend to comment the lines `error_reporting`, `display_errors` and `debug`.
* Fill the `signature_key` value with whatever you want!
* Uncomment the logger part, like in testing, if you want to monitoring Bot2Hook in a Slack channel. Just change the priority for less verbose.
* Uncomment the `server` part and change the `webhook_url` value with your URL where you want to receive webhooks.

## Don't use Webhook, use only RabbitMQ
 
Bot2Hook use RabbitMQ in background to queue events. You can switch webhook process and only use RabbitMQ. 
[MailClark](https://mailclark.ai) use Bot2Hook in this way.
 
To do that:

* Do you own docker-compose base file, without the `bot2hook_rabbitmq` container
* Don't mount a volume for apache configuration for the `bot2hook_lasp` container
* In the `bot2hook_lasp` container config, link your RabbitMQ container in the section `links`
* For the PHP config file, use the  `sample/config/sample_rabbithook.conf` to write your own
    * Modify the `rabbitmq` part with your RabbitMQ configuration
    * In the `server` part, you can change the name of the queue you want to use to add bot to Bot2Hook  

## API

### Outgoing message API

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
    

### Add bot API

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

### Signature check

When you send data to the add bot webhook, or when you receive data from Bot2Hook on your external webhook,
you must use a signature to ensure the provenance.

This signature is passed by the header `BOT2HOOK_SIGNATURE`. 
Check `sample/public/webhook.php` and `app/classes/Bot2Hook/Signature.php` files to see how to generate or validate a signature.
