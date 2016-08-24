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
    protected $lineBreak      = "\n";
    protected $isCli          = true;
    /**
     * 从这里开始抓取
     */
    function run($url) {
        $this->isCli     = ('cli' == PHP_SAPI);
        $this->curl = new Core();
        $this->curl->maxTry = 0;    //不需要重复请求
        $this->curl->cbInfo = null;
        $this->curl->opt    = array(
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_ENCODING        => 'gzip',
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

        //拒绝检测本站
        if(false !== stripos(strtolower($uri['host']), 'zjmainstay')) {
            exit('禁止检测本站');
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
            $this->output("Error Link: {$param['url']}");
        }
    }

    /**
     * 页面链接解析并添加
     */
    function parsePageLink($r, $param) {
        if($this->emptyLink >= $this->maxEmptyLink) {
            return false;
        }

        //if(!preg_match('#<html#is', $r ['content'])) return true;

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
     * 链接解析处理
     * @param $url      当前链接
     * @param $fromUrl  来源链接（如果是相对路径，这个来源链接起到关键作用）
     * @param $parseOutsideLink 外网链接是否解析
     */
    function renderUrl($url, $fromUrl, $parseOutsideLink = false) {
        $url = preg_replace('/#[^"]+$/', '', trim($url));
        if((stripos($url, ' ') !== false) || (stripos($url, '+') !== false) ) { //url中的空格处理
            $url = str_replace(array(' ', '+'), rawurlencode(' '), $url);
        }

        if(!preg_match('#https?://#is', $url)) {
            $currentBaseUrl = $this->parseBaseUrl($url, $fromUrl);
            if(substr($url, 0, 1) !== '/') {
                $fromUrl = preg_replace('/#.*$/', '', $fromUrl);
                $fromUrl = preg_replace('/\?.*$/', '', $fromUrl);
                $refferUrl = rtrim(preg_replace('#(https?://.+(?::\d+)?)/[^/]+\.[^/]+$#is', '$1', $fromUrl), '/');
            } else {
                if(substr($url, 0, 2) === '//') {   //以//开头的绝对路径
                    $refferUrl = 'http:/';  //http:/ + / + url;
                } else {
                    $refferUrl = $currentBaseUrl;
                }
            }

            #if(($url == '#') || (stripos($url, 'mailto:') !== false) || (stripos($url, 'javascript:') !== false) || (stripos($url, 'data:image') !== false)) {
            if(($url == '#') || preg_match('#^[^/]+:#is', $url)) {
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
            if($this->isCli) {
                $msg = "OK: {$param['url']} from {$param['fromUrl']}";
            } else {
                $msg = "检测结果：<span style='color:green'>正确</span>, 检测链接：[<a href='{$param['url']}' target='_blank'>{$param['url']}</a>], 链接来自：[<a href='{$param['fromUrl']}' target='_blank'>查看</a>]";
            }
            $this->output($msg);
            $this->parsePageLink($r, $param);   //继续解析
        } else {
            if($this->isCli) {
                $msg = "Err: {$param['url']} from {$param['fromUrl']}";
            } else {
                $msg = "检测结果：<span style='color:red'>错误</span>, 检测链接：[<a href='{$param['url']}' target='_blank'>{$param['url']}</a>], 链接来自：[<a href='{$param['fromUrl']}' target='_blank'>查看</a>]";
            }
            $this->output($msg);
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

    /**
     * 输出信息
     * @param  string $msg
     */
    function output($msg) {
        echo $msg;
        echo $this->isCli ? "\n" : "<br>\n";
        ob_flush();
        flush();
    }
}

if(!empty($_POST['url'])) {
    $argv[1] = trim($_POST['url']);
}

if(empty($argv[1]) || !preg_match('#^https?://#is', $argv[1])) {
    echo "Usage: php web404Check.php http://www.zjmainstay.cn \n";
    exit;
}

if('cli' != PHP_SAPI) {
    echo str_repeat(" ",1024);
}

$demo = new Web404Check();
$demo->run($argv[1]);

if('cli' != PHP_SAPI) {
    echo '<meta charset="utf-8"><br><br>继续检测<a href="check404.html">站内404爬行检测</a>';
    echo '<script>
        function removeSuccess() {document.getElementsByTagName(\'body\')[0].innerHTML = document.getElementsByTagName(\'body\')[0].innerHTML.replace(/检测结果：<span style="color:green">正确.*<br>/g, \'\');}
        </script>';
    echo '<br><br><a href="javascript:;" onclick="removeSuccess()">只看错误的结果</a>';
}

