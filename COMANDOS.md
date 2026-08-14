# Comando para utilizar en el servidor el php8.2

/opt/cpanel/ea-php82/root/usr/bin/php /opt/cpanel/composer/bin/composer install
/opt/cpanel/ea-php82/root/usr/bin/php artisan migrate:fresh --seed
