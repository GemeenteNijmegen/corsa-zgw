composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
composer dump-autoload
php artisan migrate --force
php artisan optimize:clear
php artisan optimize

Echo "Setting up cronjob for scheduler"
echo "* * * * * /cronjob.sh" | crontab -
