## Pré-requis

* composer
* docker
* docker-compose

## Install

```
git clone https://bitbucket.org/clubble/bot2hook.git
cd bot2hook
```

### Storage directories 

Execute those commands in root directory

```
mkdir -m 777 -p storage
mkdir -m 777 -p storage/sqlite
mkdir -m 777 -p storage/logs
mkdir -m 777 -p storage/rabbitmq
```

### Composer 

Execute `composer install` in root directory

## Local testing

### Local host

Define a local domain.

Edit the `/etc/hosts` file with your favorite editor and with root permission. 
Add this line in the file.

```
127.0.0.1       bot2hook.local
```

You can change `bot2hook.local` to what you want but you must use the same domain in the docker-compose config file (sample in `docker/docker-compose.webhook_sample.yml`)
