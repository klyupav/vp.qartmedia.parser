<?php
/**
 * Created by PhpStorm.
 * User: klyupav
 * Date: 02.10.18
 * Time: 18:10
 */
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/Parser.php';
require __DIR__.'/WP.php';
require __DIR__.'/../wp-config.php';

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

$source_csv_filename = __DIR__.'/../CSV/NV_120.csv';
$source_image_dir = '/Foto/';
$site_url = 'http://vp.qartmedia.tmweb.ru';
$site_root_dir = '..';

$parser = new \Parser\Parser([
    'source_csv_filename' => $source_csv_filename,
    'source_image_dir' => $source_image_dir,
]);
$wp = new \Parser\WP($conn, $site_url, $site_root_dir);

foreach ($parser->get_all_article_from_images() as $article => $images)
{
    if ($row = $parser->find_in_csv($article))
    {
        if (empty($row[2]))
        {
            continue;
        }
        // Если находит артикул, добавляет товар на сайт (фото, цена, категория и доп характеристики)
        $wp->addProduct([
            'article' => $article,
            'images' => $images,
            'price' => $row[1],
            'name' => $row[2],
            'category' => empty(trim($row[3])) ? $row[4] : [ $row[3], $row[4] ] ,
        ]);
    }
}
$tree = $wp->updateCategoryTree();