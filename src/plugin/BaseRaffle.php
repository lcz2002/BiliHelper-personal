<?php


/**
 *  Website: https://mudew.com/
 *  Author: Lkeme
 *  License: The MIT License
 *  Email: Useri@live.cn
 *  Updated: 2020 ~ 2021
 */

namespace BiliHelper\Plugin;

use BiliHelper\Core\Log;
use BiliHelper\Core\Curl;

abstract class BaseRaffle
{
    const ACTIVE_TITLE = '';
    const ACTIVE_SWITCH = '';

    protected static $wait_list;
    protected static $finish_list;
    protected static $all_list;
    protected static $room_stats = ['room_id' => 0, 'status' => false];

    public static function run()
    {
        if (getenv(static::ACTIVE_SWITCH) == 'false') {
            return;
        }
        if (static::getLock() > time()) {
            return;
        }
        static::startLottery();
    }

    /**
     * @use 抽奖逻辑
     * @return bool
     */
    protected static function startLottery(): bool
    {
        if (count(static::$wait_list) == 0) {
            return false;
        }
        $raffle_list = [];
        $room_list = [];
        static::$wait_list = static::arrKeySort(static::$wait_list, 'wait');
        $max_num = count(static::$wait_list);
        for ($i = 0; $i <= $max_num; $i++) {
            $raffle = array_shift(static::$wait_list);
            if (is_null($raffle)) {
                break;
            }
            if ($raffle['wait'] > time()) {
                array_push(static::$wait_list, $raffle);
                break;
            }
            if (count($raffle_list) > 200) {
                break;
            }
            array_push($room_list, $raffle['room_id']);
            array_push($raffle_list, $raffle);
            Statistics::addJoinList(static::ACTIVE_TITLE);
        }
        if (count($raffle_list) && count($room_list)) {
            $room_list = array_unique($room_list);
            foreach ($room_list as $room_id) {
                Live::goToRoom($room_id);
            }
            $results = static::createLottery($raffle_list);
            static::parseLottery($results);
        }
        return true;
    }

    /**
     * @use 返回抽奖数据
     * @param int $room_id
     * @return array
     */
    protected static function check(int $room_id): array
    {
        $payload = [
            'roomid' => $room_id
        ];
        $url = 'https://api.live.bilibili.com/xlive/lottery-interface/v1/lottery/getLotteryInfo';
        $raw = Curl::get($url, Sign::api($payload));
        $de_raw = json_decode($raw, true);
        if (!isset($de_raw['data']) || $de_raw['code']) {
            Log::error("获取抽奖数据错误，{$de_raw['message']}");
            return [];
        }
        return $de_raw;
    }


    /**
     * @use 解析抽奖信息
     * @param int $room_id
     * @param array $data
     * @return bool
     */
    abstract protected static function parseLotteryInfo(int $room_id, array $data): bool;


    /**
     * @use 创建抽奖
     * @param array $raffles
     * @return array
     */
    abstract protected static function createLottery(array $raffles): array;


    /**
     * @use 解析抽奖返回
     * @param array $results
     * @return mixed
     */
    abstract protected static function parseLottery(array $results);

    /**
     * @use 二维数组按key排序
     * @param $arr
     * @param $key
     * @param string $type
     * @return array
     */
    protected static function arrKeySort($arr, $key, $type = 'asc')
    {
        switch ($type) {
            case 'desc':
                array_multisort(array_column($arr, $key), SORT_DESC, $arr);
                return $arr;
            case 'asc':
                array_multisort(array_column($arr, $key), SORT_ASC, $arr);
                return $arr;
            default:
                return $arr;
        }
    }


    /**
     * @use 去重检测
     * @param $lid
     * @param bool $filter
     * @return bool
     */
    protected static function toRepeatLid($lid, $filter = true): bool
    {
        $lid = (int)$lid;
        if (in_array($lid, static::$all_list)) {
            return true;
        }
        if (count(static::$all_list) > 1000) {
            static::$all_list = [];
        }
        if ($filter) {
            array_push(static::$all_list, $lid);
        }
        return false;
    }

    /**
     * @use 数据推入队列
     * @param array $data
     * @return bool
     */
    public static function pushToQueue(array $data): bool
    {
        // 开关
        if (getenv(static::ACTIVE_SWITCH) == 'false') {
            return false;
        }
        // 去重
        if (static::toRepeatLid($data['lid'], false)) {
            return false;
        }
        // 钓鱼&&防止重复请求
        if ($data['rid'] != static::$room_stats['room_id']) {
            static::$room_stats = ['room_id' => $data['rid'], 'status' => Live::fishingDetection($data['rid'])];
        }
        if (static::$room_stats['status']) {
            return false;
        }
        // 实际检测
        $raffles_info = static::check($data['rid']);
        if (!empty($raffles_info)) {
            static::parseLotteryInfo($data['rid'], $raffles_info);
        }
        $wait_num = count(static::$wait_list);
        if ($wait_num > 10 && ($wait_num % 2)) {
            Log::info("当前队列中共有 {$wait_num} 个" . static::ACTIVE_TITLE . "待抽奖");
        }
        return true;
    }

}


