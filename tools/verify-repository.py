"""Check local plan links, handoff coverage, lock digests, and private-value exclusion."""
from __future__ import annotations

import hashlib
import json
import pathlib
import re
import subprocess
import urllib.parse

ROOT = pathlib.Path(__file__).resolve().parents[1]
issues: list[str] = []


def main() -> None:
    files = subprocess.check_output(['git', '-c', f'safe.directory={ROOT.as_posix()}', 'ls-files', '--cached', '--others', '--exclude-standard'], cwd=ROOT, text=True).splitlines()
    texts = {}
    for name in files:
        path = ROOT / name
        if path.is_file():
            try:
                texts[name] = path.read_text(encoding='utf-8-sig')
            except UnicodeError:
                pass
    for name, content in texts.items():
        if not name.endswith('.md'):
            continue
        for target in re.findall(r'\]\(([^)]+)\)', content):
            target = target.strip('<>').split('#', 1)[0]
            if not target or re.match(r'^(https?|mailto|codex|app):', target):
                continue
            target = urllib.parse.unquote(target)
            if not (ROOT / name).parent.joinpath(target).exists():
                issues.append(f'Broken local link in {name}')
    plans = []
    for name in ['identity-console-billing-notifications', 'jobs-vmm-vm', 'foundation-infrastructure-operations']:
        text = (ROOT / f'docs/pmt-docs/implementation-details/{name}.md').read_text(encoding='utf-8')
        chunks = re.split(r'^### FS-(\d{3})[^\n]*\n', text, flags=re.M)
        for index in range(1, len(chunks), 2):
            identifier, body = chunks[index:index + 2]
            plans.append(identifier)
            for label in ['목적', '추가 범위', '수정 범위', '삭제 범위', 'goal', 'non-goal', '목표-input', '목표-output', '구현 동작', '책임·의존 계약', '코드동작확인 Test방식', '코드동작확인 로깅방식', '완료·반환 증거']:
                if f'**{label}:**' not in body:
                    issues.append(f'FS-{identifier}: missing {label}')
    if sorted(plans) != [f'{index:03}' for index in range(1, 59)]:
        issues.append('Expected one detailed plan for each of 58 feature IDs')
    manifest = json.loads((ROOT / 'runtime-manifest.json').read_text(encoding='utf-8'))
    for app, entry in manifest['apps'].items():
        digest = hashlib.sha256((ROOT / f'apps/{app}/composer.lock').read_bytes()).hexdigest()
        if digest != entry['composer_lock_sha256']:
            issues.append(f'Lock digest mismatch: {app}')
    for filename, expected in manifest['frontend']['bootstrap']['files'].items():
        if hashlib.sha256((ROOT / filename).read_bytes()).hexdigest() != expected:
            issues.append(f'Asset digest mismatch: {filename}')
    private_config = ROOT / '.runtime/local.json'
    if private_config.exists():
        config = json.loads(private_config.read_text(encoding='utf-8'))
        secrets = [value for key, value in config.items() if ('password' in key or 'secret' in key) and isinstance(value, str)]
        for name, content in texts.items():
            if any(secret and secret in content for secret in secrets):
                issues.append(f'Private runtime value present in repository file: {name}')
    if issues:
        print('\n'.join(sorted(set(issues))))
        raise SystemExit(1)
    print('Repository checks passed: 58 detailed plans, local links, lock digests, private values excluded')


if __name__ == '__main__':
    main()
