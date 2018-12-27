<?php
require_once __DIR__ . '/../../vendor/autoload.php';
use Ares333\CurlMulti\Core;
/**
 * 网页抓取
 * 支持单页抓取、全站抓取、全网抓取（抓取外链）
 * @author Zjmainstay
 */
class WebClone {
    protected $curl           = null;
    protected $baseUrl        = null;
    protected $urls           = array();
    protected $emptyLink      = 0;
    protected $maxEmptyLink   = 50;     //最大空链次数
    protected $parseAllPage   = false;  //抓取全站标记 true:抓取全站   false:不抓取全站
    protected $parseOutsiteLink = false;  //抓取外链标记 true:抓取外链   false:不抓取外链

    /**
     * 从这里开始抓取
     */
    function run($url) {
        $this->curl = new Core();
        $this->curl->maxTry = 0;    //不需要重复请求
        $this->curl->cbInfo = null;
        $this->curl->opt    += array(
            CURLOPT_ENCODING => 'gzip',
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
        echo "Add url: {$url} from {$fromUrl}\n";
        if(!preg_match('#^https?://#i', $url)) return $this->curl;

        if(preg_match_all('#[\x7f-\xff]+#', $url, $matches)) {
            $origUrl = $url;
            foreach($matches[0] as $key => $val) {
                $url = str_replace($val, urlencode(mb_convert_encoding($val, 'gbk', "UTF-8")), $url);
            }
            echo "Eoncoding url: {$origUrl} to {$url}\n";
        }

        // echo "{$url} from {$fromUrl}\n";
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
                'opt' => [
                    CURLOPT_REFERER => $fromUrl,
                ],
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

            echo "OK url: {$param['url']}\n";

            //解析js/css/img
            $this->parseAssetsLink($r, $param);

            if($this->parseAllPage) {
                //解析页面其他链接（内链）
                $this->parsePageLink($r, $param);
            }
        } else {
            echo "Error url1: {$param['url']} from {$param['fromUrl']}\n";
        }
    }

    /**
     * js/css页面的请求结果处理
     */
    function handleAssetsResult($r, $param) {
        if (! $this->httpError ( $r ['info'] )) {
            $url = $param['url'];
            if(preg_match('#https?://[^/]+#is', $param['url'], $match)) {
                // $url = str_replace($match[0], '', $param['url']);    //移除链接
            }
            $pathInfo = $this->parseUrlToPath($url, $param['fromUrl'], true);
            if(!empty($pathInfo)) {
                $this->cacheFile($pathInfo, $r['content'], $param);
            }

            if(isset($param['type']) && ($param['type'] === 'css')){
                $parseOutsiteLink = true;   //js/css加载外链
                if(preg_match_all ( '/@import\s+url\s*\(\s*(["\'])?([^\'")]+)\\1?\s*\);/iU', $r['content'], $matches )) {
                    $urls = array();
                    foreach ( $matches[2] as $cssUrl ) {
                        $cssUrl = $this->renderUrl($cssUrl, $param['url'], $parseOutsiteLink);
                        if(!empty($cssUrl)) $urls[] = $cssUrl;
                    }
                    $this->addAssetsUrl($urls, $param['url'], array('type' => 'css'));
                }

                if(preg_match_all ( '/:[^{:]*\s*url\s*\(\s*(["\'])?([^\'")]+)\\1?\s*\)/i', $r['content'], $matches )) {
                    $urls = array();
                    foreach ( $matches[2] as $cssUrl ) {
                        $cssUrl = $this->renderUrl($cssUrl, $param['url'], $parseOutsiteLink);
                        if(!empty($cssUrl)) $urls[] = $cssUrl;
                    }
                    $this->addAssetsUrl($urls, $param['url'], array('type' => 'css'));
                }
            }

        } else {
            echo "Error url2: {$param['url']} from {$param['fromUrl']}\n";
        }
    }

    function isHtml($content) {
        if(!preg_match('#</?html#is', $content) && !preg_match('#<!DOCTYPE html>#is', $content)) return false;
        return true;
    }

