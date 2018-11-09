<?php
/**
 * Created by PhpStorm.
 * User: klyupav
 * Date: 02.10.18
 * Time: 20:30
 */

namespace Parser;

use Doctrine\DBAL\Connection;
use Gumlet\ImageResize;

class WP
{
    /*
     * Database connection
     * @var Doctrine\DBAL\Connection $conn
     */
    public $conn;
    protected $site_url;
    protected $site_root_dir;
    protected $upload_dir;

    public function __construct(Connection $connection, string $site_url = 'http://vp.qartmedia.tmweb.ru', $site_root_dir = '..', $upload_dir = '/wp-content/uploads/')
    {
        $this->conn = $connection;
        if (!empty($site_url))
        {
            $this->site_url = $site_url;
        }
        $this->site_root_dir = $site_root_dir;
        $this->upload_dir = $upload_dir;
    }

    /*
     * Add product
     * @param array $product
     */
    public function addProduct(array $product)
    {
        $price_info = '';
        if ( $product['price'] != $product['sale_price'] )
        {
            $price_info = "regular={$product['price']}, sale={$product['sale_price']}";
        }
        if ( $pid = $this->findProductIdByArticle($product['article']) )
        {
            // update product
            $this->updateProduct($product, $pid);
            print($product['article']." - isset. {$price_info}<br>\n");
        }
        else
        {
            // create product
            if(isset($product['images']) && is_array($product['images']))
            {
                foreach ($product['images'] as $key => $src)
                {
                    if ($uploadImage = $this->copyImageToUpload($src, $product['article']))
                    {
                        $product['images'][$key] = $uploadImage;
                    }
                }
            }
            $this->createProduct($product);
            print($product['article']." - added. {$price_info}<br>\n");
        }
    }

    /*
     * Find product ID by article
     * @param string $article
     * @return int|bool
     */
    private function findProductIdByArticle(string $article)
    {
        $sql = "SELECT post_id FROM wp_postmeta WHERE meta_key LIKE '_sku' AND meta_value LIKE '{$article}' ORDER BY meta_id DESC";
        $stmt = $this->conn->query($sql);
        if ($stmt->rowCount())
        {
            $row = $stmt->fetch();
            return $row['post_id'];
        }
        return false;
    }

    /*
     * Update product post
     * @param array $product
     * @param integer $pid
     * @return int|bool
     */
    private function updateProduct(array $product, int $pid)
    {
        return $this->updatePostMeta(['related' => @$product['related']], $pid);
    }

    /*
     * Create product post
     * @param array $product
     * @return int|bool
     */
    private function createProduct(array $product)
    {
        if (!isset($product['name']) || empty($product['name']))
        {
            return false;
        }
        $post_name = mb_strtolower($product['name']);
        $post_name = preg_replace('%[\d\s]+%uis', '-', $post_name);
        $post_name = preg_replace('%[\-]+%uis', '-', $post_name);
        $post_name = mb_substr($post_name, 0, 29);
        $post_name .=  "-" . mb_strtolower(@$product['article']);
        $post_name = preg_replace('%[\-]+%uis', '-', $post_name);

        $param = [
            'post_author' => '1',
            'post_date' => date('Y-m-d H:i:s'),
            'post_date_gmt' => date('Y-m-d H:i:s'),
            'post_content' => isset($product['desc']) ? $product['desc'] : '',
            'post_title' => isset($product['name']) ? $product['name'] : 'пустое имя',
            'post_excerpt' => '',
            'post_status' => 'publish',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_password' => '',
            'post_name' => strtolower(urlencode($post_name)),
            'to_ping' => '',
            'pinged' => '',
            'post_modified' => date('Y-m-d H:i:s'),
            'post_modified_gmt' => date('Y-m-d H:i:s'),
            'post_content_filtered' => '',
            'post_parent' => '0',
            'guid' => '',
            'menu_order' => '0',
            'post_type' => 'product',
            'post_mime_type' => '',
            'comment_count' => '0',
        ];
        if ($this->conn->insert('wp_posts', $param))
        {
            $pid = $this->conn->lastInsertId();
            $this->conn->update('wp_posts', [
                'guid' => $this->site_url."/?post_type=product&#038;p={$pid}"
            ], ['ID' => $pid]);
            $gallery = [];
            if (isset($product['images']) && is_array($product['images']))
            {
                foreach ($product['images'] as $image)
                {
                    if ($image_id = $this->createProductImage($image, $pid))
                    {
                        $gallery[$image_id] = $image;
                    }
                }
            }
            $this->createPostMeta([
                'post_id' => $pid,
                'gallery' => $gallery,
                'sku' => @$product['article'],
                'price' => @$product['price'],
                'credit1' => @$product['credit1'],
                'credit2' => @$product['credit2'],
                'credit3' => @$product['credit3'],
                'sort' => @$product['sort'],
                'sale_price' => @$product['sale_price'],
                'attr' => @$product['attr'],
                'related' => @$product['related'],
            ]);
            $this->conn->insert('wp_rp4wp_cache', [
                'post_id' => $pid,
                'word' => @$product['name'],
                'weight' => '0.987654',
                'post_type' => 'product',
            ]);
            if (isset($product['category']) && is_array($product['category']))
            {
                foreach ($product['category'] as $category)
                {
                    $cat_id = $this->createCategory(mb_strtoupper($category), isset($cat_id) ? $cat_id : 0);
                    $this->conn->insert('wp_term_relationships', ['object_id' => $pid, 'term_taxonomy_id' => $cat_id, 'term_order' => '0']);
                }
                $this->conn->insert('wp_term_relationships', ['object_id' => $pid, 'term_taxonomy_id' => 2, 'term_order' => '0']);
            }
            return $pid;
        }
        else
        {
            return false;
        }
    }

