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

$start = time();
$time_limit = 1000;
set_time_limit($time_limit);

$config = new \Doctrine\DBAL\Configuration();
$connectionParams = array(
    'dbname' => DB_NAME,
    'user' => DB_USER,
    'password' => DB_PASSWORD,
    'host' => DB_HOST,
    'driver' => PARSER_DB_DRIVER,
    'charset' => DB_CHARSET,
);
$conn = \Doctrine\DBAL\DriverManager::getConnection($connectionParams, $config);

$source_csv_filename = __DIR__.'/../CSV/'.PARSER_CSVFILENAME;
$source_xls_filename = __DIR__.'/../CSV/NV_120.xls';
$source_image_dir = '/Foto/';
$site_url = PARSER_SITE_URL;
$site_root_dir = __DIR__.'/..';

$parser = new \Parser\Parser([
    'source_csv_filename' => $source_csv_filename,
    'source_xls_filename' => $source_xls_filename,
    'source_image_dir' => $source_image_dir,
]);
$wp = new \Parser\WP($conn, $site_url, $site_root_dir);

$art_from_images = $parser->get_all_article_from_images();

foreach ($art_from_images as $article => $images)
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
            'price' => $row[8],
            'credit1' => $row[15],
            'credit2' => $row[16],
            'credit3' => $row[17],
            'sort' => $row[18],
            'related' => $row[19],
            'sale_price' => $row[1],
            'name' => $row[2],
            'desc' => 'Шуба, выполненная из переливающегося меха скандинавской норки, подчеркнет изысканность повседневного образа динамичной женщины, сделав акцент на безупречности ее имиджа. Эксклюзивная комбинированная расцветка изделия, созданная в сочетании контрастных градаций белого и черного оттенка – это отличительная черта модели.',
            'category' => empty(trim($row[3])) ? $row[4] : [ $row[3], $row[4], $row[5] ],
            'attr' => [
                'Длина' => trim($row[11]),
                'Цвет' => trim($row[12]),
                'Состав' => trim($row[9]),
                'Утеплитель' => trim($row[10]),
            ],
        ]);
    }
    if (time() - $start > $time_limit - 120)
    {
        break;
    }
}
$wp->conn->delete('wp_options', ['option_value' => '_transient_wc_term_counts']);
$tree = $wp->updateCategoryTree();