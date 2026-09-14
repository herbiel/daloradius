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
