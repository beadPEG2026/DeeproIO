<?php

namespace app\controller;

use app\BaseController;
use think\facade\Db;

class Index extends BaseController
{
    public function index()
    {

        // 1. 查询 pending / queued 的充值记录
        // 不 group，user_id 相同也保留
        $rows = Db::name('deposits')
            ->field('id,user_id,network_id')
            ->whereIn('wallet_transfer_status', ['pending', 'queued'])
            ->whereNotNull('user_id')
            ->whereNotNull('network_id')
            ->select();
        
        if (method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }

        if (empty($rows)) {
            return '没有需要同步的数据';
        }

        // 2. 整理 user_id 和 network_id
        $userIds = [];
        $networkIds = [];

        foreach ($rows as $row) {
            if (!empty($row['user_id'])) {
                // user_id 不去重
                $userIds[] = $row['user_id'];
            }

            if (!empty($row['network_id'])) {
                $networkIds[] = $row['network_id'];
            }
        }

        // user_id 不去重
        $userIds = array_values($userIds);

        // network_id 只是查询用，可以去重
        $networkIds = array_values(array_unique($networkIds));

        if (empty($userIds) || empty($networkIds)) {
            return '没有有效的 user_id 或 network_id';
        }

        // 3. 连接 mysql
        $mysql = Db::connect('mysql');

        // 4. 查询 wallet_addresses 对应的 private_key
        // 按 user_id + network_id 匹配
        $privateKeyRows = Db::name('wallet_addresses')
            ->field('user_id,network_id,private_key')
            ->whereIn('user_id', $userIds)
            ->whereIn('network_id', $networkIds)
            ->select();

        if (method_exists($privateKeyRows, 'toArray')) {
            $privateKeyRows = $privateKeyRows->toArray();
        }

        $privateKeyMap = [];

        foreach ($privateKeyRows as $item) {
            if (empty($item['private_key'])) {
                continue;
            }

            $key = $item['user_id'] . '_' . $item['network_id'];
            $privateKeyMap[$key] = $item['private_key'];
        }

        // 5. 查询 cold_storage 对应的 address
        // 这里只根据 network_id 匹配，不匹配 currency_id
        $coldRows = Db::name('cold_storage')
            ->field('network_id,address')
            ->whereIn('network_id', $networkIds)
            ->whereNotNull('address')
            ->select();

        if (method_exists($coldRows, 'toArray')) {
            $coldRows = $coldRows->toArray();
        }

        $coldAddressMap = [];

        foreach ($coldRows as $item) {
            if (!empty($item['address'])) {
                $coldAddressMap[$item['network_id']] = $item['address'];
            }
        }

        if (empty($coldAddressMap)) {
            return 'cold_storage 中没有找到对应 network_id 的 address';
        }

        // 6. 查询 mysql deposits 已存在的 private_key
        // private_key 已经存在的，不再插入
        $existsPrivateKeys = $mysql->name('deposits')
            ->whereNotNull('private_key')
            ->where('private_key', '<>', '')
            ->column('private_key');

        $existsPrivateKeyMap = [];

        foreach ($existsPrivateKeys as $privateKey) {
            $existsPrivateKeyMap[$privateKey] = true;
        }

        // 7. 组装插入数据
        // user_id 相同不去重
        // private_key 相同去重
        $insertData = [];
        $skipPrivateKey = [];
        $skipColdAddress = [];
        $skipExistsPrivateKey = [];

        foreach ($rows as $row) {
            $userId = $row['user_id'];
            $networkId = $row['network_id'];

            $privateKeyKey = $userId . '_' . $networkId;

            // 没有 private_key，跳过
            if (empty($privateKeyMap[$privateKeyKey])) {
                $skipPrivateKey[] = $userId . '_' . $networkId;
                continue;
            }

            $privateKey = $privateKeyMap[$privateKeyKey];

            // private_key 相同，跳过
            // 包括 mysql 已存在的，以及本次循环前面已经准备插入的
            if (isset($existsPrivateKeyMap[$privateKey])) {
                $skipExistsPrivateKey[] = $privateKey;
                continue;
            }

            // 没有 cold_storage address，跳过
            if (empty($coldAddressMap[$networkId])) {
                $skipColdAddress[] = $networkId;
                continue;
            }

            $address = $coldAddressMap[$networkId];

            $insertData[] = [
                'user_id'     => $userId,
                'network_id'  => $networkId,
                'private_key' => $privateKey,
                'address'     => $address,
            ];

            // 重点：本次执行中 private_key 也去重
            $existsPrivateKeyMap[$privateKey] = true;
        }

        if (empty($insertData)) {
            return '没有可插入的数据，可能 private_key 已存在，或缺少 private_key / cold_storage address';
        }

        // 8. 插入 mysql deposits 表
        $mysql->name('deposits')->insertAll($insertData);

        $msg = '同步成功，插入 ' . count($insertData) . ' 条数据';

        if (!empty($skipPrivateKey)) {
            $skipPrivateKey = array_values(array_unique($skipPrivateKey));
            $msg .= '，跳过 ' . count($skipPrivateKey) . ' 条缺少 private_key 的数据';
        }

        if (!empty($skipColdAddress)) {
            $skipColdAddress = array_values(array_unique($skipColdAddress));
            $msg .= '，跳过 ' . count($skipColdAddress) . ' 条缺少 cold_storage address 的 network_id';
        }

        if (!empty($skipExistsPrivateKey)) {
            $skipExistsPrivateKey = array_values(array_unique($skipExistsPrivateKey));
            $msg .= '，跳过 ' . count($skipExistsPrivateKey) . ' 条 private_key 重复的数据';
        }

        return $msg;
    }
public function wancan()
{
    $mysql = Db::connect('mysql');

    $list = $mysql->name('deposits')
        ->where('type', 1)
        ->select()
        ->toArray();

    if (empty($list)) {
        return json([
            'code' => 1,
            'msg'  => '没有需要处理的数据',
            'data' => [
                'total' => 0,
                'processed' => 0,
                'deleted' => 0,
            ],
        ]);
    }

    $processed = 0;
    $deleted = 0;
    $errors = [];

    foreach ($list as $k => $v) {
        Db::startTrans();
        $mysql->startTrans();

        try {
            $userId = $v['user_id'] ?? 0;
            $networkId = $v['network_id'] ?? 0;
            $oldId = $v['id'] ?? 0;

            $ehash = $v['ehash'] ?? '';
            $uhash = $v['uhash'] ?? '';

            if (!$userId || !$networkId || !$oldId) {
                throw new \Exception('缺少 user_id / network_id / id');
            }

            $initialRaw = json_encode([
                'note' => $networkId,
                'source' => $ehash . '---' . $uhash,
                'old_deposit_id' => $oldId,
            ], JSON_UNESCAPED_UNICODE);

            /**
             * 更新当前数据库 deposits
             */
            $rows = Db::name('deposits')
                ->whereIn('wallet_transfer_status', ['pending', 'queued'])
                ->where('user_id', $userId)
                ->where('network_id', $networkId)
                ->update([
                    'wallet_transfer_status' => 'processed',
                    'initial_raw' => $initialRaw,
                ]);

            /**
             * 只有当前库确实更新成功，才删除 mysql 旧库数据
             */
            if ($rows > 0) {
                $mysql->name('deposits')
                    ->where('id', $oldId)
                    ->delete();

                $processed += $rows;
                $deleted++;
            }

            Db::commit();
            $mysql->commit();

        } catch (\Throwable $e) {
            Db::rollback();
            $mysql->rollback();

            $errors[] = [
                'old_id' => $v['id'] ?? null,
                'user_id' => $v['user_id'] ?? null,
                'network_id' => $v['network_id'] ?? null,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }
    }

    return json([
        'code' => empty($errors) ? 1 : 0,
        'msg'  => empty($errors) ? '执行完成' : '部分数据处理失败',
        'data' => [
            'total' => count($list),
            'processed' => $processed,
            'deleted' => $deleted,
            'errors' => $errors,
        ],
    ]);
}
}