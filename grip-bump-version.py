#!/usr/bin/env python3
"""
GRIP version bumper — smart, diff-aware version management.

Usage:
  python3 grip-bump-version.py                    # analyze changes, suggest level, prompt
  python3 grip-bump-version.py -y                 # analyze and auto-apply without prompting
  python3 grip-bump-version.py --dry              # analyze and show what would happen, no changes
  python3 grip-bump-version.py patch              # force patch bump (1.0.0 -> 1.0.1)
  python3 grip-bump-version.py minor              # force minor bump (1.0.1 -> 1.1.0)
  python3 grip-bump-version.py major              # force major bump (1.1.0 -> 2.0.0)
  python3 grip-bump-version.py -m "message"       # seed classifier with an intended commit message
  python3 grip-bump-version.py --no-changelog     # skip CHANGELOG.md entry
  python3 grip-bump-version.py --against HEAD~1   # compare against a different git ref

Classification signals (roughly, in descending priority):
  MAJOR -> breaking schema/API changes, removed endpoints or columns,
           explicit "BREAKING"/"break"/"remove" in commit message
  MINOR -> new API endpoint, new DB table/column, new top-level feature
           (new render function + CSS block), "feat"/"feature"/"add" hint
  PATCH -> bug fixes, tweaks, polish, small refactors; "fix"/"polish"/
           "tweak" hint; low diff magnitude with no structural signals

The classifier is conservative -- when in doubt it prefers the lower
bump and explains its reasoning so the developer can override.
"""
from __future__ import annotations
import argparse, datetime, pathlib, re, subprocess, sys
from dataclasses import dataclass, field


# =============================================================
#  CONSTANTS
# =============================================================
VERSION_FILE_HTML = pathlib.Path('index.html')
VERSION_FILE_PHP  = pathlib.Path('grip-version.php')
CHANGELOG_FILE    = pathlib.Path('CHANGELOG.md')

# Scoring thresholds -- tuned against the project's actual commit history
MAJOR_THRESHOLD = 20
MINOR_THRESHOLD = 6

# Commit-message keyword weights (case-insensitive, word-bounded)
KEYWORD_WEIGHTS = [
    (r'\bbreaking\b|\bincompatib',                                +30),
    (r'\bremov(e|ed|es|ing)\b.*(endpoint|api|column|table|field)', +25),
    (r'\bfeat(ure)?\b|\bintroduc(e|es|ing|ed)\b|\bship\b',         +8),
    (r'\badd(ed|s|ing)?\b(?!.*comment)',                          +4),
    (r'\bnew\b(?!.*(year|line))',                                  +4),
    (r'\brework\b|\brefactor\b|\bredesign\b|\brevamp\b',          +3),
    (r'\bfix(es|ed|ing)?\b|\bbug(fix)?\b',                         -3),
    (r'\btypo\b|\btweak\b|\bpolish\b|\btouch.?up\b',               -4),
    (r'\bhotfix\b',                                                -2),
]

JS_FILES   = {'index.html'}
API_FILES  = {'grip-api.php'}
SQL_FILES  = {'schema.sql'}


# =============================================================
#  DATA TYPES
# =============================================================
@dataclass
class Analysis:
    level: str                          # 'patch' | 'minor' | 'major'
    score: float = 0.0
    reasons: list = field(default_factory=list)
    added_fns:       list = field(default_factory=list)
    removed_fns:     list = field(default_factory=list)
    added_endpoints: list = field(default_factory=list)
    removed_endpoints: list = field(default_factory=list)
    added_columns:   list = field(default_factory=list)
    removed_columns: list = field(default_factory=list)
    added_tables:    list = field(default_factory=list)
    removed_tables:  list = field(default_factory=list)
    added_css_rules: int = 0
    files_changed:   list = field(default_factory=list)
    lines_added:     int = 0
    lines_removed:   int = 0
    keyword_hits:    list = field(default_factory=list)


# =============================================================
#  GIT HELPERS
# =============================================================
def run(*args):
    """Run a subprocess and return stdout, empty string on error."""
    try:
        r = subprocess.run(args, capture_output=True, text=True, check=False)
        return r.stdout
    except FileNotFoundError:
        return ''


def git_diff(against='HEAD'):
    """Full unified diff of working tree + staged vs a ref."""
    staged   = run('git', 'diff', '--cached', against)
    unstaged = run('git', 'diff', against)
    return staged if staged == unstaged else staged + unstaged


