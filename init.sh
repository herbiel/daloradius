#!/bin/bash
# Executable process script for daloRADIUS docker image:
# GitHub: git@github.com:lirantal/daloradius.git
DALORADIUS_PATH=/var/www/daloradius
DALORADIUS_CONF_PATH=/var/www/daloradius/app/common/includes/daloradius.conf.php


function init_daloradius {

    if ! test -f "$DALORADIUS_CONF_PATH" || ! test -s "$DALORADIUS_CONF_PATH"; then
        cp "$DALORADIUS_CONF_PATH.sample" "$DALORADIUS_CONF_PATH"
        chown www-data:www-data "$DALORADIUS_CONF_PATH"
    fi
    [ -n "$MYSQL_HOST" ] && sed -i "s/\$configValues\['CONFIG_DB_HOST'\] = .*;/\$configValues\['CONFIG_DB_HOST'\] = '$MYSQL_HOST';/" $DALORADIUS_CONF_PATH || MYSQL_HOST=localhost
    [ -n "$MYSQL_PORT" ] && sed -i "s/\$configValues\['CONFIG_DB_PORT'\] = .*;/\$configValues\['CONFIG_DB_PORT'\] = '$MYSQL_PORT';/" $DALORADIUS_CONF_PATH
    [ -n "$MYSQL_PASSWORD" ] && sed -i "s/\$configValues\['CONFIG_DB_PASS'\] = .*;/\$configValues\['CONFIG_DB_PASS'\] = '$MYSQL_PASSWORD';/" $DALORADIUS_CONF_PATH || MYSQL_PASSWORD=radpass
    [ -n "$MYSQL_USER" ] && sed -i "s/\$configValues\['CONFIG_DB_USER'\] = .*;/\$configValues\['CONFIG_DB_USER'\] = '$MYSQL_USER';/" $DALORADIUS_CONF_PATH || MYSQL_USER=raduser
    [ -n "$MYSQL_DATABASE" ] && sed -i "s/\$configValues\['CONFIG_DB_NAME'\] = .*;/\$configValues\['CONFIG_DB_NAME'\] = '$MYSQL_DATABASE';/" $DALORADIUS_CONF_PATH || MYSQL_DATABASE=raddb
    sed -i "s/\$configValues\['FREERADIUS_VERSION'\] = .*;/\$configValues\['FREERADIUS_VERSION'\] = '3';/" $DALORADIUS_CONF_PATH
    [ -n "$PASSWORD_MIN_LENGTH" ] && sed -i "s/\$configValues\['CONFIG_DB_PASSWORD_MIN_LENGTH'\] = .*;/\$configValues\['CONFIG_DB_PASSWORD_MIN_LENGTH'\] = '$PASSWORD_MIN_LENGTH';/" $DALORADIUS_CONF_PATH
    [ -n "$PASSWORD_MAX_LENGTH" ] && sed -i "s/\$configValues\['CONFIG_DB_PASSWORD_MAX_LENGTH'\] = .*;/\$configValues\['CONFIG_DB_PASSWORD_MAX_LENGTH'\] = '$PASSWORD_MAX_LENGTH';/" $DALORADIUS_CONF_PATH

    [ -n "$DEFAULT_FREERADIUS_SERVER" ] \
        && sed -i "s/\$configValues\['CONFIG_MAINT_TEST_USER_RADIUSSERVER'\] = .*;/\$configValues\['CONFIG_MAINT_TEST_USER_RADIUSSERVER'\] = '$DEFAULT_FREERADIUS_SERVER';/" $DALORADIUS_CONF_PATH \
        || sed -i "s/\$configValues\['CONFIG_MAINT_TEST_USER_RADIUSSERVER'\] = .*;/\$configValues\['CONFIG_MAINT_TEST_USER_RADIUSSERVER'\] = 'radius';/" $DALORADIUS_CONF_PATH
    [ -n "$DEFAULT_FREERADIUS_PORT" ] && sed -i "s/\$configValues\['CONFIG_MAINT_TEST_USER_RADIUSPORT'\] = .*;/\$configValues\['CONFIG_MAINT_TEST_USER_RADIUSPORT'\] = '$DEFAULT_FREERADIUS_PORT';/" $DALORADIUS_CONF_PATH
    [ -n "$DEFAULT_CLIENT_SECRET" ] && sed -i "s/\$configValues\['CONFIG_MAINT_TEST_USER_RADIUSSECRET'\] = .*;/\$configValues\['CONFIG_MAINT_TEST_USER_RADIUSSECRET'\] = '$DEFAULT_CLIENT_SECRET';/" $DALORADIUS_CONF_PATH

    [ -n "$MAIL_SMTPADDR" ] && sed -i "s/\$configValues\['CONFIG_MAIL_SMTPADDR'\] = .*;/\$configValues\['CONFIG_MAIL_SMTPADDR'\] = '$MAIL_SMTPADDR';/" $DALORADIUS_CONF_PATH
    [ -n "$MAIL_PORT" ] && sed -i "s/\$configValues\['CONFIG_MAIL_SMTPPORT'\] = .*;/\$configValues\['CONFIG_MAIL_SMTPPORT'\] = '$MAIL_PORT';/" $DALORADIUS_CONF_PATH
    [ -n "$MAIL_AUTH" ] && sed -i "s/\$configValues\['CONFIG_MAIL_SMTPAUTH'\] = .*;/\$configValues\['CONFIG_MAIL_SMTPAUTH'\] = '$MAIL_AUTH';/" $DALORADIUS_CONF_PATH
    [ -n "$MAIL_FROM" ] && sed -i "s/\$configValues\['CONFIG_MAIL_SMTPFROM'\] = .*;/\$configValues\['CONFIG_MAIL_SMTPFROM'\] = '$MAIL_FROM';/" $DALORADIUS_CONF_PATH
    [ -n "$MAIL_ENABLED" ] && sed -i "s/\$configValues\['CONFIG_MAIL_ENABLED'\] = .*;/\$configValues\['CONFIG_MAIL_ENABLED'\] = '$MAIL_ENABLED';/" $DALORADIUS_CONF_PATH
    [ -n "$MAIL_SMTP_SECURITY" ] && sed -i "s/\$configValues\['CONFIG_MAIL_SMTP_SECURITY'\] = .*;/\$configValues\['CONFIG_MAIL_SMTP_SECURITY'\] = '$MAIL_SMTP_SECURITY';/" $DALORADIUS_CONF_PATH
    [ -n "$MAIL_SMTP_SENDER_NAME" ] && sed -i "s/\$configValues\['CONFIG_MAIL_SMTP_SENDER_NAME'\] = .*;/\$configValues\['CONFIG_MAIL_SMTP_SENDER_NAME'\] = '$MAIL_SMTP_SENDER_NAME';/" $DALORADIUS_CONF_PATH
    [ -n "$MAIL_SMTP_SUBJECT_PREFIX" ] && sed -i "s/\$configValues\['CONFIG_MAIL_SMTP_SUBJECT_PREFIX'\] = .*;/\$configValues\['CONFIG_MAIL_SMTP_SUBJECT_PREFIX'\] = '$MAIL_SMTP_SUBJECT_PREFIX';/" $DALORADIUS_CONF_PATH
    [ -n "$MAIL_SMTP_USERNAME" ] && sed -i "s/\$configValues\['CONFIG_MAIL_SMTP_USERNAME'\] = .*;/\$configValues\['CONFIG_MAIL_SMTP_USERNAME'\] = '$MAIL_SMTP_USERNAME';/" $DALORADIUS_CONF_PATH
    [ -n "$MAIL_SMTP_PASSWORD" ] && sed -i "s/\$configValues\['CONFIG_MAIL_SMTP_PASSWORD'\] = .*;/\$configValues\['CONFIG_MAIL_SMTP_PASSWORD'\] = '$MAIL_SMTP_PASSWORD';/" $DALORADIUS_CONF_PATH
    if [ -n "$MAIL_BODY_NOTE" ]; then
        escaped_note=$(printf '%s' "$MAIL_BODY_NOTE" | sed "s/'/'\\\\''/g")
        sed -i "s/\$configValues\['CONFIG_MAIL_BODY_NOTE'\] = .*;/\$configValues['CONFIG_MAIL_BODY_NOTE'] = '${escaped_note}';/" $DALORADIUS_CONF_PATH
    fi
    [ -n "$USER_VPN_SERVER" ] && sed -i "s/\$configValues\['CONFIG_USER_VPN_SERVER'\] = .*;/\$configValues\['CONFIG_USER_VPN_SERVER'\] = '$USER_VPN_SERVER';/" $DALORADIUS_CONF_PATH
    sed -i "s/\$configValues\['CONFIG_LOG_FILE'\] = .*;/\$configValues\['CONFIG_LOG_FILE'\] = '\/tmp\/daloradius.log';/" $DALORADIUS_CONF_PATH

    echo "daloRADIUS initialization completed."
}

