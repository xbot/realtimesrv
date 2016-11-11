<?php
error_reporting(E_ALL);
ini_set('error_log', 'errors.log');

require_once './config.php';
require_once './vendor/autoload.php';
require_once './lib/SessionRegistry.php';
require_once './lib/Communicate.php';

use Workerman\Worker;
use Workerman\Lib\Timer;
use lib\SessionRegistry as SessReg;
use lib\Communicate     as Comm;

!defined('MN_BUS_WORK')                and define('MN_BUS_WORK',                'work_bus');
!defined('MN_MSG_WORK_WATCH')          and define('MN_MSG_WORK_WATCH',          'watch_work');
!defined('MN_MSG_WORK_UPDATED')        and define('MN_MSG_WORK_UPDATED',        'work_updated');
!defined('MN_MSG_HANDOVER_POSSESSION') and define('MN_MSG_HANDOVER_POSSESSION', 'handover_possession');
!defined('MN_MSG_CONN_CLOSED')         and define('MN_MSG_CONN_CLOSED',         'connection_closed');

$channelServer = new Channel\Server('127.0.0.1', MN_CHANNEL_PORT);
$worker        = new Worker('websocket://0.0.0.0:'.MN_PORT);

$worker->onWorkerStart = function($worker) {
    // 清空Redis里的会话记录
    try {
        SessReg::clear();
    } catch (RedisException $e) {
        SessReg::resetInstance();
        error_log("无法清空会话：".$e->getMessage());
    }

    Channel\Client::connect('127.0.0.1', MN_CHANNEL_PORT);

    // 画布消息总线
    Channel\Client::on(MN_BUS_WORK, function($event) use ($worker) {
        if (empty($event['data']['fromConn']) || empty($event['data']['workId'])) {
            error_log('画布总线收到的事件数据格式错误：'.var_export($event, true));
            return;
        }

        $fromConn = $event['data']['fromConn'];
        unset($event['data']['fromConn']);

        $connIds = array();
        try {
            $connIds = SessReg::getByWork($event['data']['workId']);
        } catch (RedisException $e) {
            SessReg::resetInstance();
            error_log('画布总线错误：'.$e->getMessage());
        }
        foreach ($connIds as $connId) {
            if ($connId != $fromConn && isset($worker->connections[$connId]))
                Comm::send($worker->connections[$connId], $event);
        }

        if (isset($worker->connections[$fromConn])) {
            $event['success'] = true;
            Comm::send($worker->connections[$fromConn], $event);
        }
    });

    // 心跳
    Timer::add(MN_HEARTBEAT_INTERVAL, function() use ($worker) {
        $timeNow = time();
        foreach($worker->connections as $connection) {
            // 有可能该connection还没收到过消息，则lastMessageTime设置为当前时间
            if (empty($connection->lastMessageTime)) {
                $connection->lastMessageTime = $timeNow;
                continue;
            }
            // 上次通讯时间间隔大于心跳间隔，则认为客户端已经下线，关闭连接
            if ($timeNow - $connection->lastMessageTime > MN_HEARTBEAT_THRESHOLD) {
                $connection->close();
            }
        }
    });
};

$worker->onClose = function($connection) use ($worker) {
    // 从会话中删除和本连接相关的数据
    $msg = array('type' => MN_MSG_CONN_CLOSED, 'data' => array(),);
    try {
        $userObj = SessReg::getUser($connection->id);
        if (!empty($userObj->phone)) $msg['data']['phone'] = $userObj->phone;

        $workIds = SessReg::getByConn($connection->id);
        foreach ($workIds as $workId) {
            $connIds = SessReg::deleteFromWork($workId, $connection->id);
            if (empty($connIds))
                SessReg::deleteByWork($workId);
            else {
                // 把连接对应的用户数据发送给关注同一画布的其它连接
                foreach ($connIds as $connId) {
                    if (!empty($worker->connections[$connId]))
                        Comm::send($worker->connections[$connId], $msg);
                }
            }
        }
        SessReg::deleteByConn($connection->id);
    } catch (RedisException $e) {
        SessReg::resetInstance();
        error_log('无法删除会话中本连接的数据：'.$e->getMessage());
    }
};