    /*
     * Delete product post
     * @param int $post_id
     * @return int|bool
     */
    private function deleteProduct(int $post_id)
    {
        $this->conn->delete('wp_posts', ['ID' => $post_id]);
        $image_ids = $this->deleteProductImage($post_id);
        $this->deletePostMeta($post_id);
        $this->conn->delete('wp_rp4wp_cache', ['post_id' => $post_id]);
        $this->conn->update('wp_term_taxonomy', ['count' => 0 ], ['taxonomy' => 'product_cat']);
        $this->conn->update('wp_termmeta', ['meta_value' => 0 ], ['meta_key' => 'product_count_product_cat']);
        $this->conn->delete('wp_term_relationships', ['object_id' => $post_id]);

        return true;
    }


    /*
     * Increase parent category count
     * @param int $cat_id
     * @return int|bool
     */
    private function updateCategoryCount(int $cat_id = 0)
    {
        if ($cat_id)
        {
            $result = $this->conn->query("SELECT * FROM wp_term_taxonomy WHERE term_id = {$cat_id} AND taxonomy LIKE 'product_cat'");
            if ($result->rowCount())
            {
                $row = $result->fetch();
                $count = $row['count'];
                $count = (int)$count;
                $count++;
                $this->conn->update('wp_term_taxonomy', ['count' => $count ], ['term_id' => $cat_id, 'taxonomy' => 'product_cat']);
                $this->conn->update('wp_termmeta', ['meta_value' => $count ], ['term_id' => $cat_id, 'meta_key' => 'product_count_product_cat']);
            }
            return true;
        }
        return false;
    }