    /**
     * 页面链接解析并添加
     */
    function parsePageLink($r, $param) {
        if($this->emptyLink >= $this->maxEmptyLink) {
            return false;
        }

        if(!$this->isHtml($r['content'])) return false;

        $html = phpQuery::newDocumentHTML ( $r ['content'] );
        $list = $html ['a'];

        $urls = array();
        foreach ( $list as $v ) {
            $v = pq ( $v );
            $url = $this->renderUrl($v->attr ( 'href' ), $param['url'], $this->parseOutsiteLink);
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
        if(!$this->isHtml($r['content'])) return false;

        $html = phpQuery::newDocumentHTML ( $r ['content'] );

        $parseOutsiteLink = true;   //js/css/img加载外链

        //CSS
        $list = $html ['link'];
        $urls = array();
        foreach ( $list as $v ) {
            $v = pq ( $v );
            $url = $this->renderUrl($v->attr ( 'href' ), $param['url'], $parseOutsiteLink);
            if(!empty($url)) $urls[] = $url;
        }
        $this->addAssetsUrl($urls, $param['url'], array('type' => 'css'));

        //JS
        $list = $html ['script'];
        $urls = array();
        foreach ( $list as $v ) {
            $v = pq ( $v );
            if(empty($v->attr ( 'src' ))) continue;
            $url = $this->renderUrl($v->attr ( 'src' ), $param['url'], $parseOutsiteLink);
            if(!empty($url)) $urls[] = $url;
        }
        $this->addAssetsUrl($urls, $param['url'], array('type' => 'js'));

        //Image
        $list = $html ['img,input[type=image]'];
        $urls = array();
        foreach ( $list as $v ) {
            $v = pq ( $v );
            $src = $v->attr ( 'src' );
            if(empty($src)) {
                $src = $v->attr ( 'src2' );
            }
            if(empty($src)) {
                $src = $v->attr ( '_src' );
            }
            if(empty($src)) continue;
            $url = $this->renderUrl($src, $param['url'], $parseOutsiteLink);

            if(!empty($url)) {
                //图片已存在不再采集
                $pathInfo = $this->parseUrlToPath($url, $param['url'], true);
                if(!empty($pathInfo['fullPath']) && file_exists($pathInfo['fullPath'])) {
                    continue;
                }

                $urls[] = $url;
            }
        }
        $this->addAssetsUrl($urls, $param['url'], array('type' => 'img'));
        phpQuery::unloadDocuments();

        //inner css img like background: url("img.jpg");
        if(preg_match_all('/url\s*\(\s*([\'"])?([^\'")]*)\1?\)/i', $r ['content'], $matchCssImgUrls)) {
            $urls = array();
            foreach($matchCssImgUrls[2] as $url) {
                $urls[] = $url;
            }
            $this->addAssetsUrl($urls, $param['url'], array('type' => 'img'));
        }
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
        $currentBaseUrl = $this->parseBaseUrl($param['url'], $param['fromUrl']);
        $content = preg_replace('#<base [^>]*>#is', '', $content);  //移除base标签
        $content = str_replace($currentBaseUrl, '/', $content);   //移除根域名
        $content = preg_replace('#((?:src|href)\s*=\s*["\']?)/#is', '$1' . str_repeat('../', $this->getLevelToBaseUrl($param['url'], $currentBaseUrl)), $content);    //移除以/开头的/
        $content = preg_replace('#(<(?:img|link|script)[^>]*?(?:src|href)\s*=\s*["\']?)https?://#is', '$1/', $content);  //移除js/css跟域名（外网）
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

            if(!preg_match('@\.(html|js|css|jpg|png|jpeg|gif|ico)([#?].*)?$@is', $link)) {
                $newLink = preg_replace('@[#?].*$@i', '.html$0', $link);
                $content = str_replace($link, $newLink, $content);
            }
        }
        phpQuery::unloadDocuments();

        return $content;
    }

    /**
     * 链接解析处理
     * @param $url      当前链接
     * @param $fromUrl  来源链接（如果是相对路径，这个来源链接起到关键作用）
     * @param $parseOutsideLink 外网链接是否解析
     */
    function renderUrl($url, $fromUrl, $parseOutsideLink = false) {
        $url = preg_replace('/#[^"]+$/', '', trim($url));
        $url = preg_replace('/\?[^"]+$/', '', trim($url));
        if(substr($url, 0, 2) == '//') {
            preg_match('#https?:#i', $fromUrl, $match);
            $url = $match[0] . $url;
        }
        if((stripos($url, ' ') !== false) || (stripos($url, '+') !== false) ) { //url中的空格处理
            $url = str_replace(array(' ', '+'), rawurlencode(' '), $url);
        }

        if(!preg_match('#https?://#is', $url)) {
            $currentBaseUrl = $this->parseBaseUrl($url, $fromUrl);
            if(substr($url, 0, 1) !== '/') {
                $fromUrl = preg_replace('/#.*$/', '', $fromUrl);
                $fromUrl = preg_replace('/\?.*$/', '', $fromUrl);
                $refferUrl = preg_replace('#(https?://.+(?::\d+)?)/[^/]+\.[^/]+$#is', '$1', $fromUrl);
            } else {
                if(substr($url, 0, 2) === '//') {   //以//开头的绝对路径
                    $refferUrl = 'http:/';  //http:/ + / + url;
                } else {
                    $refferUrl = $currentBaseUrl;
                }
            }

            if(($url == '#') || (stripos($url, 'mailto:') !== false) || (stripos($url, 'javascript:') !== false) || (stripos($url, 'data:image') !== false)) {
                return false;
            }

            if(substr($url, 0, 5) == 'data:') {
                return $url;
            }

            $url = $refferUrl . '/' . trim($url, '/');
            $url = $this->renderRealUrl($url);
        } else if((stripos($url, $this->baseUrl) === false) && !$parseOutsideLink) {    //外链
            return false;
        }

        return rtrim($url, '/');
    }

