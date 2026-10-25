#!/usr/bin/env python3
import argparse,json,hashlib,zipfile,re
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('--assets',type=Path,required=True);p.add_argument('--kind',choices=['server','plugins'],required=True);a=p.parse_args()
manifest=json.loads((a.assets/'manifest.json').read_text());expected={x['filename'] for x in manifest['packages']}
assert expected and {f.name for f in a.assets.glob('*.zip')}==expected
if a.kind=='plugins':
    source={json.loads(f.read_text())['name']:json.loads(f.read_text())['version'] for f in Path('plugins').glob('*/package.json')}
    assert source=={x['name']:x['version'] for x in manifest['packages']},'All source plugins must be packaged'
for row in manifest['packages']:
    file=a.assets/row['filename'];assert hashlib.sha256(file.read_bytes()).hexdigest()==row['sha256']
    with zipfile.ZipFile(file) as z:
        assert z.testzip() is None;names=z.namelist()
        assert all(not n.startswith('/') and '..' not in Path(n).parts for n in names)
        assert not any(Path(n).name in {'.env','install.lock','oauth-private.key','.DS_Store'} for n in names)
        assert not any(n.endswith('bootstrap/cache/options.php') for n in names)
        assert not any(n.endswith(('.sqlite','.sqlite3','.log')) for n in names)
        if a.kind=='server':
            for needed in ['artisan','bootstrap/app.php','config/app.php','public/index.php','vendor/autoload.php','resources/views/assets/app.twig','public/app/communications.js']:assert needed in names,needed
            assert json.loads(z.read('composer.json'))['name']=='bs-community/blessing-skin-server'
            assert manifest['version'] in z.read('config/app.php').decode()
            installed=json.loads(z.read('vendor/composer/installed.json'))
            assert installed['dev'] is False,'Runtime must contain production dependencies only'
        else:
            prefix=row['name']+'/'
            assert all(n.startswith(prefix) for n in names)
            assert json.loads(z.read(prefix+'package.json'))['version']==row['version']
            assert not any(('tests' in Path(n).parts or n.endswith('phpunit.xml')) and 'vendor' not in Path(n).parts for n in names)
print('Verified',len(expected),'complete runtime package(s)')
