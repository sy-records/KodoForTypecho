<?php
/**
 * 使用七牛云对象存储服务KODO作为附件存储空间的Typecho插件。
 *
 * @package KodoForTypecho
 * @author 沈唁
 * @version 1.0.0
 * @link https://qq52o.me
 */

require_once __DIR__ . '/sdk/vendor/autoload.php';

use Qiniu\Auth;
use Qiniu\Storage\UploadManager;
use Qiniu\Storage\BucketManager;

class KodoForTypecho_Plugin implements Typecho_Plugin_Interface
{
    //上传文件目录
    const UPLOAD_DIR = '/usr/uploads';

    /**
     * 激活插件方法
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Upload')->uploadHandle = array(__CLASS__, 'uploadHandle');
        Typecho_Plugin::factory('Widget_Upload')->modifyHandle = array(__CLASS__, 'modifyHandle');
        Typecho_Plugin::factory('Widget_Upload')->deleteHandle = array(__CLASS__, 'deleteHandle');
        Typecho_Plugin::factory('Widget_Upload')->attachmentHandle = array(__CLASS__, 'attachmentHandle');
        Typecho_Plugin::factory('Widget_Upload')->attachmentDataHandle = array(__CLASS__, 'attachmentDataHandle');
        return _t('插件已激活，请前往设置');
    }

    /**
     * 禁用插件方法
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        return _t('插件已禁用');
    }

    /**
     * 插件配置方法
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $desc = new Typecho_Widget_Helper_Form_Element_Text(
            'desc', null, '', _t('插件使用说明：'),
            _t(
                '<ul>
                      <li>插件作者：沈唁 <a target="_blank" href="https://github.com/sy-records">GitHub</a> <a target="_blank" href="https://qq52o.me">博客</a></li>
                      <li>如果觉得此插件对你有所帮助，不妨到 <a href="https://github.com/sy-records/KodoForTypecho" target="_blank">Github</a> 上点个<code>Star</code>，<code>Watch</code>关注更新；</li>
                </ul>'
            )
        );
        $form->addInput($desc);

        $bucket = new Typecho_Widget_Helper_Form_Element_Text(
            'bucket',
            null, '',
            _t('Bucket名称：')
        );
        $form->addInput($bucket->addRule('required', _t('Bucket名称不能为空！')));

        $accessKey = new Typecho_Widget_Helper_Form_Element_Text(
            'accessKey',
            null, '',
            _t('accessKey：')
        );
        $form->addInput($accessKey->addRule('required', _t('accessKey不能为空！')));

        $secretKey = new Typecho_Widget_Helper_Form_Element_Text(
            'secretKey',
            null, '',
            _t('secretKey：')
        );
        $form->addInput($secretKey->addRule('required', _t('secretKey不能为空！')));

        $domain = new Typecho_Widget_Helper_Form_Element_Text(
            'domain',
            null, '',
            _t('Bucket加速域名：'),
            _t('可使用加速域名（留空则使用默认域名）<br>需加上协议头如：http:// 或 https://')
        );
        $form->addInput($domain);

        echo '<script>
          window.onload = function() 
          {
            document.getElementsByName("desc")[0].type = "hidden";
          }
        </script>';
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 上传文件处理函数
     *
     * @access public
     * @param array $file 上传的文件
     * @return mixed
     */
    public static function uploadHandle($file)
    {
        if (empty($file['name'])) {
            return false;
        }
        //获取扩展名
        $ext = self::getSafeName($file['name']);
        //判定是否是允许的文件类型
        if (!Widget_Upload::checkFileType($ext) || Typecho_Common::isAppEngine()) {
            return false;
        }
        //获取设置参数
        $options = self::getOption();
        //获取文件名
        $date = new Typecho_Date($options->gmtTime);
        $fileDir = self::getUploadDir() . '/' . $date->year . '/' . $date->month;
        $fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
        $path = $fileDir . '/' . $fileName;
        //获得上传文件
        $uploadfile = self::getUploadFile($file);
        //如果没有临时文件，则退出
        if (!isset($uploadfile)) {
            return false;
        }

        $uploadMgr = new UploadManager();
        list($ret, $err) = $uploadMgr->putFile(self::getAuthToken(), ltrim($path, "/"), $uploadfile);
        if ($err !== null) {
            var_dump($err);
            return false;
        }

        if (!isset($file['size'])) {
            $file['size'] = filesize($uploadfile);
        }

        //返回相对存储路径
        return array(
            'name' => $file['name'],
            'path' => $path,
            'size' => $file['size'],
            'type' => $ext,
            'mime' => @Typecho_Common::mimeContentType($path)
        );
    }

