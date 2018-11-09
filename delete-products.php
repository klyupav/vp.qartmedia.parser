<?php
/**
 * Created by PhpStorm.
 * User: klyupav
 * Date: 02.10.18
 * Time: 18:10
 */
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/WP.php';
require __DIR__.'/../wp-config.php';

$start = time();
$time_limit = 1000;
set_time_limit($time_limit);

$config = new \Doctrine\DBAL\Configuration();
$connectionParams = array(
    'dbname' => DB_NAME,
    'user' => DB_USER,
    'password' => DB_PASSWORD,
    'host' => DB_HOST,
    'driver' => 'pdo_mysql',
    'charset' => DB_CHARSET,
);
$conn = \Doctrine\DBAL\DriverManager::getConnection($connectionParams, $config);

$site_url = 'http://vp.qartmedia.tmweb.ru';
$site_root_dir = __DIR__.'/..';

$wp = new \Parser\WP($conn, $site_url, $site_root_dir);

$wp->deleteAllProducts();

//exec('php export.php');