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
 * Description:    Dedicated Feishu (飞书) Webhook Endpoint
 *                 Supports:
 *                 - Feishu URL Verification (challenge handshake)
 *                 - Feishu Bitable (多维表格) automation webhooks
 *                 - Feishu Approval (审批通过) webhook events
 *                 - Direct JSON with English or Chinese field names
 *
 *********************************************************************************************************
 */

// Load core configurations and utilities
require_once(__DIR__ . '/../../../common/includes/config_read.php');
require_once(__DIR__ . '/../../../common/includes/api_response.php');
require_once(__DIR__ . '/../../../common/includes/validation.php');
require_once(__DIR__ . '/../../include/management/functions.php');
require_once(__DIR__ . '/../../library/attributes.php');

// Read raw body
$rawBody = file_get_contents('php://input');
$input = array();
if (!empty($rawBody)) {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}
if (empty($input)) {
    $input = $_POST;
}

// 1. Feishu URL Verification handshake (Challenge)
if (isset($input['type']) && $input['type'] === 'url_verification' && isset($input['challenge'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('challenge' => $input['challenge']));
    exit;
}

// 2. Open Database connection
require_once(__DIR__ . '/../../../common/includes/db_open.php');

// 3. Authenticate Request
// Allow API Key via Header, Bearer token, or query param (?key=... or ?token=...)
$apiKey = $_GET['key'] ?? ($_GET['token'] ?? null);
if ($apiKey) {
    $_SERVER['HTTP_X_API_KEY'] = $apiKey;
}

require_once(__DIR__ . '/../../../common/includes/api_auth.php');
$authInfo = api_authenticate($dbSocket, $configValues);
$operator = isset($authInfo['operator']) ? $authInfo['operator'] : 'feishu-bot';

// 4. Extract form fields (supports nested Bitable record fields, Event fields, or flat JSON)
$fields = $input;
if (isset($input['event']['record']['fields']) && is_array($input['event']['record']['fields'])) {
    $fields = $input['event']['record']['fields'];
} else if (isset($input['fields']) && is_array($input['fields'])) {
    $fields = $input['fields'];
} else if (isset($input['event']) && is_array($input['event'])) {
    $fields = array_merge($input, $input['event']);
}

// Helper to look up field across multiple candidate keys (case-insensitive & Chinese)
function get_field_val($data, $keys, $default = '') {
    foreach ($keys as $k) {
        if (isset($data[$k]) && $data[$k] !== '') {
            $v = $data[$k];
            // If Bitable field value is an array (e.g. text/person object)
            if (is_array($v)) {
                if (isset($v['text'])) return trim($v['text']);
                if (isset($v['email'])) return trim($v['email']);
                if (isset($v['name'])) return trim($v['name']);
                if (isset($v[0])) {
                    if (is_array($v[0])) {
                        return trim($v[0]['text'] ?? ($v[0]['email'] ?? ($v[0]['name'] ?? '')));
                    }
                    return trim($v[0]);
                }
            }
            return trim((string)$v);
        }
    }
    return $default;
}

$email = get_field_val($fields, array('email', 'mail', 'user_email', '邮箱', '企业邮箱', '电子邮箱', '工作邮箱'));
$username = get_field_val($fields, array('username', 'user', 'account', '用户名', '账号', '帐号', '申请账号'));
$name = get_field_val($fields, array('name', 'fullname', 'realname', '姓名', '申请人', '用户姓名'));
$department = get_field_val($fields, array('department', 'dept', '部门', '所属部门'));
$password = get_field_val($fields, array('password', 'pwd', 'pass', '密码', '初始密码'));
$group = get_field_val($fields, array('group', 'groups', 'usergroup', '用户组', '分组', '权限组'));

// If username is empty, derive from email prefix
if (empty($username) && !empty($email)) {
    $parts = explode('@', $email);
    $username = strtolower(trim(str_replace('%', '', $parts[0])));
}

if (empty($username)) {
    api_send_error('Failed to extract "username" or "email" from Feishu request.', 400);
}

if (empty($email)) {
    api_send_error('Field "email" is required for Feishu OpenVPN provision & delivery.', 400);
}

// Construct standard account payload
$accountData = array(
    'username'   => $username,
    'password'   => $password,
    'email'      => $email,
    'firstname'  => $name,
    'department' => $department,
    'sync_vpn'   => true,
    'send_mail'  => true,
);

if (!empty($group)) {
    $accountData['groups'] = array($group);
}

// Delegate to standard accounts management API logic
define('DALO_ACCOUNTS_API_NO_DISPATCH', true);
require_once(__DIR__ . '/accounts.php');

handle_add_account($dbSocket, $configValues, $accountData, $operator, $valid_passwordTypes);
