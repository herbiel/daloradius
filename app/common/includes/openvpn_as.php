<?php
/*
 *********************************************************************************************************
 * daloRADIUS - RADIUS Web Platform
 * Copyright (C) 2007 - Liran Tal <liran@lirantal.com> All Rights Reserved.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 *********************************************************************************************************
 *
 * Description:    OpenVPN Access Server (AS) Client Helper
 *                 Provides functions to manage users and retrieve client profiles (.ovpn)
 *                 Supports:
 *                   - HTTP API Bridge (default port 9443 with token)
 *                   - Direct SSH to root (calling sacli)
 *                   - XML-RPC (port 943 /RPC2)
 *
 *********************************************************************************************************
 */

// Prevent this file from being directly accessed
if (strpos($_SERVER['PHP_SELF'] ?? '', '/common/includes/openvpn_as.php') !== false) {
    http_response_code(404);
    exit;
}

/**
 * Check if OpenVPN AS integration is enabled/configured in configValues.
 *
 * @param array $config
 * @return bool
 */
function openvpn_as_is_configured($config) {
    $enabled = strtolower($config['CONFIG_OPENVPN_AS_ENABLED'] ?? 'yes');
    if ($enabled !== 'yes' && $enabled !== 'true' && $enabled !== '1') {
        return false;
    }

    $host = !empty($config['CONFIG_OPENVPN_AS_HOST']) ? $config['CONFIG_OPENVPN_AS_HOST'] : (!empty($config['CONFIG_USER_VPN_SERVER']) ? $config['CONFIG_USER_VPN_SERVER'] : '192.168.50.113');
    return !empty($host);
}

/**
 * Get communication mode: 'api' (HTTP bridge, default), 'ssh', or 'xmlrpc'.
 *
 * @param array $config
 * @return string
 */
function openvpn_as_get_mode($config) {
    $mode = strtolower($config['CONFIG_OPENVPN_AS_MODE'] ?? '');
    if (!empty($mode)) {
        return $mode;
    }

    // Auto-detect mode
    if (!empty($config['CONFIG_OPENVPN_AS_API_TOKEN']) || !empty($config['CONFIG_OPENVPN_AS_API_PORT'])) {
        return 'api';
    }

    if (!empty($config['CONFIG_OPENVPN_AS_SSH_KEY']) || (!empty($config['CONFIG_OPENVPN_AS_SSH_USER']) && $config['CONFIG_OPENVPN_AS_SSH_USER'] === 'root')) {
        return 'ssh';
    }

    // Default to HTTP API bridge for maximum reliability and simplicity
    return 'api';
}

/**
 * HTTP API Bridge call to OpenVPN AS bridge service.
 *
 * @param array  $config
 * @param string $path
 * @param string $method 'GET' or 'POST'
 * @param array  $payload
 * @return array [bool $success, mixed $dataOrError]
 */
function openvpn_as_call_http($config, $path, $method = 'GET', $payload = array()) {
    $host = $config['CONFIG_OPENVPN_AS_HOST'] ?? ($config['CONFIG_USER_VPN_SERVER'] ?? '192.168.50.113');
    $port = $config['CONFIG_OPENVPN_AS_API_PORT'] ?? '9443';
    $token = $config['CONFIG_OPENVPN_AS_API_TOKEN'] ?? 'tangbull-openvpn-as-secret-key';
    $proto = strtolower($config['CONFIG_OPENVPN_AS_API_PROTO'] ?? 'http');

    $url = sprintf('%s://%s:%s%s', $proto, $host, $port, $path);

    $ch = curl_init();
    $headers = array(
        'Authorization: Bearer ' . $token,
        'Accept: application/json'
    );

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        $json_data = json_encode($payload);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        $headers[] = 'Content-Type: application/json; charset=utf-8';
        $headers[] = 'Content-Length: ' . strlen($json_data);
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);

    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return array(false, sprintf('Failed to connect to OpenVPN AS bridge (%s): %s', $url, $curl_error));
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return array(false, sprintf('OpenVPN AS bridge returned invalid JSON (HTTP %d): %s', $http_code, substr(strip_tags($response), 0, 150)));
    }

    if ($http_code >= 400 || (isset($data['success']) && $data['success'] === false)) {
        $err = $data['error'] ?? (sprintf('HTTP error %d', $http_code));
        return array(false, $err);
    }

    return array(true, $data);
}

