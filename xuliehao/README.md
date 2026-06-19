# 序列号查询网站

## 项目说明
- 前端使用原生 HTML / CSS / JavaScript
- 后端使用原生 PHP
- 数据使用 JSON 文件，保存在网站根目录下的 `data/` 子目录
- API 已独立存放在 `api/` 目录

## 页面入口
- `index.html`：公开查询界面
- `admin-login.html`：管理员登录界面
- `admin.html`：管理员面板

## 默认初始化
- 系统第一次访问任意 API 时，会自动初始化数据文件
- 默认超级管理员账号：`superadmin`
- 初始密码会随机生成 12 位十六进制强密码，并写入 PHP 错误日志
- 初始密码只会在首次初始化时输出一次，不会再通过页面直接返回
- 默认密码会以哈希形式保存到 `data/admins.json`
- 首次部署后请立即查看 PHP 错误日志，使用随机初始密码登录并马上修改密码

## 目录说明
- `assets/css/`：页面样式
- `assets/js/`：前端交互脚本
- `assets/uploads/backgrounds/`：查询页背景图上传目录
- `api/`：PHP 接口
- `data/`：JSON 数据文件

## 运行方式
1. 准备支持 PHP 8.x 的 Web 环境
2. 将项目放到网站根目录
3. 确保 `data/` 和 `assets/uploads/backgrounds/` 具备可写权限
4. 必须阻止 Web 直接访问 `data/` 目录
5. 打开 `index.html` 或 `admin-login.html` 开始使用

## 安全部署
- Apache：项目已在 `data/.htaccess` 中附带 `Require all denied`，用于拒绝所有对 `data/` 目录的网络访问
- Apache：项目已在 `assets/uploads/backgrounds/.htaccess` 中禁用脚本执行，防止上传目录被错误解析为可执行脚本
- Nginx：请把 [nginx-data-protect.conf](file:///e:/代码/xuliehao/deploy/nginx-data-protect.conf) 中的配置合并到站点配置或宝塔伪静态中，它同时保护 `data/` 目录和上传目录里的脚本访问
- 推荐做法：如果可以调整目录结构，优先把 `data/` 移到网站根目录之外
- 后台会话已启用 30 分钟闲置超时，长时间不操作会要求重新登录
- 限流默认基于 `REMOTE_ADDR`，不会再信任客户端伪造的 `X-Forwarded-For`
- 限流目录会做概率式垃圾回收，自动清理长时间未使用的限流文件，降低 Inode 堆积风险
- API 响应已默认附带 `nosniff`、`DENY` 和 `CSP` 安全头；如果你希望 `index.html`、`admin.html` 这类静态页面也受到同级保护，请在 Web 服务器配置中同步添加这些响应头

## 已实现功能
- 序列号查询
- 管理员登录 / 退出
- 序列号搜索、添加、修改、删除
- 公告修改
- 查询页背景图上传与切换
- 多管理员账号创建与编辑
- 管理员权限分级
- 管理员修改自己的账号和密码
