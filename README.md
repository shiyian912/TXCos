# TXCos
一款typecho插件，对接腾讯云COS

> 功能介绍

在编写文章完，保存或者发布文章时，插件会文章的图片上传到腾讯云COS，同时会替换文章原图片URL地址。
如果后续放弃使用腾讯云COS，为了能将文章图片URL地址还原回去，会将图片原URL地址和腾讯云COS地址一对一关系存放在数据库中。
仅支持 PHP 版本 >=7.2.5 ，typecho版本1.2.0（其他版本自测）。 

> 使用方法

 1. 创建腾讯云COS存储桶，以及用户API密钥
![2023-04-10231946.png][1]
记住这个存储桶名称和所属区域，后续会用到。
![2023-04-10232159.png][2]
介意创建子用户，给子用户授权COS访问权限。（为安全起见，因为主用户的API密钥对所有腾讯云资产都有访问权限）。
同时介意只授权COS上传权限，不授权删除权限。本插件也只有向COS上传图片功能，没有删除COS图片功能。
 2. 将本插件安装到typecho的plugins目录
下载并解压到到名为TXCos的文件夹中。
 3. 安装php扩展
需要安转cURL 扩展、xml 扩展、dom 扩展、mbstring 扩展、json 扩展。但是实测不需安装xml扩展和json扩展，或许是使用的功能没有使用到这两个插件。这是官网使用文档地址：https://cloud.tencent.com/document/product/436。
 4. 插件设置
如下图：
![2023-04-10234036.png][3]
SecretId：用户API密钥中的SecretId，如果是要存在环境变量中，变量名--TXCOS_SECRET_ID。
SecretKey：用户API密钥中的SecretKey，如果是要存在环境变量中，变量名--TXCOS_SECRET_KEY。
Bucket：存储桶名称。
Region：存储桶所属区域，填写上图标记的英文，如ap-beijing。
Directory：在存储桶中创建的文件夹(介意上传个文件夹，将图片统一上传到此文件夹中)。
 5. nginx添加环境变量方式
找到`location`分块，使用`fastcgi_param`指令添加，如下：

```lua
location ~ \.php$ {
    fastcgi_param TXCOS_SECRET_ID "your SecretId";
    fastcgi_param TXCOS_SECRET_KEY "your SecretKey";
    include fastcgi_params;
}
```
