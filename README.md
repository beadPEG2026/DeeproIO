# DeeproIO

最新完整源码：**2026-10-14 加密交付包**。

- [下载加密源码与校验文件](https://github.com/beadPEG2026/DeeproIO/releases/tag/encrypted-source-20261014-r1)（推荐 7z，约 46 MB；另提供 AES ZIP）
- 源码提交：`ad3e6cb8c180521758960318648d62008dfe4b7f`，包含 18,953 个版本化文件。
- 两种格式内容及密码相同，密码由项目负责人单独保管，不在公开库发布。
- 运行密钥、环境文件、用户数据及数据库备份不包含在包中。

仓库 main 内展开源码仍为 2026.10.05；最新版本以加密交付包及包内清单为准。

Deepro 数字资产交易平台源码，包含用户端、管理端、UMI 生态模块、行情服务、区块链服务和移动客户端。

## 目录

| 目录 | 内容 |
| --- | --- |
| `source/newex` | Laravel 主应用、Vue 用户端与管理端、UMI、迁移及自动化测试 |
| `source/newex/bridges` | 链节点接口及托管处理组件 |
| `source/newex/chart-udf` | 图表数据适配服务 |
| `source/*exchangeabcdefg.xyz` | 独立链服务源码 |
| `source/newex_vip` | 配套 PHP 服务源码 |
| `mobile/android` | Android WebView 客户端 |
| `mobile/ios` | iOS WKWebView 客户端 |

## 安装

主应用配置与构建见 [安装说明](source/newex/README.md)。链服务与移动客户端分别见 [链服务说明](source/newex/bridges/README.md) 和 [移动端说明](mobile/README.md)。依赖由各目录中的依赖清单和锁文件管理。

服务部署时需要自行配置数据库、Redis、域名、消息服务和链节点。运行密钥、账户数据、数据库备份及签名私钥不包含在源码中。不要将运行环境文件提交到仓库。

## 模块约定

交易所手续费返佣与 UMI 邀请奖励分别管理。UMI 充值使用现货账户划入；业务开放状态、托管渠道与运营权限通过后台和运行配置管理。源码中的功能入口不代表运行环境已完成配置。

## 第三方组件

保留各依赖的作者、版权和许可证声明。第三方图表、SDK、字体及素材的使用和分发须遵循相应授权；本仓库不额外授予第三方组件的许可。
