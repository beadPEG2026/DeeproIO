# run dependencies
composer install
php artisan migrate -q

# change env value to compile routes and assets for user group
sed -i~ '/^USER_ASSETS=/s/=.*/="true"/' .env
php artisan ziggy:generate
npm run user

# change env value to compile routes and assets for admin group
sed -i~ '/^USER_ASSETS=/s/=.*/="false"/' .env
php artisan ziggy:generate resources/js/ziggy_alternative.js
npm run production

# update asset version
time=$(date +%s)
sed -i~ '/^USER_ASSETS_HASH=/s/=.*/="'$time'"/' .env


