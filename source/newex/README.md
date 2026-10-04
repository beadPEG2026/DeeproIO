# Deepro 应用

Laravel 11 与 Vue 2 应用，包含用户端、管理端、现货交易、股票行情、钱包、UMI 及相关服务。

## 环境

- PHP 8.2 或更高版本（建议 8.3）、Composer 2。
- PostgreSQL、Redis；PHP 启用 PDO PostgreSQL、BCMath、cURL、Mbstring、OpenSSL、XML、Fileinfo。
- Node.js 与 npm，用于构建 Vue 资源与运行 Node 服务。

## 配置与依赖

在本目录执行：

```sh
cp .env.example .env
mkdir -p bootstrap/cache storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
composer install
php artisan key:generate
npm ci
```

先配置独立数据库和 Redis，再检查迁移状态：

```sh
php artisan migrate:status
php artisan migrate
php artisan storage:link
```

生产数据库升级前须备份并审核迁移；不要对包含业务数据的数据库执行 `migrate:fresh` 或测试命令。部署还需根据实际产品配置交易对、权限、消息服务、托管及市场数据。没有附带生产数据或预置管理员密码。

## 构建

```sh
npm run user
npm run production
npm run auth
node build-ui-styles.cjs
```

前两条分别构建用户端和管理端，用户端构建会自动生成页面预加载清单。保留的 `public/frontend`、`public/alternative` 是当前可引用的资源集合；修改源码后须重新构建并同步资源清单。`public/downloads` 包含当前 Android 下载文件及校验信息，移动端源码修改后需重新签名构建。

## 运行

Web 服务入口为 `public/index.php`，生产环境使用 Nginx/Apache 配合 PHP-FPM。开发环境可执行 `php artisan serve`。

队列使用 `php artisan horizon`；定时任务配置 Laravel scheduler。广播服务参数见 `config/broadcasting.php`，需要独立配置服务端和公开访问地址。链服务、图表服务按各自依赖清单启动；仅配置实际需要运行的服务。

## 测试

测试代码位于 `tests`。在独立环境配置 `APP_ENV=testing`  和名为 `deepro_test` 的专用测试数据库，确认连接与正式数据库隔离后运行 `vendor/bin/phpunit`。运行时数据和账户初始化独立于源码交付。