def git_numstat(against='HEAD'):
    """Return list of (added, removed, filename) for changed files."""
    out = run('git', 'diff', '--numstat', against) + \
          run('git', 'diff', '--cached', '--numstat', against)
    rows = {}
    for line in out.splitlines():
        parts = line.split('\t')
        if len(parts) < 3: continue
        try:
            a = int(parts[0]) if parts[0] != '-' else 0
            r = int(parts[1]) if parts[1] != '-' else 0
        except ValueError:
            continue
        fn = parts[2]
        cur = rows.get(fn, (0,0))
        rows[fn] = (cur[0]+a, cur[1]+r)
    return [(a,r,fn) for fn,(a,r) in rows.items()]


def git_recent_messages(n=1):
    """Last N commit subjects -- used as a weak signal when no explicit -m is given."""
    out = run('git', 'log', f'-{n}', '--pretty=%s')
    return [l for l in out.splitlines() if l.strip()]


# =============================================================
#  DIFF ANALYSIS
# =============================================================
def split_diff_by_file(diff):
    """Parse a unified diff into per-file lists of (sign, content) lines."""
    files = {}
    current = None
    for line in diff.splitlines():
        if line.startswith('diff --git '):
            m = re.match(r'diff --git a/(.+?) b/(.+)', line)
            if m:
                current = m.group(2)
                files.setdefault(current, [])
            continue
        if current is None: continue
        if line.startswith('+++') or line.startswith('---') or line.startswith('@@'):
            continue
        if line.startswith('+') or line.startswith('-'):
            files[current].append((line[0], line[1:]))
    return files


def extract_js_functions(lines, sign):
    """Collect top-level JS function names added ('+') or removed ('-')."""
    names = set()
    patterns = [
        re.compile(r'^function\s+([A-Za-z_][\w]*)\s*\('),
        re.compile(r'^async\s+function\s+([A-Za-z_][\w]*)\s*\('),
    ]
    for s, content in lines:
        if s != sign: continue
        stripped = content.lstrip()
        for p in patterns:
            m = p.match(stripped)
            if m: names.add(m.group(1))
    return names


def extract_api_endpoints(lines, sign):
    """Detect added/removed top-level resource blocks like `if($resource==='jobs'){`."""
    names = set()
    pat = re.compile(r"if\s*\(\s*\$resource\s*===\s*['\"]([A-Za-z_][\w/]*)['\"]")
    for s, content in lines:
        if s != sign: continue
        for m in pat.finditer(content):
            names.add(m.group(1))
    return names


def extract_schema_changes(lines):
    """Return (added_tables, removed_tables, added_columns, removed_columns)."""
    added_tables, removed_tables = set(), set()
    added_cols,   removed_cols   = set(), set()

    table_pat  = re.compile(r'CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?', re.I)
    drop_pat   = re.compile(r'DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?[`"]?(\w+)[`"]?', re.I)
    alter_add  = re.compile(r'ALTER\s+TABLE\s+[`"]?(\w+)[`"]?\s+ADD\s+(?:COLUMN\s+)?[`"]?(\w+)[`"]?', re.I)
    alter_drop = re.compile(r'ALTER\s+TABLE\s+[`"]?(\w+)[`"]?\s+DROP\s+(?:COLUMN\s+)?[`"]?(\w+)[`"]?', re.I)
    col_line   = re.compile(r'^\s*[`"]?(\w+)[`"]?\s+(VARCHAR|INT|BIGINT|TEXT|DATETIME|DATE|TIMESTAMP|BOOL|TINYINT|DECIMAL|FLOAT|DOUBLE|JSON|ENUM)', re.I)

    for sign, content in lines:
        m = None
        if sign == '+':
            m = table_pat.search(content)
            if m: added_tables.add(m.group(1))
            m = drop_pat.search(content)
            if m: removed_tables.add(m.group(1))
            m = alter_add.search(content)
            if m: added_cols.add(f"{m.group(1)}.{m.group(2)}")
            m = alter_drop.search(content)
            if m: removed_cols.add(f"{m.group(1)}.{m.group(2)}")
            m = col_line.match(content)
            if m: added_cols.add(m.group(1))
        elif sign == '-':
            m = table_pat.search(content)
            if m: removed_tables.add(m.group(1))
            m = alter_add.search(content)
            if m: removed_cols.add(f"{m.group(1)}.{m.group(2)}")
            m = col_line.match(content)
            if m: removed_cols.add(m.group(1))

    # Columns/tables removed then re-added -> no change
    removed_cols   -= added_cols
    removed_tables -= added_tables
    return added_tables, removed_tables, added_cols, removed_cols


