# Bot2Hook

Slack bots containers, transforming RTM events to webhooks

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

Execute those commands in root directory

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

Edit the `/etc/hosts` file with your favorite editor and with root permission. 
Add this line in the file.

```
127.0.0.1       bot2hook.local
```

### PHP Config file

Go into the `sample/config/` directory. Copy the `sample_webhook.php` file into a new `testing_webhook.php`.

Edit the new `testing_webhook.php` file. 

Uncomment the `logger` part
 

### Define slack team for logger

Go to https://api.slack.com/web, at the bottom of the page, create a personnal token associate to your team.

Copy this token and paste it in the subkey `token` of the `logger->slack` array of the new `testing_webhook.php` file.

In the subkey `channel`, indicate in which channel you ant to receive logs (ex `#debug`). This channel must exist.

### Starts docker containers

```
docker-compose -f docker/docker-compose.yml -f sample/docker-compose.webhook.yml up -d
```

Normally, you should receive 3 messages in your log channel :

> Boot2Hook server starting
> Boot2Hook consumer starting
> phpws listening on tcp://0.0.0.0:12345

### Create a testing bot for your slack team

Go to the bot configuration page in Slack App Directory: https://my.slack.com/apps/manage/A0F7YS25R-bots

Install a bot or configure a new one for your team:

* First choose his name.
* Save the integration.
* Call this page after change de `XXX`  by the new bot API Token you've just create: http://bot2hook.local/add_bot.php?bot=XXX

Normally, you should receive a wave of new messages in your log channel, and at least have a message begining by 

> Sample webhook event received: 

Following by the json of the rtmStart event.

Now your bot must be connected in your team. If you talk to it, you receive event for your messages in the log channel.

## Production

Bot2hook is used in production to power [MailClark](https://mailclark.ai).

### Using Webhook

### Using RabbitMQ
