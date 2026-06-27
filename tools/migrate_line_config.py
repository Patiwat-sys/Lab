import sqlite3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
db = root / "storage" / "lab.sqlite"
out = root / "storage" / "line.local.php"

conn = sqlite3.connect(db)
cur = conn.cursor()
cur.execute("SELECT key, value FROM app_settings WHERE key LIKE 'gas_line%'")
rows = {k: v for k, v in cur.fetchall()}
conn.close()

token = (rows.get("gas_line_channel_token") or "").strip()
to_id = (rows.get("gas_line_to_id") or "").strip()
enabled = (rows.get("gas_line_enabled") or "0") == "1"

if not token and not to_id:
    print("No DB credentials to migrate")
    raise SystemExit(0)

if out.exists():
    print("line.local.php already exists, skip")
    raise SystemExit(0)

content = (
    "<?php\nreturn [\n"
    f"    'enabled' => {'true' if enabled else 'false'},\n"
    f"    'channel_access_token' => {token!r},\n"
    f"    'to_id' => {to_id!r},\n"
    "];\n"
)
out.write_text(content, encoding="utf-8")

conn = sqlite3.connect(db)
cur = conn.cursor()
cur.execute("UPDATE app_settings SET value = '' WHERE key = 'gas_line_channel_token'")
cur.execute("UPDATE app_settings SET value = '' WHERE key = 'gas_line_to_id'")
conn.commit()
conn.close()

print("Migrated to storage/line.local.php and cleared token from DB")
