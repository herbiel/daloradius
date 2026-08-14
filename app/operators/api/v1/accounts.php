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
 * Description:    RESTful API endpoint for User Account Management:
 *                 - Add Account (POST)
 *                 - Get Account (GET)
 *                 - Delete Account (DELETE)
 *                 - Change Account Password (PUT / PATCH / POST)
 *
 *********************************************************************************************************
 */

// Load core configurations and utilities
require_once(__DIR__ . '/../../../common/includes/config_read.php');
require_once(__DIR__ . '/../../../common/includes/api_response.php');
require_once(__DIR__ . '/../../../common/includes/validation.php');
require_once(__DIR__ . '/../../include/management/functions.php');
require_once(__DIR__ . '/../../library/attributes.php');

// Open Database connection
require_once(__DIR__ . '/../../../common/includes/db_open.php');

// Authenticate API Request
require_once(__DIR__ . '/../../../common/includes/api_auth.php');
$authInfo = api_authenticate($dbSocket, $configValues);
$operator = isset($authInfo['operator']) ? $authInfo['operator'] : 'api';

// Parse Request Data & Method
$reqData = api_get_request_data();
$httpMethod = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

// Support method overriding via _method or action parameter
$action = isset($reqData['action']) ? strtolower(trim($reqData['action'])) : '';
if (isset($reqData['_method'])) {
    $httpMethod = strtoupper(trim($reqData['_method']));
}

// Ensure password encryption settings are respected
if (isset($configValues['CONFIG_DB_PASSWORD_ENCRYPTION']) &&
    strtolower(trim($configValues['CONFIG_DB_PASSWORD_ENCRYPTION'])) !== 'yes') {
    $valid_passwordTypes = array_values(array_diff($valid_passwordTypes, array("Cleartext-Password")));
}

// Router for CRUD operations
if ($httpMethod === 'POST' && ($action === 'change_password' || $action === 'set_password' || $action === 'update_password' || $action === 'password')) {
    handle_change_password($dbSocket, $configValues, $reqData, $operator, $valid_passwordTypes);
} else if ($httpMethod === 'POST' && ($action === 'delete' || $action === 'del' || $action === 'remove')) {
    handle_delete_account($dbSocket, $configValues, $reqData, $operator);
} else if ($httpMethod === 'POST' && ($action === 'get' || $action === 'show' || $action === 'view' || $action === 'read')) {
    handle_get_account($dbSocket, $configValues, $reqData);
} else {
    switch ($httpMethod) {
        case 'GET':
            handle_get_account($dbSocket, $configValues, $reqData);
            break;

        case 'POST':
            handle_add_account($dbSocket, $configValues, $reqData, $operator, $valid_passwordTypes);
            break;

        case 'PUT':
        case 'PATCH':
            handle_change_password($dbSocket, $configValues, $reqData, $operator, $valid_passwordTypes);
            break;

        case 'DELETE':
            handle_delete_account($dbSocket, $configValues, $reqData, $operator);
            break;

        default:
            api_send_error('Method Not Allowed: ' . $httpMethod, 405);
            break;
    }
}

// Close DB connection on exit
require_once(__DIR__ . '/../../../common/includes/db_close.php');


/**
 * =========================================================================
 * 1. ADD ACCOUNT (POST)
 * =========================================================================
 */
