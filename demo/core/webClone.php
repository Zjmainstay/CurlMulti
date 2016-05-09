<?php
require_once __DIR__ . '/../../vendor/autoload.php';
use Ares333\CurlMulti\Core;
/**
 * 404 link check
 * @author Zjmainstay
 */
class WebClone {
    protected $curl         = null;
    protected $baseUrl        = null;
    protected $urls           = array();
    protected $emptyLink      = 0;
    protected $maxEmptyLink   = 50;    //最大空链次数
    /**
     * 从这里开始抓取
     */
    function run($url) {
        $this->curl = new Core();
        $this->curl->maxTry = 0;    //不需要重复请求
        $this->curl->cbInfo = null;
        $this->curl->opt    = array(
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_RETURNTRANSFER  => true,
        );
        $this->urls[] = rtrim($url, '/');
        $uri = parse_url($url);
        
        if(empty($uri)) {
            exit('Error url.');
        }
        
        if(!empty($uri['port']) && ($uri['port'] != '80') && ($uri['port'] != '443')) {
            $uri['port'] = ":{$uri['port']}";
        } else {
            $uri['port'] = null;
        }
        
        $this->baseUrl = "{$uri['scheme']}://{$uri['host']}{$uri['port']}";

        $this->addUrl($url, $url);
        $this->curl->start ();
    }

    /**
     * 添加一个新页面
     */
    function addUrl($url, $fromUrl, $callback = false) {
        if(empty($callback)) {
            $callback = array ($this,'handlePageResult');
        }
        $this->curl->add ( array (
                'url' => $url,
                'args' => array (
                    // 这个参数可以一直传递下去
                    'url' => $url, 
                    'fromUrl' => $fromUrl, 
                ) 
        ), $callback );

        try {
            $this->curl->start();
        } catch(Exception $e) {

        }
        
        return $this->curl;
    }
    
    /**
     * 页面的请求结果处理
     */
    function handlePageResult($r, $param) {
        if (! $this->httpError ( $r ['info'] )) {
            $pathInfo = $this->parseUrlToPath($param['url']);
            if(!empty($pathInfo)) {
                $this->cacheFile($pathInfo, $r['content'], $param);
            }

            $this->parseAssetsLink($r, $param);
            
            $this->parsePageLink($r, $param);
        } else {
            echo "Error Link: {$param['url']}\n";
        }
    }

    /**
     * js/css页面的请求结果处理
     */
    function handleAssetsResult($r, $param) {
        if (! $this->httpError ( $r ['info'] )) {
            $url = $param['url'];

            if(preg_match('#https?://[^/]+#is', $param['url'], $match)) {
                $url = str_replace($match[0], '', $param['url']);    //移除链接
            }
            $pathInfo = $this->parseUrlToPath($url);
            if(!empty($pathInfo)) {
                $this->cacheFile($pathInfo, $r['content'], $param);
            }
        } else {
            echo "Error Link: {$param['url']}\n";
        }
    }

    /**
     * 缓存页面内容
     */
    function cacheFile($pathInfo, $data, $param = false) {
        if(!is_dir($pathInfo['dir'])) {
            if(DIRECTORY_SEPARATOR === '\\') {
                $pathInfo['dir'] = iconv('utf-8', 'gbk//ignore', $pathInfo['dir']);
            }
            mkdir($pathInfo['dir'], 0777, true);
        }

        if(DIRECTORY_SEPARATOR === '\\') {
            $pathInfo['fullPath'] = iconv('utf-8', 'gbk//ignore', $pathInfo['fullPath']);
        }

        if(!preg_match('#\.(html|js|css|jpg|png|jpeg|gif)$#is', $pathInfo['fullPath'])) {
            $pathInfo['fullPath'] .= '.html';
        }

        $data = $this->renderContent($data);

        if(!file_exists($pathInfo['fullPath'])) {
            file_put_contents($pathInfo['fullPath'], $data);
        }

        return true;
    }

    /**
     * 内容处理，移除base标签，移除绝对路径（含/开头的路径）
     */
    function renderContent($content) {
        $content = preg_replace('#<base [^>]*>#is', '', $content);  //移除base标签
        $content = str_replace($this->baseUrl, '', $content);   //移除根域名
        $content = preg_replace('#(<(?:link|script)[^>]*?(?:src|href)\s*=\s*["\']?)https?://[^/"\']+#is', '$1', $content);  //移除js/css跟域名（外网）
        $content = preg_replace('#((?:src|href)\s*=\s*["\']?)/#is', '$1', $content);    //移除以/开头的/

        return $content;
    }
    
    /**
     * 页面链接解析并添加
     */
    function parsePageLink($r, $param) {
        if($this->emptyLink >= $this->maxEmptyLink) {
            return false;
        }

        if(!preg_match('#<html#is', $r ['content'])) return true;

        $html = phpQuery::newDocumentHTML ( $r ['content'] );
        $list = $html ['a'];
        
        $urls = array();
        foreach ( $list as $v ) {
            $v = pq ( $v );
            $url = $this->renderUrl($v->attr ( 'href' ));
            if(!empty($url)) $urls[] = $url;
        }
        $urls = $this->filterUrls($urls);
        
        if(empty($urls)) {
            $this->emptyLink ++;
            return false;
        } else {
            $this->emptyLink = 0;
        }
        
        foreach ( $urls as $url ) {
            $this->addUrl($url, $param['url']);
        }
        phpQuery::unloadDocuments();
    }

