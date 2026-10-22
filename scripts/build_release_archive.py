"""Empaqueta fuentes revisables sin datos operativos, credenciales ni dependencias locales."""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import os
import subprocess
from pathlib import Path
import tarfile


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", default="storage/qa/calmina-release.tar.gz")
    args = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    # Solo se incluyen fuentes locales rastreadas o nuevas que no estén ignoradas; no se consulta ningún remoto.
    inventory_result = subprocess.run(["git", "ls-files", "--cached", "--others", "--exclude-standard", "-z"], cwd=root, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    inventory = {os.path.normcase(name) for name in inventory_result.stdout.decode("utf-8").split("\0") if name}
    output = (root / args.output).resolve()
    allowed_output = (root / "storage/qa").resolve()
    if not output.is_relative_to(allowed_output):
        raise SystemExit("El archivo de entrega debe quedar dentro de storage/qa.")
    directories = {"app", "bootstrap", "config", "database", "docker", "docs", "lang", "public", "resources", "routes", "scripts", "tests", "ai_service"}
    root_files = {"Dockerfile", "artisan", ".dockerignore", ".editorconfig", ".gitignore", ".gitattributes", "composer.json", "composer.lock", "package.json", "package-lock.json", "phpunit.xml", "README.md", "jsconfig.json", "vite.config.js", "tailwind.config.js", "postcss.config.js", "playwright.config.js", "render.yaml"}
    blocked = {"vendor", "node_modules", ".git", ".venv", "venv", "__pycache__", ".pytest_cache", "test-results", "uploads"}
    sources: dict[str, bytes] = {}
    candidates = [path for path in root.iterdir() if path.is_file()]
    for directory in sorted(directories):
        source_root = root / directory
        if source_root.is_symlink():
            raise SystemExit(f"La entrega requiere revisar el enlace: {directory}")
        for current, child_directories, file_names in os.walk(source_root):
            child_directories[:] = [name for name in child_directories if name not in blocked]
            candidates.extend(Path(current) / name for name in file_names)
    for path in sorted(candidates):
        relative = path.relative_to(root)
        parts = relative.parts
        if os.path.normcase(relative.as_posix()) not in inventory:
            continue
        if not parts or any(part in blocked for part in parts):
            continue
        if parts[0] not in directories and len(parts) != 1:
            continue
        if parts[:2] in {("public", "build"), ("public", "storage"), ("bootstrap", "cache")}:
            continue
        if path.is_symlink():
            raise SystemExit(f"La entrega requiere revisar el enlace simbólico: {relative}")
        if not path.is_file():
            continue
        if path.name.startswith(".env"):
            if len(parts) != 1 or not path.name.endswith(".example"):
                continue
        elif len(parts) == 1 and path.name not in root_files and not path.name.startswith("docker-compose."):
            continue
        if path.suffix.lower() in {".pem", ".key", ".p12", ".pfx", ".sqlite", ".sqlite3", ".dump", ".bak", ".log"}:
            continue
        if path.stem.lower() in {"credentials", "secrets", "service-account", "service_account", "id_rsa", "id_ed25519"}:
            continue
        if path.suffix.lower() == ".sql" and not relative.as_posix().startswith("docker/"):
            continue
        data = path.read_bytes()
        if path.suffix == ".sh":
            data = data.replace(b"\r\n", b"\n")
        sources[relative.as_posix()] = data
    # Los directorios vacíos de Laravel se recrean sin copiar sus datos locales.
    for relative in ["bootstrap/cache/.gitignore", "storage/app/.gitignore", "storage/framework/cache/.gitignore", "storage/framework/sessions/.gitignore", "storage/framework/views/.gitignore", "storage/logs/.gitignore"]:
        sources[relative] = b"*\n!.gitignore\n"
    hashes = {name: hashlib.sha256(data).hexdigest() for name, data in sorted(sources.items()) if name != "resources/js/ziggy.js"}
    manifest_bytes = json.dumps(hashes, sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode()
    revision = "fuente-" + hashlib.sha256(manifest_bytes).hexdigest()[:24]
    sources["release-source-manifest.json"] = json.dumps({"revision": revision, "files": hashes}, ensure_ascii=False, indent=2).encode()
    output.parent.mkdir(parents=True, exist_ok=True)
    with tarfile.open(output, "w:gz") as archive:
        for name, data in sorted(sources.items()):
            entry = tarfile.TarInfo(name)
            entry.size = len(data)
            entry.mode = 0o755 if name.endswith(".sh") else 0o644
            archive.addfile(entry, io.BytesIO(data))
    with output.open("rb") as stream:
        archive_hash = hashlib.file_digest(stream, "sha256").hexdigest()
    print(json.dumps({"archive": str(output), "revision": revision, "files": len(sources), "bytes": output.stat().st_size, "sha256": archive_hash}))


if __name__ == "__main__":
    main()