def count_css_rules(lines):
    """Rough count of new CSS selectors -- lines ending in `{` that look CSS-ish."""
    n = 0
    css_pat = re.compile(r'^\s*(?:\.|#|@|\*|:root|[a-z][\w-]*)[^{;]*\{\s*$', re.I)
    js_ish  = re.compile(r'^\s*(function\b|if\b|while\b|for\b|switch\b|else\b|try\b|catch\b|\$\w+\s*=)', re.I)
    for s, content in lines:
        if s != '+': continue
        if js_ish.match(content): continue
        if css_pat.match(content): n += 1
    return n


# =============================================================
#  CLASSIFIER
# =============================================================
def analyze(diff, numstat, message):
    a = Analysis(level='patch')
    a.lines_added   = sum(x[0] for x in numstat)
    a.lines_removed = sum(x[1] for x in numstat)
    a.files_changed = [x[2] for x in numstat]

    by_file = split_diff_by_file(diff)

    # JS / HTML function extraction
    for f in JS_FILES:
        if f not in by_file: continue
        added = extract_js_functions(by_file[f], '+')
        removed = extract_js_functions(by_file[f], '-')
        a.added_fns.extend(sorted(added - removed))
        a.removed_fns.extend(sorted(removed - added))
        a.added_css_rules += count_css_rules(by_file[f])

    # API endpoint extraction
    for f in API_FILES:
        if f not in by_file: continue
        added = extract_api_endpoints(by_file[f], '+')
        removed = extract_api_endpoints(by_file[f], '-')
        a.added_endpoints.extend(sorted(added - removed))
        a.removed_endpoints.extend(sorted(removed - added))
        # PHP function defs
        php_fn_pat = re.compile(r'^\s*function\s+([A-Za-z_][\w]*)\s*\(')
        added_php = set()
        removed_php = set()
        for s, c in by_file[f]:
            m = php_fn_pat.match(c)
            if m:
                (added_php if s == '+' else removed_php).add(m.group(1))
        a.added_fns.extend(sorted(added_php - removed_php))
        a.removed_fns.extend(sorted(removed_php - added_php))

    # Schema extraction
    for f in SQL_FILES:
        if f not in by_file: continue
        t_add, t_rem, c_add, c_rem = extract_schema_changes(by_file[f])
        a.added_tables.extend(sorted(t_add))
        a.removed_tables.extend(sorted(t_rem))
        a.added_columns.extend(sorted(c_add))
        a.removed_columns.extend(sorted(c_rem))
    # Migrations sometimes live inline in the API
    for f in API_FILES:
        if f not in by_file: continue
        _, _, c_add, c_rem = extract_schema_changes(by_file[f])
        a.added_columns.extend(sorted(set(c_add) - set(a.added_columns)))
        a.removed_columns.extend(sorted(set(c_rem) - set(a.removed_columns)))

    # -------- Score --------
    # Breaking / removal (push toward major)
    if a.removed_tables:
        a.score += 25 * len(a.removed_tables)
        a.reasons.append(f"dropped table(s): {', '.join(a.removed_tables)}")
    if a.removed_columns:
        a.score += 15 * len(a.removed_columns)
        a.reasons.append(f"dropped column(s): {', '.join(a.removed_columns)}")
    if a.removed_endpoints:
        a.score += 18 * len(a.removed_endpoints)
        a.reasons.append(f"removed API endpoint(s): {', '.join(a.removed_endpoints)}")

    # Additive features (push toward minor)
    if a.added_tables:
        a.score += 10 * len(a.added_tables)
        a.reasons.append(f"new table(s): {', '.join(a.added_tables)}")
    if a.added_columns:
        a.score += 5 * len(a.added_columns)
        a.reasons.append(f"new column(s): {', '.join(a.added_columns)}")
    if a.added_endpoints:
        a.score += 8 * len(a.added_endpoints)
        a.reasons.append(f"new API endpoint(s): {', '.join(a.added_endpoints)}")
    if a.added_fns:
        n = len(a.added_fns)
        a.score += min(n * 1.5, 12)
        sample = ', '.join(a.added_fns[:4]) + (f", +{n-4} more" if n > 4 else "")
        a.reasons.append(f"{n} new function(s): {sample}")
    if a.added_css_rules >= 10:
        a.score += min(a.added_css_rules * 0.15, 5)
        a.reasons.append(f"{a.added_css_rules} new CSS rule(s) (feature styling)")

    # Magnitude
    total = a.lines_added + a.lines_removed
    if total >= 2000:
        a.score += 8
        a.reasons.append(f"large diff ({total} lines changed)")
    elif total >= 800:
        a.score += 4
        a.reasons.append(f"substantial diff ({total} lines changed)")
    elif total >= 200:
        a.score += 1

    # Commit-message keywords
    msg_src = message or ' '.join(git_recent_messages(1))
    if msg_src:
        for pat, w in KEYWORD_WEIGHTS:
            if re.search(pat, msg_src, re.I):
                a.score += w
                a.keyword_hits.append((pat, w))

    # Classify
    if a.score >= MAJOR_THRESHOLD:
        a.level = 'major'
    elif a.score >= MINOR_THRESHOLD:
        a.level = 'minor'
    else:
        a.level = 'patch'

    # Safety rail: never auto-propose major without an explicit breaking signal
    has_breaking_signal  = bool(a.removed_tables or a.removed_columns or a.removed_endpoints)
    has_breaking_keyword = any(w >= 25 for _, w in a.keyword_hits)
    if a.level == 'major' and not (has_breaking_signal or has_breaking_keyword):
        a.level = 'minor'
        a.reasons.append("(downgraded from major -- no explicit breaking signal)")

    return a