    /*
     * Create category
     * @param string $category
     * @param int $parent
     * @return int|bool
     */
    private function createCategory(string $category, int $parent = 0)
    {
        if ($cat_id = $this->findCategory($category, $parent))
        {
//            $result = $this->conn->query("SELECT `count` FROM wp_term_taxonomy WHERE term_id = {$cat_id} AND taxonomy LIKE 'product_cat' AND parent = {$parent}");
//            if ($result->rowCount())
//            {
//                $count = $result->fetch()['count'];
//                $count = (int)$count;
//                $count++;
//                $this->conn->update('wp_term_taxonomy', ['count' => $count ], ['term_id' => $cat_id, 'taxonomy' => 'product_cat', 'parent' => $parent]);
//                $this->conn->update('wp_termmeta', ['meta_value' => $count ], ['term_id' => $cat_id, 'meta_key' => 'product_count_product_cat']);
//            }
            if ($this->updateCategoryCount($cat_id))
            {
                return $cat_id;
            }
        }
        else
        {
            $uri = strtolower($this->translit_str($category));
            $slug = strtolower(urlencode($uri));
            if ($this->conn->insert('wp_terms', ['name' => $category, 'slug' => $slug, 'term_group' => 0, 'term_order' => 0,]))
            {
                $cat_id = $this->conn->lastInsertId();
                $slug = strtolower(urlencode($uri."-{$cat_id}"));
                $this->conn->update('wp_terms', ['slug' => $slug ], ['term_id' => $cat_id]);
                $this->conn->insert('wp_term_taxonomy', ['term_id' => $cat_id, 'taxonomy' => 'product_cat', 'description' => '', 'parent' => $parent, 'count' => '1' ]);
                $this->conn->insert('wp_termmeta', ['term_id' => $cat_id, 'meta_key' => 'thumbnail_id', 'meta_value' => '0']);
                $this->conn->insert('wp_termmeta', ['term_id' => $cat_id, 'meta_key' => 'display_type', 'meta_value' => '']);
                $this->conn->insert('wp_termmeta', ['term_id' => $cat_id, 'meta_key' => 'order', 'meta_value' => $parent > 0 ? '5' : '1']);
                $this->conn->insert('wp_termmeta', ['term_id' => $cat_id, 'meta_key' => 'product_count_product_cat', 'meta_value' => '1']);
//                if ($this->updateCategoryCount($cat_id))
//                {
//                    return $cat_id;
//                }
            }
        }
        return false;
    }

    /*
     * Find category
     * @param string $category
     * @param int $parent
     * @return int|bool
     */
    private function findCategory(string $category, int $parent = 0)
    {
        $sql = "SELECT term.term_id FROM wp_terms as term, wp_term_taxonomy as tax WHERE tax.term_id = term.term_id AND term.name LIKE '{$category}' AND tax.parent = {$parent}";
        $stmt = $this->conn->query($sql);
        if ($stmt->rowCount())
        {
            $row = $stmt->fetch();
            if (isset($row ['term_id']))
            {
                return $row['term_id'];
            }
        }
        return false;
    }

    /*
     * Update category tree
     * @return array
     */
    public function updateCategoryTree()
    {
        $sql = "SELECT term.name, term.term_id FROM wp_terms as term, wp_term_taxonomy as tax WHERE tax.term_id = term.term_id AND tax.taxonomy LIKE 'product_cat'";
        $stmt = $this->conn->query($sql);
        if ($stmt->rowCount())
        {
            $rows = $stmt->fetchAll();
            $tree = [];
//            print_r($rows);die();
            foreach ($rows as $row)
            {
                if ($row['name'] === 'Uncategorized')
                {
                    continue;
                }
                if ($sub_cats = $this->findCatsByParrent($row['term_id']))
                {
                    $tree[$row['term_id']] = $sub_cats;
                }
            }
            $this->conn->update('wp_options', ['option_value' => serialize($tree) ], ['option_name' => 'product_cat_children']);
            return $tree;
        }
        return false;
    }


    /*
     * Find all sub cats
     * @return array|bool
     */
    private function findCatsByParrent($parent)
    {
        $sql = "SELECT term_id FROM wp_term_taxonomy WHERE parent = {$parent} AND taxonomy LIKE 'product_cat'";
        $stmt = $this->conn->query($sql);
        if ($stmt->rowCount())
        {
            $rows = $stmt->fetchAll();
            $cat_ids = [];
            foreach ($rows as $row)
            {
                $cat_ids[] = $row['term_id'];
            }
            return $cat_ids;
        }
        return false;
    }

    /*
     * Update post meta
     * @param array $param
     * @param integer $pid
     * @return bool
     */
    private function updatePostMeta(array $param, int $pid)
    {
        if (isset($param['related']))
        {
            $related_ids = [];
            $arts = explode(',', $param['related']);
            foreach ($arts as $art)
            {
                if($find = $this->findProductIdByArticle(trim($art)))
                {
                    $related_ids[] = $find;
                }
            }
            $meta = [
                '_wcrp_related_ids' => isset($related_ids) && !empty($related_ids) ? serialize($related_ids) : '',
            ];
            foreach ($meta as $key => $value)
            {
                $this->conn->update('wp_postmeta', ['meta_key' => $key, 'meta_value' => $value], ['post_id' => $pid]);
            }
            return true;
        }
        return false;
    }