$worker->onMessage = function($connection, $data) {
    $connection->lastMessageTime = time();
    $msg = json_decode($data, true);
    if (!empty($msg['type'])) {
        if (empty($msg['data']['token'])) {
            error_log('接收数据缺少token：'.var_export($data, true));
            $msg['success'] = false;
            $msg['message'] = '缺少token';
            Comm::send($connection, $msg);
            return;
        }
        if (empty($msg['data']['workId'])) {
            error_log('接收数据缺少画布ID：'.var_export($data, true));
            $msg['success'] = false;
            $msg['message'] = '缺少画布ID';
            Comm::send($connection, $msg);
            return;
        }
        // 通过TOKEN取用户信息
        $response = \Httpful\Request::get(MN_DOMAIN.'/api/user')
            ->addHeader('authorization', $msg['data']['token'])
            ->send();
        if (empty($response->body->user)) {
            error_log('Token验证失败：'.json_encode($response->body));
            $msg['success'] = false;
            $msg['message'] = 'Token验证失败';
            Comm::send($connection, $msg);
            return;
        }
        $userObj = $response->body->user;

        // 建立画布、连接和用户的关联关系
        try {
            SessReg::newEntry($msg['data']['workId'], $connection->id, $userObj);
        } catch (RedisException $e) {
            SessReg::resetInstance();
            error_log('建立画布和连接的关联关系失败：'.$e->getMessage());
            $msg['success'] = false;
            $msg['message'] = '操作Redis失败';
            Comm::send($connection, $msg);
            return;
        }

        switch ($msg['type']) {
            case MN_MSG_WORK_WATCH:
                // 关注画布

                if (empty($msg['data']['workData'])) {
                    error_log('接收数据缺少画布数据：'.var_export($data, true));
                    $msg['success'] = false;
                    $msg['message'] = '缺少画布数据';
                    Comm::send($connection, $msg);
                    return;
                }

                $msg['data']['fromConn'] = $connection->id;
                Channel\Client::publish(MN_BUS_WORK, $msg);
                break;
            case MN_MSG_WORK_UPDATED:
                // 画布更新

                if (empty($msg['data']['workData'])) {
                    error_log('接收数据缺少画布数据：'.var_export($data, true));
                    $msg['success'] = false;
                    $msg['message'] = '缺少画布数据';
                    Comm::send($connection, $msg);
                    return;
                }

                // 同步更新到数据库
                $workData = $msg['data']['workData'];
                if (!is_string($workData)) $workData = json_encode($workData);
                $response = \Httpful\Request::post(MN_DOMAIN."/api/work/{$msg['data']['workId']}")
                    ->sendsJson()
                    ->addHeader('authorization', $msg['data']['token'])
                    ->body($workData)
                    ->send();
                // 更新数据库失败时不再分发
                if ($response->code != 200) {
                    error_log("更新画布到数据库失败：{$response->code}");
                    $msg['success'] = false;
                    $msg['message'] = '画布保存失败';
                    Comm::send($connection, $msg);
                    return;
                }

                $msg['data']['fromConn'] = $connection->id;
                Channel\Client::publish(MN_BUS_WORK, $msg);
                break;
            case MN_MSG_HANDOVER_POSSESSION:
                // 转交画布修改权

                if (empty($msg['data']['phone'])) {
                    error_log('接收数据缺少用户phone：'.var_export($data, true));
                    $msg['success'] = false;
                    $msg['message'] = '缺少用户电话';
                    Comm::send($connection, $msg);
                    return;
                }

                $msg['data']['fromConn'] = $connection->id;
                Channel\Client::publish(MN_BUS_WORK, $msg);
                break;
            default:
                // 通用画布消息分发接口
                $msg['data']['fromConn'] = $connection->id;
                Channel\Client::publish(MN_BUS_WORK, $msg);
                break;
        }
    } else {
        error_log('接收数据格式不正确：'.var_export($data, true));
        Comm::send($connection, json_encode(array('success' => false, 'message' => '未知的请求类型')));
    }
};

Worker::runAll();
