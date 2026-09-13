# OpenVPN Access Server (AS) 集成说明

本项目已支持与 OpenVPN Access Server (AS) 联动：在 daloRADIUS 中添加用户或点击“发送邮件”时，自动触发调用 OpenVPN AS 接口创建/同步用户并抓取 `.ovpn` 客户端配置文件，将配置文件作为附件随登录账号密码一并发送给用户。

## 部署说明

### 1. OpenVPN AS 服务器端（如 192.168.50.113）
在 OpenVPN AS 服务器上部署轻量 API Bridge 守护进程：

1. 将 `as_api_bridge.py` 复制到 `/usr/local/openvpn_as/scripts/as_api_bridge.py` 并赋予执行权限：
   ```bash
   chmod +x /usr/local/openvpn_as/scripts/as_api_bridge.py
   ```
2. 将 `openvpn-as-api.service` 复制到 `/etc/systemd/system/openvpn-as-api.service`：
   ```bash
   systemctl daemon-reload
   systemctl enable --now openvpn-as-api.service
   ```
3. 检查服务运行状态：
   ```bash
   systemctl status openvpn-as-api.service
   curl http://127.0.0.1:9443/api/health
   ```

### 2. daloRADIUS 端配置（.env）
在 `.env` 中配置 OpenVPN AS 的参数：
```bash
# --- VPN & OpenVPN Access Server ---
USER_VPN_SERVER=vpn.company.com
OPENVPN_AS_ENABLED=yes
OPENVPN_AS_HOST=192.168.50.113
OPENVPN_AS_MODE=api
OPENVPN_AS_API_PORT=9443
OPENVPN_AS_API_TOKEN=tangbull-openvpn-as-secret-key
OPENVPN_AS_PROFILE_TYPE=userlogin
OPENVPN_AS_DEFAULT_GROUP=
```

### 3. 操作流程
1. **新建账号** (`mng-new.php`)：
   - 录入用户名、密码、电子邮箱并保存。
   - 页面成功提示处将出现：`[Send VPN Profile & Credentials]` 按钮，点击即可自动调用 OpenVPN AS 接口并在成功后直接发送邮件。
2. **用户列表批量发送** (`mng-list-all.php` / `mng-search.php`)：
   - 勾选用户，点击底部的 `Send Mail` 按钮，后台将自动并发获取对应用户的 OpenVPN Profile 并附带 `.ovpn` 附件发送给用户。