# =============================================================
#  FILE MUTATORS
# =============================================================
def read_current_version():
    c = VERSION_FILE_HTML.read_text()
    m = re.search(r"const GRIP_VERSION = '(\d+)\.(\d+)\.(\d+)';", c)
    if not m:
        sys.exit("error: GRIP_VERSION not found in index.html")
    return int(m.group(1)), int(m.group(2)), int(m.group(3))


def apply_bump(level, old):
    major, minor, patch = old
    if   level == 'major': return major+1, 0, 0
    elif level == 'minor': return major, minor+1, 0
    else:                  return major, minor, patch+1


def write_version(new, today):
    new_s = f"{new[0]}.{new[1]}.{new[2]}"
    c = VERSION_FILE_HTML.read_text()
    c = re.sub(r"const GRIP_VERSION = '[^']*';",    f"const GRIP_VERSION = '{new_s}';",    c, count=1)
    c = re.sub(r"const GRIP_BUILD_DATE = '[^']*';", f"const GRIP_BUILD_DATE = '{today}';", c, count=1)
    VERSION_FILE_HTML.write_text(c)
    if VERSION_FILE_PHP.exists():
        VERSION_FILE_PHP.write_text(f"""<?php
// ============================================================
//  GRIP Version -- shared across all GRIP files
//  Update with: python3 grip-bump-version.py [major|minor|patch]
// ============================================================

define('GRIP_VERSION',      '{new_s}');
define('GRIP_VERSION_DATE', '{today}');
define('GRIP_VERSION_FULL', GRIP_VERSION . ' (' . GRIP_VERSION_DATE . ')');
define('GRIP_APP_NAME',     'GRIP Gear Tracker');
""")


def write_changelog(new, today, level, analysis, message):
    new_s = f"{new[0]}.{new[1]}.{new[2]}"
    bullets = []
    if message:
        bullets.append(message.strip().splitlines()[0])
    if analysis.added_tables:
        bullets.append(f"New table(s): {', '.join(analysis.added_tables)}")
    if analysis.added_columns:
        bullets.append(f"New column(s): {', '.join(analysis.added_columns)}")
    if analysis.added_endpoints:
        bullets.append(f"New API endpoint(s): {', '.join(analysis.added_endpoints)}")
    if analysis.removed_endpoints:
        bullets.append(f"**Removed** endpoint(s): {', '.join(analysis.removed_endpoints)}")
    if analysis.removed_tables:
        bullets.append(f"**Dropped** table(s): {', '.join(analysis.removed_tables)}")
    if analysis.removed_columns:
        bullets.append(f"**Dropped** column(s): {', '.join(analysis.removed_columns)}")
    if analysis.added_fns and not message:
        n = len(analysis.added_fns)
        if n <= 6:
            bullets.append(f"New functions: {', '.join(analysis.added_fns)}")
        else:
            bullets.append(f"{n} new functions (see diff)")
    if not bullets:
        bullets.append(f"Minor changes ({analysis.lines_added} insertions, {analysis.lines_removed} deletions)")

    entry_lines = [
        f"## [{new_s}] - {today}  _{level}_",
        "",
        *(f"- {b}" for b in bullets),
        "",
    ]
    entry = "\n".join(entry_lines)

    if CHANGELOG_FILE.exists():
        existing = CHANGELOG_FILE.read_text()
        header_match = re.match(r"(# [^\n]*\n+(?:> [^\n]*\n+)?)", existing)
        if header_match:
            head = header_match.group(1)
            rest = existing[len(head):]
            CHANGELOG_FILE.write_text(head + entry + "\n" + rest)
        else:
            CHANGELOG_FILE.write_text(entry + "\n" + existing)
    else:
        CHANGELOG_FILE.write_text(
            "# Changelog\n\n"
            "> Generated automatically by `grip-bump-version.py`. "
            "Format: [version] - date _level_\n\n"
            + entry
        )