    function parseBaseUrl($url, $fromUrl) {
        if(!preg_match('#https?://#is', $url)) {        //相对路径
            $url = $fromUrl;
        }
        //从来源地址解析根域名
        if(!preg_match('#https?://[^/]+(?::\d+)?#is', $url, $match)) {
            return false;
        }

        return $match[0];
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
    function parseUrlToPath($url, $fromUrl, $parseOutsiteLink = false) {
        $url = $this->renderUrl($url, $fromUrl, $parseOutsiteLink || $this->parseOutsiteLink);
        if(empty($url)) {
            return false;
        }

        $currentBaseUrl = $this->parseBaseUrl($url, $fromUrl);

        $url = str_replace($currentBaseUrl, '', $url);

        $url = preg_replace('#\?.*$#i', '', $url);

        $url = preg_replace('/#.*$/i', '', $url);

        if(!preg_match('#^(.*/)?([^/]*)$#is', $url, $match)) {
            return false;
        }

        $filename = $this->renderFilename($match[2]);
        if(empty($filename)) {
            $filename = 'index';
        }

        $dirname  = rtrim($this->renderPath($this->parseBaseUrlToPath($match[1], $currentBaseUrl)), '/');

        $fullPath = $dirname . '/' . $filename;
        if(DIRECTORY_SEPARATOR === '\\') {
            $fullPath = iconv('utf-8', 'gbk//ignore', $fullPath);
        }
        if(!preg_match('@\.(html|js|css|jpg|png|jpeg|gif|ico)([#?].*)?$@is', $fullPath)) {
            $fullPath .= '.html';
        }

        return array(
                'filename'  => $filename,
                'dir'       => $dirname,
                'fullPath'  => $fullPath,
            );
    }

    /**
     * 解析url补充根路径
     */
    function parseBaseUrlToPath($url, $baseUrl) {
        return $this->getBaseDir($baseUrl) . str_replace($baseUrl, '', $url);
    }

    /**
     * 设置存储根目录（不含站点本身目录）
     */
    function setBaseStorageDir($dir) {
        if(!is_dir($dir)) {
            mkdir($dir);
        }
        $this->baseStorageDir = $dir;
    }

    /**
     * 项目存储根目录
     */
    function getBaseDir($baseUrl) {
        return $this->baseStorageDir . '/' . $this->renderPath($baseUrl);
    }

    function getLevelToBaseUrl($url, $baseUrl) {
        $subUrl = str_replace($baseUrl, '', $url);
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

    function setParseOutsiteLink($flag) {
        $this->parseOutsiteLink = $flag;
    }

    function getUrls() {
        return $this->urls;
    }

}

if(empty($argv[1]) || !preg_match('#^https?://#is', $argv[1])) {
    echo "Usage: php webClone.php http://www.zjmainstay.cn [parseAllPage=0] [outsiteLink=0]\n";
    exit;
}

$demo = new WebClone();
$parseAllPage = empty($argv[2]) ? 0 : $argv[2];
$demo->setParseAllPage($parseAllPage);    //设置全站采集标记 1:采集全站 0:不采集全站（默认）
$parseOutsiteLink = empty($argv[3]) ? 0 : $argv[3];
$demo->setParseOutsiteLink($parseOutsiteLink);    //设置外链采集标记 1:采集外链 0:不采集外链（默认）
$demo->setBaseStorageDir(__DIR__ . '/domains');
$demo->run($argv[1]);
print_r($demo->getUrls());
echo "\n", __DIR__ . '/domains', "\n";


//bug 页面本身的url() css解析
