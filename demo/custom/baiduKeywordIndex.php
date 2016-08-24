<?php
/**
 * 百度关键词排名检测
 *
 * 提供一个关键词和域名，通过百度搜索该关键词的位置，确定该关键词的排名效果
 */


require_once __DIR__ . '/../../vendor/autoload.php';
use Ares333\CurlMulti\Core;
/**
 * 百度关键词排名检测
 *
 * 提供一个关键词和域名，通过百度搜索该关键词的位置，确定该关键词的排名效果
 * @author Zjmainstay
 * @website http://www.zjmainstay.cn
 */
class CheckBaiduKeywordIndex {
    protected $curl           = null;
    protected $domain         = null;    //检测排名的域名
    protected $keyword        = null;    //检测排名的关键词
    protected $maxCheckPage   = 10;      //最大检测分页
    protected $urlN           = '{pn}';  //搜索第N页的链接模板
    protected $lineBreak      = '';
    protected $matchIndex     = array();

    /**
     * 开始检测
     */
    public function run($domain, $keyword) {
        $this->lineBreak = ('cli' == PHP_SAPI) ? "\n" : '<br>';
        $this->domain = trim($domain);
        $this->keyword = trim($keyword);
        $this->curl = new Core();
        $this->curl->maxTry = 0;    //不需要重复请求
        $this->curl->cbInfo = null;
        $this->curl->opt    = array(
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
        );

        if(!$this->checkDomainFormatIsRight($this->domain)) {
            return false;
        }

        $searchKeyword = urlencode($this->keyword);

        $url = "http://www.baidu.com/s?wd={$searchKeyword}";
        $this->urlN = "http://www.baidu.com/s?ie=utf-8&wd={$searchKeyword}&pn={pn}";

        $this->addUrl($url, 1);
        $this->curl->start();

        if(!empty($this->matchIndex)) {
            $msg = "Keyword [{$this->keyword}] match at: " . implode(',', $this->matchIndex);
            $this->_log($msg);
        } else {
            // $msg = "Keyword [{$this->keyword}] not match any one.";
            // $this->_log($msg);
        }
    }

    /**
     * 解析页面抓取结果
     * @param  array $result Ares333\CurlMulti\Core 抓取结果信息
     * @param  array $params 添加链接时携带的参数
     */
    public function handlePageResult($result, $params) {
        if ( 200 == $result ['info']['http_code']) {
            //解析页面搜索结果
            $pageInfo = $this->parsePage($result['content']);

            //检测关键词位置并存储结果
            $this->checkAndSaveKeywordIndex($params['page'], $pageInfo);

            //加入下一页的抓取
            $nextPage = $params['page'] + 1;
            $this->addUrl($this->getUrlN($nextPage), $nextPage);
        } else {
            echo "Error Link: {$result['info']['url']}\n";
        }
    }

    /**
     * 最大检测页数
     * @param  int $maxPage
     */
    public function setMaxCheckPage($maxPage) {
        $this->maxCheckPage = max(1, (int)$maxPage);
    }

    /**
     * 解析页面搜索结果
     * @param  string $content 页面采集结果文本
     * @return array 以域名为键值的数组，同一个域名有多个排序结果，则该键值下是多个数组记录
     */
    protected function parsePage($content) {
        $pattern = '#class="c-showurl"[^>]*>(.*?)\/(?!b>)#is';

        $result = array();
        if(preg_match_all($pattern, $content, $matches)) {
            foreach($matches[1] as $index => $match) {
                $domain = strip_tags($match);
                if(!isset($result[$domain])) {
                    $result[$domain] = array();
                }
                $result[$domain][] = $index + 1;
            }
        }

        return $result;
    }

    /**
     * 检测关键词位置并存储结果
     * @param  int $page 当前分页
     * @param  array $pageInfo 解析页面搜索结果返回值
     */
    protected function checkAndSaveKeywordIndex($page, $pageInfo) {
        if(isset($pageInfo[$this->domain])) {
            foreach($pageInfo[$this->domain] as $index) {
                $this->matchIndex[] = ($page - 1) * 10 + $index;
            }
        }
    }

    /**
     * 添加一个抓取链接
     * @param string $url 抓取链接
     * @param int $page 当前分页数
     */
    protected function addUrl($url, $page) {
        if($page > $this->maxCheckPage) {
            // $this->_log("Reach the maxCheckPage: {$this->maxCheckPage}");
            return false;
        }

        $this->curl->add(
            array (
                'url' => $url,
                'args' => array (
                    'page' => $page,
                )
            ), array (
                $this,
                'handlePageResult'
            )
        );
    }

    /**
     * 获取第N页的抓取链接
     * @param  int $page 第N页
     * @return string
     */
    protected function getUrlN($page) {
        return str_ireplace('{pn}', max(0, ($page - 1)) * 10, $this->urlN);
    }

    /**
     * 记录日志信息
     * @param  string $msg 日志文本
     */
    protected function _log($msg) {
        echo $msg, $this->lineBreak;
    }

    /**
     * 简单交易域名格式
     * @param  string $domain 域名
     * @return bool
     */
    protected function checkDomainFormatIsRight($domain) {
        if(!preg_match('#.+\.(?:com\.cn|com|cn|net|org)$#is', trim($domain))) {
            return false;
        }

        return true;
    }
}



if(!empty($_POST['domain'])) {
    $argv[1] = trim($_POST['domain']);
}
if(!empty($_POST['keywords'])) {
    $argv[2] = trim($_POST['keywords']);
}
if(!empty($_POST['maxCheckPage'])) {
    $argv[3] = max(1, min(20, (int)abs(trim($_POST['maxCheckPage'])))); //演示性能考虑
}

if(empty($argv[1]) || empty($argv[2])) {
    echo "Usage: php baiduKeywordIndex.php domain keyword_separate_by_, [maxCheckPage=10]\n";
    exit;
}

if(empty($argv[3])) {
    $argv[3] = 10;
}

$keywords = explode(',', $argv[2]);
foreach($keywords as $keyword) {
    $checkBaiduKeywordIndex = new CheckBaiduKeywordIndex;
    if(!empty($argv[3])) {
        $checkBaiduKeywordIndex->setMaxCheckPage($argv[3]);
    }
    $checkBaiduKeywordIndex->run($argv[1], $keyword);
}

echo "Every keyword check {$argv[3]} pages, done!\n";
//password

echo "<meta charset='utf-8'><br><a href='baiduKeywordIndex.html'>百度关键词排名查询</a>";