function wait_for_mysql() {
    echo -n "Waiting for mysql ($MYSQL_HOST)..."
    local attempt=0
    while [ "$attempt" -lt 60 ]; do
        if MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin ping -h"$MYSQL_HOST" -u root --silent 2>/dev/null; then
            echo "ok"
            return 0
        fi
        sleep 2
        attempt=$((attempt + 1))
    done
    echo "failed"
    echo "ERROR: MySQL at $MYSQL_HOST is not reachable with MYSQL_ROOT_PASSWORD." >&2
    return 1
}

function init_database {
    if [ -z "$MYSQL_ROOT_PASSWORD" ]; then
        echo "ERROR: MYSQL_ROOT_PASSWORD is not set in the container environment." >&2
        return 1
    fi

    export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
    mysql -h "$MYSQL_HOST" -u root -e "CREATE DATABASE IF NOT EXISTS \`$MYSQL_DATABASE\`;" || return 1
    mysql -h "$MYSQL_HOST" -u root -e "CREATE USER IF NOT EXISTS '$MYSQL_USER'@'%' IDENTIFIED BY '$MYSQL_PASSWORD';" || return 1
    mysql -h "$MYSQL_HOST" -u root -e "GRANT ALL PRIVILEGES ON \`$MYSQL_DATABASE\`.* TO '$MYSQL_USER'@'%';" || return 1
    mysql -h "$MYSQL_HOST" -u root -e "FLUSH PRIVILEGES;" || return 1

    export MYSQL_PWD="$MYSQL_PASSWORD"
    mysql -h "$MYSQL_HOST" -u "$MYSQL_USER" "$MYSQL_DATABASE" < "$DALORADIUS_PATH/contrib/db/mariadb-daloradius.sql" || return 1

    unset MYSQL_PWD
    echo "Database initialization for daloRADIUS completed."
}

echo "Starting daloRADIUS..."

# Always sync daloradius.conf.php from environment (e.g. after .env password changes).
init_daloradius

INIT_LOCK=/data/.init_done
if ! test -f "$INIT_LOCK"; then
    date > $INIT_LOCK
fi

# wait for MySQL-Server to be ready
wait_for_mysql || exit 1

DB_LOCK=/data/.db_init_done
if test -f "$DB_LOCK"; then
    echo "Database lock file exists, skipping initial setup of mysql database."
else
    if init_database; then
        date > "$DB_LOCK"
    else
        echo "ERROR: Database initialization failed. Remove $DB_LOCK and data/mysql/ then retry." >&2
        exit 1
    fi
fi

# Start Apache2 in the foreground
/usr/sbin/apachectl -DFOREGROUND -k start
