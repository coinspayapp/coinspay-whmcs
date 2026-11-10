"""
Builds dist/coinspay-whmcs-<version>.zip: the files to upload into the root of WHMCS, and the
installation guides.

    python bin/build.py
"""

import pathlib
import re
import sys
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
FILES = [
    "modules/gateways/coinspay.php",
    "modules/gateways/callback/coinspay.php",
    "modules/gateways/coinspay",
    "includes/hooks/coinspay.php",
]
DOCS = ["docs/install-en.md", "docs/install-ru.md", "LICENSE"]


def version() -> str:
    match = re.search(r"const VERSION = '([^']+)'", (ROOT / "modules/gateways/coinspay/Settings.php").read_text(encoding="utf-8"))
    if not match:
        sys.exit("no VERSION in Settings.php")
    return match.group(1)


def main() -> None:
    target = ROOT / "dist" / f"coinspay-whmcs-{version()}.zip"
    target.parent.mkdir(exist_ok=True)
    with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED) as archive:
        for entry in FILES:
            path = ROOT / entry
            paths = sorted(p for p in path.rglob("*") if p.is_file()) if path.is_dir() else [path]
            for file in paths:
                archive.write(file, "upload/" + file.relative_to(ROOT).as_posix())
        for doc in DOCS:
            if (ROOT / doc).is_file():
                archive.write(ROOT / doc, pathlib.Path(doc).name)
    names = zipfile.ZipFile(target).namelist()
    print(f"{target.relative_to(ROOT)}: {len(names)} files")


if __name__ == "__main__":
    main()
