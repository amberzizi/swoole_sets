<?php
namespace server;

use Swoole\Http\Request as Requestsw;
use Swoole\Http\Response as Responsesw;
use Swoole\WebSocket\CloseFrame;
use Swoole\Coroutine\Http\Server as Serversw;
use function Swoole\Coroutine\run;
use Redis;



//redis keys

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

//redis

/**
 * redis存储操作
 * Class SysRedis
 * @package server
 */
class SysRedis
{
    protected $redisconnect;

    public function __construct()
    {
        $rec = new Redis();
        $rec->connect(\Yaconf::get('swoole.swoole.host'),
            13000,
            0);
        $rec->auth(\Yaconf::get('swoole.swoole.password'));
        $rec->select(5);
        $this->redisconnect = $rec;
    }

    public function saveRedisSet($key,$info,$expire = 0){
        $this->redisconnect->sAdd($key,$info);
        $this->redisconnect->expire($key,$expire);
    }
    public function returnConnection(){
        return $this->redisconnect;
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
class CallbackActionIm{
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
    public static function create_callback_statechange_struct_obj($request,$OptPlatform,$imid,$action,$reason){
        $sendinfo = array();
        $sendinfo['CallbackCommand'] = 'State.StateChange';
        $sendinfo['ClientIP'] = $request->server['remote_addr'];
        $sendinfo['OptPlatform'] = $OptPlatform;
        $sendinfo['To_Imid'] = $imid;
        $sendinfo['Action'] = $action;
        $sendinfo['Reason'] = $reason;
        return $sendinfo;
    }
}

class SwooleServer{

    protected $server = null;       //Swoole\Server对象
    protected $host = '0.0.0.0'; //监听对应外网的IP 0.0.0.0监听所有ip
    protected $port = 8080;      //监听端口号
    protected $url = '/supiSimDealCenter';      //监听路径
    protected $redisconnect = null;
    protected $limit_useobj_save = 5 * 60;
    protected $single_point_login = true;//是否开启单点登录

    public function __construct()
    {
        $this->redisconnect = new SysRedis();
    }

    /**
     * 开启server
     */
    public function start_server(){


        run(function () {
            set_time_limit(0);
            $this->server = new Serversw($this->host, $this->port, true);
            //设置server相关参数
            $this->server->set(array(
//                'worker_num' => 4,         //设置启动的worker进程数。【默认值：CPU 核数】
//                'max_request' => 1000,    //设置每个worker进程的最大任务数。【默认值：0 即不会退出进程】
//                'daemonize' => 0,          //开启守护进程化【默认值：0】
//                'enable_coroutine' => true //开启异步风格服务器的协程支持
                  'ssl_cert_file' => '/etc/nginx/ssl/testim.pem',
                  'ssl_key_file' => '/etc/nginx/ssl/testim.key',
            ));

            $this->server->handle($this->url, function (Requestsw $request, Responsesw $ws) {
                $ws->upgrade();
                $opensendinfo = $ws->recv()->data;
                $login_status = false;
                /** (1)登录，检查token，获取登录imid **/
                if (!empty($opensendinfo)){
                    $resultemp = $this->loginuser($opensendinfo,$ws,$request);
                    if ($resultemp){
                        $ws = $resultemp;
                        $login_status = true;
                    }

                }
                while (true) {
                    /** (3)根据登录状态处理循环 **/
                    if (!$login_status){
                        break;
                    }
                    $frame = $ws->recv();
                    if ($frame === '') {
                        $ws->close();
                        break;
                    } else if ($frame === false) {
                        echo 'errorCode: ' . swoole_last_error() . "\n";
                        $ws->close();
                        break;
                    } else {

                        /** 关闭分支：正常的关闭 及 关闭浏览器 **/
                        if ($frame->data == 'close' || get_class($frame) === CloseFrame::class) {
                            //移除出全局fd 断线
                            $this->user_disconnect($ws,$request);
                            //通知服务器端
                            break;
                        }

                        //(3)ping pong 刷新全局fd
                        if ($frame->opcode == WEBSOCKET_OPCODE_PING) {
                            //php server ping
                            /** 刷新全局唯一用户对象有效时间 续时 **/
                            $this->refreash_wsobject_available_time($ws);
                            $ws->push('PONE', WEBSOCKET_OPCODE_PONG);

                        }else if ($frame->data == 'PING') {
                            //js ping
                            //CallbackAction::httpsRequest('http://testim.qiaotiantian.com/swoole_client/callbackurl',array(),'GET');
                            /** 刷新全局唯一用户对象有效时间 续时 **/
                            $this->refreash_wsobject_available_time($ws);
                            $ws->push('PONE');


                        }else{
                            //var_dump($wsObjects);全局参数
                            $info = $frame->data;
//                            $info_arr = json_decode($info,true);
//                            if (isset($info_arr['action_type']) && $info_arr['action_type'] == 'User.relogin'){
//                                $login_status = $this->loginuser($info,$ws);
//
//                            }
                            global $wsObjects;
                            var_dump(count($wsObjects));
                            $ws->push('111111');


                        }

                    }
                }
            });


            $this->server->start();
        });
    }

    /**
     * 登录检查
     */
    private function loginuser($opensendinfo,$ws,$request){
        /** check_login_user_token 登录，检查token，获取登录imid **/
        $checkresult = $this->check_login_user_token($opensendinfo);
        if ($checkresult){
            /** (2)登录成功 发送信息，存储ws到redis **/
            $this->login_success_notice($checkresult,$ws,$request);
            return $ws;
        }else{
            $this->login_fail_notice($ws);
            return false;
        }
    }

    /**
     * 检查登录用户token
     */
    private function check_login_user_token($logininfo){
        $logininfo_arr = json_decode($logininfo,true);
        if (empty($logininfo_arr['logintoken'])){
            return false;
        }
        $info = $this->redisconnect->returnConnection()->get(SysSetting::wxpre(SysSetting::$IM_TOKEN_KEYS.$logininfo_arr['logintoken']));
        return $info;
    }

    /**
     *登录成功
     * 单点登录检查
     */
    private function login_success_notice($checkresult,&$ws,$request){
        $checkresult_arr = json_decode($checkresult,true);
        $msgbody = array();
        $msgbody[] = array('action'=>'login','event'=>'success','msgtype'=>'text','msginfo'=>'登录成功');
        $sendinfo = $this->create_return_struct($msgbody,$checkresult_arr,'login');
        $ws->push($sendinfo);
        if (isset($checkresult_arr['login_manager']) && $checkresult_arr['login_manager']){
            $ws->ismanager = true;
        }
        $ws->logintoken = $checkresult_arr['token'];
        $ws->imid = $checkresult_arr['imid'];
        $ws->platformid = $checkresult_arr['belong_platform_id'];

        $savews = json_encode($ws);

        if ($this->single_point_login){
            //如果开启了单点登录
            //检查是否为断线重连
            //清理之前登录的用户
            $this->single_point_deal($ws);

        }


        //判断用户归属  普通用户 /管理员
        if (isset($checkresult_arr['login_manager']) && $checkresult_arr['login_manager']){
            //redis绑定在线imid信息
            $this->redisconnect->returnConnection()->setex(SysSetting::wxpre(SysSetting::$IM_ONLINE_MANAGER_IMIDS.$checkresult_arr['belong_platform_id'].':'.$checkresult_arr['imid']),$this->limit_useobj_save,$savews);
            //全局list里放入imid
            $this->redisconnect->returnConnection()->sAdd(SysSetting::wxpre(SysSetting::$IM_ONLINE_MANAGER_IMIDS_LIST.$checkresult_arr['belong_platform_id']),$checkresult_arr['imid']);
        }else{
            //redis绑定在线imid信息
            $this->redisconnect->returnConnection()->setex(SysSetting::wxpre(SysSetting::$IM_ONLINE_IMIDS.$checkresult_arr['belong_platform_id'].':'.$checkresult_arr['imid']),$this->limit_useobj_save,$savews);
            //全局list里放入imid
            $this->redisconnect->returnConnection()->sAdd(SysSetting::wxpre(SysSetting::$IM_ONLINE_IMIDS_LIST.$checkresult_arr['belong_platform_id']),$checkresult_arr['imid']);
        }

        //设置全局对象
        $this->setGloble_var($ws);
        //登录成功构造消息体
        $imcenterinfo = CallbackActionIm::create_callback_statechange_struct_obj($request,'sim_',$ws->imid,'Login','Normal');
        //通知imcenter
        CallbackActionIm::httpsRequest(CallbackActionIm::$callbackurl,$imcenterinfo,'POST');


    }

    private function setGloble_var(&$ws){
        //全局绑定
        global $wsObjects;
        $wsObjects[$ws->imid] = $ws;
    }

    /**
     * 单点登录处理
     * 检查是否为断线重连
     */
    private function single_point_deal($ws){

        $checkiflogin = $this->check_user_if_available($ws->ismanager ?? false,$ws->platformid,$ws->imid);
        if ($checkiflogin && ($ws->logintoken != $checkiflogin->logintoken)){
            var_dump('single_point_login');
            //当全局有以往登录的账号   并且登录token 和最后一次登录的token不同  证明不是断线重连
            //(1)删除登录token
            $token = $checkiflogin->logintoken;
            $this->redisconnect->returnConnection()->del(SysSetting::wxpre(SysSetting::$IM_TOKEN_KEYS.$token));
            //（2）发送登出信息
            global $wsObjects;
            $ori_ws = $wsObjects[$checkiflogin->imid];
            $this->login_fail_notice($ori_ws,'已在其他位置登入');

        }
    }

    /**
     * 刷新全局用户有效时间
     */
    private function refreash_wsobject_available_time($ws){
        $savews = json_encode($ws);
        if (isset($ws->ismanager)){
            //管理员
            $this->redisconnect->returnConnection()->setex(SysSetting::wxpre(SysSetting::$IM_ONLINE_MANAGER_IMIDS.$ws->platformid.':'.$ws->imid),$this->limit_useobj_save,$savews);
        }else{
            $this->redisconnect->returnConnection()->setex(SysSetting::wxpre(SysSetting::$IM_ONLINE_IMIDS.$ws->platformid.':'.$ws->imid),$this->limit_useobj_save,$savews);

        }
    }

    /**
     * 登录失败
     */
    private function login_fail_notice($ws,$stringinfo = '登录失败,请重新登录'){
        $ws->push($stringinfo);
        $ws->close();
    }

    /**
     * 断线/登出
     * 删除redis上ws
     */
    private function user_disconnect($ws,$request){
        if (isset($ws->ismanager)){
            $this->redisconnect->returnConnection()->del(SysSetting::wxpre(SysSetting::$IM_ONLINE_MANAGER_IMIDS.$ws->platformid.':'.$ws->imid));
            //全局list里删除imid
            $this->redisconnect->returnConnection()->sRem(SysSetting::wxpre(SysSetting::$IM_ONLINE_MANAGER_IMIDS_LIST.$ws->platformid),$ws->imid);
        }else{
            $this->redisconnect->returnConnection()->del(SysSetting::wxpre(SysSetting::$IM_ONLINE_IMIDS.$ws->platformid.':'.$ws->imid));
            //全局list里删除imid
            $this->redisconnect->returnConnection()->sRem(SysSetting::wxpre(SysSetting::$IM_ONLINE_IMIDS_LIST.$ws->platformid),$ws->imid);
        }
        $ws->push('登出');
        $ws->close();
        global $wsObjects;
        unset($wsObjects[$ws->imid]);
        //登出构造消息体
        $imcenterinfo = CallbackActionIm::create_callback_statechange_struct_obj($request,'sim_',$ws->imid,'Logout','Normal');
        //通知imcenter
        CallbackActionIm::httpsRequest(CallbackActionIm::$callbackurl,$imcenterinfo,'POST');
    }

    /**
     * 检查目标用户是否在线？
     * return false   / ws obj
     */
    private function check_user_if_available($ifmanager,$pf,$imid){
        if ($ifmanager){
            $ifexsit = $this->redisconnect->returnConnection()->sIsMember(SysSetting::wxpre(SysSetting::$IM_ONLINE_MANAGER_IMIDS_LIST.$pf),$imid);
            if ($ifexsit){
                $re = $this->redisconnect->returnConnection()->get(SysSetting::wxpre(SysSetting::$IM_ONLINE_MANAGER_IMIDS.$pf.':'.$imid));
                if ($re){
                    return json_decode($re);
                }
            }
            return false;

        }else{
            $ifexsit = $this->redisconnect->returnConnection()->sIsMember(SysSetting::wxpre(SysSetting::$IM_ONLINE_IMIDS_LIST.$pf),$imid);
            if ($ifexsit){
                $re = $this->redisconnect->returnConnection()->get(SysSetting::wxpre(SysSetting::$IM_ONLINE_IMIDS.$pf.':'.$imid));
                if ($re){
                    return json_decode($re);
                }
            }
            return false;
        }
    }

    /**
     * 创建信息结构体
     */
    private function create_return_struct($msgbody,$userinfo,$action='',$toimid='',$fromimid=''){
        $return = array();
        $return['userinfo'] = $userinfo;
        $return['action'] = $action;
        $return['to_imid'] = $toimid;
        $return['from_imid'] = $fromimid;
        //$msgbody = array(
        //      array('action'=>'talk','event'=>'normal','msgtype'=>'text','msginfo'=>'ceshi'),
        //     array('action'=>'talk','event'=>'normal','msgtype'=>'text','msginfo'=>'ceshi')
        //);
        $return['msgbody'] = $msgbody;
        return json_encode($return,JSON_UNESCAPED_UNICODE);
    }



}

$currentSwooleServer = new SwooleServer();
$currentSwooleServer->start_server();