    /*
     * Create post meta
     * @param array $param
     * @return bool
     */
    private function createPostMeta(array $param)
    {
        $_product_image_gallery = [];
        if (isset($param['gallery']) && is_array($param['gallery']))
        {
            foreach ( $param['gallery'] as $post_image_id => $src )
            {
                if (!isset($_thumbnail_id))
                {
                    $_thumbnail_id = $post_image_id;
                }
                else
                {
                    $_product_image_gallery[] = $post_image_id;
                }
                $this->_wp_attachment_metadata($src, $post_image_id);
            }
        }
        foreach ($param['attr'] as $key => $attr)
        {
            if (empty($attr))
            {
                continue;
            }
            switch ($key){
                case 'Длина':
                    $_product_attributes['%d0%b4%d0%bb%d0%b8%d0%bd%d0%b0'] = [
                        'name' => 'Длина',
                        'value' => $attr. " см",
                        'position' => 0,
                        'is_visible' => 1,
                        'is_variation' => 0,
                        'is_taxonomy' => 0,
                    ];
                    break;
                case 'Цвет':
                    $_product_attributes['%d1%86%d0%b2%d0%b5%d1%82'] = [
                        'name' => 'Цвет',
                        'value' => $attr,
                        'position' => 1,
                        'is_visible' => 1,
                        'is_variation' => 0,
                        'is_taxonomy' => 0,
                    ];
                    break;
                case 'Состав':
                    $_product_attributes['%d1%81%d0%be%d1%81%d1%82%d0%b0%d0%b2'] = [
                        'name' => 'Состав',
                        'value' => $attr,
                        'position' => 2,
                        'is_visible' => 1,
                        'is_variation' => 0,
                        'is_taxonomy' => 0,
                    ];
                    break;
                case 'Утеплитель':
                    $_product_attributes['%d1%83%d1%82%d0%b5%d0%bf%d0%bb%d0%b8%d1%82%d0%b5%d0%bb%d1%8c'] = [
                        'name' => 'Утеплитель',
                        'value' => $attr,
                        'position' => 3,
                        'is_visible' => 1,
                        'is_variation' => 0,
                        'is_taxonomy' => 0,
                    ];
                    break;
            }
        }

        if (isset($param['related']))
        {
            $related_ids = [];
            $arts = explode(',', $param['related']);
            foreach ($arts as $art)
            {
                if($find = $this->findProductIdByArticle(trim($art)))
                {
                    $related_ids[] = $find;
                }
            }
        }

        $meta = [
            '_wcrp_related_ids' => isset($related_ids) && !empty($related_ids) ? serialize($related_ids) : '',
            '_pppprice3' => isset($param['sort']) ? $param['sort'] : '',
            '_pppprice2' => isset($param['credit3']) ? $param['credit3'] : '',
            '_pppprice' => isset($param['credit2']) ? $param['credit2'] : '',
            '_rrpprice' => isset($param['credit1']) ? $param['credit1'] : '',
            '_dwls_first_image' => '',
            '_vc_post_settings' => 'a:1:{s:10:"vc_grid_id";a:0:{}}',
            '_wc_review_count' => '0',
            '_wc_rating_count' => 'a:0:{}',
            '_wc_average_rating' => '0',
            '_edit_last' => '1',
            '_edit_lock' => '1538478071:1',
            '_thumbnail_id' => isset($_thumbnail_id) ? $_thumbnail_id : '',
            'rp4wp_auto_linked' => '1',
            '_uncode_featured_media_display' => 'carousel',
            '_sku' => isset($param['sku']) ? $param['sku'] : 'нет артикула',
            '_regular_price' => isset($param['price']) ? $param['price'] : '',
            '_sale_price' => isset($param['sale_price']) ? $param['sale_price'] : '',
            '_sale_price_dates_from' => '',
            '_sale_price_dates_to' => '',
            'total_sales' => '0',
            '_tax_status' => 'taxable',
            '_tax_class' => '',
            '_manage_stock' => 'no',
            '_backorders' => 'no',
            '_sold_individually' => 'no',
            '_weight' => '',
            '_length' => '',
            '_width' => '',
            '_height' => '',
            '_upsell_ids' => 'a:0:{}',
            '_crosssell_ids' => 'a:0:{}',
            '_purchase_note' => '',
            '_default_attributes' => 'a:0:{}',
            '_virtual' => 'no',
            '_downloadable' => 'no',
            '_product_image_gallery' => implode(',', $_product_image_gallery),
            '_download_limit' => '-1',
            '_download_expiry' => '-1',
            '_stock' => null,
            '_stock_status' => 'instock',
            '_product_version' => '3.4.5',
            '_price' => isset($param['sale_price']) ? $param['sale_price'] : @$param['price'],
            '_uncode_specific_menu_opaque' => 'off',
            '_uncode_specific_menu_no_shadow' => 'off',
            '_uncode_blocks_list' => '39',
            '_uncode_revslider_list' => 'one',
            '_uncode_header_full_width' => 'on',
            '_uncode_header_height' => 'a:2:{i:0;s:2:"50";i:1;s:1:"%";}',
            '_uncode_header_title' => 'on',
            '_uncode_header_title_custom' => 'off',
            '_uncode_header_style' => 'dark',
            '_uncode_header_content_width' => 'off',
            '_uncode_header_custom_width' => '100',
            '_uncode_header_align' => 'left',
            '_uncode_header_position' => 'header-center header-middle',
            '_uncode_header_title_size' => 'h1',
            '_uncode_header_title_italic' => 'off',
            '_uncode_header_featured' => 'on',
            '_uncode_header_background' => 'a:6:{s:16:"background-color";s:10:"color-wayh";s:17:"background-repeat";s:0:"";s:21:"background-attachment";s:0:"";s:19:"background-position";s:0:"";s:15:"background-size";s:0:"";s:16:"background-image";s:0:"";}',
            '_uncode_header_parallax' => 'off',
            '_uncode_header_kburns' => 'off',
            '_uncode_header_overlay_color_alpha' => '100',
            '_uncode_header_scroll_opacity' => 'off',
            '_uncode_header_scrolldown' => 'off',
            '_uncode_menu_no_padding' => 'off',
            '_uncode_menu_no_padding_mobile' => 'off',
            '_uncode_product_media_size' => '0',
            '_uncode_specific_navigation_hide' => 'off',
            '_uncode_fullpage_type' => 'curtain',
            '_uncode_fullpage_opacity' => 'off',
            '_uncode_scroll_dots' => 'off',
            '_uncode_empty_dots' => 'off',
            '_uncode_scroll_history' => 'off',
            '_uncode_scroll_safe_padding' => 'on',
            '_uncode_scroll_additional_padding' => '0',
            '_uncode_fullpage_mobile' => 'off',
            'slide_template' => 'default',
            '_wpb_vc_js_status' => 'false',
            '_wpb_vc_js_status' => 'false',
        ];
        if (isset($_product_attributes))
        {
            $meta['_product_attributes'] = serialize($_product_attributes);
        }
        foreach ($meta as $key => $value)
        {
            $this->conn->insert('wp_postmeta', ['meta_key' => $key, 'meta_value' => $value, 'post_id' => @$param['post_id'] ]);
        }
    }

