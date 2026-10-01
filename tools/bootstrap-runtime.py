"""Prepare workspace-local development tools from official distributions."""
from __future__ import annotations

import hashlib
import json
import re
import urllib.request
import zipfile
import zlib
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TOOLS = ROOT / ".runtime" / "tools"
DOWNLOADS = ROOT / ".runtime" / "downloads"


def fetch(url: str) -> bytes:
    request = urllib.request.Request(url, headers={"User-Agent": "CloudPortalLab/1.0"})
    with urllib.request.urlopen(request, timeout=90) as response:
        return response.read()


def unpack(url: str, filename: str, destination: Path, sha256: str | None = None) -> dict:
    archive = DOWNLOADS / filename
    if not archive.exists():
        archive.write_bytes(fetch(url))
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    if sha256 and digest != sha256:
        raise RuntimeError(f"Checksum mismatch: {filename}")
    destination.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(archive) as bundle:
        resolved_root = destination.resolve()
        for member in bundle.infolist():
            candidate = (destination / member.filename).resolve()
            if not candidate.is_relative_to(resolved_root):
                raise RuntimeError("Archive contains an unsafe path")
            if member.is_dir():
                candidate.mkdir(parents=True, exist_ok=True)
                continue
            if candidate.is_file() and candidate.stat().st_size == member.file_size:
                with candidate.open('rb') as current:
                    checksum = 0
                    for chunk in iter(lambda: current.read(1024 * 1024), b''):
                        checksum = zlib.crc32(chunk, checksum)
                if checksum & 0xffffffff == member.CRC:
                    continue
            bundle.extract(member, destination)
    print(f"Prepared {filename}; sha256={digest}", flush=True)
    return {"url": url, "sha256": digest}


def main() -> None:
    TOOLS.mkdir(parents=True, exist_ok=True)
    DOWNLOADS.mkdir(parents=True, exist_ok=True)
    pinned = json.loads((ROOT / 'runtime-manifest.json').read_text(encoding='utf-8'))['runtime'] if (ROOT / 'runtime-manifest.json').exists() else None
    if pinned:
        php_source = pinned['php']
        php_filename = php_source['url'].rsplit('/', 1)[-1]
        manifest = {'php': {'version':php_source['version'], **unpack(php_source['url'],php_filename,TOOLS/'php',php_source['sha256'])}}
    else:
        metadata = json.loads(fetch("https://downloads.php.net/~windows/releases/releases.json"))
        release = metadata["8.4"]
        build_key = next(key for key in release if key.startswith("nts-") and key.endswith("-x64"))
        php_zip = release[build_key]["zip"]
        manifest = {"php": {"version": release["version"], **unpack(
            "https://downloads.php.net/~windows/releases/" + php_zip["path"],
            php_zip["path"], TOOLS / "php", php_zip["sha256"]
        )}}
    composer_path = TOOLS / 'composer.phar'
    composer_digest = pinned['composer']['sha256'] if pinned else fetch("https://getcomposer.org/download/2.10.3/composer.phar.sha256sum").decode().split()[0]
    composer = composer_path.read_bytes() if composer_path.exists() else fetch("https://getcomposer.org/download/2.10.3/composer.phar")
    if hashlib.sha256(composer).hexdigest() != composer_digest:
        raise RuntimeError("Composer checksum mismatch")
    if not composer_path.exists():
        composer_path.write_bytes(composer)
    manifest["composer"] = {"version": "2.10.3", "url": "https://getcomposer.org/download/2.10.3/composer.phar", "sha256": composer_digest}
    print("Prepared Composer", flush=True)
    ca_path = TOOLS / 'cacert.pem'
    ca_checksum = TOOLS / 'cacert.pem.sha256'
    ca_digest = ca_checksum.read_text(encoding='ascii').strip() if ca_checksum.exists() else fetch("https://curl.se/ca/cacert.pem.sha256").decode().split()[0]
    ca_bundle = ca_path.read_bytes() if ca_path.exists() else fetch("https://curl.se/ca/cacert.pem")
    if hashlib.sha256(ca_bundle).hexdigest() != ca_digest:
        raise RuntimeError("CA bundle checksum mismatch")
    if not ca_path.exists():
        ca_path.write_bytes(ca_bundle)
    ca_checksum.write_text(ca_digest + '\n', encoding='ascii')
    if pinned:
        nginx_name=pinned['nginx']['url'].rsplit('/',1)[-1]
        manifest['nginx']=unpack(pinned['nginx']['url'],nginx_name,TOOLS/'nginx',pinned['nginx']['sha256'])
    else:
        nginx_page = fetch("https://nginx.org/en/download.html").decode()
        nginx_names = re.findall(r'href="(?:https://nginx.org)?/download/(nginx-[0-9.]+\.zip)"', nginx_page)
        if not nginx_names:
            raise RuntimeError("Official nginx Windows download not found")
        nginx_name = nginx_names[0]
        manifest["nginx"] = unpack("https://nginx.org/download/" + nginx_name, nginx_name, TOOLS / "nginx")
    mysql_name = "mysql-8.4.11-winx64.zip"
    manifest["mysql"] = {"version": "8.4.11", **unpack(
        "https://cdn.mysql.com/Downloads/MySQL-8.4/" + mysql_name,
        mysql_name, TOOLS / "mysql", pinned['mysql']['sha256'] if pinned else None
    )}
    (TOOLS / "downloads-manifest.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    print("Workspace runtime tools ready", flush=True)


if __name__ == "__main__":
    main()
