# Mirrors the "processed-files" image of the sites (Sites/*/docker/processed-files/), which is the
# WebDAV server this driver talks to in production. The sites build it from a private base image;
# a public Ubuntu image is used here so the tests can run anywhere.
# processed-files.conf is a verbatim copy of the sites' configuration - keep both in sync.
FROM ubuntu:22.04

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install --yes --no-install-recommends apache2 \
    && rm -rf /var/lib/apt/lists/*
RUN a2enmod dav dav_fs expires
RUN a2dissite 000-default
ADD --chown=root:root processed-files.conf /etc/apache2/sites-available/processed-files.conf
RUN a2ensite processed-files.conf

RUN mkdir -p /var/www/processed_files/
RUN chown www-data:www-data -R /var/www/processed_files/

CMD ["apache2ctl", "-D", "FOREGROUND"]

EXPOSE 80
