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
 * Description:    API Root Information and Endpoint Directory
 *
 *********************************************************************************************************
 */

require_once(__DIR__ . '/../../common/includes/api_response.php');

api_send_response(array(
    'api'         => 'daloRADIUS REST API',
    'version'     => 'v1',
    'status'      => 'online',
    'endpoints'   => array(
        'accounts' => array(
            'url'         => '/api/v1/accounts.php',
            'methods'     => array('GET', 'POST', 'PUT', 'PATCH', 'DELETE'),
            'description' => 'RADIUS account management (Add, Get, Delete, Change Password)',
            'actions'     => array(
                'POST /api/v1/accounts.php'         => 'Create a new RADIUS account',
                'GET /api/v1/accounts.php?username' => 'Retrieve account details, attributes, and usage stats',
                'DELETE /api/v1/accounts.php'       => 'Delete an account and related profile info',
                'PUT /api/v1/accounts.php'          => 'Change account password',
            ),
        ),
    ),
    'authentication' => array(
        'basic_auth'  => 'HTTP Basic Auth with operator username/password',
        'bearer_token'=> 'Authorization: Bearer <API_KEY_OR_TOKEN>',
        'header_key'  => 'X-API-Key: <API_KEY_OR_TOKEN>',
        'session'     => 'Operator web session cookie',
    ),
), 200, 'Welcome to daloRADIUS API');
