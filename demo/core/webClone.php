<?php
require_once __DIR__ . '/../../vendor/autoload.php';
use Ares333\CurlMulti\Core;
/**
 * 404 link check
 * @author Zjmainstay
 */
class WebClone {
    protected $curl           = null;
    protected $baseUrl        = null;
    protected $urls           = array();
    protected $emptyLink      = 0;
    protected $maxEmptyLink   = 50;     //最大空链次数
    protected $parseAllPage   = false;  //抓取全站标记 true:抓取全站   false:不抓取全站

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
            CURLOPT_FOLLOWLOCATION  => true,
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
    function addUrl($url, $fromUrl, $callback = false, $args = array()) {
        if(!preg_match('#^https?://#i', $url)) return $this->curl;

        #echo "{$url} from {$fromUrl}\n";
        if(empty($callback)) {
            $callback = array ($this,'handlePageResult');
        }

        $defaultArgs = array (
                    // 这个参数可以一直传递下去
                    'url' => $url, 
                    'fromUrl' => $fromUrl, 
                );
        $args = array_merge($defaultArgs, $args);

        $this->curl->add ( array (
                'url' => $url,
                'args' => $args,
        ), $callback );

        try {
            $this->curl->start();
        } catch(Exception $e) {

        }
        
        return $this->curl;
    }
    
    /**
     * 普通页面的请求结果处理
     */
    function handlePageResult($r, $param) {
        if (! $this->httpError ( $r ['info'] )) {
            $pathInfo = $this->parseUrlToPath($param['url'], $param['fromUrl']);
            if(!empty($pathInfo)) {
                $this->cacheFile($pathInfo, $r['content'], $param);
            }

            //解析js/css/img
            $this->parseAssetsLink($r, $param);
            
            if($this->parseAllPage) {
                //解析页面其他链接（内链）
                $this->parsePageLink($r, $param);
            }
        } else {
            echo "Error Link: {$param['url']} from {$param['fromUrl']}\n";
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
            $pathInfo = $this->parseUrlToPath($url, $param['fromUrl']);
            if(!empty($pathInfo)) {
                $this->cacheFile($pathInfo, $r['content'], $param);
            }

            if(isset($param['type']) && ($param['type'] === 'css')){
                if(preg_match_all ( '/@import\s+url\s*\((.+)\);/iU', $r['content'], $matches )) {
                    $urls = array();
                    foreach ( $matches[1] as $cssUrl ) {
                        $cssUrl = $this->renderUrl($cssUrl, $param['url'], false);
                        if(!empty($cssUrl)) $urls[] = $cssUrl;
                    }
                    $this->addAssetsUrl($urls, $param['url'], array('type' => 'css'));
                }

                if(preg_match_all ( '/:\s*url\s*\(\s*(\'|")?(.+?)\\1?\s*\)/i', $r['content'], $matches )) {
                    $urls = array();
                    foreach ( $matches[2] as $cssUrl ) {
                        $cssUrl = $this->renderUrl($cssUrl, $param['url'], false);
                        if(!empty($cssUrl)) $urls[] = $cssUrl;
                    }
                    $this->addAssetsUrl($urls, $param['url'], array('type' => 'css'));
                }
            }

        } else {
            echo "Error Link: {$param['url']} from {$param['fromUrl']}\n";
        }
    }