/**
 * Execute command over SSH to OpenVPN AS host.
 *
 * @param array  $config
 * @param string $remote_command
 * @return array [bool $success, string $outputOrError]
 */
function openvpn_as_call_ssh($config, $remote_command) {
    $host = $config['CONFIG_OPENVPN_AS_HOST'] ?? ($config['CONFIG_USER_VPN_SERVER'] ?? '192.168.50.113');
    $user = $config['CONFIG_OPENVPN_AS_SSH_USER'] ?? 'root';
    $port = intval($config['CONFIG_OPENVPN_AS_SSH_PORT'] ?? 22);
    $key_path = $config['CONFIG_OPENVPN_AS_SSH_KEY'] ?? '';

    $key_opt = (!empty($key_path) && file_exists($key_path)) ? sprintf('-i %s', escapeshellarg($key_path)) : '';

    $cmd = sprintf(
        'ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8 -p %d %s %s@%s %s 2>&1',
        $port,
        $key_opt,
        escapeshellarg($user),
        escapeshellarg($host),
        escapeshellarg($remote_command)
    );

    $output = array();
    $exit_code = 0;
    exec($cmd, $output, $exit_code);

    $out_str = trim(implode("\n", $output));
    if ($exit_code !== 0) {
        return array(false, sprintf('SSH command failed (exit code %d): %s', $exit_code, $out_str));
    }

    return array(true, $out_str);
}

/**
 * Ensure the user exists and set user properties in OpenVPN AS UserDB.
 *
 * @param array  $config
 * @param string $username
 * @param array  $properties (e.g. ['conn_group' => 'group_name'])
 * @return array [bool $success, string $message]
 */
function openvpn_as_ensure_user($config, $username, $properties = array()) {
    $username = trim($username);
    if (empty($username)) {
        return array(false, 'Username cannot be empty.');
    }

    $mode = openvpn_as_get_mode($config);
    $group = $properties['conn_group'] ?? ($config['CONFIG_OPENVPN_AS_DEFAULT_GROUP'] ?? '');

    if ($mode === 'ssh') {
        $sacli = $config['CONFIG_OPENVPN_AS_SACLI_PATH'] ?? '/usr/local/openvpn_as/scripts/sacli';
        $cmd = sprintf('%s --user %s --key type --value user_connect UserPropPut', escapeshellarg($sacli), escapeshellarg($username));
        if (!empty($group)) {
            $cmd .= sprintf(' && %s --user %s --key conn_group --value %s UserPropPut', escapeshellarg($sacli), escapeshellarg($username), escapeshellarg($group));
        }

        list($ok, $msg) = openvpn_as_call_ssh($config, $cmd);
        if (!$ok) {
            return array(false, sprintf('OpenVPN AS user creation failed: %s', $msg));
        }
        return array(true, sprintf('User [%s] synchronized via SSH.', $username));
    }

    // Default: HTTP API bridge
    $payload = array(
        'username' => $username,
        'group' => $group
    );
    list($ok, $data) = openvpn_as_call_http($config, '/api/user/sync', 'POST', $payload);
    if (!$ok) {
        return array(false, sprintf('OpenVPN AS API user sync failed: %s', $data));
    }

    return array(true, sprintf('User [%s] synchronized to OpenVPN AS.', $username));
}

/**
 * Retrieve the client configuration profile (.ovpn) for the given user.
 *
 * @param array  $config
 * @param string $username
 * @param string $profile_type 'userlogin' or 'autologin'
 * @return array [bool $success, string $profileContentOrError]
 */
