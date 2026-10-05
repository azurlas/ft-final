#!/usr/bin/env python3
import json,urllib.request,urllib.parse,pathlib,base64,os
from experiments import kubectl
root=pathlib.Path('output/evaluation')
def json_get(url,headers=None):return json.load(urllib.request.urlopen(urllib.request.Request(url,headers=headers or {}),timeout=15))
password=pathlib.Path('output/secrets/grafana.env').read_text().strip().split('=',1)[1];headers={'Authorization':'Basic '+base64.b64encode(('admin:'+password).encode()).decode()};base='http://grafana.midterm.k8s.orb.local:3000'
ds=json_get(base+'/api/datasources',headers);assert {'prometheus','victorialogs'}<={d['uid'] for d in ds}
rr=json_get(base+'/api/dashboards/uid/roadrunner-http',headers);app=json_get(base+'/api/dashboards/uid/university-reliability',headers);assert len(rr['dashboard']['panels'])==9
for uid in ['prometheus','victorialogs']:
    health=json_get(base+'/api/datasources/uid/'+uid+'/health',headers);assert health['status']=='OK',health
prom=os.environ.get('PROMETHEUS_URL',base+'/api/datasources/proxy/uid/prometheus')
queries={}
for query in ['rr_http_request_duration_seconds_count','university_purchases','envoy_http_downstream_rq_total']:
    j=json_get(prom+'/api/v1/query?'+urllib.parse.urlencode({'query':query}),headers);assert j['status']=='success' and j['data']['result'],query;queries[query]={'series':len(j['data']['result'])}
logs=urllib.request.urlopen(urllib.request.Request(base+'/api/datasources/proxy/uid/victorialogs/select/logsql/query',data=urllib.parse.urlencode({'query':'kubernetes.pod_namespace:=midterm | unpack_json | filter (_msg:=payment.attempt_completed OR message:=payment.attempt_completed) AND context.request_id:*','limit':3}).encode(),headers=headers),timeout=15).read().decode();assert logs.strip();(root/'payment-logs.jsonl').write_text(logs)
assert '990000000' not in logs and 'cvv' not in logs.lower()
report={'grafanaVersion':json_get(base+'/api/health')['version'],'datasources':[{k:d[k] for k in ('name','uid','type','url')} for d in ds],'officialRoadRunnerPanels':len(rr['dashboard']['panels']),'metrics':queries,'correlatedPaymentLogs':len(logs.strip().splitlines())}
(root/'observability.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps(report,indent=2))