    /*
     * Delete post meta
     * @param int $post_id
     * @return bool
     */
    private function deletePostMeta(int $post_id)
    {
        $this->conn->delete('wp_postmeta', ['post_id' => $post_id]);
    }

    /*
     * Create image post
     * @param string $image
     * @param int $post_parent
     * @return int|bool
     */
    private function createProductImage(string $image, int $post_parent)
    {
        $image_name = pathinfo($image, PATHINFO_FILENAME);
        $url = $this->site_root_dir.$image;
        $size = getimagesize($url);
        $param = [
            'post_author' => '1',
            'post_date' => date('Y-m-d H:i:s'),
            'post_date_gmt' => date('Y-m-d H:i:s'),
            'post_content' => '',
            'post_title' => $image_name,
            'post_excerpt' => '',
            'post_status' => 'inherit',
            'comment_status' => 'open',
            'ping_status' => 'closed',
            'post_password' => '',
            'post_name' => urlencode($image_name),
            'to_ping' => '',
            'pinged' => '',
            'post_modified' => date('Y-m-d H:i:s'),
            'post_modified_gmt' => date('Y-m-d H:i:s'),
            'post_content_filtered' => '',
            'post_parent' => $post_parent,
            'guid' => $url,
            'menu_order' => '0',
            'post_type' => 'attachment',
            'post_mime_type' => $size['mime'],
            'comment_count' => '0',
        ];
        if ($this->conn->insert('wp_posts', $param))
        {
            $ID = $this->conn->lastInsertId();
            return $ID;
        }
        else
        {
            return false;
        }
    }

