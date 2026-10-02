#!/usr/bin/env bash
# FreeRADIUS for the WiFi Portal, on the same Ubuntu 24.04 server and PostgreSQL database.
#
#   sudo bash /var/www/wifiportal/deploy/setup-radius.sh
#
# Called by setup-server.sh; run it on its own to add RADIUS to a server that is
# already set up. Safe to run again: passwords and the shared secret are kept.
#
#   Phone -> MikroTik (RADIUS client) -> FreeRADIUS -> PostgreSQL radcheck <- Laravel
#
# Laravel writes each registration into radcheck (password, MAC, Expiration); every
# router asks FreeRADIUS, so one registration works on all routers in the city.
#
# Options (environment):
#   RADIUS_CLIENTS  networks the routers send RADIUS from, comma-separated
#                   (default: the private ranges 10.0.0.0/8,172.16.0.0/12,192.168.0.0/16)
#   RADIUS_ADDRESS  this server's IP as the routers see it (default: first IP of this host)
set -euo pipefail

APP_DIR=${APP_DIR:-/var/www/wifiportal}
RADDB=/etc/freeradius/3.0
RADIUS_CLIENTS=${RADIUS_CLIENTS:-10.0.0.0/8,172.16.0.0/12,192.168.0.0/16}
RADIUS_ADDRESS=${RADIUS_ADDRESS:-$(hostname -I | awk '{print $1}')}
RAD_DB_USER=radius

[[ $EUID -eq 0 ]] || { echo "Run with sudo." >&2; exit 1; }
[[ -f $APP_DIR/.env ]] || { echo "$APP_DIR/.env not found: run setup-server.sh first." >&2; exit 1; }

env_get() { grep -E "^$1=" "$APP_DIR/.env" | tail -n1 | cut -d= -f2- | tr -d '"'; }
env_set() {   # sets KEY=value in .env, adding the line if missing
    if grep -qE "^$1=" "$APP_DIR/.env"; then
        sed -i "s#^$1=.*#$1=$2#" "$APP_DIR/.env"
    else
        echo "$1=$2" >> "$APP_DIR/.env"
    fi
}

DB_NAME=$(env_get DB_DATABASE)
DB_USER=$(env_get DB_USERNAME)
[[ -n $DB_NAME && -n $DB_USER ]] || { echo "DB_DATABASE and DB_USERNAME must be set in .env." >&2; exit 1; }

echo "==> FreeRADIUS packages"
DEBIAN_FRONTEND=noninteractive apt-get install -y freeradius freeradius-postgresql freeradius-utils
[[ -d $RADDB ]] || { echo "Expected FreeRADIUS config in $RADDB." >&2; exit 1; }

echo "==> RADIUS tables in $DB_NAME"
# The FreeRADIUS schema, owned by the app's role. Tables that already exist (radcheck,
# created by the Laravel migration) are skipped.
cd /tmp
sudo -u postgres psql -q -d "$DB_NAME" -c "SET ROLE $DB_USER" \
    -f "$RADDB/mods-config/sql/main/postgresql/schema.sql" 2>&1 | grep -v "already exists" || true
for t in radcheck radreply radgroupcheck radgroupreply radusergroup radacct radpostauth nas; do
    sudo -u postgres psql -tAc "SELECT to_regclass('public.$t')" -d "$DB_NAME" | grep -q "$t" \
        || { echo "Table $t was not created." >&2; exit 1; }
done

echo "==> Database role for FreeRADIUS (read logins, write accounting)"
SQL_CONF=$RADDB/mods-available/wifiportal-sql
RAD_DB_PASS=$( [[ -f $SQL_CONF ]] && sed -n 's/^\s*password = "\(.*\)"$/\1/p' "$SQL_CONF" | head -n1 || true )
RAD_DB_PASS=${RAD_DB_PASS:-$(openssl rand -hex 24)}
if sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$RAD_DB_USER'" | grep -q 1; then
    sudo -u postgres psql -qc "ALTER ROLE $RAD_DB_USER LOGIN PASSWORD '$RAD_DB_PASS'"
else
    sudo -u postgres psql -qc "CREATE ROLE $RAD_DB_USER LOGIN PASSWORD '$RAD_DB_PASS'"
fi
sudo -u postgres psql -q -d "$DB_NAME" <<SQL
GRANT CONNECT ON DATABASE "$DB_NAME" TO $RAD_DB_USER;
GRANT USAGE ON SCHEMA public TO $RAD_DB_USER;
GRANT SELECT ON radcheck, radreply, radgroupcheck, radgroupreply, radusergroup, nas TO $RAD_DB_USER;
GRANT SELECT, INSERT, UPDATE ON radacct, radpostauth TO $RAD_DB_USER;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO $RAD_DB_USER;

-- FreeRADIUS logs each login attempt in radpostauth, by default with the password in
-- plain text. Never keep it: blank it on every insert, and in rows already there.
CREATE OR REPLACE FUNCTION wifiportal_blank_postauth_pass() RETURNS trigger AS \$\$
BEGIN
    NEW.pass := '';
    RETURN NEW;
END;
\$\$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS wifiportal_blank_pass ON radpostauth;
CREATE TRIGGER wifiportal_blank_pass BEFORE INSERT OR UPDATE ON radpostauth
    FOR EACH ROW EXECUTE FUNCTION wifiportal_blank_postauth_pass();
UPDATE radpostauth SET pass = '' WHERE pass IS NOT NULL AND pass <> '';
SQL

