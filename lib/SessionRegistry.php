<?php
namespace lib;

/**
 * Class SessionRegistry
 * @author Donie
 */
class SessionRegistry
{
    private static $_instance;

    private $_redis;

    private function __construct() {
        $this->_redis = new \Redis();
        $this->_redis->connect(MN_REDIS_IP, MN_REDIS_PORT);
    }
    private function __clone() {}
    
    public static function getInstance()
    {
        if (!self::$_instance) {
            self::$_instance = new self;
        }
        return self::$_instance;
    }

    public function keys($pattern='*')
    {
        return $this->_redis->keys($pattern);
    }
    public function del($key)
    {
        $this->_redis->del($key);
    }
    public function sadd($key, $value)
    {
        $this->_redis->sadd($key, $value);
    }
    public function srem($key, $value)
    {
        $this->_redis->srem($key, $value);
    }
    public function smembers($key)
    {
        return $this->_redis->smembers($key);
    }
    public function set($key, $value)
    {
        $this->_redis->set($key, $value);
    }
    public function get($key)
    {
        return $this->_redis->get($key);
    }
    
    /**
     * 清空Redis中存储的本类数据
     *
     * @return void
     */
    public static function clear()
    {
        $keys = self::getInstance()->keys();
        foreach ($keys as $key) {
            if (strpos($key, 'worksession_') === 0)
                self::getInstance()->del($key);
        }
    }
    
    /**
     * 建立画布和WS连接的映射关系
     *
     * @param int    $workId  画布ID
     * @param int    $connId  WS连接ID
     * @param object $userObj 用户数据对象
     * @return void
     */
    public static function newEntry($workId, $connId, $userObj)
    {
        // 画布和连接的对应关系
        $key = "worksession_conns_{$workId}";
        self::getInstance()->sadd($key, $connId);
        // 连接和画布的对应关系
        $key = "worksession_works_{$connId}";
        self::getInstance()->sadd($key, $workId);
        // 连接和用户的对应关系
        $key = "worksession_user_{$connId}";
        self::getInstance()->set($key, json_encode($userObj));
    }

    /**
     * 通过连接ID取用户信息
     *
     * @param  int $connId WebSocket连接ID
     * @return object
     */
    public static function getUser($connId)
    {
        $key = "worksession_user_{$connId}";
        return json_decode(self::getInstance()->get($key));
    }
    
    /**
     * 通过画布ID取会话数据
     *
     * @param  int $workId 画布ID
     * @return array
     */
    public static function getByWork($workId)
    {
        $key = "worksession_conns_{$workId}";
        return self::getInstance()->smembers($key, 0, -1);
    }
    
    /**
     * 通过连接ID取画布ID数组
     *
     * @param  int $connId WS连接ID
     * @return array
     */
    public static function getByConn($connId)
    {
        $key = "worksession_works_{$connId}";
        return self::getInstance()->smembers($key, 0, -1);
    }

    /**
     * 从画布的连接数组中删除指定的连接ID，并返回剩余的连接ID数组
     *
     * @param  int $workId 画布ID
     * @param  int $connId WS连接ID
     * @return array
     */
    public static function deleteFromWork($workId, $connId)
    {
        $key = "worksession_conns_{$workId}";
        self::getInstance()->srem($key, $connId);
        return self::getByWork($workId);
    }
    
    /**
     * 删除画布对应的连接ID数组
     *
     * @param  int $workId 画布ID
     * @return void
     */
    public static function deleteByWork($workId)
    {
        $key = "worksession_conns_{$workId}";
        self::getInstance()->del($key);
    }

    /**
     * 删除连接对应的画布数组
     *
     * @param  int $connId WS连接ID
     * @return void
     */
    public static function deleteByConn($connId)
    {
        $key = "worksession_works_{$connId}";
        self::getInstance()->del($key);
        $key = "worksession_user_{$connId}";
        self::getInstance()->del($key);
    }
}
