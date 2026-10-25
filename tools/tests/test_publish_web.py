import importlib.util,json,hashlib,subprocess,tempfile,unittest
from pathlib import Path
spec=importlib.util.spec_from_file_location('web',Path(__file__).resolve().parents[1]/'publish-web.py');web=importlib.util.module_from_spec(spec);spec.loader.exec_module(web)

class WebPublishTest(unittest.TestCase):
 def setUp(self):
  self.temp=tempfile.TemporaryDirectory();self.root=Path(self.temp.name);self.assets=self.root/'assets';self.assets.mkdir();self.site=self.root/'site'
 def tearDown(self):self.temp.cleanup()
 def assets_for(self,kind='server',version='1.0.0'):
  rows=[]
  for name in (['server'] if kind=='server' else ['one','two']):
   f=self.assets/(name+'.zip');f.write_bytes(('runtime '+version).encode())
   rows.append({'name':name,'version':version,'filename':f.name,'sha256':hashlib.sha256(f.read_bytes()).hexdigest(),'metadata':{locale:{'name':name,'version':version,'title':name,'description':'Plugin','author':'Author'} for locale in ['en','zh_CN']}})
  data={'version':version,'commit':'a'*40,'packages':rows};(self.assets/'manifest.json').write_text(json.dumps(data))
  (self.assets/'SHA256SUMS').write_text(''.join(hashlib.sha256(f.read_bytes()).hexdigest()+'  '+f.name+'\n' for f in self.assets.iterdir() if f.name!='SHA256SUMS'))
  return data
 def test_server_feed_points_to_pages_and_zip_bytes_unchanged(self):
  data=self.assets_for();web.stage_site(self.site,self.assets,'server','owner/repo','v1.0.0','https://skin.example.test')
  feed=json.loads((self.site/'update.json').read_text());self.assertEqual(feed['url'],'https://skin.example.test/releases/v1.0.0/server.zip');self.assertEqual(feed['sha256'],data['packages'][0]['sha256'])
  self.assertEqual((self.site/'releases/v1.0.0/server.zip').read_bytes(),(self.assets/'server.zip').read_bytes());self.assertEqual((self.site/'CNAME').read_text(),'skin.example.test\n');self.assertTrue((self.site/'.nojekyll').exists())
  web.release.validate_assets(self.site/'releases/v1.0.0')
 def test_all_plugins_have_individual_pages_downloads_in_both_languages(self):
  self.assets_for('plugins');web.stage_site(self.site,self.assets,'plugins','owner/repo','20261025-patch','https://plugins.example.test')
  for locale in ['en','zh_CN']:
   feed=json.loads((self.site/('registry_'+locale+'.json')).read_text());self.assertEqual(len(feed['packages']),2)
   for row in feed['packages']:self.assertTrue(row['dist']['url'].startswith('https://plugins.example.test/releases/20261025-patch/'))
 def test_new_version_preserves_previous_download_and_unrelated_files(self):
  self.assets_for();web.stage_site(self.site,self.assets,'server','owner/repo','v1.0.0','https://skin.example.test');(self.site/'other.txt').write_text('keep')
  self.assets_for(version='1.0.1');web.stage_site(self.site,self.assets,'server','owner/repo','v1.0.1','https://skin.example.test')
  self.assertTrue((self.site/'releases/v1.0.0/server.zip').exists());self.assertEqual((self.site/'other.txt').read_text(),'keep');self.assertEqual(json.loads((self.site/'update.json').read_text())['latest'],'1.0.1')
 def test_repeated_version_is_idempotent_but_replacement_is_rejected(self):
  self.assets_for();web.stage_site(self.site,self.assets,'server','owner/repo','v1.0.0','https://skin.example.test');web.stage_site(self.site,self.assets,'server','owner/repo','v1.0.0','https://skin.example.test')
  self.assets_for(version='9.0.0')
  with self.assertRaises(ValueError):web.stage_site(self.site,self.assets,'server','owner/repo','v1.0.0','https://skin.example.test')
 def test_corrupt_artifact_cannot_update_feed(self):
  self.assets_for();(self.assets/'server.zip').write_bytes(b'corrupt')
  with self.assertRaises(ValueError):web.stage_site(self.site,self.assets,'server','owner/repo','v1.0.0','https://skin.example.test')
  self.assertFalse((self.site/'update.json').exists())
 def test_local_git_publication_only_creates_web_branch_and_can_repeat(self):
  self.assets_for();bare=self.root/'remote.git';subprocess.run(['git','init','--quiet','--bare',str(bare)],check=True)
  web.publish_branch(str(bare),self.assets,'server','owner/repo','v1.0.0','https://skin.example.test',self.root/'first')
  sha=subprocess.check_output(['git','--git-dir='+str(bare),'rev-parse','refs/heads/web-publish'],text=True).strip()
  web.publish_branch(str(bare),self.assets,'server','owner/repo','v1.0.0','https://skin.example.test',self.root/'second')
  self.assertEqual(sha,subprocess.check_output(['git','--git-dir='+str(bare),'rev-parse','refs/heads/web-publish'],text=True).strip());self.assertTrue((self.root/'second/update.json').exists());self.assertFalse((self.root/'second/.git').exists())
if __name__=='__main__':unittest.main()
