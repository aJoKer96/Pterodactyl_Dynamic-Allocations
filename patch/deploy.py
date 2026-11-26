#!/usr/bin/env python3
"""Install built assets by switching the manifest last. Old hashed assets remain for rollback."""
import argparse, base64, hashlib, json, os, pathlib, re, shutil, stat, tempfile
P=argparse.ArgumentParser();sub=P.add_subparsers(dest='action',required=True)
for name in ('install','rollback'):
    p=sub.add_parser(name)
    if name=='install': p.add_argument('checkout',type=pathlib.Path)
    p.add_argument('panel',type=pathlib.Path);p.add_argument('backup',type=pathlib.Path)
a=P.parse_args();panel=a.panel.resolve();backup=a.backup.resolve()
if os.geteuid()!=0: raise SystemExit('Deploy as root (build as unprivileged user).')
if not re.search(r"['\"]version['\"]\s*=>\s*['\"]2\.0\.0-beta\.3['\"]",(panel/'config/app.php').read_text()): raise SystemExit('Only installed Panel 2.0.0-beta.3 is supported.')
if not (panel/'storage/framework/down').is_file(): raise SystemExit('Put the panel into file-based maintenance mode first (php artisan down).')
dest=panel/'public/assets';live=dest/'manifest.json'
if not dest.is_dir() or dest.is_symlink() or live.is_symlink(): raise SystemExit('Unexpected asset directory or manifest.')
def digest(path): return hashlib.sha256(path.read_bytes()).hexdigest()
def atomic(src,dst,uid,gid,mode=0o644):
    fd,tmp=tempfile.mkstemp(prefix='.dynamic-allocations-',dir=dst.parent)
    try:
        with os.fdopen(fd,'wb') as f:
            with src.open('rb') as source: shutil.copyfileobj(source,f)
            f.flush();os.fsync(f.fileno());os.fchown(f.fileno(),uid,gid);os.fchmod(f.fileno(),mode)
        os.replace(tmp,dst)
    finally:
        if os.path.exists(tmp): os.unlink(tmp)
if a.action=='rollback':
    info=json.loads((backup/'metadata.json').read_text())
    if info['panel']!=str(panel) or digest(live)!=info['new_manifest']: raise SystemExit('Manifest differs from the installed patch. Do not overwrite a newer panel build.')
    old=backup/'manifest.json'
    if digest(old)!=info['old_manifest']: raise SystemExit('Backup hash mismatch.')
    atomic(old,live,info['uid'],info['gid'],info['mode']);print('Stock manifest restored. New hashed assets were left unused.');raise SystemExit(0)
checkout=a.checkout.resolve();source=checkout/'public/assets';manifest=source/'manifest.json'
marker=(checkout/'.dynamic-allocations-build-ok').read_text().split()[0]
if marker!=digest(manifest): raise SystemExit('Missing or invalid successful-build marker.')
if not (checkout/'resources/scripts/components/admin/servers/DynamicNetwork.tsx').is_file(): raise SystemExit('Missing native source patch.')
installed=json.loads((panel/'extensions/dynamic-allocations/extension.json').read_text())
if installed['id']!='dynamic-allocations' or installed['version']!='0.4.1': raise SystemExit('Install/enable extension v0.4.1 first.')
if backup.exists(): raise SystemExit('Backup path must be new.')
if backup.is_relative_to(panel): raise SystemExit('Keep backup outside the panel tree.')
entries=json.loads(manifest.read_text()); assets=set()
def verify_name(name):
    p=pathlib.PurePosixPath(name)
    # Vite beta.3 emits flat filenames, not extension directories.
    if len(p.parts)!=1 or not re.fullmatch(r'[A-Za-z0-9_.@+-]+',name) or name in ('.','..','manifest.json') or name.startswith('.'): raise SystemExit('Unexpected asset path: '+name)
    path=source/name
    if not path.is_file() or path.is_symlink(): raise SystemExit('Missing asset: '+name)
    assets.add(name);return path
def verify_sri(name,sri):
    path=verify_name(name)
    if sri!='sha384-'+base64.b64encode(hashlib.sha384(path.read_bytes()).digest()).decode(): raise SystemExit('SRI mismatch: '+name)
for e in entries.values():
    verify_sri(e['file'],e['integrity'])
    for f in e.get('css',[]): verify_sri(f,e['cssIntegrity'][f])
    for f in e.get('assets',[]): verify_name(f)
# Check before writing anything; never replace an existing hashed file with other bytes.
for f in assets:
    p=dest/f
    if p.is_symlink() or (p.exists() and (not p.is_file() or digest(p)!=digest(source/f))): raise SystemExit('Existing asset differs: '+f)
st=live.stat();backup.mkdir(mode=0o700,parents=True);shutil.copy2(live,backup/'manifest.json')
info={'panel':str(panel),'old_manifest':digest(live),'new_manifest':digest(manifest),'uid':st.st_uid,'gid':st.st_gid,'mode':stat.S_IMODE(st.st_mode)}
(backup/'metadata.json').write_text(json.dumps(info,indent=2)+'\n')
for f in sorted(assets):
    if not (dest/f).exists(): atomic(source/f,dest/f,st.st_uid,st.st_gid)
atomic(manifest,live,st.st_uid,st.st_gid,stat.S_IMODE(st.st_mode))
print(f'Installed {len(assets)} verified assets; manifest switched. Rollback backup: {backup}')