    /*
     * Delete image post
     * @param int $post_parent
     */
    private function deleteProductImage(int $post_parent)
    {
        $stmt = $this->conn->query("SELECT ID FROM wp_posts WHERE post_parent={$post_parent} AND post_type = 'attachment'");
        $image_ids = false;
        if ($stmt->rowCount())
        {
            foreach ($stmt->fetchAll() as $row)
            {
                $this->conn->delete('wp_postmeta', ['post_id' => $row['ID'] ]);
            }
        }
        $this->conn->delete('wp_posts', ['post_parent' => $post_parent, 'post_type' => 'attachment']);

        return $image_ids;
    }

    /*
     * Delete all products from database
     */
    public function deleteAllProducts()
    {
        $stmt = $this->conn->query("SELECT ID FROM wp_posts WHERE post_type = 'product'");
        if ($stmt->rowCount())
        {
            foreach ($stmt->fetchAll() as $row)
            {
                $this->deleteProduct($row['ID']);
            }
        }
        $this->updateCategoryTree();
    }

    /*
     * Copy image to upload dir
     * @param string $src
     * @param string $article
     * @return string|bool
     */
    private function copyImageToUpload(string $src, string $article = '')
    {
        $source = $this->site_root_dir.$src;
        if (file_exists($source))
        {
            $file = str_replace(' ', '-', basename($src));
            $upload_dir = $this->upload_dir."by_sku/{$article}/";
            $dir = $this->site_root_dir.$upload_dir;
            if (!file_exists($dir))
            {
                mkdir($dir, 0777, true);
            }
            $dest = $dir.$file;
            if (!file_exists($dest))
            {
                copy($source, $dest);
            }
            return $upload_dir.$file;
        }
        return false;
    }

