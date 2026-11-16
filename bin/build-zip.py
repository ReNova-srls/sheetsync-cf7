"""Builds dist/sheetsync-cf7-<version>.zip, ready for Plugins > Add New > Upload.

Usage (from the repository root): python bin/build-zip.py
Files listed in .distignore are left out. The version is read from the plugin header.
"""
import pathlib
import re
import zipfile

ROOT = pathlib.Path(__file__).resolve().parent.parent
SLUG = "sheetsync-cf7"

header = (ROOT / f"{SLUG}.php").read_text(encoding="utf-8")
version = re.search(r"^\s*\*\s*Version:\s*(\S+)", header, re.M).group(1)

ignored = []
for line in (ROOT / ".distignore").read_text(encoding="utf-8").splitlines():
    line = line.strip()
    if line and not line.startswith("#"):
        ignored.append(line.lstrip("/"))


def is_ignored(rel: str) -> bool:
    parts = rel.split("/")
    return any(rel == entry or parts[0] == entry or parts[-1] == entry for entry in ignored)


out_dir = ROOT / "dist"
out_dir.mkdir(exist_ok=True)
out = out_dir / f"{SLUG}-{version}.zip"

files = sorted(
    p for p in ROOT.rglob("*")
    if p.is_file() and not is_ignored(p.relative_to(ROOT).as_posix())
)

with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as archive:
    for path in files:
        archive.write(path, f"{SLUG}/{path.relative_to(ROOT).as_posix()}")

print(f"{out.relative_to(ROOT)}: {len(files)} files")