# =============================================================
#  REPORTING
# =============================================================
COLORS = {
    'major': '\033[1;31m',
    'minor': '\033[1;33m',
    'patch': '\033[1;32m',
    'dim':   '\033[2m',
    'reset': '\033[0m',
    'bold':  '\033[1m',
}
def col(key, txt):
    if not sys.stdout.isatty(): return txt
    return COLORS.get(key, '') + txt + COLORS['reset']


def print_analysis(a, old, new):
    old_s = f"{old[0]}.{old[1]}.{old[2]}"
    new_s = f"{new[0]}.{new[1]}.{new[2]}"
    bar = '=' * 60
    print(f"\n{col('bold', bar)}")
    print(f"  {col('bold', 'GRIP Version Analysis')}")
    print(f"{col('bold', bar)}\n")
    print(f"  Current: {col('dim', old_s)}")
    print(f"  Suggest: {col(a.level, new_s)}  {col(a.level, '['+a.level.upper()+']')}  score={a.score:.1f}\n")

    if a.files_changed:
        print(f"  {col('bold','Files changed')} ({len(a.files_changed)}): "
              f"{col('dim','+'+str(a.lines_added))} / {col('dim','-'+str(a.lines_removed))}")
        for f in a.files_changed[:6]:
            print(f"    - {f}")
        if len(a.files_changed) > 6:
            print(f"    - ... +{len(a.files_changed)-6} more")
        print()

    if a.reasons:
        print(f"  {col('bold','Signals')}:")
        for r in a.reasons:
            print(f"    - {r}")
        print()
    else:
        print(f"  {col('dim','No structural signals -- classified by magnitude and keywords only')}\n")

    if a.keyword_hits:
        print(f"  {col('bold','Keyword hints')}:")
        for pat, w in a.keyword_hits:
            sign = '+' if w > 0 else ''
            tag = 'major' if w >= 20 else ('minor' if w > 0 else 'patch')
            print(f"    - {col('dim', pat)} -> {col(tag, f'{sign}{w}')}")
        print()


