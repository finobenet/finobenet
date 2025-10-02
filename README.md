<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://finobe.net/s/img/logo.png" width="400" alt="Finobe Logo"></a></p>

<p align="center">
Laravel status<br>
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Finobe/Aesthetiful Source code

This repository contains all the things needed for you to start.

# Disclaimer

> ⚠️ **Important:** By using this project, you agree that you are responsible for your own setup and usage. The authors are not liable for any issues caused by this software.

# Setup

Before setting up, this project is design to run on Ubuntu 24.04 or newer, you need to install the following packages:

php8.3-fpm
php8.3-redis
php8.3-soap
php8.3-xml
php8.3-readline
php8.3-common
php8.3-gd
php8.3-zip
php8.3-mbstring
php8.3-mysql
php8.3-curl
nginx
mysql-server
redis-server
composer
timidity
supervisor
ffmpeg

Make sure to have this in your nginx server block when making it
```
location / {
        try_files $uri $uri/ /index.php?$query_string;
}
```

Keep in mind that Google is your friend while setting this up, we don't have dedicated people to help with issues that is related to this repository
1. Setup mysql and your nginx configuration
2. Setup redis with protection mode off (the redis server is hosted locally only)
3. Download this repository and import the database with the file provided
4. Copy .env.example to .env and setup the variables in there especially the ones that mention domain (or it wont work)
5. in the folder run "sudo chown -R www-data:www-data ./" and "sudo chmod -R 775 ./"
6. run the composer install command
7. run the laravel migration command
8. run the laravel key generation command

# Post-requisite
- Setting up a worker client is required for video submissions to be processed
- You have to aquire your self all the things needed to get a working client to work on here