    /**
     * 页面链接解析并添加
     */
    function parsePageLink($r, $param) {
        if($this->emptyLink >= $this->maxEmptyLink) {
            return false;
        }

        if(!preg_match('#</?html#is', $r ['content'])) return true;

        $html = phpQuery::newDocumentHTML ( $r ['content'] );
        $list = $html ['a'];
        
        $urls = array();
        foreach ( $list as $v ) {
            $v = pq ( $v );
            $url = $this->renderUrl($v->attr ( 'href' ), $param['url']);
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
     * 解析js/css/img
     */
    function parseAssetsLink($r, $param) {
        if(!preg_match('#</?html#is', $r ['content'])) return true;

        $html = phpQuery::newDocumentHTML ( $r ['content'] );

        //CSS
        $list = $html ['link'];
        $urls = array();
        foreach ( $list as $v ) {
            $v = pq ( $v );
            $url = $this->renderUrl($v->attr ( 'href' ), $param['url'], false);
            if(!empty($url)) $urls[] = $url;
        }
        $this->addAssetsUrl($urls, $param['url'], array('type' => 'css'));

        //JS
        $list = $html ['script'];
        $urls = array();
        foreach ( $list as $v ) {
            $v = pq ( $v );
            if(empty($v->attr ( 'src' ))) continue;
            $url = $this->renderUrl($v->attr ( 'src' ), $param['url'], false);
            if(!empty($url)) $urls[] = $url;
        }
        $this->addAssetsUrl($urls, $param['url'], array('type' => 'js'));

        //Image
        $list = $html ['img'];
        $urls = array();
        foreach ( $list as $v ) {
            $v = pq ( $v );
            if(empty($v->attr ( 'src' ))) continue;
            $url = $this->renderUrl($v->attr ( 'src' ), $param['url'], false);
            if(!empty($url)) $urls[] = $url;
        }
        $this->addAssetsUrl($urls, $param['url'], array('type' => 'img'));

        phpQuery::unloadDocuments();
    }

    function addAssetsUrl($urls, $fromUrl, $args = array()) {
        $urls = $this->filterUrls($urls);
        foreach ( $urls as $url ) {
            $this->addUrl($url, $fromUrl, array($this, 'handleAssetsResult'), $args);
        }
    }

    /**
     * 缓存页面内容
     */
    function cacheFile($pathInfo, $data, $param) {
        if(!is_dir($pathInfo['dir'])) {
            if(DIRECTORY_SEPARATOR === '\\') {
                $pathInfo['dir'] = iconv('utf-8', 'gbk//ignore', $pathInfo['dir']);
            }
            mkdir($pathInfo['dir'], 0777, true);
        }

        if(DIRECTORY_SEPARATOR === '\\') {
            $pathInfo['fullPath'] = iconv('utf-8', 'gbk//ignore', $pathInfo['fullPath']);
        }

        if(!preg_match('#\.(html|js|css|jpg|png|jpeg|gif|ico)$#is', $pathInfo['fullPath'])) {
            $pathInfo['fullPath'] .= '.html';
        }

        $data = $this->renderContent($data, $param);

        if(!file_exists($pathInfo['fullPath'])) {
            file_put_contents($pathInfo['fullPath'], $data);
        }

        return true;
    }

    /**
     * 内容处理，移除base标签，移除绝对路径（含/开头的路径）
     */
    function renderContent($content, $param) {
        $content = preg_replace('#<base [^>]*>#is', '', $content);  //移除base标签
        $content = str_replace($this->baseUrl, '', $content);   //移除根域名
        $content = preg_replace('#(<(?:img|link|script)[^>]*?(?:src|href)\s*=\s*["\']?)https?://[^/"\']+#is', '$1', $content);  //移除js/css跟域名（外网）
        $content = preg_replace('#((?:src|href)\s*=\s*["\']?)/#is', '$1' . str_repeat('../', $this->getLevelToBaseUrl($param['url'])), $content);    //移除以/开头的/
        $content = preg_replace('#href=(["\'])\1#is', 'href="index.html"', $content);
        $content .= "<!-- CurrentUrl: {$param['url']}, FromUrl: {$param['fromUrl']} -->";
        //文件补全.html
        $html = phpQuery::newDocumentHTML ( $content );
        $list = $html['a,img,link,script'];
        foreach ( $list as $v ) {
            $v = pq ( $v );
            $src = $v->attr ( 'src' );
            $href = $v->attr('href');
            $link = !empty($src) ? $src : $href;
            if(empty($link)) continue;

            if(!preg_match('#\.(html|js|css|jpg|png|jpeg|gif|ico)$#is', $link)) {
                $linkQuote = preg_quote($link, '#');
                
                $content = preg_replace("#((?:src|href)\s*=\s*([\"'])?\s*{$linkQuote})\\2#is", '$1.html$2', $content);
            }
        }
        phpQuery::unloadDocuments();
        
        return $content;
    }
    
    /**
     * 链接解析处理
     * @param $url      当前链接
     * @param $fromUrl  来源链接（如果是相对路径，这个来源链接起到关键作用）
     * @param $skipOutsideLink 跳过外网链接
     */
    function renderUrl($url, $fromUrl, $skipOutsideLink = true) {
        $url = preg_replace('/#[^"]+$/', '', trim($url));
        if(!preg_match('#https?://#is', $url)) {
            if(substr($url, 0, 1) !== '/') {
                $fromUrl = preg_replace('/#.*$/', '', $fromUrl);
                $fromUrl = preg_replace('/\?.*$/', '', $fromUrl);
                $refferUrl = preg_replace('#(https?://.+)/[^/]+\.[^/]+$#is', '$1', $fromUrl);
            } else {
                $refferUrl = $this->baseUrl;
            }

            if(($url == '#') || (stripos($url, 'mailto:') !== false) || (stripos($url, 'javascript:') !== false) || (stripos($url, 'data:image') !== false)) {
                return false;
            }
            
            $url = $refferUrl . '/' . trim($url, '/');
            $url = $this->renderRealUrl($url);
        } else if((stripos($url, $this->baseUrl) === false) && $skipOutsideLink) {    //外链不加载
            return false;
        }

        return rtrim($url, '/');
    }

    /**
     * 处理相对路径，得到绝对路径
     */
    function renderRealUrl($url) {
        if(stripos($url, '../') !== false) {
            $arr = explode('/', $url);

            $newArr = [];
            foreach($arr as $val) {
                if('..' === $val) {
                    array_pop($newArr);
                } else {
                    array_push($newArr, $val);
                }
            }

            $url = implode('/', $newArr);
        }

        return $url;
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
    function parseUrlToPath($url, $fromUrl) {
        $url = $this->renderUrl($url, $fromUrl);
        if(empty($url)) {
            return false;
        }

        $url = str_replace($this->baseUrl, '', $url);

        $url = preg_replace('#\?.*$#i', '', $url);

        $url = preg_replace('/#.*$/i', '', $url);

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

    function getLevelToBaseUrl($url) {
        $subUrl = str_replace($this->baseUrl, '', $url);
        $subUrlArr = explode('/', trim($subUrl, '/'));
        return max(0, count($subUrlArr) - 1);
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

    function setParseAllPage($flag) {
        $this->parseAllPage = $flag;
    }

    function getUrls() {
        return $this->urls;
    }

}

if(empty($argv[1]) || !preg_match('#^https?://#is', $argv[1])) {
    echo "Usage: php webClone.php http://www.zjmainstay.cn \n";
    exit;
}

$demo = new WebClone();
$demo->setParseAllPage(true);    //设置全站采集标记
$demo->setBaseStorageDir(__DIR__ . '/domains');
$demo->run($argv[1]);
print_r($demo->getUrls());