function handle_add_account($dbSocket, $configValues, $data, $operator, $valid_passwordTypes) {
    // 1. Validate Username
    if (!isset($data['username']) || trim($data['username']) === '') {
        api_send_error('Field "username" is required.', 400);
    }
    $username = trim(str_replace('%', '', $data['username']));

    // 2. Validate Password
    if (!isset($data['password']) || (string)$data['password'] === '') {
        api_send_error('Field "password" is required.', 400);
    }
    $password = (string)$data['password'];

    // 3. Check if user already exists
    if (user_exists($dbSocket, $username)) {
        api_send_error(sprintf('User "%s" already exists.', $username), 409);
    }

    // 4. Password Type
    $passwordType = 'Cleartext-Password';
    if (isset($data['password_type']) && !empty(trim($data['password_type']))) {
        $passwordType = trim($data['password_type']);
    } else if (isset($data['passwordType']) && !empty(trim($data['passwordType']))) {
        $passwordType = trim($data['passwordType']);
    }

    if (!in_array($passwordType, $valid_passwordTypes)) {
        api_send_error(sprintf('Invalid password_type "%s". Supported types: %s', $passwordType, implode(', ', $valid_passwordTypes)), 400);
    }

    // Hash password value
    $hashedPassword = hashPasswordAttribute($passwordType, $password);
    if ($hashedPassword === false) {
        $hashedPassword = $password;
    }

    // 5. Insert Password into radcheck
    $sql = sprintf("INSERT INTO %s (`id`, `username`, `attribute`, `op`, `value`) VALUES (0, '%s', '%s', ':=', '%s')",
                   $configValues['CONFIG_DB_TBL_RADCHECK'],
                   $dbSocket->escapeSimple($username),
                   $dbSocket->escapeSimple($passwordType),
                   $dbSocket->escapeSimple($hashedPassword));
    $res = $dbSocket->query($sql);
    if (DB::isError($res)) {
        api_send_error('Failed to create user password attribute: ' . $res->getMessage(), 500);
    }

    $attributesCount = 1;

    // 6. Handle Standard Quick RADIUS Attributes
    // Check attributes
    $checkAttributes = array(
        'max_all_session'  => 'Max-All-Session',
        'maxallsession'    => 'Max-All-Session',
        'simultaneous_use' => 'Simultaneous-Use',
        'simultaneoususe'  => 'Simultaneous-Use',
    );

    foreach ($checkAttributes as $key => $attrName) {
        if (isset($data[$key]) && (string)$data[$key] !== '') {
            $val = trim($data[$key]);
            $sql = sprintf("INSERT INTO %s (`id`, `username`, `attribute`, `op`, `value`) VALUES (0, '%s', '%s', ':=', '%s')",
                           $configValues['CONFIG_DB_TBL_RADCHECK'],
                           $dbSocket->escapeSimple($username),
                           $dbSocket->escapeSimple($attrName),
                           $dbSocket->escapeSimple($val));
            $dbSocket->query($sql);
            $attributesCount++;
        }
    }

    // Expiration date
    if (isset($data['expiration']) && !empty(trim($data['expiration']))) {
        $expVal = trim($data['expiration']);
        try {
            $expDate = new DateTime($expVal);
            $formattedExp = $expDate->format('d M Y');
        } catch (Exception $e) {
            $formattedExp = $expVal;
        }

        $sql = sprintf("INSERT INTO %s (`id`, `username`, `attribute`, `op`, `value`) VALUES (0, '%s', 'Expiration', ':=', '%s')",
                       $configValues['CONFIG_DB_TBL_RADCHECK'],
                       $dbSocket->escapeSimple($username),
                       $dbSocket->escapeSimple($formattedExp));
        $dbSocket->query($sql);
        $attributesCount++;
    }

    // Reply attributes
    $replyAttributes = array(
        'session_timeout'   => 'Session-Timeout',
        'sessiontimeout'     => 'Session-Timeout',
        'idle_timeout'      => 'Idle-Timeout',
        'idletimeout'       => 'Idle-Timeout',
        'framed_ip_address' => 'Framed-IP-Address',
        'framedipaddress'   => 'Framed-IP-Address',
    );

    foreach ($replyAttributes as $key => $attrName) {
        if (isset($data[$key]) && (string)$data[$key] !== '') {
            $val = trim($data[$key]);
            $sql = sprintf("INSERT INTO %s (`id`, `username`, `attribute`, `op`, `value`) VALUES (0, '%s', '%s', ':=', '%s')",
                           $configValues['CONFIG_DB_TBL_RADREPLY'],
                           $dbSocket->escapeSimple($username),
                           $dbSocket->escapeSimple($attrName),
                           $dbSocket->escapeSimple($val));
            $dbSocket->query($sql);
            $attributesCount++;
        }
    }

    // 7. Handle Custom Attributes Array
    if (isset($data['attributes']) && is_array($data['attributes'])) {
        foreach ($data['attributes'] as $customAttr) {
            if (!is_array($customAttr) || empty($customAttr['attribute']) || !isset($customAttr['value'])) {
                continue;
            }
            $cAttrName = trim($customAttr['attribute']);
            $cAttrVal  = trim($customAttr['value']);
            $cAttrOp   = isset($customAttr['op']) ? trim($customAttr['op']) : ':=';
            $cAttrType = isset($customAttr['type']) ? strtolower(trim($customAttr['type'])) : 'check';

            $targetTable = ($cAttrType === 'reply') ? $configValues['CONFIG_DB_TBL_RADREPLY'] : $configValues['CONFIG_DB_TBL_RADCHECK'];

            if (is_passwordlike_attribute($cAttrName)) {
                $cAttrVal = hashPasswordAttribute($cAttrName, $cAttrVal);
            }

            $sql = sprintf("INSERT INTO %s (`id`, `username`, `attribute`, `op`, `value`) VALUES (0, '%s', '%s', '%s', '%s')",
                           $targetTable,
                           $dbSocket->escapeSimple($username),
                           $dbSocket->escapeSimple($cAttrName),
                           $dbSocket->escapeSimple($cAttrOp),
                           $dbSocket->escapeSimple($cAttrVal));
            $dbSocket->query($sql);
            $attributesCount++;
        }
    }

    // 8. Handle Groups / Profiles Mapping
    $groups = array();
    if (isset($data['groups'])) {
        $groups = is_array($data['groups']) ? $data['groups'] : explode(',', (string)$data['groups']);
    } else if (isset($data['group'])) {
        $groups = is_array($data['group']) ? $data['group'] : explode(',', (string)$data['group']);
    }

    $groupsCount = 0;
    if (!empty($groups)) {
        $cleanGroups = array();
        foreach ($groups as $g) {
            $g = trim($g);
            if (!empty($g)) {
                $cleanGroups[] = $g;
            }
        }
        if (!empty($cleanGroups)) {
            $groupsCount = insert_multiple_user_group_mappings($dbSocket, $username, $cleanGroups);
        }
    }

    // 9. Handle User Info (userinfo table)
    $currentDatetime = date('Y-m-d H:i:s');
    $userInfoInput = isset($data['user_info']) && is_array($data['user_info']) ? $data['user_info'] : $data;

    $portalPassword = isset($userInfoInput['portal_password']) ? $userInfoInput['portal_password'] :
                      (isset($userInfoInput['portalloginpassword']) ? $userInfoInput['portalloginpassword'] :
                      (isset($userInfoInput['portalLoginPassword']) ? $userInfoInput['portalLoginPassword'] : ''));

    $enablePortal = isset($userInfoInput['enable_portal_login']) ? $userInfoInput['enable_portal_login'] :
                    (isset($userInfoInput['enableportallogin']) ? $userInfoInput['enableportallogin'] :
                    (isset($userInfoInput['enableUserPortalLogin']) ? $userInfoInput['enableUserPortalLogin'] : '0'));

    $changeUserInfo = isset($userInfoInput['change_user_info']) ? $userInfoInput['change_user_info'] :
                      (isset($userInfoInput['changeuserinfo']) ? $userInfoInput['changeuserinfo'] :
                      (isset($userInfoInput['changeUserInfo']) ? $userInfoInput['changeUserInfo'] : '0'));

    $userInfoParams = array(
        'firstname'           => isset($userInfoInput['firstname']) ? $userInfoInput['firstname'] : '',
        'lastname'            => isset($userInfoInput['lastname']) ? $userInfoInput['lastname'] : '',
        'email'               => isset($userInfoInput['email']) ? $userInfoInput['email'] : '',
        'department'          => isset($userInfoInput['department']) ? $userInfoInput['department'] : '',
        'company'             => isset($userInfoInput['company']) ? $userInfoInput['company'] : '',
        'workphone'           => isset($userInfoInput['workphone']) ? $userInfoInput['workphone'] : '',
        'homephone'           => isset($userInfoInput['homephone']) ? $userInfoInput['homephone'] : '',
        'mobilephone'         => isset($userInfoInput['mobilephone']) ? $userInfoInput['mobilephone'] : '',
        'address'             => isset($userInfoInput['address']) ? $userInfoInput['address'] : '',
        'city'                => isset($userInfoInput['city']) ? $userInfoInput['city'] : '',
        'state'               => isset($userInfoInput['state']) ? $userInfoInput['state'] : '',
        'country'             => isset($userInfoInput['country']) ? $userInfoInput['country'] : '',
        'zip'                 => isset($userInfoInput['zip']) ? $userInfoInput['zip'] : '',
        'notes'               => isset($userInfoInput['notes']) ? $userInfoInput['notes'] : '',
        'changeuserinfo'      => ($enablePortal == '1' || !empty($portalPassword)) ? $changeUserInfo : '0',
        'enableportallogin'   => !empty($portalPassword) ? ($enablePortal ? '1' : '0') : '0',
        'portalloginpassword' => $portalPassword,
        'creationdate'        => $currentDatetime,
        'creationby'          => $operator,
    );

    $hasUserInfo = false;
    foreach ($userInfoParams as $k => $v) {
        if (!in_array($k, array('changeuserinfo', 'enableportallogin', 'creationdate', 'creationby')) && !empty($v)) {
            $hasUserInfo = true;
            break;
        }
    }

    $addedUserInfo = false;
    if ($hasUserInfo) {
        $addedUserInfo = add_user_info($dbSocket, $username, $userInfoParams);
    }

    // 10. Handle Billing Info (userbillinfo table)
    $billInfoInput = isset($data['billing_info']) && is_array($data['billing_info']) ? $data['billing_info'] : $data;

    $billParams = array(
        'planName'                 => isset($billInfoInput['plan_name']) ? $billInfoInput['plan_name'] :
                                      (isset($billInfoInput['planName']) ? $billInfoInput['planName'] : ''),
        'contactperson'            => isset($billInfoInput['contact_person']) ? $billInfoInput['contact_person'] :
                                      (isset($billInfoInput['contactperson']) ? $billInfoInput['contactperson'] : ''),
        'company'                  => isset($billInfoInput['bi_company']) ? $billInfoInput['bi_company'] :
                                      (isset($billInfoInput['company']) ? $billInfoInput['company'] : ''),
        'email'                    => isset($billInfoInput['bi_email']) ? $billInfoInput['bi_email'] :
                                      (isset($billInfoInput['email']) ? $billInfoInput['email'] : ''),
        'phone'                    => isset($billInfoInput['bi_phone']) ? $billInfoInput['bi_phone'] :
                                      (isset($billInfoInput['phone']) ? $billInfoInput['phone'] : ''),
        'address'                  => isset($billInfoInput['bi_address']) ? $billInfoInput['bi_address'] :
                                      (isset($billInfoInput['address']) ? $billInfoInput['address'] : ''),
        'city'                     => isset($billInfoInput['bi_city']) ? $billInfoInput['bi_city'] :
                                      (isset($billInfoInput['city']) ? $billInfoInput['city'] : ''),
        'state'                    => isset($billInfoInput['bi_state']) ? $billInfoInput['bi_state'] :
                                      (isset($billInfoInput['state']) ? $billInfoInput['state'] : ''),
        'country'                  => isset($billInfoInput['bi_country']) ? $billInfoInput['bi_country'] :
                                      (isset($billInfoInput['country']) ? $billInfoInput['country'] : ''),
        'zip'                      => isset($billInfoInput['bi_zip']) ? $billInfoInput['bi_zip'] :
                                      (isset($billInfoInput['zip']) ? $billInfoInput['zip'] : ''),
        'paymentmethod'            => isset($billInfoInput['payment_method']) ? $billInfoInput['payment_method'] :
                                      (isset($billInfoInput['paymentmethod']) ? $billInfoInput['paymentmethod'] : ''),
        'cash'                     => isset($billInfoInput['cash']) ? $billInfoInput['cash'] : '',
        'creditcardname'           => isset($billInfoInput['credit_card_name']) ? $billInfoInput['credit_card_name'] :
                                      (isset($billInfoInput['creditcardname']) ? $billInfoInput['creditcardname'] : ''),
        'creditcardnumber'         => isset($billInfoInput['credit_card_number']) ? $billInfoInput['credit_card_number'] :
                                      (isset($billInfoInput['creditcardnumber']) ? $billInfoInput['creditcardnumber'] : ''),
        'creditcardverification'   => isset($billInfoInput['credit_card_verification']) ? $billInfoInput['credit_card_verification'] :
                                      (isset($billInfoInput['creditcardverification']) ? $billInfoInput['creditcardverification'] : ''),
        'creditcardtype'           => isset($billInfoInput['credit_card_type']) ? $billInfoInput['credit_card_type'] :
                                      (isset($billInfoInput['creditcardtype']) ? $billInfoInput['creditcardtype'] : ''),
        'creditcardexp'            => isset($billInfoInput['credit_card_exp']) ? $billInfoInput['credit_card_exp'] :
                                      (isset($billInfoInput['creditcardexp']) ? $billInfoInput['creditcardexp'] : ''),
        'lead'                     => isset($billInfoInput['lead']) ? $billInfoInput['lead'] : '',
        'coupon'                   => isset($billInfoInput['coupon']) ? $billInfoInput['coupon'] : '',
        'ordertaker'               => isset($billInfoInput['ordertaker']) ? $billInfoInput['ordertaker'] : '',
        'notes'                    => isset($billInfoInput['bi_notes']) ? $billInfoInput['bi_notes'] :
                                      (isset($billInfoInput['notes']) ? $billInfoInput['notes'] : ''),
        'changeuserbillinfo'       => isset($billInfoInput['change_user_bill_info']) ? $billInfoInput['change_user_bill_info'] : '0',
        'billdue'                  => isset($billInfoInput['bill_due']) ? $billInfoInput['bill_due'] :
                                      (isset($billInfoInput['billdue']) ? $billInfoInput['billdue'] : ''),
        'nextinvoicedue'           => isset($billInfoInput['next_invoice_due']) ? $billInfoInput['next_invoice_due'] :
                                      (isset($billInfoInput['nextinvoicedue']) ? $billInfoInput['nextinvoicedue'] : ''),
        'creationdate'             => $currentDatetime,
        'creationby'               => $operator,
    );

    $hasBillInfo = false;
    foreach ($billParams as $k => $v) {
        if (!in_array($k, array('changeuserbillinfo', 'creationdate', 'creationby')) && !empty($v)) {
            $hasBillInfo = true;
            break;
        }
    }

    $addedBillingInfo = false;
    if ($hasBillInfo) {
        $addedBillingInfo = add_user_billing_info($dbSocket, $username, $billParams);
    }

    // Success response
    api_send_response(array(
        'username'         => $username,
        'password_type'    => $passwordType,
        'attributes_count' => $attributesCount,
        'groups_count'     => $groupsCount,
        'has_user_info'    => $addedUserInfo,
        'has_billing_info' => $addedBillingInfo,
    ), 201, sprintf('Account "%s" created successfully.', $username));
}


