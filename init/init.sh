composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
composer dump-autoload
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
