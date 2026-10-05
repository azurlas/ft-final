#!/usr/bin/env python3
import json,time,pathlib
from experiments import kubectl,scale,ready,create_user,purchase,OUT
results=[]
for repetition in range(1,4):
    ns='midterm';a=create_user('http://midterm.k8s.orb.local');started=time.monotonic()
    try:
        scale(ns,'statefulset','redis-queue',0);kubectl(ns,'wait','--for=delete','pod/redis-queue-0','--timeout=60s');time.sleep(4)
        p=purchase(a);pid=p['id'];accepted=time.monotonic();assert p['status']=='pending'
        time.sleep(5);scale(ns,'statefulset','redis-queue',1);ready(ns,'statefulset','redis-queue');restored=time.monotonic()
        deadline=time.monotonic()+90
        while time.monotonic()<deadline:
            p=a.ok('GET',f'/api/purchases/{pid}')
            if p['status'] in ('succeeded','failed','declined'):break
            time.sleep(.5)
        assert p['status']=='succeeded' and len(p['attempts'])==1,p
        result={'scenario':'queue_dependency_outage','repetition':repetition,'purchaseId':pid,'acceptedDuringOutage':True,'status':p['status'],'attempts':len(p['attempts']),'secondsAfterRedisReady':time.monotonic()-restored,'secondsFromAcceptance':time.monotonic()-accepted}
        results.append(result);print(result,flush=True)
    finally:scale(ns,'statefulset','redis-queue',1);ready(ns,'statefulset','redis-queue')
(OUT/'broker-recovery.json').write_text(json.dumps(results,indent=2)+'\n')
