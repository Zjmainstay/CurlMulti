<?php
require_once __DIR__ . '/../../vendor/autoload.php';
use Ares333\CurlMulti\Core;
/**
 * 404 link check
 * @author Zjmainstay
 */
class Web404Check {
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
        
        $this->curl->add ( array (
                'url' => $url,
                'args' => array (
                    // 这个参数可以一直传递下去
                    'url' => $url,
                    'fromUrl' => $url, 
                ) 
        ), array (
                $this,
                'handlePageResult' 
        ) )->start ();
    }
    
    /**
     * 第一个页面的请求结果处理
     */
    function handlePageResult($r, $param) {
        if (! $this->httpError ( $r ['info'] )) {
            $this->parsePageLink($r, $param);
        } else {
            echo "Error Link: {$param['url']}\n";
        }
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
    
    /**
     * 链接解析处理
     */
    function renderUrl($url) {
        $url = preg_replace('/#[^"]+$/', '', trim($url));
        if(!preg_match('#https?://#is', $url)) {
            if(($url == '#') || (stripos($url, 'mailto:') !== false) || (stripos($url, 'javascript:') !== false)) {
                return false;
            }
            
            $url = $this->baseUrl . '/' . trim($url, '/');
        } else if(stripos($url, $this->baseUrl) === false) {    //外链不加载
            return false;
        }
        
        return rtrim($url, '/');
    }
    
    /**
     * 添加一个新页面
     */
    function addUrl($url, $fromUrl) {
        $this->curl->add ( array (
                'url' => $url,
                'args' => array (
                    // 这个参数可以一直传递下去
                    'url' => $url, 
                    'fromUrl' => $fromUrl, 
                ) 
        ), array (
                $this,
                'test404' 
        ) );
        
        return $this->curl;
    }
    
    /**
     * 404 检测，正确页面继续加入解析
     */
    function test404($r, $param) {
        if (! $this->httpError ( $r ['info'] )) {
            echo "\nOK :{$param['url']} from {$param['fromUrl']}";
            $this->parsePageLink($r, $param);   //继续解析
        } else {
            echo "\nErr:{$param['url']} from {$param['fromUrl']}";
        }
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
}

if(empty($argv[1]) || !preg_match('#^https?://#is', $argv[1])) {
    echo "Usage: php web404Check.php http://www.zjmainstay.cn \n";
    exit;
}

$demo = new Web404Check();
$demo->run($argv[1]);
