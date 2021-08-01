<?php

use Swoole\Http\Request as Requestsw;
use Swoole\Http\Response as Responsesw;
use Swoole\WebSocket\CloseFrame;
use Swoole\Coroutine\Http\Server as Serversw;
use function Swoole\Coroutine\run;

//redis keys
class SysSetting{
    public static $pre = 'ws:';
    //set
    public static $makesure_ticketkey = "ticket:action:makesure:"; //:actionid:datattimes已支付的作为归属
    //set
    public static $temp_singleactiontimegroup = "ticket:action:clienttempfdrelationgroup:"; //:actionid:datattimes当前活动场次内 运行时参与人员
    //string
    public static $global_fd = "fd:"; //运行时fd
    //set
    public static $temp_singleactiontimepicked = "ticket:action:clienttemppicked:"; //:actionid:datattimes当前活动场次内 运行时参与人员 已选择



    public static function wxpre($key){
        return SysSetting::$pre.$key;
    }
}

//redis
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
        $rec->select(2);
        $this->redisconnect = $rec;
    }

    public function saveRedisSet($key,$info,$expire = 0){
        $this->redisconnect->sAdd($key,$info);
        $this->redisconnect->expire($key,$expire);
    }
    public function returnConnection(){
        return $this->redisconnect;
    }

    //首次接入活动页
    public function firstlogin_action($fd,$sendinfo){
        $sendinfo_arr = json_decode($sendinfo,true);

        $actionallmembers = $this->redisconnect->sMembers(
            SysSetting::wxpre(SysSetting::$temp_singleactiontimegroup.$sendinfo_arr['action_id'].':'.$sendinfo_arr['datetimes']));

        //（1）获取到本活动分组内已存在的fduid用户关系
        //检查是否uid是否已在本页面登录过 删除群组内老的fd||UID关系；删除老fd相关临时选座记录；
        // 一个活动页仅允许记录一次
        // 超时断连
        foreach ($actionallmembers as $member){
            //单点  //超时断连
            if ((stristr($member,$fd.'||') !== false) || (stristr($member,'||'.$sendinfo_arr['client_id']) !== false)){
                $oldfd_arr = explode('||',$member);
                $oldfd = $oldfd_arr[0];
                $this->remove_tempgroup_member($member,SysSetting::wxpre(SysSetting::$temp_singleactiontimegroup.$sendinfo_arr['action_id'].':'.$sendinfo_arr['datetimes']));
                $this->remove_tempgrouppicked_info_search($member,SysSetting::wxpre(SysSetting::$temp_singleactiontimepicked.$sendinfo_arr['action_id'].':'.$sendinfo_arr['datetimes']));
                //$this->remove_fd($oldfd);
            }

        }

        //(2)插入新的临时群组fd||UID关系 更新全局fd表
        $this->add_fd($fd,$sendinfo_arr['client_id'],$sendinfo_arr['action_id'],$sendinfo_arr['datetimes']);
        $this->add_tempgroup_member($fd.'||'.$sendinfo_arr['client_id'],SysSetting::wxpre(SysSetting::$temp_singleactiontimegroup.$sendinfo_arr['action_id'].':'.$sendinfo_arr['datetimes']));

        //(3)当前活动获取稳定的座位信息 + 临时座位占用信息
        $actionstable_sites = $this->redisconnect->sMembers(SysSetting::wxpre(SysSetting::$makesure_ticketkey.$sendinfo_arr['action_id'].':'.$sendinfo_arr['datetimes']));
        $actiontemp_sites = $this->redisconnect->sMembers(SysSetting::wxpre(SysSetting::$temp_singleactiontimepicked.$sendinfo_arr['action_id'].':'.$sendinfo_arr['datetimes']));

        return array('actionstable_sites'=>$actionstable_sites,'actiontemp_sites'=>$actiontemp_sites);
    }


    //临时选座记录 增
    public function add_tempgrouppicked_info($member,$key){
        $this->redisconnect->sAdd($key,$member);
    }
    //临时选座记录 精准删
    public function remove_tempgrouppicked_info($member,$key){
        $this->redisconnect->sRem($key,$member);
    }
    //临时选座记录 范围删
    public function remove_tempgrouppicked_info_search($member,$key){
        $actionallmembers = $this->redisconnect->sMembers($key);
        foreach ($actionallmembers as $memberitem){
            if (stristr($memberitem,$member) !== false){
                $this->redisconnect->sRem($key,$memberitem);
            }
        }
    }
    //临时选座记录 范围查询
    public function check_tempgrouppicked_info_search($member,$key){
        $actionallmembers = $this->redisconnect->sMembers($key);
        foreach ($actionallmembers as $memberitem){
            if (stristr($memberitem,$member) !== false){
                return $memberitem;
            }
        }
        return false;
    }

    //临时活动组成员 增
    public function add_tempgroup_member($member,$key){
        $this->redisconnect->sAdd($key,$member);
    }
    //临时活动组成员 删
    public function remove_tempgroup_member($member,$key){
        $this->redisconnect->sRem($key,$member);
    }
    //临时活动组成员 范围删
    public function remove_tempgroup_member_search($member,$key){
        $actionallmembers = $this->redisconnect->sMembers($key);
        foreach ($actionallmembers as $memberitem){
            if (stristr($memberitem,$member) !== false){
                $this->redisconnect->sRem($key,$memberitem);
            }
        }
    }
    //全局fd
    //fd - uid  参与多活动关系记录
    public function add_fd($fd,$uid,$actionid,$datatimes){
        $info = $this->redisconnect->get(SysSetting::wxpre(SysSetting::$global_fd.$fd));
        if ($info){
            $info_arr = json_decode($info,true);
            $info_arr['client_id'] = $uid;
            $info_arr['join_action_info'][] = $actionid.':'.$datatimes;
            $info_arr['join_action_info'] = array_unique($info_arr['join_action_info']);
            $this->redisconnect->set(SysSetting::wxpre(SysSetting::$global_fd.$fd),json_encode($info_arr,JSON_UNESCAPED_UNICODE));
        }else{
            $info_arr = array();
            $info_arr['client_id'] = $uid;
            $info_arr['join_action_info'][] = $actionid.':'.$datatimes;
            $this->redisconnect->set(SysSetting::wxpre(SysSetting::$global_fd.$fd),json_encode($info_arr,JSON_UNESCAPED_UNICODE),60);
        }
    }
    //下线移除fd关系  遍历所有临时活动，移除临时活动记录，移出临时组成员
    public function remove_fd($fd){
        $info = $this->redisconnect->get(SysSetting::wxpre(SysSetting::$global_fd.$fd));
        $info_arr = json_decode($info,true);
        if (isset($info_arr['join_action_info']) && count($info_arr['join_action_info'])>0){
            foreach ($info_arr['join_action_info'] as $actionitem){
                //移除组临时成员
                $this->remove_tempgroup_member($fd.'||'.$info_arr['client_id'],SysSetting::wxpre(SysSetting::$temp_singleactiontimegroup.$actionitem));
                //移除所有临时选座记录
                $this->remove_tempgrouppicked_info_search($fd.'||'.$info_arr['client_id'],SysSetting::wxpre(SysSetting::$temp_singleactiontimepicked.$actionitem));

            }
        }
        //删除fd
        $this->redisconnect->del(SysSetting::wxpre(SysSetting::$global_fd.$fd));
    }
    public function check_had_fd($fd){
        $re = $this->redisconnect->exists(SysSetting::wxpre(SysSetting::$global_fd.$fd));
        if ($re == 1){
            return true;
        }
        return false;
    }
    public function refresh_fd_score($fd){
        $this->redisconnect->expire(SysSetting::wxpre(SysSetting::$global_fd.$fd),60);
    }

    //变化座位选择
    public function change_tempsite_status($fd,$sendinfo){
        $sendinfo_arr = json_decode($sendinfo,true);
        if ($sendinfo_arr['action_type'] == 'pick_add'){
            //检查 是否已经被人选取  不可重复选取
            $check = $this->check_tempgrouppicked_info_search('||'.$sendinfo_arr['action_site_id'],SysSetting::wxpre(SysSetting::$temp_singleactiontimepicked.$sendinfo_arr['action_id'].':'.$sendinfo_arr['datetimes']));
            if ($check){
                //该座位已被选中
                return false;
            }
            $member = $fd.'||'.$sendinfo_arr['client_id'].'||'.$sendinfo_arr['action_site_id'];
            $this->add_tempgrouppicked_info($member,
                SysSetting::wxpre(SysSetting::$temp_singleactiontimepicked.$sendinfo_arr['action_id'].':'.$sendinfo_arr['datetimes']));
        }else if ($sendinfo_arr['action_type'] == 'pick_remove'){
            $member = $fd.'||'.$sendinfo_arr['client_id'].'||'.$sendinfo_arr['action_site_id'];
            //检查 是否是本uid有修改取消权限
            $check = $this->check_tempgrouppicked_info_search($member,SysSetting::wxpre(SysSetting::$temp_singleactiontimepicked.$sendinfo_arr['action_id'].':'.$sendinfo_arr['datetimes']));
            if (!$check){
                //没有命中 选择该座位的非本uid
                return false;
            }

            $this->remove_tempgrouppicked_info($member,
                SysSetting::wxpre(SysSetting::$temp_singleactiontimepicked.$sendinfo_arr['action_id'].':'.$sendinfo_arr['datetimes']));
        }

        return true;

    }

}



