# TinyS3

TinyS3 是一个适合 cPanel、DirectAdmin 虚拟主机的私有对象存储服务。PHP 8.5 处理每次 HTTP 请求，文件保存在主机磁盘，MySQL 保存对象名称、大小、校验值和索引。安装时直接上传项目即可，无需 Composer、Node.js、常驻进程或定时任务。

## 安装到虚拟主机

1. 为对象存储准备一个启用 HTTPS 的独立域名，例如 `s3.example.com`。选择 64 位 PHP 8.5，启用 `pdo_mysql`、`fileinfo`、`SimpleXML`、`XMLReader` 和 `session`。使用支持 `.htaccess` 和 URL 重写的 Apache 2.4 或兼容服务器。纯 Nginx 主机需要能配置网站转发规则。
2. 在主机面板创建数据库和专用数据库用户。使用 MySQL 8 或 MariaDB 10.6 及以上版本，存储引擎使用 InnoDB。在 phpMyAdmin 中选中数据库并导入 `schema.sql`。安装后应用账号需要 `SELECT`、`INSERT`、`UPDATE`、`DELETE` 权限。
3. 将项目上传到账号的私有目录，例如 `/home/ACCOUNT/tinys3`。将这个域名的网站根目录设置为 `/home/ACCOUNT/tinys3/public`。确认文件管理器显示隐藏文件，上传时包含 `public/.htaccess`。
4. 在项目目录中，将 `config.example.php` 复制为 `config.php`。填写数据库连接字符串 `dsn`、用户名 `db_user` 和密码 `db_password`。数据库名和用户名通常包含主机账号前缀。创建 `data` 目录，确保运行 PHP 的账号可以写入。在 PHP 以主机账号身份运行时，`data` 可设为 `0700`，`config.php` 可设为 `0600`。
5. 在有 PHP 的电脑或主机终端执行 `php bin/credentials.php`。将输出的三项配置复制到 `config.php`，将第一行的管理密码保存到密码管理器。S3 客户端使用 `access_key` 和 `secret_key`。管理面板使用独立密码，配置文件只保存它的散列值。默认区域是 `us-east-1`。
6. 打开 `https://s3.example.com/admin`，使用管理密码登录。选择 **Create bucket** 创建存储桶，进入桶后选择 **Upload object** 上传文件。对象名称可以包含路径，例如 `photos/2026/snow.jpg`，面板会按目录显示。相同名称的上传会替换已有文件。

**网站根目录固定时**，将 `public/` 中的内容放入该域名的网站根目录，将其余文件和 `data/` 保存在网站目录之外。把网站入口 `index.php` 最后一行改为 `require '/home/ACCOUNT/tinys3/bootstrap.php';`，路径使用你的实际账号目录。DirectAdmin 中也可以使用这种布局。若主机启用了 `open_basedir`，需要允许 PHP 访问项目和数据目录。

网站根目录中只需放置 `index.php`、转发配置 `.htaccess` 和 `assets/` 中的静态资源。`config.php`、数据库结构和对象文件应始终位于网站根目录以外。服务会在启动时检查配置与数据目录的位置。

## 连接 S3 客户端

| 设置 | 值 |
| --- | --- |
| Endpoint | `https://s3.example.com` |
| Region | `config.php` 中的 `region`，默认 `us-east-1` |
| 身份验证 | AWS Signature Version 4 |
| 寻址方式 | Path style，例如 `/my-bucket/photos/snow.jpg` |
| 访问凭据 | `access_key` 和 `secret_key` |

**AWS CLI** 可以使用独立配置。运行 `aws configure --profile tinys3` 输入密钥和区域，再执行以下命令。`when_required` 使客户端使用主机可以直接接收的请求体，避开部分新版 SDK 默认使用的 AWS 流式校验尾部字段。

```sh
aws configure set s3.addressing_style path --profile tinys3
aws configure set request_checksum_calculation when_required --profile tinys3
export AWS_PROFILE=tinys3
export AWS_ENDPOINT_URL_S3=https://s3.example.com

aws s3 mb s3://my-bucket
aws s3 cp photo.jpg s3://my-bucket/photos/photo.jpg
aws s3 ls s3://my-bucket/photos/
aws s3 cp s3://my-bucket/photos/photo.jpg downloaded.jpg
aws s3 presign s3://my-bucket/photos/photo.jpg --expires-in 600
```

