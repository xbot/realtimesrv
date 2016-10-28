<?php
/**
 * 如果本程序和主站在同一台主机，注意配置服务器HOSTS，映射域名MN_DOMAIN到本机
 */

// 监听端口
!defined('MN_PORT') and define('MN_PORT', 4759);
// Channel监听端口
!defined('MN_CHANNEL_PORT') and define('MN_CHANNEL_PORT', 7759);
// Redis IP
!defined('MN_REDIS_IP') and define('MN_REDIS_IP', '127.0.0.1');
// Redis 端口
!defined('MN_REDIS_PORT') and define('MN_REDIS_PORT', 6379);
// 主站的域名
!defined('MN_DOMAIN') and define('MN_DOMAIN', 'www.maoniuyun.com');
// 心跳间隔，每n秒检查一次
!defined('MN_HEARTBEAT_INTERVAL') and define('MN_HEARTBEAT_INTERVAL', 60);
// 心跳阈值，超过n秒无通讯的连接将被关闭
!defined('MN_HEARTBEAT_THRESHOLD') and define('MN_HEARTBEAT_THRESHOLD', 7200);
