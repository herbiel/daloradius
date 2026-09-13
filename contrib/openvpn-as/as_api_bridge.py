#!/usr/bin/env python3
"""
OpenVPN Access Server (AS) API Bridge for daloRADIUS
Enables daloRADIUS to synchronize user properties and generate/retrieve
.ovpn client profiles via OpenVPN AS's sacli CLI tool over HTTP REST.
"""

import json
import os
import subprocess
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs

SACLI = os.environ.get("AS_SACLI_PATH", "/usr/local/openvpn_as/scripts/sacli")
AUTH_TOKEN = os.environ.get("AS_API_TOKEN", "tangbull-openvpn-as-secret-key")
PORT = int(os.environ.get("AS_API_PORT", "9443"))

def run_sacli(*args):
    cmd = [SACLI] + list(args)
    p = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    return p.returncode, p.stdout.strip(), p.stderr.strip()

class ASHandler(BaseHTTPRequestHandler):
    def log_message(self, format, *args):
        sys_msg = "%s - - [%s] %s\n" % (self.address_string(), self.log_date_time_string(), format % args)
        print(sys_msg.strip(), flush=True)

    def check_auth(self):
        auth = self.headers.get("Authorization", "")
        if auth.startswith("Bearer "):
            token = auth[7:].strip()
            if token == AUTH_TOKEN:
                return True
        token_hdr = self.headers.get("X-API-Token", "")
        if token_hdr == AUTH_TOKEN:
            return True
        return False

    def send_json(self, status_code, data):
        self.send_response(status_code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.end_headers()
        self.wfile.write(json.dumps(data).encode("utf-8"))

    def do_GET(self):
        parsed = urlparse(self.path)
        if parsed.path == "/api/health":
            self.send_json(200, {"status": "ok", "service": "openvpn_as_bridge"})
            return

        if not self.check_auth():
            self.send_json(401, {"success": False, "error": "Unauthorized"})
            return

        qs = parse_qs(parsed.query)
        if parsed.path == "/api/user/profile":
            username = qs.get("username", [""])[0]
            ptype = qs.get("type", ["userlogin"])[0]
            if not username:
                self.send_json(400, {"success": False, "error": "Username required"})
                return
            cmd = "GetAutologin" if ptype == "autologin" else "GetUserlogin"
            code, out, err = run_sacli("-u", username, cmd)
            if code == 0 and out:
                self.send_json(200, {"success": True, "profile": out})
            else:
                self.send_json(500, {"success": False, "error": err or out or "Failed to get profile"})
            return

        self.send_json(404, {"error": "Not found"})

    def do_POST(self):
        if not self.check_auth():
            self.send_json(401, {"success": False, "error": "Unauthorized"})
            return

        parsed = urlparse(self.path)
        content_length = int(self.headers.get("Content-Length", 0))
        body = self.rfile.read(content_length).decode("utf-8") if content_length > 0 else "{}"
        try:
            data = json.loads(body)
        except Exception:
            data = {}

        username = data.get("username", "").strip()
        if not username:
            self.send_json(400, {"success": False, "error": "Username required"})
            return

        group = data.get("group", "").strip()
        ptype = data.get("type", "userlogin").strip()

        if parsed.path in ["/api/user/sync", "/api/user/create_and_profile"]:
            code, out, err = run_sacli("--user", username, "--key", "type", "--value", "user_connect", "UserPropPut")
            if code != 0:
                self.send_json(500, {"success": False, "error": f"Failed to put user: {err or out}"})
                return
            if group:
                run_sacli("--user", username, "--key", "conn_group", "--value", group, "UserPropPut")

            if parsed.path == "/api/user/create_and_profile":
                cmd = "GetAutologin" if ptype == "autologin" else "GetUserlogin"
                code, out, err = run_sacli("-u", username, cmd)
                if code == 0 and out:
                    self.send_json(200, {"success": True, "message": "User created and profile generated", "profile": out})
                else:
                    self.send_json(500, {"success": False, "error": f"User created but profile failed: {err or out}"})
                return

            self.send_json(200, {"success": True, "message": "User synchronized successfully"})
            return

        self.send_json(404, {"error": "Not found"})

if __name__ == "__main__":
    server = HTTPServer(("0.0.0.0", PORT), ASHandler)
    print(f"OpenVPN AS API Bridge listening on port {PORT}...", flush=True)
    server.serve_forever()
