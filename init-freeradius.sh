#!/bin/bash
# Executable process script for daloRADIUS freeradius docker image:
# GitHub: git@github.com:lirantal/daloradius.git
RADIUS_PATH=/etc/freeradius

function init_freeradius {
	# Enable SQL in freeradius
	sed -i 's|driver = "rlm_sql_null"|driver = "rlm_sql_mysql"|' $RADIUS_PATH/mods-available/sql
	sed -i 's|dialect = "sqlite"|dialect = "mysql"|' $RADIUS_PATH/mods-available/sql
	sed -i 's|dialect = ${modules.sql.dialect}|dialect = "mysql"|' $RADIUS_PATH/mods-available/sqlcounter # avoid instantiation error
	sed -i 's|ca_file = "/etc/ssl/certs/my_ca.crt"|#ca_file = "/etc/ssl/certs/my_ca.crt"|' $RADIUS_PATH/mods-available/sql #disable sql encryption
        sed -i 's|ca_path = "/etc/ssl/certs/"|#ca_path = "/etc/ssl/certs/"|' $RADIUS_PATH/mods-available/sql #disable sql encryption
	sed -i 's|certificate_file = "/etc/ssl/certs/private/client.crt"|#certificate_file = "/etc/ssl/certs/private/client.crt"|' $RADIUS_PATH/mods-available/sql #disable sql encryption
	sed -i 's|private_key_file = "/etc/ssl/certs/private/client.key"|#private_key_file = "/etc/ssl/certs/private/client.key"|' $RADIUS_PATH/mods-available/sql #disable sql encryption
	sed -i 's|tls_required = yes|tls_required = no|' $RADIUS_PATH/mods-available/sql #disable sql encryption
	sed -i 's|#\s*read_clients = yes|read_clients = yes|' $RADIUS_PATH/mods-available/sql
	ln -s $RADIUS_PATH/mods-available/sql $RADIUS_PATH/mods-enabled/sql
	ln -s $RADIUS_PATH/mods-available/sqlcounter $RADIUS_PATH/mods-enabled/sqlcounter
	ln -s $RADIUS_PATH/mods-available/sqlippool $RADIUS_PATH/mods-enabled/sqlippool
	sed -i 's|instantiate {|instantiate {\nsql|' $RADIUS_PATH/radiusd.conf # mods-enabled does not ensure the right order

	# Enable used tunnel for unifi
	sed -i 's|use_tunneled_reply = no|use_tunneled_reply = yes|' $RADIUS_PATH/mods-available/eap

	# Enable status in freeadius
	ln -s $RADIUS_PATH/sites-available/status $RADIUS_PATH/sites-enabled/status

	# Set Database connection
	sed -i 's|^#\s*server = .*|server = "'$MYSQL_HOST'"|' $RADIUS_PATH/mods-available/sql
	sed -i 's|^#\s*port = .*|port = "'$MYSQL_PORT'"|' $RADIUS_PATH/mods-available/sql
	sed -i '1,$s/radius_db.*/radius_db="'$MYSQL_DATABASE'"/g' $RADIUS_PATH/mods-available/sql
	sed -i 's|^#\s*password = .*|password = "'$MYSQL_PASSWORD'"|' $RADIUS_PATH/mods-available/sql
	sed -i 's|^#\s*login = .*|login = "'$MYSQL_USER'"|' $RADIUS_PATH/mods-available/sql

	if [ -n "$DEFAULT_CLIENT_SECRET" ]; then
		sed -i 's|testing123|'$DEFAULT_CLIENT_SECRET'|' $RADIUS_PATH/mods-available/sql
	fi
	echo "freeradius initialization completed."
}

function init_database {
	mysql -h "$MYSQL_HOST" -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < $RADIUS_PATH/mods-config/sql/main/mysql/schema.sql
	mysql -h "$MYSQL_HOST" -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < $RADIUS_PATH/mods-config/sql/ippool/mysql/schema.sql

	# Insert a client for the current subnet (to allow daloradius to perform checks)
	IP=`ifconfig eth0 | awk '/inet/{ print $2;} '` # does also work: $IP=`hostname -I | awk '{print $1}'`
	NM=`ifconfig eth0 | awk '/netmask/{ print $4;} '`
	CIDR=`ipcalc $IP $NM | awk '/Network/{ print $2;} '`
	SECRET=testing123
	if [ -n "$DEFAULT_CLIENT_SECRET" ]; then
		SECRET=$DEFAULT_CLIENT_SECRET
	fi
	echo "Adding client for $CIDR with default secret $SECRET"
	mysql -h "$MYSQL_HOST" -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "INSERT INTO nas (nasname,shortname,type,ports,secret,server,community,description) VALUES ('$CIDR','DOCKER NET','other',0,'$SECRET',NULL,'','')"

	echo "Database initialization for freeradius completed."
}