    /**
     * 修改文件处理函数
     *
     * @access public
     * @param array $content 老文件
     * @param array $file 新上传的文件
     * @return mixed
     */
    public static function modifyHandle($content, $file)
    {
        if (empty($file['name'])) {
            return false;
        }

        //获取扩展名
        $ext = self::getSafeName($file['name']);
        //判定是否是允许的文件类型
        if ($content['attachment']->type != $ext || Typecho_Common::isAppEngine()) {
            return false;
        }
        //获取文件路径
        $path = $content['attachment']->path;
        //获得上传文件
        $uploadfile = self::getUploadFile($file);
        //如果没有临时文件，则退出
        if (!isset($uploadfile)) {
            return false;
        }

        $uploadMgr = new UploadManager();
        $kodo_path = ltrim($path, "/");
        list($ret, $err) = $uploadMgr->putFile(self::getAuthToken($kodo_path), $kodo_path, $uploadfile);
        if ($err !== null) {
            var_dump($err);
            return false;
        }

        if (!isset($file['size'])) {
            $file['size'] = filesize($uploadfile);
        }

        //返回相对存储路径
        return array(
            'name' => $content['attachment']->name,
            'path' => $content['attachment']->path,
            'size' => $file['size'],
            'type' => $content['attachment']->type,
            'mime' => $content['attachment']->mime
        );
    }

    /**
     * 删除文件
     *
     * @access public
     * @param array $content 文件相关信息
     * @return string
     */
    public static function deleteHandle(array $content)
    {
        $bucket = self::getBucketName();
        $bucketManager = new BucketManager(self::getAuth());
        $err = $bucketManager->delete($bucket, ltrim($content['attachment']->path, "/"));
        if ($err != null) {
            var_dump($err);
            return false;
        }
        return true;
    }

    /**
     * 获取实际文件绝对访问路径
     *
     * @access public
     * @param array $content 文件相关信息
     * @return string
     */
    public static function attachmentHandle(array $content)
    {
        //获取设置参数
        $options = self::getOption();
        return Typecho_Common::url($content['attachment']->path, self::getDomain());
    }

    /**
     * 获取实际文件数据
     *
     * @access public
     * @param array $content
     * @return string
     */
    public static function attachmentDataHandle($content)
    {
        $bucket = self::getBucketName();
        $bucketManager = new BucketManager(self::getAuth());
        list($fileInfo, $err) = $bucketManager->stat($bucket, ltrim($content['attachment']->path, "/"));
        if ($err != null) {
            var_dump($err);
            return false;
        }
        return $fileInfo;
    }

    /**
     * @return mixed
     */
    public static function getOption()
    {
        return Typecho_Widget::widget('Widget_Options')->plugin('KodoForTypecho');
    }

    /**
     * @return Auth
     */
    public static function getAuth()
    {
        $options = self::getOption();
        $accessKey = $options->accessKey;
        $secretKey = $options->secretKey;
        // 构建鉴权对象
        return new Auth($accessKey, $secretKey);
    }

    /**
     * @return mixed
     */
    public static function getBucketName()
    {
        $options = self::getOption();
        return $options->bucket;
    }

    /**
     * @return string
     */
    public static function getAuthToken($keyToOverwrite = null)
    {
        // 生成上传 Token
        return self::getAuth()->uploadToken(self::getBucketName(), $keyToOverwrite);
    }

    /**
     * @return string
     */
    private static function getUploadDir()
    {
        if (defined('__TYPECHO_UPLOAD_DIR__')) {
            return __TYPECHO_UPLOAD_DIR__;
        } else {
            return self::UPLOAD_DIR;
        }
    }

    /**
     * @param $file
     * @return mixed|string
     */
    private static function getUploadFile($file)
    {
        return isset($file['tmp_name']) ? $file['tmp_name'] : (isset($file['bytes']) ? $file['bytes'] : (isset($file['bits']) ? $file['bits'] : ''));
    }

    /**
     * @return mixed
     */
    private static function getDomain()
    {
        $options = self::getOption();
        $domain = $options->domain;
        return $domain;
    }

    /**
     * @param $name
     * @return string
     */
    private static function getSafeName(&$name)
    {
        $name = str_replace(array('"', '<', '>'), '', $name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);
        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }
}