composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
composer dump-autoload
php artisan migrate --force
php artisan optimize:clear
php artisan optimize

echo "Setting up cronjob for scheduler"
echo "* * * * * php /var/www/html/artisan schedule:run >> /var/log/artisan-scheduler.log 2>&1" | crontab -