/**
 * =========================================================================
 * 2. GET ACCOUNT (GET)
 * =========================================================================
 */
function handle_get_account($dbSocket, $configValues, $data) {
    if (!isset($data['username']) || trim($data['username']) === '') {
        api_send_error('Parameter "username" is required to get account details.', 400);
    }

    $username = trim(str_replace('%', '', $data['username']));

    // Check user existence in radcheck or userinfo
    $existsInRadcheck = user_exists($dbSocket, $username, 'CONFIG_DB_TBL_RADCHECK');
    $existsInUserInfo = user_exists($dbSocket, $username, 'CONFIG_DB_TBL_DALOUSERINFO');

    if (!$existsInRadcheck && !$existsInUserInfo) {
        api_send_error(sprintf('Account "%s" not found.', $username), 404);
    }

    $accountData = array(
        'username' => $username,
    );

    // 1. Get Check Attributes (radcheck)
    $checkAttributes = array();
    $passwordType = null;
    $sql = sprintf("SELECT `id`, `attribute`, `op`, `value` FROM %s WHERE `username`='%s' ORDER BY `id` ASC",
                   $configValues['CONFIG_DB_TBL_RADCHECK'],
                   $dbSocket->escapeSimple($username));
    $res = $dbSocket->query($sql);
    if (!DB::isError($res)) {
        while ($row = $res->fetchRow(DB_FETCHMODE_ASSOC)) {
            if (is_passwordlike_attribute($row['attribute']) || $row['attribute'] === 'Auth-Type') {
                $passwordType = $row['attribute'];
            }
            $checkAttributes[] = array(
                'id'        => (int)$row['id'],
                'attribute' => $row['attribute'],
                'op'        => $row['op'],
                'value'     => $row['value'],
            );
        }
    }
    $accountData['password_type'] = $passwordType;
    $accountData['check_attributes'] = $checkAttributes;

    // 2. Get Reply Attributes (radreply)
    $replyAttributes = array();
    $sql = sprintf("SELECT `id`, `attribute`, `op`, `value` FROM %s WHERE `username`='%s' ORDER BY `id` ASC",
                   $configValues['CONFIG_DB_TBL_RADREPLY'],
                   $dbSocket->escapeSimple($username));
    $res = $dbSocket->query($sql);
    if (!DB::isError($res)) {
        while ($row = $res->fetchRow(DB_FETCHMODE_ASSOC)) {
            $replyAttributes[] = array(
                'id'        => (int)$row['id'],
                'attribute' => $row['attribute'],
                'op'        => $row['op'],
                'value'     => $row['value'],
            );
        }
    }
    $accountData['reply_attributes'] = $replyAttributes;

    // 3. Get User Groups (radusergroup)
    $groups = array();
    $sql = sprintf("SELECT `groupname`, `priority` FROM %s WHERE `username`='%s' ORDER BY `priority` ASC",
                   $configValues['CONFIG_DB_TBL_RADUSERGROUP'],
                   $dbSocket->escapeSimple($username));
    $res = $dbSocket->query($sql);
    $isDisabled = false;
    if (!DB::isError($res)) {
        while ($row = $res->fetchRow(DB_FETCHMODE_ASSOC)) {
            if ($row['groupname'] === 'daloRADIUS-Disabled-Users') {
                $isDisabled = true;
            }
            $groups[] = array(
                'groupname' => $row['groupname'],
                'priority'  => (int)$row['priority'],
            );
        }
    }
    $accountData['groups'] = $groups;

    // 4. Calculate Account Status (active / disabled / expired)
    $status = 'active';
    if ($isDisabled) {
        $status = 'disabled';
    } else {
        // Check Expiration attribute
        foreach ($checkAttributes as $attr) {
            if (strtolower($attr['attribute']) === 'expiration') {
                try {
                    $expTime = strtotime($attr['value']);
                    if ($expTime !== false && $expTime < time()) {
                        $status = 'expired';
                    }
                } catch (Exception $e) {}
                break;
            }
        }
    }
    $accountData['status'] = $status;

    // 5. Get User Info (userinfo)
    $userInfo = null;
    $sql = sprintf("SELECT * FROM %s WHERE `username`='%s' LIMIT 1",
                   $configValues['CONFIG_DB_TBL_DALOUSERINFO'],
                   $dbSocket->escapeSimple($username));
    $res = $dbSocket->query($sql);
    if (!DB::isError($res) && $res->numRows() === 1) {
        $userInfo = $res->fetchRow(DB_FETCHMODE_ASSOC);
        if (isset($userInfo['id'])) {
            $userInfo['id'] = (int)$userInfo['id'];
        }
    }
    $accountData['user_info'] = $userInfo;

    // 6. Get Billing Info (userbillinfo)
    $billingInfo = null;
    $sql = sprintf("SELECT * FROM %s WHERE `username`='%s' LIMIT 1",
                   $configValues['CONFIG_DB_TBL_DALOUSERBILLINFO'],
                   $dbSocket->escapeSimple($username));
    $res = $dbSocket->query($sql);
    if (!DB::isError($res) && $res->numRows() === 1) {
        $billingInfo = $res->fetchRow(DB_FETCHMODE_ASSOC);
        if (isset($billingInfo['id'])) {
            $billingInfo['id'] = (int)$billingInfo['id'];
        }
    }
    $accountData['billing_info'] = $billingInfo;

    // 7. Get Accounting Summary from radacct
    $usage = array(
        'total_sessions'  => 0,
        'upload_bytes'    => 0,
        'download_bytes'  => 0,
        'total_bytes'     => 0,
        'last_connection' => null,
    );
    $sql = sprintf("SELECT COUNT(radacctid) as total_sessions,
                           SUM(AcctInputOctets) as total_upload,
                           SUM(AcctOutputOctets) as total_download,
                           MAX(acctstarttime) as last_conn
                    FROM %s WHERE `username`='%s'",
                   $configValues['CONFIG_DB_TBL_RADACCT'],
                   $dbSocket->escapeSimple($username));
    $res = $dbSocket->query($sql);
    if (!DB::isError($res) && $res->numRows() > 0) {
        $row = $res->fetchRow(DB_FETCHMODE_ASSOC);
        $usage['total_sessions']  = (int)$row['total_sessions'];
        $usage['upload_bytes']    = (int)$row['total_upload'];
        $usage['download_bytes']  = (int)$row['total_download'];
        $usage['total_bytes']     = $usage['upload_bytes'] + $usage['download_bytes'];
        $usage['last_connection'] = $row['last_conn'];
    }
    $accountData['usage'] = $usage;

    api_send_response($accountData, 200);
}


