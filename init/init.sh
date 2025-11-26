composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
composer dump-autoload
php artisan migrate --force
php artisan optimize:clear
php artisan optimize

# create admin user
php artisan db:seet --class=UserSeeder