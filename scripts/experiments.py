#!/usr/bin/env python3
"""Namespace-scoped fault experiments. Always restores replica counts in finally blocks."""
import json,time,uuid,pathlib,subprocess,threading,urllib.request,urllib.error,hashlib,statistics,os,argparse,concurrent.futures
from smoke import Api
KUBECTL=os.environ.get('KUBECTL_BIN','/Users/nariman/.orbstack/bin/kubectl')
if not pathlib.Path(KUBECTL).exists():KUBECTL='kubectl'
OUT=pathlib.Path('output/evaluation');OUT.mkdir(parents=True,exist_ok=True)
def kubectl(ns,*args,input=None):
    assert ns in ('midterm','midterm-baseline','midterm-monitoring','midterm-gateway')
    r=subprocess.run([KUBECTL,'--context','orbstack','-n',ns,*args],input=input,text=True,capture_output=True,timeout=100)
    if r.returncode:raise RuntimeError(r.stderr[-1000:])
    return r.stdout
def scale(ns,kind,name,n):return kubectl(ns,'scale',kind+'/'+name,'--replicas='+str(n))
def ready(ns,kind,name):kubectl(ns,'rollout','status',kind+'/'+name,'--timeout=90s')
def sql(ns,query):
    code='require "vendor/autoload.php"; (new Symfony\\Component\\Dotenv\\Dotenv())->bootEnv(".env"); $p=(new Doctrine\\DBAL\\Tools\\DsnParser(["mysql"=>"pdo_mysql"]))->parse($_ENV["DATABASE_URL"]); $d=Doctrine\\DBAL\\DriverManager::getConnection($p); echo json_encode($d->fetchAllAssociative('+json.dumps(query)+'));'
    return json.loads(kubectl(ns,'exec','deploy/api','--','php','-r',code))