function openvpn_as_get_profile($config, $username, $profile_type = '') {
    $username = trim($username);
    if (empty($username)) {
        return array(false, 'Username cannot be empty.');
    }

    if (empty($profile_type)) {
        $profile_type = strtolower($config['CONFIG_OPENVPN_AS_PROFILE_TYPE'] ?? 'userlogin');
    }

    $mode = openvpn_as_get_mode($config);

    if ($mode === 'ssh') {
        $sacli = $config['CONFIG_OPENVPN_AS_SACLI_PATH'] ?? '/usr/local/openvpn_as/scripts/sacli';
        $subcmd = ($profile_type === 'autologin') ? 'GetAutologin' : 'GetUserlogin';
        $cmd = sprintf('%s -u %s %s', escapeshellarg($sacli), escapeshellarg($username), escapeshellarg($subcmd));

        list($ok, $profile) = openvpn_as_call_ssh($config, $cmd);
        if (!$ok) {
            return array(false, sprintf('Failed to retrieve %s profile via SSH: %s', $profile_type, $profile));
        }

        if (empty($profile) || strpos($profile, 'client') === false && strpos($profile, 'dev tun') === false && strpos($profile, 'OpenVPN') === false) {
            return array(false, sprintf('Invalid profile returned via SSH for user [%s]: %s', $username, substr($profile, 0, 100)));
        }

        return array(true, $profile);
    }

    // Default: HTTP API bridge
    $path = sprintf('/api/user/profile?username=%s&type=%s', urlencode($username), urlencode($profile_type));
    list($ok, $data) = openvpn_as_call_http($config, $path, 'GET');
    if (!$ok) {
        return array(false, sprintf('Failed to retrieve profile via API: %s', $data));
    }

    $profile = $data['profile'] ?? '';
    if (empty($profile) || !is_string($profile)) {
        return array(false, sprintf('Empty profile returned from OpenVPN AS for user [%s].', $username));
    }

    return array(true, $profile);
}

/**
 * Convenience method: ensure user and fetch profile in one call.
 *
 * @param array  $config
 * @param string $username
 * @param string $profile_type
 * @param array  $properties
 * @return array [bool $success, string $profileContent, string $statusMessage]
 */
function openvpn_as_create_and_fetch_profile($config, $username, $profile_type = '', $properties = array()) {
    $username = trim($username);
    if (empty($username)) {
        return array(false, '', 'Username cannot be empty.');
    }

    if (empty($profile_type)) {
        $profile_type = strtolower($config['CONFIG_OPENVPN_AS_PROFILE_TYPE'] ?? 'userlogin');
    }

    $mode = openvpn_as_get_mode($config);
    if ($mode === 'api') {
        $group = $properties['conn_group'] ?? ($config['CONFIG_OPENVPN_AS_DEFAULT_GROUP'] ?? '');
        $payload = array(
            'username' => $username,
            'group' => $group,
            'type' => $profile_type
        );
        list($ok, $data) = openvpn_as_call_http($config, '/api/user/create_and_profile', 'POST', $payload);
        if (!$ok) {
            return array(false, '', sprintf('OpenVPN AS create_and_profile failed: %s', $data));
        }

        $profile = $data['profile'] ?? '';
        return array(true, $profile, 'OpenVPN AS user synced and profile generated successfully.');
    }

    // Fallback: 2-step process for SSH or other modes
    list($user_ok, $user_msg) = openvpn_as_ensure_user($config, $username, $properties);
    list($prof_ok, $prof_data) = openvpn_as_get_profile($config, $username, $profile_type);

    if (!$prof_ok) {
        return array(false, '', $prof_data);
    }

    $msg = $user_ok ? $user_msg : ('Warning: ' . $user_msg);
    return array(true, $prof_data, $msg);
}

/**
 * Build a modern, rich HTML email body containing account credentials,
 * cross-platform client download links, explicit iOS US App Store requirement notice,
 * and clear step-by-step setup instructions.
 *
 * @param string $firstname
 * @param string $lastname
 * @param string $username
 * @param string $password
 * @param string $vpn_server
 * @param bool   $has_profile_attachment
 * @param string $portal_url
 * @return string HTML email content
 */
