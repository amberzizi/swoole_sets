<?php

namespace server;

use Redis;

/**
 * redis 订阅 过期事件处理
 */


/**
 * redis存储key
 * Class SysSetting
 * @package server
 */
class SysSetting{
    public static $KEYS_PREFIX = "sim:";
    //imtoken 存储key string
    public static $IM_TOKEN_KEYS = "platform:token:";  //:token
    //在线imid 存储key string
    public static $IM_ONLINE_IMIDS = "platform:online_imid:";  //:platfrom
    //在线imids  list
    public static $IM_ONLINE_IMIDS_LIST = "platform:online_imids_list:"; //:platfrom

    //在线imid 存储key string  管理员
    public static $IM_ONLINE_MANAGER_IMIDS = "platform:online_manager_imid:";  //:platfrom
    //在线imids  list  管理员
    public static $IM_ONLINE_MANAGER_IMIDS_LIST = "platform:online_manager_imids_list:"; //:platfrom


    public static function wxpre($key){
        return SysSetting::$KEYS_PREFIX.$key;
    }
}


/**
 * 通知回调类
 * 上下线通知
 * 聊天信息保存
 *
 * Class CallbackAction
 * @package server
 */
class CallbackAction{
    public static $callbackurl = 'https://testim.qiaotiantian.com/sim/v1/sim_callback_receive_interface';
    /**
     *  http请求
     */
    public static function httpsRequest($url, $data=null, $method='GET', $erpHeader=array()) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        if(!empty($erpHeader)){
            curl_setopt($curl, CURLOPT_HTTPHEADER, $erpHeader);
        }
        if(!empty($data) && strtoupper($method) == 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }
        if($method == 'DELETE'){
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_NOSIGNAL, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT , 30);
        curl_setopt($curl, CURLOPT_TIMEOUT_MS, 120000);
        $output = curl_exec($curl);
        curl_close($curl);
        return $output;
    }

    //在线状态变化
    public static function create_callback_statechange_struct_obj($ip,$OptPlatform,$imid,$action,$reason){
        $sendinfo = array();
        $sendinfo['CallbackCommand'] = 'State.StateChange';
        $sendinfo['ClientIP'] = $ip;
        $sendinfo['OptPlatform'] = $OptPlatform;
        $sendinfo['To_Imid'] = $imid;
        $sendinfo['Action'] = $action;
        $sendinfo['Reason'] = $reason;
        return $sendinfo;
    }
}


//redis

/**
 * redis存储操作
 * @package server
 */

class RedisPub{

    public $redisconnect = '';

    public function __construct()
    {
        $rec = new \Redis();
        $rec->connect(\Yaconf::get('swoole.swoole.host'),
            13000,
            0);
        $rec->auth(\Yaconf::get('swoole.swoole.password'));
        $rec->select(5);
        $this->redisconnect = $rec;
    }

    public function dealPub(){
        $this->redisconnect->setOption(\Redis::OPT_READ_TIMEOUT, -1);
        //$this->redisconnect->psubscribe(array('__keyevent@5__:expired'),array($this,'callbackdeal'));
        $this->redisconnect->psubscribe(array('__keyevent@5__:expired'),array($this,'callbackdeal'));
    }

    function callbackdeal($redis, $pattern, $chan, $msg){
        $info_arr = explode(':',$msg);
        if(isset($info_arr[2]) && $info_arr[2] == 'online_imid'){
            $imid_arr = explode('_',$info_arr[4]);
            if (isset($imid_arr[1]) && $imid_arr[1] == 'NUSER'){
                //普通用户
                //用户key异常超时删除
                //从全局队列中移除
                $keys = SysSetting::wxpre(SysSetting::$IM_ONLINE_IMIDS_LIST.$info_arr[3]);
                $values = $info_arr[4];
                $this->newobjDelSetMember($keys,$values);
                //通知掉线
            }

            if (isset($imid_arr[1]) && $imid_arr[1] == 'UK'){
                //游客 未知用户
                //用户key异常超时删除
                //从全局队列中移除
                $keys = SysSetting::wxpre(SysSetting::$IM_ONLINE_IMIDS_LIST.$info_arr[3]);
                $values = $info_arr[4];
                $this->newobjDelSetMember($keys,$values);
                //通知掉线
            }

        }else if(isset($info_arr[2]) && $info_arr[2] == 'online_manager_imid'){
            //管理员
                $imid_arr = explode('_',$info_arr[4]);
                //普通用户
                //用户key异常超时删除
                //从全局队列中移除
                $keys = SysSetting::wxpre(SysSetting::$IM_ONLINE_MANAGER_IMIDS_LIST.$info_arr[3]);
                $values = $info_arr[4];
                $this->newobjDelSetMember($keys,$values);
                //通知掉线

        }

        //登录成功构造消息体
        $imcenterinfo = CallbackAction::create_callback_statechange_struct_obj('0.0.0.0','sim_',$info_arr[4],'Disconnect','Normal');
        //通知imcenter
        CallbackAction::httpsRequest(CallbackAction::$callbackurl,$imcenterinfo,'POST');
    }

    //删除 全局imid
    public function newobjDelSetMember($mkey,$mvalue){
        $rec2 = new \Redis();
        $rec2->connect(\Yaconf::get('swoole.swoole.host'),
            13000,
            0);
        $rec2->auth(\Yaconf::get('swoole.swoole.password'));
        $rec2->select(5);
        $rec2->sRem($mkey,$mvalue);
    }
}

$rep = new RedisPub();
$rep->dealPub();
