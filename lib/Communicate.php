<?php
namespace lib;

/**
 * Class Communicate
 * @author Donie
 */
class Communicate
{
    /**
     * 发送消息给指定连接
     *
     * @param  object $conn WebSocket连接
     * @param  mixed  $msg  消息
     * @return bool   true表示成功，false表示失败
     */
    public static function send($conn, $msg)
    {
        $status = $conn->send(json_encode($msg));
        if ($status) $conn->lastMessageTime = time();
        if (MN_DEBUG)
            error_log("DEBUG: 发送画布 ".(!empty($msg['data']['workId']) ? $msg['data']['workId'] : '?')
            ."@".(!empty($msg['type']) ? $msg['type'] : '?')."@{$msg['__debugInfo']['timestamp']} 的请求到连接{$conn->id}"
            .($status ? '成功' : '失败'));
        return $status;
    }
    
}