/**
 * =========================================================================
 * 3. DELETE ACCOUNT (DELETE)
 * =========================================================================
 */
function handle_delete_account($dbSocket, $configValues, $data, $operator) {
    if (!isset($data['username']) || empty($data['username'])) {
        api_send_error('Field "username" is required for account deletion.', 400);
    }

    $usernames = is_array($data['username']) ? $data['username'] : explode(',', (string)$data['username']);
    $delradacct = isset($data['delradacct']) && (
        $data['delradacct'] === true ||
        $data['delradacct'] === 1 ||
        strtolower((string)$data['delradacct']) === 'yes' ||
        strtolower((string)$data['delradacct']) === 'true' ||
        (string)$data['delradacct'] === '1'
    );

    $cleanUsers = array();
    foreach ($usernames as $u) {
        $u = trim(str_replace('%', '', (string)$u));
        if (!empty($u) && !in_array($u, $cleanUsers)) {
            $cleanUsers[] = $u;
        }
    }

    if (empty($cleanUsers)) {
        api_send_error('No valid username provided for deletion.', 400);
    }

    $escapedUsers = array();
    foreach ($cleanUsers as $u) {
        $escapedUsers[] = $dbSocket->escapeSimple($u);
    }
    $usersInClause = "'" . implode("', '", $escapedUsers) . "'";

    // Check if at least one user exists
    $sql = sprintf("SELECT DISTINCT `username` FROM %s WHERE `username` IN (%s)",
                   $configValues['CONFIG_DB_TBL_RADCHECK'],
                   $usersInClause);
    $res = $dbSocket->query($sql);
    $foundInCheck = array();
    if (!DB::isError($res)) {
        while ($row = $res->fetchRow()) {
            $foundInCheck[] = $row[0];
        }
    }

    $sql = sprintf("SELECT DISTINCT `username` FROM %s WHERE `username` IN (%s)",
                   $configValues['CONFIG_DB_TBL_DALOUSERINFO'],
                   $usersInClause);
    $res = $dbSocket->query($sql);
    $foundInInfo = array();
    if (!DB::isError($res)) {
        while ($row = $res->fetchRow()) {
            $foundInInfo[] = $row[0];
        }
    }

    $foundTotal = array_unique(array_merge($foundInCheck, $foundInInfo));
    if (empty($foundTotal)) {
        api_send_error(sprintf('Account(s) "%s" not found.', implode(', ', $cleanUsers)), 404);
    }

    // Set postauth user field based on version
    $postauthUserField = 'username';
    if (isset($configValues['FREERADIUS_VERSION']) && $configValues['FREERADIUS_VERSION'] === '1') {
        $postauthUserField = 'user';
    }

    // Delete postauth records
    $sql = sprintf("DELETE FROM %s WHERE `%s` IN (%s)",
                   $configValues['CONFIG_DB_TBL_RADPOSTAUTH'],
                   $postauthUserField,
                   $usersInClause);
    $dbSocket->query($sql);

    // Delete from all user tables
    $tables = array(
        $configValues['CONFIG_DB_TBL_RADCHECK'],
        $configValues['CONFIG_DB_TBL_RADREPLY'],
        $configValues['CONFIG_DB_TBL_RADUSERGROUP'],
        $configValues['CONFIG_DB_TBL_DALOUSERINFO'],
        $configValues['CONFIG_DB_TBL_DALOUSERBILLINFO'],
    );

    if ($delradacct) {
        $tables[] = $configValues['CONFIG_DB_TBL_RADACCT'];
    }

    foreach ($tables as $tbl) {
        $sql = sprintf("DELETE FROM %s WHERE `username` IN (%s)", $tbl, $usersInClause);
        $dbSocket->query($sql);
    }

    api_send_response(array(
        'deleted_usernames' => $foundTotal,
        'count'             => count($foundTotal),
        'delradacct'        => $delradacct,
    ), 200, sprintf('%d account(s) deleted successfully.', count($foundTotal)));
}


