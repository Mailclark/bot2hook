FROM ubuntu:16.04

ENV DEBIAN_FRONTEND noninteractive

# Install updates
# Install supervisor
# Install utilities
# Install PHP
RUN apt-get update && apt-get upgrade -y && apt-get install -y \
    supervisor \
    nano unzip wget \
    sqlite3 \
    php7.0 php7.0-sqlite php7.0-curl php7.0-mbstring php7.0-bcmath

# Configure supervisor
RUN mkdir -p /var/log/supervisor

# Configure supervisord
RUN rm -rf /etc/supervisor/conf.d
RUN ln -s /supervisor /etc/supervisor/conf.d

# Configure
RUN mkdir -p /http /crontabs /supervisor

# PHP-CLI
ADD php-cli.ini /php-cli.ini
RUN ln -s /php-cli.ini /etc/php/7.0/cli/conf.d/04-custom.ini

# Expose clubble folder for mounting
VOLUME ["/http", "/supervisor"]

CMD ["/usr/bin/supervisord"]