# =============================================================
#  MAIN
# =============================================================
def main():
    ap = argparse.ArgumentParser(description='Smart, diff-aware GRIP version bumper.')
    ap.add_argument('level', nargs='?', choices=['major','minor','patch'],
                    help='Force a specific bump level (skips analysis).')
    ap.add_argument('-y','--yes','--auto', action='store_true',
                    help='Non-interactive -- apply suggestion without prompting.')
    ap.add_argument('--dry', action='store_true',
                    help="Analyze only -- don't modify any files.")
    ap.add_argument('-m','--message', default='',
                    help='Intended commit message -- used as a classifier signal.')
    ap.add_argument('--against', default='HEAD',
                    help='Git ref to compare against (default HEAD).')
    ap.add_argument('--no-changelog', action='store_true',
                    help='Skip CHANGELOG.md update.')
    ap.add_argument('--set', dest='set_version', default=None,
                    help='Set an explicit version (e.g. --set 1.16.0). Bypasses classifier; '
                         'useful for retroactive calibration. Use with -m to document the reason.')
    args = ap.parse_args()

    today = datetime.date.today().isoformat()
    old   = read_current_version()

    if args.set_version:
        m = re.match(r'^(\d+)\.(\d+)\.(\d+)$', args.set_version)
        if not m:
            sys.exit(f"error: --set expects X.Y.Z format, got {args.set_version!r}")
        new = (int(m.group(1)), int(m.group(2)), int(m.group(3)))
        level = (
            'major' if new[0] > old[0] else
            'minor' if new[0] == old[0] and new[1] > old[1] else
            'patch' if new[0] == old[0] and new[1] == old[1] and new[2] > old[2] else
            'rewind'  # going backwards, rare
        )
        reasons = [f"explicitly set via --set {args.set_version}"]
        if args.message: reasons.append(args.message)
        analysis = Analysis(level=level, reasons=reasons)
        print_analysis(analysis, old, new)
        if args.dry:
            print(col('dim','(dry run -- no changes written)\n'))
            return 0
        if not args.yes:
            try:
                ans = input(f"  Confirm set version to {col(level, args.set_version)}? [Y/n] ").strip()
            except (EOFError, KeyboardInterrupt):
                print('\naborted.'); return 1
            if ans and ans.lower() != 'y':
                print('aborted.'); return 1
        write_version(new, today)
        if VERSION_FILE_PHP.exists():
            print(f"  {col('dim','-')} grip-version.php updated")
        print(f"  {col('dim','-')} index.html updated")
        if not args.no_changelog:
            write_changelog(new, today, level, analysis, args.message)
            print(f"  {col('dim','-')} CHANGELOG.md updated")
        old_s = f"{old[0]}.{old[1]}.{old[2]}"
        new_s = f"{new[0]}.{new[1]}.{new[2]}"
        print(f"\n  {col('bold','OK')} GRIP {col('dim',old_s)} -> {col(level, new_s)}  "
              f"({today}, {level} / --set)\n")
        return 0

    if args.level:
        # Still analyze so the changelog has signals
        diff    = git_diff(args.against)
        numstat = git_numstat(args.against)
        if diff or numstat:
            analysis = analyze(diff, numstat, args.message)
            analysis.level = args.level
            analysis.reasons.insert(0, f"forced to {args.level} via command line")
        else:
            analysis = Analysis(level=args.level,
                                reasons=[f"forced to {args.level} (no pending diff detected)"])
        new = apply_bump(args.level, old)
        print_analysis(analysis, old, new)
    else:
        diff    = git_diff(args.against)
        numstat = git_numstat(args.against)
        if not diff and not numstat:
            print(col('dim', 'No pending changes detected vs '+args.against+'. Nothing to bump.'))
            return 0
        analysis = analyze(diff, numstat, args.message)
        new = apply_bump(analysis.level, old)
        print_analysis(analysis, old, new)

        if args.dry:
            print(col('dim','(dry run -- no changes written)\n'))
            return 0

        if not args.yes:
            while True:
                new_str = '.'.join(map(str, new))
                prompt = (f"  Apply {col(analysis.level, analysis.level.upper())} bump to "
                          f"{col(analysis.level, new_str)}? "
                          f"[{col('bold','Y')}/n/p(atch)/m(inor)/M(ajor)] ")
                try:
                    ans = input(prompt).strip()
                except (EOFError, KeyboardInterrupt):
                    print('\naborted.')
                    return 1
                if ans == '' or ans.lower() == 'y':
                    break
                elif ans.lower() == 'n':
                    print('aborted.')
                    return 1
                elif ans.lower() == 'p':
                    analysis.level = 'patch'; new = apply_bump('patch', old); break
                elif ans == 'm':
                    analysis.level = 'minor'; new = apply_bump('minor', old); break
                elif ans == 'M':
                    analysis.level = 'major'; new = apply_bump('major', old); break
                else:
                    print("  choose y / n / p / m / M")

    if args.dry:
        print(col('dim','(dry run -- no changes written)\n'))
        return 0

    write_version(new, today)
    if VERSION_FILE_PHP.exists():
        print(f"  {col('dim','-')} grip-version.php updated")
    print(f"  {col('dim','-')} index.html updated")

    if not args.no_changelog:
        write_changelog(new, today, analysis.level, analysis, args.message)
        print(f"  {col('dim','-')} CHANGELOG.md updated")

    old_s = f"{old[0]}.{old[1]}.{old[2]}"
    new_s = f"{new[0]}.{new[1]}.{new[2]}"
    print(f"\n  {col('bold','OK')} GRIP {col('dim',old_s)} -> {col(analysis.level, new_s)}  "
          f"({today}, {analysis.level})\n")
    return 0


if __name__ == '__main__':
    sys.exit(main())