/**
 * =========================================================================
 * 4. CHANGE ACCOUNT PASSWORD (PUT / PATCH / POST)
 * =========================================================================
 */
function handle_change_password($dbSocket, $configValues, $data, $operator, $valid_passwordTypes) {
    // 1. Validate Username
    if (!isset($data['username']) || trim($data['username']) === '') {
        api_send_error('Field "username" is required to change password.', 400);
    }
    $username = trim(str_replace('%', '', $data['username']));

    // 2. Validate New Password
    $password = null;
    if (isset($data['password']) && (string)$data['password'] !== '') {
        $password = (string)$data['password'];
    } else if (isset($data['new_password']) && (string)$data['new_password'] !== '') {
        $password = (string)$data['new_password'];
    } else if (isset($data['newPassword']) && (string)$data['newPassword'] !== '') {
        $password = (string)$data['newPassword'];
    }

    if ($password === null) {
        api_send_error('Field "password" (or "new_password") is required.', 400);
    }

    // 3. Check if user exists
    $exists = user_exists($dbSocket, $username, 'CONFIG_DB_TBL_RADCHECK') ||
              user_exists($dbSocket, $username, 'CONFIG_DB_TBL_DALOUSERINFO');
    if (!$exists) {
        api_send_error(sprintf('Account "%s" not found.', $username), 404);
    }

    // 4. Determine Password Type
    $passwordType = null;
    if (isset($data['password_type']) && !empty(trim($data['password_type']))) {
        $passwordType = trim($data['password_type']);
    } else if (isset($data['passwordType']) && !empty(trim($data['passwordType']))) {
        $passwordType = trim($data['passwordType']);
    }

    // If not provided in request, check existing password attribute for user
    if ($passwordType === null) {
        $sql = sprintf("SELECT `attribute` FROM %s WHERE `username`='%s' AND (`attribute` LIKE '%%-Password' OR `attribute`='Auth-Type' OR `attribute`='User-Password') LIMIT 1",
                       $configValues['CONFIG_DB_TBL_RADCHECK'],
                       $dbSocket->escapeSimple($username));
        $res = $dbSocket->query($sql);
        if (!DB::isError($res) && $res->numRows() > 0) {
            $row = $res->fetchRow();
            $passwordType = $row[0];
        } else {
            $passwordType = 'Cleartext-Password';
        }
    }

    if (!in_array($passwordType, $valid_passwordTypes)) {
        api_send_error(sprintf('Invalid password_type "%s". Supported types: %s', $passwordType, implode(', ', $valid_passwordTypes)), 400);
    }

    // Hash password value
    $hashedPassword = hashPasswordAttribute($passwordType, $password);
    if ($hashedPassword === false) {
        $hashedPassword = $password;
    }

    // 5. Update or Insert Password Attribute in radcheck
    $sql = sprintf("SELECT `id` FROM %s WHERE `username`='%s' AND (`attribute` LIKE '%%-Password' OR `attribute`='Auth-Type' OR `attribute`='User-Password')",
                   $configValues['CONFIG_DB_TBL_RADCHECK'],
                   $dbSocket->escapeSimple($username));
    $res = $dbSocket->query($sql);
    $existingPassAttrCount = (!DB::isError($res)) ? $res->numRows() : 0;

    if ($existingPassAttrCount > 0) {
        // Update existing password attributes
        $sql = sprintf("UPDATE %s SET `attribute`='%s', `op`=':=', `value`='%s' WHERE `username`='%s' AND (`attribute` LIKE '%%-Password' OR `attribute`='Auth-Type' OR `attribute`='User-Password')",
                       $configValues['CONFIG_DB_TBL_RADCHECK'],
                       $dbSocket->escapeSimple($passwordType),
                       $dbSocket->escapeSimple($hashedPassword),
                       $dbSocket->escapeSimple($username));
        $resUpdate = $dbSocket->query($sql);
        if (DB::isError($resUpdate)) {
            api_send_error('Failed to update password in database: ' . $resUpdate->getMessage(), 500);
        }
    } else {
        // Insert new password attribute
        $sql = sprintf("INSERT INTO %s (`id`, `username`, `attribute`, `op`, `value`) VALUES (0, '%s', '%s', ':=', '%s')",
                       $configValues['CONFIG_DB_TBL_RADCHECK'],
                       $dbSocket->escapeSimple($username),
                       $dbSocket->escapeSimple($passwordType),
                       $dbSocket->escapeSimple($hashedPassword));
        $resInsert = $dbSocket->query($sql);
        if (DB::isError($resInsert)) {
            api_send_error('Failed to insert password in database: ' . $resInsert->getMessage(), 500);
        }
    }

    // 6. Optional: Update Portal Login Password if requested
    $portalPassword = null;
    if (isset($data['portal_password'])) {
        $portalPassword = (string)$data['portal_password'];
    } else if (isset($data['portalloginpassword'])) {
        $portalPassword = (string)$data['portalloginpassword'];
    } else if (isset($data['update_portal_password']) && ($data['update_portal_password'] === true || $data['update_portal_password'] === 1 || $data['update_portal_password'] === 'true' || $data['update_portal_password'] === '1')) {
        // Sync portal password with the new account password
        $portalPassword = $password;
    }

    $portalUpdated = false;
    if ($portalPassword !== null) {
        $currentDatetime = date('Y-m-d H:i:s');
        if (user_exists($dbSocket, $username, 'CONFIG_DB_TBL_DALOUSERINFO')) {
            $sql = sprintf("UPDATE %s SET `portalloginpassword`='%s', `updatedate`='%s', `updateby`='%s' WHERE `username`='%s'",
                           $configValues['CONFIG_DB_TBL_DALOUSERINFO'],
                           $dbSocket->escapeSimple($portalPassword),
                           $currentDatetime,
                           $dbSocket->escapeSimple($operator),
                           $dbSocket->escapeSimple($username));
            $dbSocket->query($sql);
            $portalUpdated = true;
        }
    }

    api_send_response(array(
        'username'       => $username,
        'password_type'  => $passwordType,
        'portal_updated' => $portalUpdated,
    ), 200, sprintf('Password for account "%s" changed successfully.', $username));
}
