<?php
/**
 * Created by PhpStorm.
 * User: klyupav
 * Date: 02.10.18
 * Time: 19:41
 */

namespace Parser;

use Port\Excel\ExcelReader;

class Parser
{
    private $source_csv_filename = __DIR__.'/../CSV/NV_120.csv';
    private $source_xls_filename = __DIR__.'/../CSV/NV_120.xls';
    private $source_image_dir = '/Foto/';

    public function __construct($param)
    {
        if (isset($param['source_image_dir']))
        {
            $this->source_image_dir = $param['source_image_dir'];
        }
        if (isset($param['source_csv_filename']))
        {
            $this->source_csv_filename = $param['source_csv_filename'];
        }
        if (isset($param['source_xls_filename']))
        {
            $this->source_xls_filename = $param['source_xls_filename'];
        }
    }

    /*
     * @param resource $fp
     * @return array|bool
     */
    private function next_row_csv($fp)
    {
        if ($row = fgets($fp))
        {
            $row = explode(';', $row);
            foreach ($row as $key => $value) {
                $row[$key] = trim(trim($value), '"');
            }
            return $row;
        }

        return false;
    }

    /*
     * Find product by article from csv file
     * @param string $article
     * @return array|bool
     */
    public function find_in_csv(string $article)
    {
        if ($fp = fopen($this->source_csv_filename, 'r'))
        {
            while ( $row = $this->next_row_csv($fp) )
            {
                if ( $row[0] === $article )
                {
                    return $row;
                }
            }
        }

        return false;
    }

    /*
     * Find product by article from csv file
     * @param string $article
     * @return array|bool
     */
    public function find_in_xls(string $article)
    {
        if (file_exists($this->source_xls_filename))
        {
            $file = new \SplFileObject($this->source_xls_filename);
            $reader = new ExcelReader($file);
            for ($i = 0; $reader->count() > $i; $i++)
            {
                $row = $reader->getRow($i);
                if ( $row[0] === $article )
                {
                    return $row;
                }
            }
        }

        return false;
    }

    /*
     * @return array
     */
    public function get_all_article_from_images()
    {
        $files = scandir(__DIR__."/../".$this->source_image_dir);
        $article = [];
        foreach ($files as $file)
        {
            if (preg_match('%([^ ]+) \d%uis', $file, $match))
            {
                $article[$match[1]][] = $this->source_image_dir.$file;
            }
        }

        return $article;
    }
}