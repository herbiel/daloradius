# daloRADIUS REST API Documentation

The daloRADIUS REST API allows external systems, client applications, and automation scripts to interact programmatically with daloRADIUS to manage RADIUS user accounts.

---

## Base URL

- **Docker Environment:** `http://<host>:8000/api/v1`
- **Standard Apache/Nginx:** `http://<host>/daloradius/app/operators/api/v1`
- **Root Alias:** `/api/accounts.php` proxies directly to `/api/v1/accounts.php`

---

## Authentication

All requests to the daloRADIUS API require authentication. Three authentication methods are supported:

### 1. HTTP Basic Authentication (Recommended for scripts)
Pass your daloRADIUS operator credentials via HTTP Basic Auth:
```bash
curl -u "administrator:radius" http://localhost:8000/api/v1/accounts.php?username=john_doe
```

### 2. Bearer Token / API Key Header
Pass an API key or operator token via `Authorization: Bearer` or `X-API-Key` header:
```bash
curl -H "Authorization: Bearer YOUR_API_KEY" http://localhost:8000/api/v1/accounts.php?username=john_doe
# OR
curl -H "X-API-Key: YOUR_API_KEY" http://localhost:8000/api/v1/accounts.php?username=john_doe
```
*(You can configure `$configValues['CONFIG_API_KEY'] = 'your-secret-key';` in `daloradius.conf.php`)*.

### 3. Session Authentication
If making requests from a web browser with an active daloRADIUS operator login session, cookies will authenticate the request automatically.

---

## Endpoints

### 1. Add Account

Creates a new RADIUS account with attributes, profile groups, contact info, and billing configuration.

- **Method:** `POST`
- **URL:** `/api/v1/accounts.php` (or `/api/accounts.php`)
- **Headers:** `Content-Type: application/json`

#### Request Body Parameters:

| Parameter | Type | Required | Description |
|---|---|---|---|
| `username` | string | **Yes** | RADIUS username |
| `password` | string | **Yes** | User password |
| `password_type` | string | No | `Cleartext-Password` (default), `MD5-Password`, `SHA1-Password`, `Crypt-Password`, `NT-Password`, `User-Password` |
| `groups` | array/string | No | Group or list of groups (e.g. `["Gold_Plan"]` or `"Gold_Plan,Support"`) |
| `max_all_session` | int/string | No | `Max-All-Session` check attribute (total allowed session time in seconds) |
| `simultaneous_use` | int/string | No | `Simultaneous-Use` check attribute |
| `expiration` | string | No | Account expiration date (e.g. `2026-12-31` or `31 Dec 2026`) |
| `session_timeout` | int/string | No | `Session-Timeout` reply attribute in seconds |
| `idle_timeout` | int/string | No | `Idle-Timeout` reply attribute in seconds |
| `framed_ip_address` | string | No | `Framed-IP-Address` reply attribute (e.g. `192.168.1.100`) |
| `attributes` | array | No | Array of custom check/reply attributes: `[{"attribute": "Mikrotik-Rate-Limit", "op": ":=", "value": "10M/10M", "type": "reply"}]` |
| `user_info` | object | No | User info fields: `firstname`, `lastname`, `email`, `department`, `company`, `workphone`, `homephone`, `mobilephone`, `address`, `city`, `state`, `country`, `zip`, `notes`, `portal_password`, `enable_portal_login` |
| `billing_info` | object | No | Billing fields: `plan_name`, `contact_person`, `payment_method`, `cash`, `credit_card_name`, `credit_card_number`, etc. |

#### Example Request:
```bash
curl -X POST http://localhost:8000/api/v1/accounts.php \
  -u "administrator:radius" \
  -H "Content-Type: application/json" \
  -d '{
    "username": "john_doe",
    "password": "SecretPassword123!",
    "password_type": "Cleartext-Password",
    "groups": ["Gold_Plan"],
    "session_timeout": 3600,
    "idle_timeout": 600,
    "user_info": {
      "firstname": "John",
      "lastname": "Doe",
      "email": "john.doe@example.com",
      "company": "Acme Corp"
    }
  }'
```

#### Example Response (`201 Created`):
```json
{
  "success": true,
  "status": 201,
  "message": "Account \"john_doe\" created successfully.",
  "data": {
    "username": "john_doe",
    "password_type": "Cleartext-Password",
    "attributes_count": 3,
    "groups_count": 1,
    "has_user_info": true,
    "has_billing_info": false
  }
}
```

---

### 2. Get Account

Retrieves account details including status (active, disabled, expired), check attributes, reply attributes, group memberships, user profile info, billing info, and accounting usage statistics.

