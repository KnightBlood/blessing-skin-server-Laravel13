#!/usr/bin/env python3
"""Mirror a published release to web-publish and prepare the exact Pages tree."""
import argparse,hashlib,html,importlib.util,json,os,re,shutil,subprocess,tempfile
from pathlib import Path
from urllib.parse import urlparse,quote

spec=importlib.util.spec_from_file_location('release_tools',Path(__file__).with_name('publish-release.py'))
release=importlib.util.module_from_spec(spec);spec.loader.exec_module(release)

def run(args,cwd=None):
    return subprocess.check_output(args,cwd=cwd,text=True).strip()

def stage_site(site,assets,kind,repo,tag,base_url):
    data,files=release.validate_assets(assets)
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._+-]*',tag):raise ValueError('Invalid release tag')
    address=urlparse(base_url)
    if address.scheme!='https' or not address.hostname or address.username or address.password or address.query or address.fragment:
        raise ValueError('Pages base URL must be a public HTTPS URL')
    site.mkdir(parents=True,exist_ok=True)
    if site.is_symlink() or (site/'releases').is_symlink():raise ValueError('Invalid Pages output path')
    target=site/'releases'/tag
    if target.exists():
        old=json.loads((target/'manifest.json').read_text())
        if old['packages']!=data['packages'] or old['commit']!=data['commit']:raise ValueError('Published version already contains different artifacts')
    else:target.mkdir(parents=True)
    for name,file in files.items():
        if Path(name).name!=name or (target/name).is_symlink():raise ValueError('Invalid asset path')
        shutil.copy2(file,target/name)
    release.write_feeds(target,data,kind,repo,tag,base_url=base_url.rstrip('/')+'/releases/'+quote(tag,safe='')+'/')
    release.validate_assets(target)
    feeds=['update.json'] if kind=='server' else ['registry_en.json','registry_zh_CN.json']
    for name in ['manifest.json',*feeds]:shutil.copy2(target/name,site/name)
    checks=[hashlib.sha256((site/name).read_bytes()).hexdigest()+'  '+name+'\n' for name in ['manifest.json',*feeds]]
    checks += [row['sha256']+'  releases/'+tag+'/'+row['filename']+'\n' for row in data['packages']]
    (site/'SHA256SUMS').write_text(''.join(checks))
    (site/'.nojekyll').write_text('')
    if not address.hostname.endswith('.github.io'):(site/'CNAME').write_text(address.hostname+'\n')
    rows=''.join('<tr><td>'+html.escape(row.get('metadata',{}).get('zh_CN',{}).get('title',row['name']))+'</td><td>'+html.escape(row['version'])+'</td><td><a href="releases/'+quote(tag,safe='')+'/'+quote(row['filename'],safe='')+'">ZIP</a></td></tr>' for row in data['packages'])
    title='Release '+data['version'] if kind=='server' else tag
    links=' · '.join('<a href="'+name+'">'+name+'</a>' for name in [*feeds,'manifest.json','SHA256SUMS'])
    (site/'index.html').write_text('<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'+html.escape(title)+'</title><main><h1>'+html.escape(title)+'</h1><p>'+links+'</p><table><thead><tr><th>程序 / 插件</th><th>版本</th><th>下载</th></tr></thead><tbody>'+rows+'</tbody></table></main></html>\n')
    (site/'latest.json').write_text(json.dumps({'tag':tag,'commit':data['commit'],'kind':kind},indent=2)+'\n')
    return data

def publish_branch(remote,assets,kind,repo,tag,base_url,output):
    if output.exists():raise ValueError('Pages output directory already exists')
    with tempfile.TemporaryDirectory(prefix='blessing-pages-') as folder:
        checkout=Path(folder)/'checkout'
        run(['git','init','--quiet','--initial-branch=web-publish',str(checkout)])
        run(['git','remote','add','origin',remote],checkout)
        exists=run(['git','ls-remote','--heads','origin','refs/heads/web-publish'],checkout)
        if exists:
            run(['git','fetch','--quiet','--depth=1','origin','web-publish'],checkout)
            run(['git','checkout','--quiet','-B','web-publish','FETCH_HEAD'],checkout)
        stage_site(checkout,assets,kind,repo,tag,base_url)
        run(['git','add','--all'],checkout)
        changed=subprocess.run(['git','diff','--cached','--quiet'],cwd=checkout).returncode
        if changed not in (0,1):raise RuntimeError('Could not inspect Pages changes')
        if changed:
            run(['git','-c','user.name=github-actions[bot]','-c','user.email=41898282+github-actions[bot]@users.noreply.github.com','-c','commit.gpgsign=false','commit','--quiet','-m','Publish '+tag+' to Pages'],checkout)
            run(['git','push','--quiet','origin','HEAD:refs/heads/web-publish'],checkout)
        shutil.copytree(checkout,output,ignore=shutil.ignore_patterns('.git'))
    print('web-publish ready: '+base_url)

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--kind',choices=['server','plugins'],required=True);parser.add_argument('--base-url',required=True);parser.add_argument('--output',type=Path,required=True)
    args=parser.parse_args();repo=os.environ['GITHUB_REPOSITORY']
    if not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+',repo):raise ValueError('Invalid repository')
    current=json.loads(release.gh('release','view','--repo',repo,'--json','tagName,isDraft'))
    if current['isDraft']:raise ValueError('Pages requires a published release')
    with tempfile.TemporaryDirectory(prefix='blessing-release-assets-') as folder:
        assets=Path(folder)
        release.gh('release','download',current['tagName'],'--repo',repo,'--dir',str(assets))
        publish_branch('https://github.com/'+repo+'.git',assets,args.kind,repo,current['tagName'],args.base_url,args.output.resolve())

if __name__=='__main__':main()
