echo "Setting up cronjob for scheduler"
echo "* * * * * php /var/www/html/artisan schedule:run >> /var/log/artisan-scheduler.log 2>&1" | crontab -

echo "Starting cron"
cron

# Service requires mtls files not env parameters. Container env parameters are used to configure the path to load each file from.
echo "Setting up corsa MTLS"
mkdir /cert
echo -e $CORSA_MTLS_PRIVATE_KEY > /cert/corsa-mtls.key
echo -e $CORSA_MTLS_CERTIFICATE > /cert/corsa-mtls.crt
echo -e $CORSA_MTLS_CA_BUNDLE > /cert/corsa-mtls.pem