    /*
     * Create thumb images
     * @param string $src
     * @return bool
     */
    private function _wp_attachment_metadata(string $src, int $post_id)
    {
        $source = $this->site_root_dir.$src;
        $size = getimagesize($source);
        $pathinfo = pathinfo($src);
        $meta = [
            'width' => 800,
            'height' => 1200,
            'file' => str_replace($this->upload_dir, '',$src),
            'size' => [
                'thumbnail' => [
                    'file' => $pathinfo['filename'].'-150x150.'.$pathinfo['extension'],
                    'width' => 150,
                    'height' => 150,
                    'mime-type' => $size['mime'],
                ],
                'medium' => [
                    'file' => $pathinfo['filename'].'-200x300.'.$pathinfo['extension'],
                    'width' => 200,
                    'height' => 300,
                    'mime-type' => $size['mime'],
                ],
                'medium_large' => [
                    'file' => $pathinfo['filename'].'-768x1152.'.$pathinfo['extension'],
                    'width' => 768,
                    'height' => 1152,
                    'mime-type' => $size['mime'],
                ],
                'large' => [
                    'file' => $pathinfo['filename'].'-683x1024.'.$pathinfo['extension'],
                    'width' => 683,
                    'height' => 1024,
                    'mime-type' => $size['mime'],
                ],
                'woocommerce_thumbnail' => [
                    'file' => $pathinfo['filename'].'-300x450.'.$pathinfo['extension'],
                    'width' => 300,
                    'height' => 450,
                    'mime-type' => $size['mime'],
                ],
                'woocommerce_single' => [
                    'file' => $pathinfo['filename'].'-600x900.'.$pathinfo['extension'],
                    'width' => 600,
                    'height' => 900,
                    'mime-type' => $size['mime'],
                ],
                'woocommerce_gallery_thumbnail' => [
                    'file' => $pathinfo['filename'].'-100x100.'.$pathinfo['extension'],
                    'width' => 100,
                    'height' => 100,
                    'mime-type' => $size['mime'],
                ],
                'shop_catalog' => [
                    'file' => $pathinfo['filename'].'-300x450.'.$pathinfo['extension'],
                    'width' => 300,
                    'height' => 450,
                    'mime-type' => $size['mime'],
                ],
                'shop_catalog' => [
                    'file' => $pathinfo['filename'].'-683x1024.'.$pathinfo['extension'],
                    'width' => 683,
                    'height' => 1024,
                    'mime-type' => $size['mime'],
                ],
                'shop_single' => [
                    'file' => $pathinfo['filename'].'-600x900.'.$pathinfo['extension'],
                    'width' => 600,
                    'height' => 900,
                    'mime-type' => $size['mime'],
                ],
                'shop_thumbnail' => [
                    'file' => $pathinfo['filename'].'-100x100.'.$pathinfo['extension'],
                    'width' => 100,
                    'height' => 100,
                    'mime-type' => $size['mime'],
                ],
            ],
            'image_meta' => [
                'aperture' => '7.1',
                'credit' => 'Igor Alekseev www.igoralekseev.c',
                'camera' => 'Canon EOS 5D Mark II',
                'caption' => '',
                'created_timestamp' => '1536237860',
                'copyright' => '',
                'focal_length' => '50',
                'iso' => '250',
                'shutter_speed' => '0.00625',
                'title' => '',
                'orientation' => '0',
                'keywords' => [],
            ]
        ];
        if (file_exists($source))
        {
            $upload_dir = dirname($src).'/';
            $dir = $this->site_root_dir.$upload_dir;
            if (!file_exists($dir))
            {
                mkdir($dir, 0777, true);
            }
            $ir = new ImageResize($source);
//            $dest = $dir.$pathinfo['basename'];
//            if (!file_exists($dest))
//            {
//                $ir->save($dest);
//            }
            foreach ( $meta['size'] as $img )
            {
                $file = $img['file'];
                $dest = $dir.$file;
                if (file_exists($dest))
                {
                    continue;
                }
                $ir->resizeToBestFit($img['width'], $img['height']);
                $ir->save($dest);
            }
            $this->conn->insert('wp_postmeta', ['meta_key' => '_wp_attached_file', 'meta_value' => $meta['file'], 'post_id' => $post_id ]);
            $this->conn->insert('wp_postmeta', ['meta_key' => '_wp_attachment_metadata', 'meta_value' => serialize($meta), 'post_id' => $post_id ]);
            return true;
        }
        return false;
    }

    public function translit_str($str){
        $tr = array(
            "А"=>"a","Б"=>"b","В"=>"v","Г"=>"g",
            "Д"=>"d","Е"=>"e","Ё"=>"yo","Ж"=>"zh","З"=>"z","И"=>"i",
            "Й"=>"y","К"=>"k","Л"=>"l","М"=>"m","Н"=>"n",
            "О"=>"o","П"=>"p","Р"=>"r","С"=>"s","Т"=>"t",
            "У"=>"u","Ф"=>"f","Х"=>"h","Ц"=>"c","Ч"=>"ch",
            "Ш"=>"sh","Щ"=>"shch","Ъ"=>"","Ы"=>"y","Ь"=>"",
            "Э"=>"e","Ю"=>"yu","Я"=>"ya","а"=>"a","б"=>"b",
            "в"=>"v","г"=>"g","д"=>"d","е"=>"e","ё"=>"yo","ж"=>"zh",
            "з"=>"z","и"=>"i","й"=>"y","к"=>"k","л"=>"l",
            "м"=>"m","н"=>"n","о"=>"o","п"=>"p","р"=>"r",
            "с"=>"s","т"=>"t","у"=>"u","ф"=>"f","х"=>"h",
            "ц"=>"c","ч"=>"ch","ш"=>"sh","щ"=>"shch","ъ"=>"",
            "ы"=>"y","ь"=>"","э"=>"e","ю"=>"yu","я"=>"ya",
            " "=> "-", "."=> "", ","=> "", "/"=> "", "\""=> "",
            "'"=> "", "/"=> "", ":"=> "", "("=> "", ")"=> "", "!"=> "",
            "&amp;"=> "", "&quot;"=> "", "&laquo;"=> "", "&raquo;"=> "",
            "%"=> "", "-"=> "-", "&"=> "", "$"=> "", "«"=> "", "»"=> "", "+"=> ""
        );
        return trim(strtr($str,$tr), "-");
    }
}