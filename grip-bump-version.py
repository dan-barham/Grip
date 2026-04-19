#!/usr/bin/env python3
"""
GRIP version bumper — run from the same folder as grip-index.html.
Usage:  python3 grip-bump-version.py          # bumps patch: 1.0.0 -> 1.0.1
        python3 grip-bump-version.py minor    # bumps minor: 1.0.1 -> 1.1.0
        python3 grip-bump-version.py major    # bumps major: 1.1.0 -> 2.0.0
"""
import re, sys, datetime, pathlib

part = sys.argv[1].lower() if len(sys.argv) > 1 else 'patch'
assert part in ('major','minor','patch'), "Usage: bump-version.py [major|minor|patch]"

today = datetime.date.today().isoformat()

# ── Bump HTML version ────────────────────────────────────────
f = pathlib.Path('grip-index.html')
c = f.read_text()
m = re.search(r"const GRIP_VERSION = '(\d+)\.(\d+)\.(\d+)';", c)
assert m, "GRIP_VERSION not found in grip-index.html"

major, minor, patch = int(m.group(1)), int(m.group(2)), int(m.group(3))
old = f"{major}.{minor}.{patch}"
if part == 'major': major += 1; minor = 0; patch = 0
elif part == 'minor': minor += 1; patch = 0
else: patch += 1
new = f"{major}.{minor}.{patch}"

c = c.replace(f"const GRIP_VERSION = '{old}';", f"const GRIP_VERSION = '{new}';", 1)
c = re.sub(r"const GRIP_BUILD_DATE = '[^']*';", f"const GRIP_BUILD_DATE = '{today}';", c)
f.write_text(c)

# ── Update grip-version.php — write whole file to avoid regex corruption ──
vf = pathlib.Path('grip-version.php')
if vf.exists():
    vf.write_text(f"""<?php
// ============================================================
//  GRIP Version — shared across all GRIP files
//  Update with: python3 grip-bump-version.py [major|minor|patch]
// ============================================================

define('GRIP_VERSION',      '{new}');
define('GRIP_VERSION_DATE', '{today}');
define('GRIP_VERSION_FULL', GRIP_VERSION . ' (' . GRIP_VERSION_DATE . ')');
define('GRIP_APP_NAME',     'GRIP Gear Tracker');
""")
    print(f"grip-version.php updated")

print(f"GRIP version bumped: {old} -> {new} ({today})")
