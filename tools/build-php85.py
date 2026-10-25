#!/usr/bin/env python3
"""Build a clean PHP 8.5 runtime archive from this checkout."""
from pathlib import Path
import argparse,subprocess,shutil,tempfile,zipfile,hashlib,json,os,re
p=argparse.ArgumentParser();p.add_argument('--php',default='php');p.add_argument('--composer',default='composer');p.add_argument('--yarn',default='yarn');p.add_argument('--output',required=True);p.add_argument('--skip-install',action='store_true');a=p.parse_args()
repo=Path(__file__).resolve().parents[1];out=Path(a.output).resolve()
if out.exists():raise SystemExit('Output directory must not exist')
def run(cmd):subprocess.run(cmd,cwd=repo,check=True)
run([a.php,'-r','exit(PHP_VERSION_ID >= 80500 && PHP_VERSION_ID < 80600 ? 0 : 1);'])
if not a.skip_install:
 composer=shutil.which(a.composer) or str(Path(a.composer).resolve())
 run([a.php,composer,'install','--no-dev','--no-scripts','--no-interaction','--prefer-dist','--optimize-autoloader'])
 for cache in (repo/'bootstrap/cache').glob('*.php'):cache.unlink()
 run([a.php,'artisan','package:discover'])
 run([a.yarn,'install','--frozen-lockfile','--non-interactive'])
run([a.yarn,'build'])
assets=repo/'public/app';assets.mkdir(parents=True,exist_ok=True)
for name in ['bg.webp','favicon.ico']:shutil.copy2(repo/'resources/assets/src/images'/name,assets/name)
for f in (repo/'resources/assets/standalone').glob('*.js'):shutil.copy2(f,assets/f.name)
version=json.loads((repo/'package.json').read_text())['version']
assert re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:[._+-][A-Za-z0-9.-]+)?',version)
assert re.search(r"['\"]version['\"]\s*=>\s*['\"]"+re.escape(version)+r"['\"]",(repo/'config/app.php').read_text()),'package.json and config/app.php versions must match'
out.mkdir(parents=True)
with tempfile.TemporaryDirectory(prefix='blessing-php85-') as tmp:
 stage=Path(tmp)
 for name in ['app','bootstrap','config','database','plugins','public','resources/lang','resources/views','resources/misc/textures','routes','storage','vendor','.env.example','artisan','LICENSE','README.md','README-zh.md','index.html','composer.json','composer.lock','CHANGELOG-PATCH.md','BUILD-PHP85.md']:
  source=repo/name;target=stage/name
  if source.is_dir():shutil.copytree(source,target,ignore=shutil.ignore_patterns('.DS_Store','node_modules','.git'))
  elif source.is_file():target.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(source,target)
 for name in ['bootstrap/cache','storage']:
  for f in (stage/name).rglob('*'):
   if f.is_file() and f.name!='.gitignore':f.unlink()
 for name in ['storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs','resources/views/overrides']:(stage/name).mkdir(parents=True,exist_ok=True)
 archive=out/f'blessing-skin-server_{version}_php85.zip'
 with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as z:
  for f in sorted(stage.rglob('*')):
   if f.is_file():z.write(f,str(f.relative_to(stage)))
checksum=hashlib.sha256(archive.read_bytes()).hexdigest()
manifest={'version':version,'commit':os.environ.get('GITHUB_SHA') or subprocess.check_output(['git','rev-parse','HEAD'],cwd=repo,text=True).strip(),'php':'8.5','packages':[{'name':'blessing-skin-server','version':version,'filename':archive.name,'sha256':checksum}]}
(out/'manifest.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n')
(out/'SHA256SUMS').write_text(checksum+'  '+archive.name+'\n'+hashlib.sha256((out/'manifest.json').read_bytes()).hexdigest()+'  manifest.json\n')
print(archive)