    function parseAssetsLink($r, $param) {
        if(!preg_match('#<html#is', $r ['content'])) return true;

        $html = phpQuery::newDocumentHTML ( $r ['content'] );

        //CSS
        $list = $html ['link'];
        $urls = array();
        foreach ( $list as $v ) {
            $v = pq ( $v );
            $url = $this->renderUrl($v->attr ( 'href' ), false);
            if(!empty($url)) $urls[] = $url;
        }
        $urls = $this->filterUrls($urls);
        foreach ( $urls as $url ) {
            $this->addUrl($url, $param['url'], array($this, 'handleAssetsResult'));
        }

        //JS
        $list = $html ['script'];
        $urls = array();
        foreach ( $list as $v ) {
            $v = pq ( $v );
            if(empty($v->attr ( 'src' ))) continue;
            $url = $this->renderUrl($v->attr ( 'src' ), false);
            if(!empty($url)) $urls[] = $url;
        }
        $urls = $this->filterUrls($urls);
        foreach ( $urls as $url ) {
            $this->addUrl($url, $param['url'], array($this, 'handleAssetsResult'));
        }

        //Image
        $list = $html ['img'];
        $urls = array();
        foreach ( $list as $v ) {
            $v = pq ( $v );
            if(empty($v->attr ( 'src' ))) continue;
            $url = $this->renderUrl($v->attr ( 'src' ), false);
            if(!empty($url)) $urls[] = $url;
        }
        $urls = $this->filterUrls($urls);
        foreach ( $urls as $url ) {
            $this->addUrl($url, $param['url'], array($this, 'handleAssetsResult'));
        }

        phpQuery::unloadDocuments();
    }
    
    /**
     * 链接解析处理
     */
    function renderUrl($url, $skipOutsideLink = true) {
        $url = preg_replace('/#[^"]+$/', '', trim($url));
        if(!preg_match('#https?://#is', $url)) {
            if(($url == '#') || (stripos($url, 'mailto:') !== false) || (stripos($url, 'javascript:') !== false)) {
                return false;
            }
            
            $url = $this->baseUrl . '/' . trim($url, '/');
        } else if((stripos($url, $this->baseUrl) === false) && $skipOutsideLink) {    //外链不加载
            return false;
        }
        
        return rtrim($url, '/');
    }
    
    /**
     * 过滤已检测链接
     */
    function filterUrls($urls) {
        $urls = array_unique(array_diff($urls, $this->urls));
        
        if(count($urls)) {
            $this->urls = array_merge($this->urls, $urls);
            
            return $urls;
        }
        
        return array();
    }

    /**
     * 处理http头不是200的请求
     *
     * @param array $info
     * @return boolean 是否有错
     */
    function httpError($info) {
        if ($info ['http_code'] != 200) {
            //user_error ( 'http error, code=' . $info ['http_code'] . ', url=' . $info ['url'] );
            return true;
        }
        return false;
    }

    /**
     * 解析url为路径
     */
    function parseUrlToPath($url) {
        $url = $this->renderUrl($url);

        $url = str_replace($this->baseUrl, '', $url);

        if(!preg_match('#^(.*/)?([^/]*)$#is', $url, $match)) {
            return false;
        }

        $filename = $this->renderFilename($match[2]);
        if(empty($filename)) {
            $filename = 'index';
        }

        $dirname  = rtrim($this->renderPath($this->parseBaseUrlToPath($match[1])), '/');

        return array(
                'filename'  => $filename,
                'dir'       => $dirname,
                'fullPath'  => $dirname . '/' . $filename,
            );
    }

    /**
     * 解析url补充根路径
     */
    function parseBaseUrlToPath($url) {
        return $this->getBaseDir() . str_replace($this->baseUrl, '', $url);
    }

    /**
     * 设置存储根目录（不含站点本身目录）
     */
    function setBaseStorageDir($dir) {
        $this->baseStorageDir = $dir;
    }

    /**
     * 项目存储根目录
     */
    function getBaseDir() {
        return $this->baseStorageDir . '/' . $this->renderPath($this->baseUrl);
    }

    /**
     * 移除路径特殊字符
     */
    function renderPath($path) {
        return str_replace(array('\\', ':', '*', '?', '"', '<', '>', '|'), '_', preg_replace('#^https?://#', '', $path));
    }

    /**
     * 移除文件名特殊字符
     */
    function renderFilename($filename) {
        return str_replace(array('\\', '/', ':', '*', '?', '"', '<', '>', '|'), '_', $filename);
    }

}

if(empty($argv[1]) || !preg_match('#^https?://#is', $argv[1])) {
    echo "Usage: php webClone.php http://www.zjmainstay.cn \n";
    exit;
}

$demo = new WebClone();
$demo->setBaseStorageDir(__DIR__ . '/domains');
$demo->run($argv[1]);
