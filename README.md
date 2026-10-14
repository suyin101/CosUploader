# COS 图片上传（WordPress 插件）

把 WordPress 媒体库里的图片（以及其他附件）自动上传到 [腾讯云对象存储 COS](https://cloud.tencent.com/product/cos)，前台直接用 COS 地址访问，随时可以切回本站。

## 功能

- 新上传的图片（含所有缩略图）自动同步到 COS
- 前台图片地址、响应式图片（srcset）自动改为 COS 地址，支持 CDN / 自定义域名
- 「使用 COS / 使用本站」一键切换，不删除任何文件
- 批量同步旧文件：存储桶里已有的文件自动识别，不重复上传；可暂停、可续传
- 在媒体库删除图片时，同时删除 COS 上的文件
- 测试连接：检查上传、查询、公开访问、删除四项权限
- SecretKey 加密保存；在线更新并校验文件指纹

## 安装

1. 在 [Releases](https://github.com/suyin101/CosUploader/releases) 下载 `cos-uploader.zip`
2. WordPress 后台 → 插件 → 安装插件 → 上传插件，选择 zip 并启用
3. 设置 → COS 图片上传，填写 SecretId、SecretKey、地域、存储桶，保存后到「同步」页测试连接

之后的版本可以在插件设置页的「更新」里一键升级。

### 把密钥写在 wp-config.php（可选）

```php
define('COS_UPLOADER_SECRET_ID', 'AKID...');
define('COS_UPLOADER_SECRET_KEY', '...');
```

## 要求

- WordPress 5.8+
- PHP 7.2+（需要 openssl、curl 扩展）
- 存储桶权限为「公有读私有写」

## 发布新版本（开发者）

先在 `CHANGELOG.md` 写好 `## vX.Y.Z` 一节，然后：

```bash
./scripts/release.sh 1.3.1
```