- **Method:** `GET`
- **URL:** `/api/v1/accounts.php?username={username}`

#### Example Request:
```bash
curl -X GET "http://localhost:8000/api/v1/accounts.php?username=john_doe" \
  -u "administrator:radius"
```

#### Example Response (`200 OK`):
```json
{
  "success": true,
  "status": 200,
  "data": {
    "username": "john_doe",
    "status": "active",
    "password_type": "Cleartext-Password",
    "check_attributes": [
      {
        "id": 101,
        "attribute": "Cleartext-Password",
        "op": ":=",
        "value": "SecretPassword123!"
      }
    ],
    "reply_attributes": [
      {
        "id": 55,
        "attribute": "Session-Timeout",
        "op": ":=",
        "value": "3600"
      },
      {
        "id": 56,
        "attribute": "Idle-Timeout",
        "op": ":=",
        "value": "600"
      }
    ],
    "groups": [
      {
        "groupname": "Gold_Plan",
        "priority": 0
      }
    ],
    "user_info": {
      "id": 42,
      "username": "john_doe",
      "firstname": "John",
      "lastname": "Doe",
      "email": "john.doe@example.com",
      "company": "Acme Corp"
    },
    "billing_info": null,
    "usage": {
      "total_sessions": 5,
      "upload_bytes": 10485760,
      "download_bytes": 52428800,
      "total_bytes": 62914560,
      "last_connection": "2026-08-14 10:15:30"
    }
  }
}
```

---

### 3. Change Account Password

Updates the password for an existing account. The password is automatically hashed according to the user's password type or the specified `password_type`.

- **Method:** `PUT` or `PATCH` (or `POST` with `action=change_password`)
- **URL:** `/api/v1/accounts.php`
- **Headers:** `Content-Type: application/json`

#### Request Body Parameters:

| Parameter | Type | Required | Description |
|---|---|---|---|
| `username` | string | **Yes** | RADIUS username |
| `password` | string | **Yes** | New password (or `new_password`) |
| `password_type` | string | No | Optional new password type (e.g. `Cleartext-Password`, `MD5-Password`, `SHA1-Password`, `Crypt-Password`, `NT-Password`). If omitted, preserves existing type. |
| `portal_password` | string | No | Optional new password for User Portal login |
| `update_portal_password` | bool | No | Set to `true` to sync portal password with the new account password |

#### Example Request:
```bash
curl -X PUT http://localhost:8000/api/v1/accounts.php \
  -u "administrator:radius" \
  -H "Content-Type: application/json" \
  -d '{
    "username": "john_doe",
    "password": "NewSuperSecretPassword456!",
    "password_type": "Cleartext-Password"
  }'
```

#### Example Response (`200 OK`):
```json
{
  "success": true,
  "status": 200,
  "message": "Password for account \"john_doe\" changed successfully.",
  "data": {
    "username": "john_doe",
    "password_type": "Cleartext-Password",
    "portal_updated": false
  }
}
```

---

### 4. Delete Account

Deletes a user account from `radcheck`, `radreply`, `radusergroup`, `userinfo`, `userbillinfo`, and `radpostauth`. Optionally deletes accounting history from `radacct`.

- **Method:** `DELETE` (or `POST` with `action=delete`)
- **URL:** `/api/v1/accounts.php?username={username}`
- **Headers:** `Content-Type: application/json`

#### Parameters:

| Parameter | Type | Required | Description |
|---|---|---|---|
| `username` | string/array | **Yes** | Username or comma-separated list of usernames |
| `delradacct` | bool/string | No | Set to `true` or `"yes"` to also delete accounting history from `radacct` (default `false`) |

#### Example Request:
```bash
curl -X DELETE "http://localhost:8000/api/v1/accounts.php?username=john_doe&delradacct=true" \
  -u "administrator:radius"
```

#### Example Response (`200 OK`):
```json
{
  "success": true,
  "status": 200,
  "message": "1 account(s) deleted successfully.",
  "data": {
    "deleted_usernames": [
      "john_doe"
    ],
    "count": 1,
    "delradacct": true
  }
}
```

---

## Form-Only / Action Parameter Compatibility

For HTTP clients or tools that only support standard `POST` and `GET` requests, you can specify the `action` parameter:

| Desired Action | Method | URL & Parameters |
|---|---|---|
| Add Account | `POST` | `/api/v1/accounts.php` with `username` and `password` |
| Get Account | `GET` or `POST` | `/api/v1/accounts.php?username=john_doe` or `action=get` |
| Change Password | `POST` | `/api/v1/accounts.php` with `action=change_password`, `username`, and `password` |
| Delete Account | `POST` | `/api/v1/accounts.php` with `action=delete`, `username` |
