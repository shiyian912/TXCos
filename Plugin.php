<?php

namespace TypechoPlugin\TXCos;

use Typecho\Plugin\Exception;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Text;
use Utils\Helper;
use Widget\Options;

require 'tencent-php/vendor/autoload.php';

use Qcloud;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * TXCos，腾讯云cos对象存储插件
 * 保存文章时，会将图片上传到腾讯云且替换文章图片原地址
 * 使用本插件，请添加本人友链，谢谢
 *
 * @package TXCos
 * @author cultureSun
 * @version 1.0.0
 * @link http://culturesun.site
 */
class Plugin implements PluginInterface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     * @throws \Typecho\Db\Exception
     */
    public static function activate()
    {
        \Typecho\Plugin::factory('Widget\Contents\Post\Edit')->write = __CLASS__ . '::render';
        $db = \Typecho\Db::get();
        $prefix = $db->getPrefix();
        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}tx_cos` (
                    `url` varchar(255) COMMENT '本地图片地址',
                    `txUrl` varchar(255) COMMENT '腾讯cos地址'
                )DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci";
        $db->query($sql);
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     */
    public static function deactivate()
    {
    }

    /**
     * 获取插件配置面板
     *
     * @param Form $form 配置面板
     */
    public static function config(Form $form)
    {
        /** 分类名称 */
        $isEncrypted = new Select('isEncrypted', array('1' => '是', '0' => '否'), '1', 'Encrypted', _t('选择否secretId和secretKey会明文存在数据库中，选择是需手动加入php全局环境变量中(需$_SERVER可以访问到)'));
        $secretId = new Text('secretId', null, '', 'SecretId', _t('用户的 SecretId，若从环境变量获取，变量名为(TXCOS_SECRET_ID)，获取可参考https://cloud.tencent.com/document/product/598/37140'));
        $secretKey = new Text('secretKey', null, '', 'SecretKey', _t('用户的 SecretKey，若从环境变量获取，变量名为(TXCOS_SECRET_KEY)，获取可参考https://cloud.tencent.com/document/product/598/37140'));

        $bucket = new Text('bucket', null, '', 'Bucket', _t('用户的 存储桶'));
        $region = new Text('region', null, '', 'Region', _t('用户的 存储桶所属区域'));
        $directory = new Text('directory', null, '', 'Directory', _t('用户的 存储桶中的存储目录'));

        $form->addInput($isEncrypted);
        $form->addInput($secretId);
        $form->addInput($secretKey);
        $form->addInput($bucket);
        $form->addInput($region);
        $form->addInput($directory);
        if (isset($_POST['isEncrypted']) && $_POST['isEncrypted'] == '1') {
            $_POST['secretId'] = '';
            $_POST['secretKey'] = '';
        }
    }

    /**
     * 个人用户的配置面板
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 插件实现方法
     *
     * @access public
     * @return void
     * @throws Exception
     */
    public static function render($post)
    {
        $text = $post['text'];
        $urls = self::getImageUrlFromText($text);
        foreach ($urls as $url) {
            $txUrl = self::putImage($url);
            if (!isset($txUrl)) {
                continue;
            }
            $text = self::replaceAndSaveUrl($text, $url, $txUrl);
        }
        $post['text'] = $text;
        return $post;
    }

    public static function replaceAndSaveUrl($text, $url, $txUrl)
    {
        $text = str_ireplace($url, $txUrl, $text);
        $db = \Typecho\Db::get();
        $db->query($db->insert('table.tx_cos')->rows(array('url' => $url, "txUrl" => $txUrl)));
        return $text;
    }

    public static function getImageUrlFromText($text): array
    {
        $bucket = Options::alloc()->plugin('TXCos')->bucket;
        $patten = '/\!\[(.*)\]\((http.+)\)/';
        preg_match_all($patten, $text, $arr);
        $result = [];
        if (isset($arr) && count($arr[2]) > 0) {
            foreach ($arr[2] as $value) {
                if (!in_array($value, $result)) {
                    if (strpos($value, $bucket) !== false || strncmp($value, 'http', 4) !== 0) {
                        continue;
                    }
                    $result[] = $value;
                }
            }
        }

        $patten = '/\!\[(.*)\]\[([0-9]+)\]/';
        preg_match_all($patten, $text, $arr);
        if (isset($arr) && count($arr[2]) > 0) {
            foreach ($arr[2] as $value) {
                preg_match('/\[' . $value . '\]:\s*(http.+)\s?/', $text, $matcher);
                if (!in_array(trim($matcher[1]), $result)) {
                    if (strpos($matcher[1], $bucket) !== false || strncmp($matcher[1], 'http', 4) !== 0) {
                        continue;
                    }
                    $result[] = trim($matcher[1]);
                }
            }
        }

        return $result;
    }


    /**
     * @throws Exception
     */
    public static function putImage($imageUrl)
    {
        if (Options::alloc()->plugin('TXCos')->isEncrypted == '0') {
            $secretId = Options::alloc()->plugin('TXCos')->secretId;
            $secretKey = Options::alloc()->plugin('TXCos')->secretKey;
        } else {
            $secretId = $_SERVER['TXCOS_SECRET_ID'];
            $secretKey = $_SERVER['TXCOS_SECRET_KEY'];
        }

        $region = Options::alloc()->plugin('TXCos')->region;
        $cosClient = new Qcloud\Cos\Client(
            array(
                'region' => $region,
                'schema' => 'https', //协议头部，默认为 http
                'credentials' => array(
                    'secretId' => $secretId,
                    'secretKey' => $secretKey)));
        try {
            $bucket = Options::alloc()->plugin('TXCos')->bucket; //存储桶名称 格式：BucketName-APPID
            $directory = Options::alloc()->plugin('TXCos')->directory; //存储目录
            $key = $directory . '/' . substr($imageUrl, strripos($imageUrl, "/") + 1); //此处的 key 为对象键，对象键是对象在存储桶中的唯一标识
            if (strpos($imageUrl, Helper::options()->siteUrl) !== false) {
                $srcPath = __TYPECHO_ROOT_DIR__ . parse_url($imageUrl)['path'];//本地文件绝对路径
            } else {
                $srcPath = $imageUrl;
            }
            $file = fopen($srcPath, "rb");
            if ($file) {
                $result = $cosClient->putObject(array(
                    'Bucket' => $bucket,
                    'Key' => $key,
                    'Body' => $file));
            } else {
                throw new Exception("找不到图片(" . $imageUrl . ")请检查是否存在");
            }

            return 'https://' . $result->offsetGet('Location');
        } catch (\Exception $e) {
            echo "上传文件错误\n";
        }
    }
}