# Applied on every container start so WiFi (PEAP) is not reset to MD5 after rebuild/restart.
function configure_eap {
	EAP_TYPE=${RADIUS_DEFAULT_EAP_TYPE:-peap}
	EAP_INNER_TYPE=${RADIUS_PEAP_INNER_EAP_TYPE:-mschapv2}
	EAP_CONF=$RADIUS_PATH/mods-available/eap

	# Outer default only (first occurrence). Nested peap/ttls blocks have their own default_eap_type.
	if grep -qE '^\s*default_eap_type\s*=' "$EAP_CONF"; then
		sed -i '0,/^\s*default_eap_type\s*=/s/^\s*default_eap_type\s*=.*/\tdefault_eap_type = '"$EAP_TYPE"'/' "$EAP_CONF"
		echo "EAP outer default_eap_type set to $EAP_TYPE"
	fi

	# PEAP inner tunnel must authenticate with mschapv2/pap — never "peap".
	if grep -qE '^\s*peap\s*\{' "$EAP_CONF"; then
		sed -i '/^\s*peap\s*{/,/^\s*\}/ s/^\s*default_eap_type\s*=.*/\t\tdefault_eap_type = '"$EAP_INNER_TYPE"'/' "$EAP_CONF"
		echo "PEAP inner default_eap_type set to $EAP_INNER_TYPE"
	fi

	sed -i 's|use_tunneled_reply = no|use_tunneled_reply = yes|' "$EAP_CONF"
}

# Install TLS certs for PEAP/TTLS on every container start (supports ACME renew + restart).
function sync_radius_certs {
	CERT_SRC=${RADIUS_CERT_DIR:-/opt/radius-certs}
	RADIUS_CERT_PATH=$RADIUS_PATH/certs
	KEY="$CERT_SRC/privkey.pem"
	FULLCHAIN="$CERT_SRC/fullchain.pem"

	if [[ -f "$KEY" && -f "$FULLCHAIN" ]]; then
		cat "$KEY" "$FULLCHAIN" > "$RADIUS_CERT_PATH/server.pem"
		cp "$FULLCHAIN" "$RADIUS_CERT_PATH/ca.pem"
		chown freerad:freerad "$RADIUS_CERT_PATH/server.pem" "$RADIUS_CERT_PATH/ca.pem"
		chmod 640 "$RADIUS_CERT_PATH/server.pem" "$RADIUS_CERT_PATH/ca.pem"
		echo "RADIUS TLS certificates installed from $CERT_SRC"
	elif [[ -f "$CERT_SRC/server-combined.pem" ]]; then
		cp "$CERT_SRC/server-combined.pem" "$RADIUS_CERT_PATH/server.pem"
		if [[ -f "$FULLCHAIN" ]]; then
			cp "$FULLCHAIN" "$RADIUS_CERT_PATH/ca.pem"
		fi
		chown freerad:freerad "$RADIUS_CERT_PATH/server.pem"
		chmod 640 "$RADIUS_CERT_PATH/server.pem"
		[[ -f "$RADIUS_CERT_PATH/ca.pem" ]] && chown freerad:freerad "$RADIUS_CERT_PATH/ca.pem" && chmod 640 "$RADIUS_CERT_PATH/ca.pem"
		echo "RADIUS TLS certificates installed from $CERT_SRC/server-combined.pem"
	else
		echo "No custom TLS certs in $CERT_SRC; using image defaults."
	fi
}

echo "Starting freeradius..."

# wait for MySQL-Server to be ready
while ! mysqladmin ping -h"$MYSQL_HOST" --silent; do
	echo "Waiting for mysql ($MYSQL_HOST)..."
	sleep 20
done

INIT_LOCK=/data/.freeradius_init_done
if test -f "$INIT_LOCK"; then
	echo "Init lock file exists, skipping initial setup."
else
	init_freeradius
	date > $INIT_LOCK
fi

DB_LOCK=/data/.db_init_done
if test -f "$DB_LOCK"; then
	echo "Database lock file exists, skipping initial setup of mysql database."
else
	init_database
	date > $DB_LOCK
fi

configure_eap
sync_radius_certs

# Start freeradius in the foreground and in debug mode
exec freeradius -f "$@"