echo "==> sql module"
cat > "$SQL_CONF" <<EOF
# Written by WiFi Portal deploy/setup-radius.sh. Changes are overwritten on the next run.
sql {
    dialect = "postgresql"
    driver = "rlm_sql_\${dialect}"

    server = "localhost"
    port = 5432
    login = "$RAD_DB_USER"
    password = "$RAD_DB_PASS"
    radius_db = "$DB_NAME"

    acct_table1 = "radacct"
    acct_table2 = "radacct"
    postauth_table = "radpostauth"
    authcheck_table = "radcheck"
    groupcheck_table = "radgroupcheck"
    authreply_table = "radreply"
    groupreply_table = "radgroupreply"
    usergroup_table = "radusergroup"
    delete_stale_sessions = yes

    pool {
        start = 5
        min = 4
        max = 64
        spare = 8
        uses = 0
        retry_delay = 30
        lifetime = 0
        idle_timeout = 60
    }

    read_clients = no
    client_table = "nas"
    group_attribute = "SQL-Group"

    \$INCLUDE \${modconfdir}/sql/main/\${dialect}/queries.conf
}
EOF
chown root:freerad "$SQL_CONF"
chmod 640 "$SQL_CONF"
rm -f "$RADDB/mods-enabled/sql"
ln -s ../mods-available/wifiportal-sql "$RADDB/mods-enabled/sql"

# The default site calls "-sql" (used when the module is enabled) and "expiration"
# (rejects logins past their Expiration and ends sessions at that time).
grep -qE '^\s*-?sql\s*$' "$RADDB/sites-enabled/default" \
    || echo "!! sites-enabled/default does not call sql: add 'sql' to authorize, accounting and post-auth."
grep -qE '^\s*expiration\s*$' "$RADDB/sites-enabled/default" \
    || echo "!! sites-enabled/default does not call expiration: logins will not end at their validity."

echo "==> Shared secret and RADIUS clients (the routers)"
SECRET=$(env_get RADIUS_SECRET)
if [[ -z $SECRET ]]; then
    SECRET=$(openssl rand -hex 16)
    env_set RADIUS_SECRET "$SECRET"
fi
[[ -n $(env_get RADIUS_HOST) ]] || env_set RADIUS_HOST "$RADIUS_ADDRESS"
# FreeRADIUS reads Expiration in this server's local time
env_set RADIUS_TIMEZONE "$(timedatectl show -p Timezone --value 2>/dev/null || echo UTC)"

CLIENTS=$RADDB/clients.conf
sed -i '/^# BEGIN wifiportal/,/^# END wifiportal/d' "$CLIENTS"
{
    echo "# BEGIN wifiportal (written by deploy/setup-radius.sh; changes are overwritten)"
    i=0
    IFS=',' read -ra NETS <<< "$RADIUS_CLIENTS"
    for net in "${NETS[@]}"; do
        net=$(echo "$net" | xargs)
        [[ -n $net ]] || continue
        i=$((i + 1))
        cat <<EOF
client wifiportal_routers_$i {
    ipaddr = $net
    secret = $SECRET
    require_message_authenticator = no
    shortname = wifiportal-$i
}
EOF
    done
    echo "# END wifiportal"
} >> "$CLIENTS"

if command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then
    echo "==> Firewall: RADIUS from the routers only"
    for net in "${NETS[@]}"; do
        net=$(echo "$net" | xargs)
        [[ -n $net ]] && ufw allow proto udp from "$net" to any port 1812,1813 comment 'wifiportal radius' >/dev/null
    done
fi

echo "==> Start FreeRADIUS"
freeradius -C -X >/tmp/freeradius-check.log 2>&1 \
    || { tail -n 30 /tmp/freeradius-check.log; echo "FreeRADIUS configuration check failed (full log: /tmp/freeradius-check.log)." >&2; exit 1; }
systemctl enable freeradius >/dev/null
systemctl restart freeradius

echo "==> Self-test: a temporary login through the database"
TEST_USER="setup-test-$(openssl rand -hex 4)"
TEST_PASS=$(openssl rand -hex 8)
LOCAL_SECRET=$(awk '/^client localhost/,/^}/' "$CLIENTS" | sed -n 's/^\s*secret\s*=\s*//p' | head -n1)
sudo -u postgres psql -qd "$DB_NAME" -c "INSERT INTO radcheck (username, attribute, op, value) VALUES ('$TEST_USER', 'Cleartext-Password', ':=', '$TEST_PASS')"
sleep 1
if radtest "$TEST_USER" "$TEST_PASS" 127.0.0.1 0 "${LOCAL_SECRET:-testing123}" 2>/dev/null | grep -q "Access-Accept"; then
    echo "    OK: FreeRADIUS accepted a login from radcheck."
else
    echo "!! Self-test failed. Debug with: sudo systemctl stop freeradius && sudo freeradius -X"
fi
sudo -u postgres psql -qd "$DB_NAME" -c "DELETE FROM radcheck WHERE username = '$TEST_USER'"

cd "$APP_DIR"
sudo -u www-data php artisan optimize >/dev/null

cat <<EOF

FreeRADIUS is running on this server.
  RADIUS_HOST=$(env_get RADIUS_HOST)   (routers send logins here; change it in .env if they reach this server on another IP)
  Routers allowed from: $RADIUS_CLIENTS
  RADIUS_TIMEZONE=$(env_get RADIUS_TIMEZONE)

Next: in the dashboard, apply each router's configuration again (Routers > the router > Re-apply configuration)
so it points at RADIUS and logs registered phones in by MAC. New registrations then go to RADIUS.
EOF
