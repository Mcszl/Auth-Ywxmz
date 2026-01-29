<?php
/**
 * AWS SDK 环境变量设置
 * 此文件在加载 AWS SDK 之前设置环境变量
 * 用于避免 AWS SDK 读取配置文件时触发 open_basedir 限制
 */

// 方法1：设置超全局变量
$_ENV['AWS_CONFIG_FILE'] = '/dev/null';
$_ENV['AWS_SHARED_CREDENTIALS_FILE'] = '/dev/null';
$_SERVER['AWS_CONFIG_FILE'] = '/dev/null';
$_SERVER['AWS_SHARED_CREDENTIALS_FILE'] = '/dev/null';

// 方法2：使用 ini_set 设置变量（如果 putenv 被禁用）
// 注意：这个方法可能不适用于所有情况，但值得尝试
@ini_set('variables_order', 'EGPCS');

// 加载 AWS SDK
require_once __DIR__ . '/vendor/autoload.php';
