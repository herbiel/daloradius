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
 * Description:    API Authentication middleware supporting Basic Auth,
 *                 API Key / Bearer token, and Session authentication.
 *
 *********************************************************************************************************
 */

require_once(__DIR__ . '/api_response.php');

/**
 * Extract Authorization header across various server environments
 *
 * @return string|null
 */
function api_get_auth_header() {
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        return trim($_SERVER['HTTP_AUTHORIZATION']);
    }
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (isset($headers['Authorization'])) {
            return trim($headers['Authorization']);
        }
        if (isset($headers['authorization'])) {
            return trim($headers['authorization']);
        }
    }
    return null;
}

/**
 * Authenticate incoming API request
 *
 * @param DB $dbSocket Database connection object
 * @param array $configValues Configuration parameters
 * @return array Authenticated operator/client information
 */
function api_authenticate($dbSocket, $configValues) {
    // If API authentication is explicitly disabled in config
    if (isset($configValues['CONFIG_API_REQUIRE_AUTH']) &&
        strtolower(trim($configValues['CONFIG_API_REQUIRE_AUTH'])) === 'no') {
        return array(
            'authenticated' => true,
            'auth_type'     => 'none',
            'operator'      => 'api-anonymous',
        );
    }

    // 1. Check existing web session
    if (session_status() === PHP_SESSION_NONE) {
        if (file_exists(__DIR__ . '/../../operators/library/sessions.php')) {
            include_once(__DIR__ . '/../../operators/library/sessions.php');
            if (function_exists('dalo_session_start')) {
                dalo_session_start();
            } else {
                @session_start();
            }
        } else {
            @session_start();
        }
    }

    if (isset($_SESSION['daloradius_logged_in']) && $_SESSION['daloradius_logged_in'] === true) {
        $operator = isset($_SESSION['operator_user']) ? $_SESSION['operator_user'] : 'administrator';
        return array(
            'authenticated' => true,
            'auth_type'     => 'session',
            'operator'      => $operator,
        );
    }

    // 2. Check X-API-Key or Bearer Token
    $apiKey = null;
    if (isset($_SERVER['HTTP_X_API_KEY'])) {
        $apiKey = trim($_SERVER['HTTP_X_API_KEY']);
    }

    $authHeader = api_get_auth_header();
    if ($apiKey === null && $authHeader !== null && stripos($authHeader, 'Bearer ') === 0) {
        $apiKey = trim(substr($authHeader, 7));
    }

    if ($apiKey !== null && !empty($apiKey)) {
        // Check against CONFIG_API_KEY in configValues
        if (isset($configValues['CONFIG_API_KEY']) && !empty($configValues['CONFIG_API_KEY'])) {
            if (hash_equals($configValues['CONFIG_API_KEY'], $apiKey)) {
                if (!isset($_SESSION['operator_user'])) {
                    $_SESSION['operator_user'] = 'api-key';
                }
                return array(
                    'authenticated' => true,
                    'auth_type'     => 'api_key',
                    'operator'      => 'api-key',
                );
            }
        }

        // Also check if the API key matches an operator password/token
        $sql = sprintf("SELECT username, id FROM %s WHERE password='%s' LIMIT 1",
                       $configValues['CONFIG_DB_TBL_DALOOPERATORS'],
                       $dbSocket->escapeSimple($apiKey));
        $res = $dbSocket->query($sql);
        if (!DB::isError($res) && $res->numRows() === 1) {
            $row = $res->fetchRow(DB_FETCHMODE_ASSOC);
            $_SESSION['operator_user'] = $row['username'];
            $_SESSION['operator_id'] = $row['id'];
            return array(
                'authenticated' => true,
                'auth_type'     => 'operator_token',
                'operator'      => $row['username'],
                'operator_id'   => $row['id'],
            );
        }
    }

    // 3. Check HTTP Basic Authentication
    $authUser = null;
    $authPass = null;

    if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
        $authUser = $_SERVER['PHP_AUTH_USER'];
        $authPass = $_SERVER['PHP_AUTH_PW'];
    } else if ($authHeader !== null && stripos($authHeader, 'Basic ') === 0) {
        $decoded = base64_decode(substr($authHeader, 6));
        if ($decoded && strpos($decoded, ':') !== false) {
            list($authUser, $authPass) = explode(':', $decoded, 2);
        }
    }

    if ($authUser !== null && $authPass !== null) {
        $sql = sprintf("SELECT id, username, password FROM %s WHERE username='%s' AND password='%s'",
                       $configValues['CONFIG_DB_TBL_DALOOPERATORS'],
                       $dbSocket->escapeSimple($authUser),
                       $dbSocket->escapeSimple($authPass));
        $res = $dbSocket->query($sql);

        if (!DB::isError($res) && $res->numRows() === 1) {
            $row = $res->fetchRow(DB_FETCHMODE_ASSOC);
            $_SESSION['daloradius_logged_in'] = true;
            $_SESSION['operator_user'] = $row['username'];
            $_SESSION['operator_id'] = $row['id'];

            return array(
                'authenticated' => true,
                'auth_type'     => 'basic_auth',
                'operator'      => $row['username'],
                'operator_id'   => $row['id'],
            );
        }
    }

    // If authentication failed
    header('WWW-Authenticate: Basic realm="daloRADIUS API"');
    api_send_error('Unauthorized: Invalid or missing credentials. Provide HTTP Basic Auth (operator username/password) or Bearer/X-API-Key token.', 401);
}