run(function () {
    $server = new Serversw('0.0.0.0', 8080, false);
    $server->handle('/websocket_sitepick', function (Requestsw $request, Responsesw $ws) {
        $ws->upgrade();
        $redisconnect = new SysRedis();

        //首次连接
        //首次连入服务器
        //（1）检查是否uid已加入活动组，离线老接入
        //（2）加入活动组
        //（3）返回最新稳定及临时选座状态
        $frame_onconnect = $ws->recv();
        $sites_status = $redisconnect->firstlogin_action($frame_onconnect->fd,$frame_onconnect->data);
        $ws->push(json_encode($sites_status,JSON_UNESCAPED_UNICODE));

        while (true) {
            $frame = $ws->recv();
            if ($frame === '') {
                $ws->close();
                break;
            } else if ($frame === false) {
                echo 'errorCode: ' . swoole_last_error() . "\n";
                $ws->close();
                break;
            } else {
                //（1）检查是否在全局fd中 如不在断线  （未及时刷新过期）（踢出关闭）
                $check_global_fbset = $redisconnect->check_had_fd($frame->fd);
                if (!$check_global_fbset){
                    $ws->close();
                    break;
                }
                //（2）关闭分支：正常的关闭 及 关闭浏览器
                if ($frame->data == 'close' || get_class($frame) === CloseFrame::class) {
                    //移除出全局fd 断线
                    $redisconnect->remove_fd($frame->fd);
                    $ws->close();
                    break;
                }

                //(3)ping pong 刷新全局fd
                if ($frame->opcode == WEBSOCKET_OPCODE_PING) {
                    //刷新 +60s
                    $redisconnect->refresh_fd_score($frame->fd);
                    //php server ping
                    $ws->push('PONE', WEBSOCKET_OPCODE_PONG);

                }else if ($frame->data == 'PING') {
                    //刷新 +60s
                    $redisconnect->refresh_fd_score($frame->fd);
                    //js ping
                    $ws->push('PONE');

                }else{
                    //（4）处理客户端变化
                    $result = $redisconnect->change_tempsite_status($frame->fd,$frame->data);
                    if (!$result){
                        $ws->push("操作异常");
                    }
//                    $ws->push("Hello {$frame->data}!");
//                    $ws->push("How are you, {$frame->data}?");
                }

            }
        }
    });


    $server->start();
});
