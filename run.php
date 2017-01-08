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

$worker = new Worker('websocket://0.0.0.0:'.MN_PORT);
$worker->count = MN_WORKER_NUM;

$worker->onWorkerStart = function($worker) {
    // 清空Redis里的会话记录
    try {
        SessReg::clear();
    } catch (RedisException $e) {
        SessReg::resetInstance();
        error_log("ERROR: 无法清空会话：".$e->getMessage());
    }

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
                error_log("ERROR: 连接{$connection->id}最近消息时间戳是{$connection->lastMessageTime}，超过".MN_HEARTBEAT_THRESHOLD."，在{$timeNow}断开");
                $connection->close();
            }
        }
    });
};

$worker->onClose = function($connection) use ($worker) {
    // 从会话中删除和本连接相关的数据
    $msg = array('type' => MN_MSG_CONN_CLOSED, 'data' => array(),);
    $msg['__debugInfo']['timestamp'] = microtime(true);
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
        error_log('ERROR: 无法删除会话中本连接的数据：'.$e->getMessage());
    }
};

$worker->onMessage = function($connection, $data) use ($worker) {
    $connection->lastMessageTime = time();
    $msg = json_decode($data, true);
    if (!empty($msg['type'])) {
        $msg['__debugInfo']['timestamp'] = microtime(true);

        if (MN_DEBUG) {
            error_log("DEBUG: 收到画布 ".(!empty($msg['data']['workId']) ? $msg['data']['workId'] : '?')."@".(!empty($msg['type']) ? $msg['type'] : '?')."@{$msg['__debugInfo']['timestamp']} 的请求");
        }

        if (empty($msg['data']['token'])) {
            error_log('ERROR: 接收数据缺少token：'.var_export($data, true));
            $msg['success'] = false;
            $msg['message'] = '缺少token';
            Comm::send($connection, $msg);
            return;
        }
        if (empty($msg['data']['workId'])) {
            error_log('ERROR: 接收数据缺少画布ID：'.var_export($data, true));
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
            error_log('ERROR: Token验证失败：'.json_encode($response->body));
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
            error_log('ERROR: 建立画布和连接的关联关系失败：'.$e->getMessage());
            $msg['success'] = false;
            $msg['message'] = '操作Redis失败';
            Comm::send($connection, $msg);
            return;
        }

        switch ($msg['type']) {
            case MN_MSG_WORK_WATCH:
                // 关注画布
                break;
            case MN_MSG_WORK_UPDATED:
                // 画布更新

                if (empty($msg['data']['workData'])) {
                    error_log('ERROR: 接收数据缺少画布数据：'.var_export($data, true));
                    $msg['success'] = false;
                    $msg['message'] = '缺少画布数据';
                    Comm::send($connection, $msg);
                    return;
                }

                // 缓存画布数据
                $workData = is_string($msg['data']['workData']) ? $msg['data']['workData'] : json_encode($msg['data']['workData']);
                $status = SessReg::saveWorkCache($msg['data']['workId'], $workData, $msg['data']['token'], $msg['__debugInfo']['timestamp']);
                if (!$status) error_log("ERROR: 缓存画布{$msg['data']['workId']}数据失败");
                else {
                    if (MN_DEBUG)
                        error_log("DEBUG: 画布 {$msg['data']['workId']}@{$msg['type']}@{$msg['__debugInfo']['timestamp']} 已缓存");
                }

                // 设置保存画布缓存到数据库的定时器
                SessReg::registerUpdateTimer($msg['data']['workId']);
                break;
            case MN_MSG_HANDOVER_POSSESSION:
                // 转交画布修改权
                /*
                 * // 提交画布缓存到数据库
                 * SessReg::submitWorkCache($msg['data']['workId']);
                 */
                break;
            default:
                // 通用画布消息分发接口
                break;
        }

        // 分发消息给其它连接
        $connIds = array();
        try {
            $connIds = SessReg::getByWork($msg['data']['workId']);
        } catch (RedisException $e) {
            SessReg::resetInstance();
            error_log('ERROR: 画布总线错误：'.$e->getMessage());
        }
        foreach ($connIds as $connId) {
            if ($connId != $connection->id && isset($worker->connections[$connId]))
                Comm::send($worker->connections[$connId], $msg);
        }

        // 返回消息状态
        $msg['success'] = true;
        Comm::send($connection, $msg);
    } else {
        error_log('ERROR: 接收数据格式不正确：'.var_export($data, true));
        Comm::send($connection, json_encode(array('success' => false, 'message' => '未知的请求类型')));
    }
};

Worker::runAll();
