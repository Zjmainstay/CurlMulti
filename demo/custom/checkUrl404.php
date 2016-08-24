<?php
require_once __DIR__ . '/../../vendor/autoload.php';
use Ares333\CurlMulti\Core;
/**
 * 404 link check
 * @author Zjmainstay
 */
class CheckUrl404 {
    protected $is404IdArr = array();
    /**
     * 从这里开始抓取
     */
    public function run() {
        $this->curl = new Core();
        $this->curl->maxTry = 0;    //不需要重复请求
        $this->curl->cbInfo = null;
        $this->curl->opt    = array(
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_TIMEOUT         => 5,
            CURLOPT_NOBODY          => true,
        );

        //从数据库获取数据 404字段为0的
        $urls = array(
                    array(
                        'id' => 1,
                        'domain' => "http://www.baidu.com",
                    ),
                    array(
                        'id' => 2,
                        'domain' => "http://www.baiduerror.com",
                    ),
                    array(
                        'id' => 3,
                        'domain' => "http://www.baidu.com/error_page.html",
                    ),
                    array(
                        'id' => 4,
                        'domain' => "http://www.zjmainstay.cn/php-curl",
                    ),
                    array(
                        'id' => 5,
                        'domain' => "https://demo.zjmainstay.cn",
                    ),
                );

        foreach($urls as $url) {
            $this->addUrl($url['id'], $url['domain']);
        }

        $this->curl->start();

        //404标记字段的值：
        //0: 未检测
        //-1: 不是404
        //1: 是404

        if(!empty($this->is404IdArr['error'])) {
            $ids = implode(',', array_keys($this->is404IdArr['error']));

            var_dump($ids);
            //update domains set c_404=1 where id in({$ids}) and c_404=0;

            //更新对于id的404标记字段为1
            //在访问一个连接时，如果得到404字段为1，则直接返回404文本，不再去请求
        }

        if(!empty($this->is404IdArr['success'])) {
            $ids = implode(',', array_keys($this->is404IdArr['success']));

            var_dump($ids);

            //更新对于id的404标记字段为-1
        }
    }

    protected function addUrl($id, $url) {
        $this->curl->add ( array (
                'url' => $url,
                'args' => array (
                    'id'   => $id,
                )
        ), array (
                $this,
                'test404'
        ), array (
                $this,
                'callFail'
        ));
    }

    /**
     * 404 检测
     */
    public function test404($r, $param) {
        if (200 !== (int)$r ['info']['http_code']) {
            $this->is404IdArr['error'][$param['id']] = false;
        } else {
            $this->is404IdArr['success'][$param['id']] = true;
        }
    }

    public function callFail($r, $param) {
        $this->is404IdArr['error'][$param['id']] = false;
    }
}


$checkUrl404 = new CheckUrl404;
$checkUrl404->run();