function openvpn_as_build_email_body($firstname, $lastname, $username, $password, $vpn_server, $has_profile_attachment = true, $portal_url = '') {
    $name = trim("$firstname $lastname") ?: $username;
    if (empty($portal_url)) {
        $portal_url = sprintf('https://%s:943/', $vpn_server);
    }

    $safe_name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safe_user = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $safe_pass = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');
    $safe_serv = htmlspecialchars($vpn_server, ENT_QUOTES, 'UTF-8');
    $safe_port = htmlspecialchars($portal_url, ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<div style="max-width: 680px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #2d3748; line-height: 1.6; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; background-color: #ffffff;">
    <!-- Header -->
    <div style="background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); color: #ffffff; padding: 24px 30px; text-align: left;">
        <h2 style="margin: 0 0 6px 0; font-size: 22px; font-weight: 700; letter-spacing: -0.5px;">VPN 账号已开通 & 客户端配置指南</h2>
        <p style="margin: 0; font-size: 14px; opacity: 0.9;">Secure Network Access & OpenVPN Configuration</p>
    </div>

    <!-- Main Content -->
    <div style="padding: 24px 30px;">
        <p style="font-size: 15px; margin-top: 0;">您好，<strong>{$safe_name}</strong>！您的 VPN 账户已成功创建，账号凭据及连接说明如下：</p>

        <!-- Credentials Card -->
        <div style="background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 16px 20px; margin: 18px 0;">
            <div style="font-size: 14px; font-weight: 600; color: #475569; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.5px;">🔑 登录凭据 (Credentials)</div>
            <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                <tr>
                    <td style="padding: 6px 0; color: #64748b; width: 110px;"><strong>登录用户名：</strong></td>
                    <td style="padding: 6px 0; font-family: monospace; font-size: 15px; color: #0f172a;"><strong>{$safe_user}</strong></td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #64748b;"><strong>登录密码：</strong></td>
                    <td style="padding: 6px 0; font-family: monospace; font-size: 15px; color: #0f172a;"><strong style="background: #e2e8f0; padding: 2px 6px; border-radius: 4px;">{$safe_pass}</strong></td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #64748b;"><strong>VPN 服务器：</strong></td>
                    <td style="padding: 6px 0; font-family: monospace; font-size: 14px; color: #334155;">{$safe_serv}</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #64748b;"><strong>Web 客户端门户：</strong></td>
                    <td style="padding: 6px 0; font-size: 13px;"><a href="{$safe_port}" target="_blank" style="color: #2563eb; text-decoration: none;">{$safe_port}</a> <span style="color: #94a3b8; font-size: 12px;">(支持直接网页登录下载)</span></td>
                </tr>
            </table>
        </div>

        <!-- Attachments Notice -->
        <div style="background-color: #eff6ff; border-left: 4px solid #3b82f6; padding: 12px 16px; margin: 18px 0; border-radius: 0 6px 6px 0; font-size: 14px;">
            <strong style="color: #1e40af;">📎 本邮件附件已包含专属配置文件与离线说明：</strong><br>
            1. <strong><code>{$safe_user}.ovpn</code></strong>：您的个人加密配置文件，导入即可自动连接。<br>
            2. <strong><code>OpenVPN_快速使用指南及下载.html</code></strong>：离线双击即可打开的各平台详细配置图解说明书。
        </div>

        <!-- iOS Requirement Alert -->
        <div style="background-color: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #f59e0b; padding: 14px 16px; margin: 18px 0; border-radius: 0 6px 6px 0; font-size: 13.5px; color: #92400e; line-height: 1.5;">
            <strong style="font-size: 14px; display: block; margin-bottom: 4px;">⚠️ 【重要须知】iOS (iPhone / iPad) 用户下载说明：</strong>
            因 Apple 政策限制，OpenVPN Connect 官方客户端<b>未在大陆区 App Store 上架</b>。
            iPhone/iPad 用户<b>必须切换至海外地区（如美区、港区）Apple ID</b> 登录 App Store，搜索「<strong>OpenVPN Connect</strong>」进行下载安装。如暂无海外 Apple ID，请联系管理员协助。
        </div>

        <!-- Software Download Matrix -->
        <h3 style="font-size: 16px; color: #1e293b; margin: 24px 0 12px 0; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px;">📥 官方客户端各平台下载</h3>
        <p style="font-size: 13px; color: #64748b; margin-top: 0;">请根据您的设备类型选择对应客户端下载安装：</p>

        <table style="width: 100%; border-collapse: separate; border-spacing: 0 8px; font-size: 13px;">
            <tr style="background-color: #f8fafc; border-radius: 6px;">
                <td style="padding: 10px 14px; font-weight: 600; width: 140px;">🖥️ Windows (10/11)</td>
                <td style="padding: 10px 14px; color: #64748b;">官方 64位 MSI 安装包</td>
                <td style="padding: 10px 14px; text-align: right;">
                    <a href="https://openvpn.net/downloads/openvpn-connect-v3-windows.msi" target="_blank" style="display: inline-block; background-color: #2563eb; color: #ffffff; text-decoration: none; padding: 6px 14px; border-radius: 6px; font-weight: 500; font-size: 12px;">直接下载 MSI</a>
                </td>
            </tr>
            <tr style="background-color: #f8fafc; border-radius: 6px;">
                <td style="padding: 10px 14px; font-weight: 600;">🍏 macOS (Apple / Intel)</td>
                <td style="padding: 10px 14px; color: #64748b;">通用 DMG 安装镜像</td>
                <td style="padding: 10px 14px; text-align: right;">
                    <a href="https://openvpn.net/downloads/openvpn-connect-v3-macos.dmg" target="_blank" style="display: inline-block; background-color: #2563eb; color: #ffffff; text-decoration: none; padding: 6px 14px; border-radius: 6px; font-weight: 500; font-size: 12px;">直接下载 DMG</a>
                </td>
            </tr>
            <tr style="background-color: #f8fafc; border-radius: 6px;">
                <td style="padding: 10px 14px; font-weight: 600;">🤖 Android (安卓手机/平板)</td>
                <td style="padding: 10px 14px; color: #64748b;">官方 APK 直链 / Google Play</td>
                <td style="padding: 10px 14px; text-align: right;">
                    <a href="https://openvpn.net/downloads/openvpn-connect-v3-android.apk" target="_blank" style="display: inline-block; background-color: #059669; color: #ffffff; text-decoration: none; padding: 6px 12px; border-radius: 6px; font-weight: 500; font-size: 12px; margin-right: 4px;">下载 APK</a>
                    <a href="https://play.google.com/store/apps/details?id=net.openvpn.openvpn" target="_blank" style="display: inline-block; background-color: #475569; color: #ffffff; text-decoration: none; padding: 6px 10px; border-radius: 6px; font-size: 12px;">Google Play</a>
                </td>
            </tr>
            <tr style="background-color: #fef3c7; border-radius: 6px;">
                <td style="padding: 10px 14px; font-weight: 600; color: #92400e;">🍎 iOS (iPhone / iPad)</td>
                <td style="padding: 10px 14px; color: #b45309;"><strong>需美区/海外 Apple ID</strong></td>
                <td style="padding: 10px 14px; text-align: right;">
                    <a href="https://apps.apple.com/us/app/openvpn-connect-openvpn-app/id590379981" target="_blank" style="display: inline-block; background-color: #d97706; color: #ffffff; text-decoration: none; padding: 6px 14px; border-radius: 6px; font-weight: 500; font-size: 12px;">App Store (美区)</a>
                </td>
            </tr>
        </table>

        <!-- Step-by-Step Guide -->
        <h3 style="font-size: 16px; color: #1e293b; margin: 26px 0 14px 0; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px;">🚀 4 步快速上手使用说明</h3>
        <ol style="padding-left: 20px; margin: 0; font-size: 14px; line-height: 1.8; color: #334155;">
            <li><strong>安装客户端：</strong>点击上方对应系统链接，下载并完成 OpenVPN Connect 客户端安装。</li>
            <li><strong>保存配置文件：</strong>将本邮件附件中的 <code>{$safe_user}.ovpn</code> 个人专属配置文件保存到电脑或手机中。</li>
            <li><strong>导入配置：</strong>打开 OpenVPN Connect 软件，切换到 <strong>File / Upload File</strong> 标签页，拖入或选择保存的 <code>{$safe_user}.ovpn</code> 文件导入。</li>
            <li><strong>一键连接：</strong>用户名已自动填充，输入本邮件上方提供的<strong>登录密码</strong>，勾选「Save password（记住密码）」，点击 <strong>CONNECT</strong> 按钮即可接入网络。</li>
        </ol>

        <!-- FAQ Note -->
        <div style="margin-top: 24px; padding-top: 16px; border-top: 1px dashed #e2e8f0; font-size: 13px; color: #64748b;">
            <p style="margin: 4px 0;"><strong>💡 常见问题与支持：</strong></p>
            <ul style="padding-left: 18px; margin: 6px 0;">
                <li>若连接时提示密码错误，请确认输入密码时前后未包含多余空格。</li>
                <li>若连接无响应，请确认所在 WiFi 网络未屏蔽 VPN 端口（UDP 1194 / TCP 4433）。</li>
                <li>如遇任何使用疑问，请随时联系网络管理员或 IT 运维团队。</li>
            </ul>
        </div>
    </div>

    <!-- Footer -->
    <div style="background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 14px 30px; text-align: center; font-size: 12px; color: #94a3b8;">
        Network Administration & Security Team &bull; daloRADIUS & OpenVPN Access Server
    </div>
</div>
HTML;

    return $html;
}

/**
 * Generate a standalone offline HTML user guide attachment that users can save and double click.
 *
 * @param string $username
 * @param string $password
 * @param string $vpn_server
 * @param string $portal_url
 * @return string Complete HTML document
 */
function openvpn_as_build_offline_guide_html($username, $password, $vpn_server, $portal_url = '') {
    if (empty($portal_url)) {
        $portal_url = sprintf('https://%s:943/', $vpn_server);
    }
    $safe_user = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $safe_pass = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');
    $safe_serv = htmlspecialchars($vpn_server, ENT_QUOTES, 'UTF-8');
    $safe_port = htmlspecialchars($portal_url, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OpenVPN 快速配置与客户端使用指南</title>
    <style>
        :root { --primary: #2563eb; --primary-hover: #1d4ed8; --bg: #f8fafc; --text: #1e293b; --card: #ffffff; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'PingFang SC', 'Microsoft YaHei', sans-serif; background-color: var(--bg); color: var(--text); margin: 0; padding: 24px; line-height: 1.6; }
        .container { max-width: 800px; margin: 0 auto; background: var(--card); border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.06); padding: 36px; }
        .header { border-bottom: 2px solid #f1f5f9; padding-bottom: 20px; margin-bottom: 24px; }
        .header h1 { margin: 0 0 8px 0; font-size: 26px; color: #0f172a; }
        .header p { margin: 0; color: #64748b; font-size: 15px; }
        .card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px 22px; margin: 20px 0; }
        .card-title { font-weight: 700; color: #334155; margin-bottom: 12px; font-size: 15px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-family: monospace; font-size: 14px; background: #e2e8f0; color: #0f172a; }
        .alert-ios { background: #fffbeb; border-left: 4px solid #f59e0b; padding: 14px 18px; border-radius: 0 8px 8px 0; margin: 20px 0; color: #92400e; font-size: 14px; }
        .btn { display: inline-block; padding: 8px 16px; border-radius: 6px; font-weight: 600; font-size: 13px; text-decoration: none; color: #fff; background: var(--primary); transition: background 0.2s; }
        .btn:hover { background: var(--primary-hover); }
        .btn-green { background: #059669; }
        .btn-green:hover { background: #047857; }
        .btn-amber { background: #d97706; }
        .btn-amber:hover { background: #b45309; }
        table.matrix { width: 100%; border-collapse: collapse; margin: 16px 0; }
        table.matrix th, table.matrix td { padding: 12px 14px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
        table.matrix th { background: #f1f5f9; color: #475569; }
        ol.steps { padding-left: 20px; font-size: 15px; }
        ol.steps li { margin-bottom: 14px; }
        .footer { margin-top: 36px; padding-top: 18px; border-top: 1px solid #e2e8f0; text-align: center; color: #94a3b8; font-size: 13px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🔒 OpenVPN 客户端下载与使用指南</h1>
        <p>个人专属配置及跨平台客户端连接说明文档</p>
    </div>

    <div class="card">
        <div class="card-title">🔑 您的 VPN 专属账户信息</div>
        <p style="margin: 6px 0;"><strong>用户名：</strong> <span class="badge">{$safe_user}</span></p>
        <p style="margin: 6px 0;"><strong>初始密码：</strong> <span class="badge">{$safe_pass}</span></p>
        <p style="margin: 6px 0;"><strong>VPN 服务器：</strong> <span class="badge">{$safe_serv}</span></p>
        <p style="margin: 6px 0;"><strong>Web 客户端门户：</strong> <a href="{$safe_port}" target="_blank">{$safe_port}</a></p>
    </div>

    <div class="alert-ios">
        <strong>⚠️ 【特别提示】苹果 iOS (iPhone / iPad) 用户须知：</strong><br>
        由于苹果政策原因，OpenVPN 官方客户端<strong>未在大陆区 App Store 上架</strong>。<br>
        iOS 用户请先退出大陆 Apple ID，在 App Store 登录<strong>海外区（如美区、港区）Apple ID</strong>，搜索下载「<strong>OpenVPN Connect</strong>」。如需美区账号，请联系 IT 管理员。
    </div>

    <h3>📥 各平台官方客户端下载直链</h3>
    <table class="matrix">
        <thead>
            <tr>
                <th>操作系统平台</th>
                <th>适用说明</th>
                <th>下载入口</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>🖥️ Windows 10 / 11</strong></td>
                <td>官方 64 位完整安装包 (.msi)</td>
                <td><a class="btn" href="https://openvpn.net/downloads/openvpn-connect-v3-windows.msi" target="_blank">下载 Windows MSI</a></td>
            </tr>
            <tr>
                <td><strong>🍏 macOS (Apple/Intel)</strong></td>
                <td>全芯片架构通用安装镜像 (.dmg)</td>
                <td><a class="btn" href="https://openvpn.net/downloads/openvpn-connect-v3-macos.dmg" target="_blank">下载 macOS DMG</a></td>
            </tr>
            <tr>
                <td><strong>🤖 Android (安卓手机)</strong></td>
                <td>官方安装包 APK / Google Play</td>
                <td>
                    <a class="btn btn-green" href="https://openvpn.net/downloads/openvpn-connect-v3-android.apk" target="_blank">下载 APK 直链</a>
                </td>
            </tr>
            <tr>
                <td><strong>🍎 iOS (iPhone / iPad)</strong></td>
                <td>需美区 / 海外 Apple ID</td>
                <td><a class="btn btn-amber" href="https://apps.apple.com/us/app/openvpn-connect-openvpn-app/id590379981" target="_blank">App Store (美区)</a></td>
            </tr>
        </tbody>
    </table>

    <h3>🚀 4 步接入步骤说明</h3>
    <ol class="steps">
        <li><strong>下载安装客户端：</strong>根据上面的操作系统表格，下载并完成对应客户端的安装。</li>
        <li><strong>获取专属配置文件：</strong>将邮件中随附的 <code>{$safe_user}.ovpn</code> 文件下载保存到本地。</li>
        <li><strong>导入配置文件：</strong>打开 OpenVPN Connect，选择 <strong>File / Upload File</strong>，将下载的 <code>{$safe_user}.ovpn</code> 拖入或选取导入。</li>
        <li><strong>输入密码连接：</strong>用户名已自动填好，输入本页上方的初始密码，勾选 <strong>Save password</strong>，点击 <strong>CONNECT</strong> 即可接入公司网络！</li>
    </ol>

    <div class="card" style="background:#f1f5f9;">
        <div class="card-title">💡 常见排错建议</div>
        <ul style="padding-left: 20px; margin: 0; font-size: 14px; color: #475569;">
            <li><strong>提示 Authentication Failed：</strong>请检查密码复制时是否带入多余的前后空格。</li>
            <li><strong>提示 Server Unreachable：</strong>请排查当前所连局域网是否封禁了外部 VPN 端口（UDP 1194/11940）。</li>
            <li><strong>配置文件遗失：</strong>如配置文件丢失，可随时登录 Web 客户端门户（{$safe_port}）重新下载。</li>
        </ul>
    </div>

    <div class="footer">
        IT 运维及安全保障团队 &bull; 如有疑问请联系系统管理员
    </div>
</div>
</body>
</html>
HTML;
}
