#!/usr/bin/env python3
"""Real parallel WordPress option-state test; fixture callbacks, no provider traffic."""
from pathlib import Path
import json, os, subprocess, sys, tempfile, time, uuid
root=Path(sys.argv[1]).resolve()
assert root.parent.name.startswith('rentfetch-followup-test-'), 'Disposable follow-up Cove site required'
fixture=root/'wp-content/plugins/rentfetch-sync/tests/fixtures/accelerated-coordination-worker.php'
cmd=['wp','--path='+str(root),'--skip-plugins','--skip-themes','eval-file',str(fixture)]
def start(mode,run,index=None,barrier=''):
 env=os.environ.copy();env['RFS_COORDINATION_TEST']=json.dumps(dict(mode=mode,run_id=run,index=index,barrier=barrier))
 return subprocess.Popen(cmd,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
def finish(p):
 out,err=p.communicate(timeout=45)
 assert p.returncode==0,(out+err)[-2500:]
 return out
def run(mode,name,**kw):return finish(start(mode,name,**kw))
for cancelled in [False,True]:
 name='coord-'+uuid.uuid4().hex
 run('setup',name)
 with tempfile.TemporaryDirectory(prefix='rfs-coordination-') as folder:
  workers=[start('worker',name,index=i,barrier=folder) for i in range(5)]
  try:
   deadline=time.monotonic()+25
   while len(list(Path(folder).glob('ready-*')))<5 and time.monotonic()<deadline:time.sleep(.03)
   assert len(list(Path(folder).glob('ready-*')))==5,'All five providers must be in flight before release'
   if cancelled:run('cancel',name)
  finally:
   (Path(folder)/'release').touch()
   for p in workers:finish(p)
  output=run('check',name)
  result=json.loads(next(x.split('=',1)[1] for x in output.splitlines() if x.startswith('COORDINATION_RESULT=')))
  assert result['records']==5,result
  if cancelled:assert result['status']=='cancelled' and result['events']==0,result
  else:
   assert result==dict(completed=5,failed=0,status='complete',events=1,records=5),result
   run('worker',name,index=0,barrier=folder)
   repeated=run('check',name)
   assert 'COORDINATION_RESULT='+json.dumps(result,separators=(',',':')) in repeated,'Duplicate callback changed terminal state or records'
  print(('Cancellation' if cancelled else 'Concurrent completion')+' passed: '+json.dumps(result))
