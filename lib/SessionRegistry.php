<?php
namespace lib;

use Workerman\Lib\Timer;

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
        $this->_redis->pconnect(MN_REDIS_IP, MN_REDIS_PORT);
    }
    private function __clone() {}
    
    public static function getInstance()
    {
        if (!self::$_instance) {
            self::$_instance = new self;
        }
        return self::$_instance;
    }

    public static function resetInstance()
    {
        self::$_instance = null;
    }

    public function __call($method, $params)
    {
        if (method_exists($this->_redis, $method)) {
            return call_user_func_array(array($this->_redis, $method), $params);
        } else {
            error_log("Redis方法不存在：{$method}\n");
            return null;
        }
    }

    /**
     * 清空Redis中存储的本类数据
     *
     * @return void
     */
    public static function clear()
    {
        $keys = self::getInstance()->keys('*');
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
        // 如果画布缓存存在，设置缓存未过期
        if (!empty(self::getWorkCache($workId))) self::renewWorkCache($workId);
        // 画布和定时器的对应关系
        $key = "worksession_timer_{$workId}";
        $timerId = self::getInstance()->get($key);
        if (!$timerId) {
            $timerId = Timer::add(MN_WORK_UPDATE_INTERVAL, function() use (&$timerId, &$workId) {
                $cacheData = self::getWorkCache($workId);
                if (!empty($cacheData['workData']) && !empty($cacheData['token'])) {
                    $response = \Httpful\Request::post(MN_DOMAIN."/api/work/{$workId}")
                        ->sendsJson()
                        ->addHeader('authorization', $cacheData['token'])
                        ->body($cacheData['workData'])
                        ->send();
                    // 更新数据库失败时不再分发
                    if ($response->code != 200)
                        error_log("更新画布到数据库失败：{$response->code}");
                }
                // 若缓存已被置为过期，删除缓存数据并销毁定时器
                if (!empty($cacheData['obsolete'])) {
                    Timer::del($timerId);
                    $key = "worksession_timer_{$workId}";
                    self::getInstance()->del($key);
                    $key = "worksession_workcache_{$workId}";
                    self::getInstance()->del($key);
                }
            });
            self::getInstance()->set($key, $timerId);
        }
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
        $tmp = self::getInstance()->smembers($key);
        return is_array($tmp) ? $tmp : array();
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
        $tmp = self::getInstance()->smembers($key);
        return is_array($tmp) ? $tmp : array();
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
     * 删除画布对应的连接ID数组、缓存的画布数据和定时器
     *
     * @param  int $workId 画布ID
     * @return void
     */
    public static function deleteByWork($workId)
    {
        $key = "worksession_conns_{$workId}";
        self::getInstance()->del($key);
        // 标记画布缓存已失效，在下一次定时器执行后启动自毁逻辑
        self::obsoleteWorkCache($workId);
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

    /**
     * 缓存画布数据
     *
     * @param  string $workId   画布ID
     * @param  string $workData 画布数据
     * @param  string $token    口令
     * @return void
     */
    public static function saveWorkCache($workId, $workData, $token)
    {
        $key = "worksession_workcache_{$workId}";
        $status1 = self::getInstance()->hset($key, 'workData', $workData);
        $status2 = self::getInstance()->hset($key, 'token', $token);
        return $status1 !== false && $status2 !== false;
    }
    
    /**
     * 取缓存的画布数据
     *
     * @param  string $workId 画布ID
     * @return array
     */
    public static function getWorkCache($workId)
    {
        $key = "worksession_workcache_{$workId}";
        return self::getInstance()->hgetall($key);
    }
    
    /**
     * 删除所有画布数据时标记画布缓存即将过期，当下一次计时器执行后自行销毁
     *
     * @param  string $workId 画布ID
     * @return bool
     */
    public static function obsoleteWorkCache($workId)
    {
        $key = "worksession_workcache_{$workId}";
        $status = self::getInstance()->hset($key, 'obsolete', 1);
        return $status !== false;
    }
    
    /**
     * 创建会话时设置画布缓存未过期
     *
     * @param  string $workId 画布ID
     * @return bool
     */
    public static function renewWorkCache($workId)
    {
        $key = "worksession_workcache_{$workId}";
        $status = self::getInstance()->hset($key, 'obsolete', 0);
        return $status !== false;
    }
}
