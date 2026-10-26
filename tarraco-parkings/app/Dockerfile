# Imagen única de la aplicación: PHP 8.2 sobre Apache.
# No hay multi-stage porque no se compila nada: el código PHP se copia tal cual.
FROM php:8.2-apache

# openssl se usa en el arranque para generar el certificado autofirmado si
# no existe. pdo_mysql es la única extensión que necesita la aplicación.
RUN apt-get update \
 && apt-get install -y --no-install-recommends openssl libonig-dev \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install pdo pdo_mysql mbstring \
 && a2enmod rewrite ssl headers \
 && printf 'ServerName tarraco.local\n' > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername

# Un solo VirtualHost con HTTP (redirección) y HTTPS (aplicación).
COPY docker/apache-ssl.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-tarraco.ini

# Código de la aplicación. El DocumentRoot es /var/www/html/public.
COPY . /var/www/html

RUN mkdir -p /var/www/html/public/uploads /etc/apache2/certs \
 && chown -R www-data:www-data /var/www/html/public/uploads \
 && chmod -R 775 /var/www/html/public/uploads

COPY docker/entrypoint.sh /usr/local/bin/tarraco-entrypoint
RUN chmod +x /usr/local/bin/tarraco-entrypoint

EXPOSE 80 443

ENTRYPOINT ["tarraco-entrypoint"]
CMD ["apache2-foreground"]
