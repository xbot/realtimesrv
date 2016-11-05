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
        $conn->lastMessageTime = $status ? time() : 0;
        return $status;
    }
    
}
