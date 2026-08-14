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
 * Description:    JSON API response helper utilities
 *
 *********************************************************************************************************
 */

// Send CORS and JSON Content-Type headers
function api_send_headers() {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Requested-With');

    // Handle preflight OPTIONS request
    if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

/**
 * Send a structured JSON response and terminate script
 *
 * @param mixed $data Data payload to return
 * @param int $statusCode HTTP status code (e.g. 200, 201, 400, 404, 500)
 * @param string|null $message Human-readable message
 * @param bool $success Success status flag
 */
function api_send_response($data = null, $statusCode = 200, $message = null, $success = true) {
    api_send_headers();
    http_response_code($statusCode);

    $response = array(
        'success' => (bool)$success,
        'status'  => (int)$statusCode,
    );

    if ($message !== null) {
        $response['message'] = (string)$message;
    }

    if ($data !== null) {
        $response['data'] = $data;
    }

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * Send a JSON error response and terminate script
 *
 * @param string $message Error message
 * @param int $statusCode HTTP status code (e.g. 400, 401, 403, 404, 409, 500)
 * @param mixed $details Optional error details or validation errors
 */
function api_send_error($message, $statusCode = 400, $details = null) {
    api_send_headers();
    http_response_code($statusCode);

    $response = array(
        'success' => false,
        'status'  => (int)$statusCode,
        'error'   => (string)$message,
    );

    if ($details !== null) {
        $response['details'] = $details;
    }

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * Parse incoming request payload (JSON or Form URL encoded)
 *
 * @return array Parsed key-value array
 */
function api_get_request_data() {
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
    $contentType = isset($_SERVER['CONTENT_TYPE']) ? strtolower($_SERVER['CONTENT_TYPE']) : '';

    $data = array();

    // Query parameters
    if (!empty($_GET)) {
        $data = array_merge($data, $_GET);
    }

    // POST form data
    if (!empty($_POST)) {
        $data = array_merge($data, $_POST);
    }

    // Raw body input (e.g. JSON or PUT/DELETE payload)
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        if (strpos($contentType, 'application/json') !== false || ($rawInput[0] === '{' || $rawInput[0] === '[')) {
            $jsonData = json_decode($rawInput, true);
            if (is_array($jsonData)) {
                $data = array_merge($data, $jsonData);
            }
        } else if ($method === 'PUT' || $method === 'PATCH' || $method === 'DELETE') {
            parse_str($rawInput, $parsedData);
            if (is_array($parsedData)) {
                $data = array_merge($data, $parsedData);
            }
        }
    }

    return $data;
}