def probe(base,path,samples,stop):
    start=time.monotonic()
    while not stop.is_set():
        tick=time.monotonic();code=0
        try:
            with urllib.request.urlopen(base+path,timeout=3) as r:r.read();code=r.status
        except urllib.error.HTTPError as e:code=e.code
        except (OSError,TimeoutError):pass
        samples.append({'utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'monotonic':tick,'relativeSeconds':tick-start,'status':code,'latencyMs':(time.monotonic()-tick)*1000})
        stop.wait(max(0,.2-(time.monotonic()-tick)))
def statistics_for(samples,end):
    duration=end-samples[0]['monotonic'];unavailable=0;outages=[];open_outage=None
    for i,s in enumerate(samples):
        nxt=samples[i+1]['monotonic'] if i+1<len(samples) else end
        if s['status']!=200:
            unavailable+=nxt-s['monotonic']
            if open_outage is None:open_outage=s['monotonic']
        elif open_outage is not None:outages.append(s['monotonic']-open_outage);open_outage=None
    censored=open_outage is not None
    if censored:outages.append(end-open_outage)
    failures=len(outages);up=duration-unavailable;first=next((s['relativeSeconds'] for s in samples if s['status']!=200),None)
    return {'windowSeconds':duration,'requests':len(samples),'failedRequests':sum(s['status']!=200 for s in samples),'requestAvailability':sum(s['status']==200 for s in samples)/len(samples),'sampledTimeAvailability':up/duration,'outageCount':failures,'mttrSeconds':statistics.mean(outages) if outages and not censored else None,'mtbfOperatingSeconds':up/failures if failures else None,'mttfSeconds':first,'mttfRightCensored':first is None,'censoredAtSeconds':duration if first is None else None,'failureRatePerSecond':failures/duration,'recoveryRightCensored':censored,'maxLatencyMs':max(s['latencyMs'] for s in samples)}
def availability(ns,base,scenario,rep):
    samples=[];stop=threading.Event();path='/api/courses' if scenario=='cache_outage' else '/health/ready';thread=threading.Thread(target=probe,args=(base,path,samples,stop));thread.start();time.sleep(2);injected=time.monotonic()
    try:
        if scenario=='api_crash':
            pod=json.loads(kubectl(ns,'get','pods','-l','component=api','-o','json'))['items'][0]['metadata']['name'];kubectl(ns,'delete','pod',pod,'--grace-period=0','--force','--wait=false');time.sleep(5);ready(ns,'deployment','api')
        else:
            kind,name=('deployment','redis-cache') if scenario=='cache_outage' else ('statefulset','mysql')
            scale(ns,kind,name,0)
            # Confirm deletion, otherwise the "outage" may still have a serving process.
            kubectl(ns,'wait','--for=delete','pod','-l','component='+name,'--timeout=60s');time.sleep(5)
            scale(ns,kind,name,1);ready(ns,kind,name)
        time.sleep(5)
    finally:
        if scenario!='api_crash':scale(ns,kind,name,1);ready(ns,kind,name)
        stop.set();thread.join()
    end=time.monotonic();result={'type':'availability','namespace':ns,'scenario':scenario,'repetition':rep,'endpoint':path,'injectedRelativeSeconds':injected-samples[0]['monotonic'],'stats':statistics_for(samples,end),'samples':samples}
    (OUT/f'{ns}-{scenario}-{rep}.json').write_text(json.dumps(result,indent=2)+'\n');print(ns,scenario,rep,result['stats']['requestAvailability'],flush=True);return result
def create_user(base):
    a=Api(base);login='fault-'+uuid.uuid4().hex[:12];a.ok('POST','/api/register',{'login':login,'username':'Fault Experiment','password':'StudentPass123!'});a.login(login,'StudentPass123!');return a
def purchase(a):return a.ok('POST','/api/courses/3/purchase',json.loads(pathlib.Path('docs/examples/payment-success.json').read_text()),{'Idempotency-Key':'fault-'+uuid.uuid4().hex})
def payment_queue_loss(ns,base,rep):
    workers=2 if ns=='midterm' else 1;a=create_user(base);start=time.monotonic();pid=None
    try:
        scale(ns,'deployment','worker',0);kubectl(ns,'wait','--for=delete','pod','-l','component=worker','--timeout=60s')
        p=purchase(a);pid=p['id'];accepted=time.monotonic()
        if ns=='midterm':
            # Publish using the real relay, then discard the broker copy after MySQL marked it published.
            kubectl(ns,'exec','deploy/api','--','php','bin/console','app:payment-relay','--once')
        before=sql(ns,f'SELECT COUNT(*) n FROM outbox WHERE aggregate_id={pid} AND published_at IS NOT NULL')[0]['n']
        kubectl(ns,'exec','redis-queue-0','--','redis-cli','FLUSHALL')
        scale(ns,'deployment','worker',workers);ready(ns,'deployment','worker')
        deadline=time.monotonic()+25
        while time.monotonic()<deadline:
            p=a.ok('GET',f'/api/purchases/{pid}')
            if p['status'] in ('succeeded','failed','declined'):break
            time.sleep(.5)
        counts=sql(ns,f'SELECT (SELECT COUNT(*) FROM mock_receipts WHERE purchase_id={pid}) receipts,(SELECT COUNT(*) FROM enrollments WHERE purchase_id={pid}) enrollments,(SELECT COUNT(*) FROM payment_attempts WHERE purchase_id={pid}) attempts')[0]
        result={'type':'payment','scenario':'published_queue_loss','namespace':ns,'repetition':rep,'purchaseId':pid,'accepted':True,'publishedOutboxRowsBeforeLoss':int(before),'status':p['status'],'recovered':p['status']=='succeeded','recoverySeconds':time.monotonic()-accepted if p['status']=='succeeded' else None,'observationSeconds':time.monotonic()-accepted,'rightCensored':p['status'] not in ('succeeded','failed','declined'),'counts':{k:int(v) for k,v in counts.items()}}
        if ns=='midterm':assert result['recovered'] and result['counts']=={'receipts':1,'enrollments':1,'attempts':1},result
    finally:scale(ns,'deployment','worker',workers);ready(ns,'deployment','worker')
    (OUT/f'{ns}-queue-loss-{rep}.json').write_text(json.dumps(result,indent=2)+'\n');print(ns,'queue_loss',rep,result['status'],flush=True);return result
def checkpoint_recovery(ns,base,hook,rep):
    assert ns=='midterm';a=create_user(base);start=time.monotonic();workers=2
    try:
        oldpods=[x['metadata']['name'] for x in json.loads(kubectl(ns,'get','pods','-l','component=worker','-o','json'))['items']]
        kubectl(ns,'set','env','deploy/worker','EXPERIMENT_HOOK='+hook);ready(ns,'deployment','worker')
        # Deployment rollout status can complete while old consumers are still terminating.
        # Wait for them to disappear before creating the checkpoint purchase.
        for old in oldpods:kubectl(ns,'wait','--for=delete','pod/'+old,'--timeout=60s')
        p=purchase(a);pid=p['id'];accepted=time.monotonic()
        deadline=time.monotonic()+20;observed=False
        while time.monotonic()<deadline:
            if hook=='after_provider_success':observed=int(sql(ns,f'SELECT COUNT(*) n FROM mock_receipts WHERE purchase_id={pid}')[0]['n'])==1 and a.ok('GET',f'/api/purchases/{pid}')['status']=='processing'
            else:
                observed=int(sql(ns,f'SELECT COUNT(*) n FROM outbox WHERE aggregate_id={pid} AND lease_until IS NOT NULL AND published_at IS NULL')[0]['n'])>0
            if observed:break
            time.sleep(.2)
        if not observed:
            (OUT/f'{ns}-{hook}-aborted-{rep}.json').write_text(json.dumps({'scenario':hook,'purchaseId':pid,'checkpointObserved':False,'status':a.ok('GET',f'/api/purchases/{pid}')['status'],'reason':'Checkpoint was not observed; no crash claim is made'},indent=2)+'\n')
        assert observed,'Checkpoint was not observed'
        checkpoint=time.monotonic();pods=json.loads(kubectl(ns,'get','pods','-l','component=worker','-o','json'))['items']
        for pod in pods:kubectl(ns,'delete','pod',pod['metadata']['name'],'--grace-period=0','--force','--wait=false')
        kubectl(ns,'set','env','deploy/worker','EXPERIMENT_HOOK-');ready(ns,'deployment','worker')
        deadline=time.monotonic()+60
        while time.monotonic()<deadline:
            p=a.ok('GET',f'/api/purchases/{pid}')
            if p['status'] in ('succeeded','failed','declined'):break
            time.sleep(.5)
        counts=sql(ns,f'SELECT (SELECT COUNT(*) FROM mock_receipts WHERE purchase_id={pid}) receipts,(SELECT COUNT(*) FROM enrollments WHERE purchase_id={pid}) enrollments,(SELECT COUNT(*) FROM payment_attempts WHERE purchase_id={pid}) attempts')[0]
        assert p['status']=='succeeded' and all(int(v)==1 for v in counts.values()),(p,counts)
        result={'type':'checkpoint','scenario':hook,'namespace':ns,'repetition':rep,'purchaseId':pid,'checkpointObserved':True,'status':p['status'],'recovered':True,'recoverySeconds':time.monotonic()-checkpoint,'elapsedSeconds':time.monotonic()-accepted,'counts':{k:int(v) for k,v in counts.items()}}
    finally:kubectl(ns,'set','env','deploy/worker','EXPERIMENT_HOOK-');scale(ns,'deployment','worker',workers);ready(ns,'deployment','worker')
    (OUT/f'{ns}-{hook}-{rep}.json').write_text(json.dumps(result,indent=2)+'\n');print(ns,hook,rep,'recovered',flush=True);return result
def load(ns,base):
    def request(_):
        t=time.monotonic()
        try:
            with urllib.request.urlopen(base+'/api/courses',timeout=10) as r:r.read();status=r.status
        except urllib.error.HTTPError as e:status=e.code
        except OSError:status=0
        return {'status':status,'latencyMs':(time.monotonic()-t)*1000}
    started=time.monotonic()
    with concurrent.futures.ThreadPoolExecutor(max_workers=20) as pool:samples=list(pool.map(request,range(300)))
    result={'type':'load','namespace':ns,'concurrency':20,'requests':300,'elapsedSeconds':time.monotonic()-started,'statusCounts':{str(s):sum(x['status']==s for x in samples) for s in set(x['status'] for x in samples)},'p95LatencyMs':sorted(x['latencyMs'] for x in samples)[284],'samples':samples};(OUT/f'{ns}-load.json').write_text(json.dumps(result,indent=2)+'\n');print(ns,'load',result['statusCounts'],flush=True);return result
def main():
    p=argparse.ArgumentParser();p.add_argument('--repetitions',type=int,default=3);p.add_argument('--only',choices=['availability','queue','checkpoints','load','all'],default='all');args=p.parse_args();results=[]
    inventory={'utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'context':'orbstack','repetitions':args.repetitions,'deployments':{ns:json.loads(kubectl(ns,'get','deployments','-o','json')) for ns in ('midterm','midterm-baseline')},'pods':{ns:json.loads(kubectl(ns,'get','pods','-o','json')) for ns in ('midterm','midterm-baseline')},'configHashes':{ns:hashlib.sha256(kubectl(ns,'get','configmap','university-config','-o','json').encode()).hexdigest() for ns in ('midterm','midterm-baseline')}}
    # Deployment env contains only secret references, never generated values.
    (OUT/'inventory.json').write_text(json.dumps(inventory,indent=2)+'\n')
    for ns,base in [('midterm-baseline','http://baseline.midterm.k8s.orb.local:8080'),('midterm','http://midterm.k8s.orb.local')]:
        for rep in range(1,args.repetitions+1):
            if args.only in ('availability','all'):
                for scenario in ('api_crash','cache_outage','mysql_outage'):results.append(availability(ns,base,scenario,rep))
            if args.only in ('queue','all'):results.append(payment_queue_loss(ns,base,rep))
        if args.only in ('load','all'):results.append(load(ns,base))
    if args.only in ('checkpoints','all'):
        for rep in range(1,args.repetitions+1):
            for hook in ('after_provider_success','after_outbox_publish'):results.append(checkpoint_recovery('midterm','http://midterm.k8s.orb.local',hook,rep))
    (OUT/f'summary-{args.only}.json').write_text(json.dumps([{k:v for k,v in x.items() if k!='samples'} for x in results],indent=2)+'\n')
if __name__=='__main__':main()