**Boto3** 可以复用同一个凭据配置。`upload_file` 会根据文件大小自动选择普通上传或分段上传。

```python
import boto3
from botocore.config import Config

s3 = boto3.Session(profile_name="tinys3").client(
    "s3",
    endpoint_url="https://s3.example.com",
    region_name="us-east-1",
    config=Config(
        signature_version="s3v4",
        s3={"addressing_style": "path"},
        request_checksum_calculation="when_required",
    ),
)
s3.upload_file("archive.zip", "my-bucket", "backups/archive.zip")
s3.download_file("my-bucket", "backups/archive.zip", "restored.zip")
```

## 支持的操作

| 范围 | 功能 |
| --- | --- |
| 存储桶 | 创建、列举、查询、查询区域、删除空桶 |
| 对象列表 | ListObjects V1/V2、前缀、分隔符、分页、URL 编码 |
| 对象 | 上传、覆盖、下载、查询元数据、复制、删除以及批量删除 |
| 下载 | 单段字节范围、ETag 和修改时间条件、预签名下载链接 |
| 上传 | 预签名 PUT、用户元数据、Content-Type、Content-MD5、请求体 SHA-256，普通 PUT 还支持 CRC32、CRC32C、SHA-1 和 SHA-256 校验请求头 |
| 分段上传 | 发起上传、上传分段、查看分段及未完成的上传、完成、取消；支持 MD5 和 CRC32 |
| 管理面板 | 登录与退出、容量与对象数量、最近更新、存储桶管理、按目录浏览和分页查看文件、上传、下载、删除 |

TinyS3 使用一组拥有全部读写权限的 S3 凭据。持有预签名链接的人可以在有效期内执行对应请求，最长有效期为七天。客户端与主机的系统时间需要准确。兼容范围限于表中操作，未实现的功能包括 IAM、桶策略、公共读权限、虚拟主机式寻址、版本管理、对象锁定、服务端加密、生命周期规则和 AWS 的 `aws-chunked` 请求编码。

## 文件、容量和备份

- **读取与写入**。文件按固定大小的缓冲区读写。普通上传先写入随机文件并校验，再通过数据库事务更新对象记录。并发读取可以继续读完已打开的文件。目录列表利用索引和游标分页，面板的容量统计在对象写入、覆盖和删除时更新。
- **主机限制**。管理面板使用浏览器表单上传，单个文件受 `upload_max_filesize` 限制，`post_max_size` 需要为完整表单预留空间。S3 客户端还受 Web 服务器请求大小、代理超时、PHP 执行时间和磁盘配额限制。对于大文件，可配置客户端分段大小，例如 8 MiB。除最后一个分段外，每个分段至少 5 MiB。完成上传时服务器会顺序读取分段并生成最终文件，需要为两份文件以及可能被替换的旧文件预留空间。
- **占用差异**。面板显示已经完成上传的对象大小之和。未完成的分段、临时文件和数据库索引也会占用主机配额。通过 `aws s3api list-multipart-uploads --bucket my-bucket` 可查看未完成上传，再用 `aws s3api abort-multipart-upload --bucket my-bucket --key OBJECT_KEY --upload-id UPLOAD_ID` 取消。删除存储桶前需要删除其中的对象，并完成或取消未结束的上传。
- **备份与恢复**。在主机面板暂停网站请求，并等正在执行的上传和删除完成，再一起备份数据库、整个 `data/` 目录和私有配置。恢复时使用同一批备份，并在重新开放访问前检查读取、上传和下载。进程异常终止或磁盘故障可能留下未被数据库引用的文件。维护时应先备份，并在暂停请求后核对数据库记录，避免误删正在上传的数据。
- **错误排查**。S3 错误返回 XML 和 `x-amz-request-id`。遇到 HTTP 500 时，可使用请求 ID 查找主机面板中的 PHP 错误日志。签名错误通常需要核对区域、系统时间、域名和路径，并确认代理保留原始查询字符串、`Host` 和 `Authorization` 请求头。

## 本地运行

配置好数据库、凭据和数据目录，在项目根目录执行以下命令，然后打开 `http://127.0.0.1:8080/admin`。PHP 内置服务器仅供本机开发，生产环境由虚拟主机的 Web 服务器处理请求。

```sh
php -S 127.0.0.1:8080 -t public public/index.php
```
