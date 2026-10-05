#!/usr/bin/env python3
import json,csv,pathlib,statistics,collections
root=pathlib.Path('output/evaluation');groups=collections.defaultdict(list);requests=[]
for path in sorted(root.glob('*.json')):
    if path.name.startswith(('summary','inventory')):continue
    j=json.loads(path.read_text())
    if not isinstance(j,dict) or j.get('type')!='availability':continue
    groups[j['namespace'],j['scenario']].append(j)
    requests.extend({'namespace':j['namespace'],'scenario':j['scenario'],'repetition':j['repetition'],**s} for s in j['samples'])
with (root/'requests.csv').open('w',newline='') as file:
    w=csv.DictWriter(file,fieldnames=list(requests[0]),lineterminator='\n');w.writeheader();w.writerows(requests)
summary=[]
for (ns,scenario),runs in sorted(groups.items()):
    def mean(key):
        values=[r['stats'][key] for r in runs if r['stats'][key] is not None];return statistics.mean(values) if values else None
    summary.append({'namespace':ns,'scenario':scenario,'repetitions':len(runs),'requestAvailabilityMean':mean('requestAvailability'),'sampledTimeAvailabilityMean':mean('sampledTimeAvailability'),'mttrSecondsMean':mean('mttrSeconds'),'mtbfOperatingSecondsMean':mean('mtbfOperatingSeconds'),'mttfSecondsMean':mean('mttfSeconds'),'failureRatePerSecondMean':mean('failureRatePerSecond'),'requests':sum(r['stats']['requests'] for r in runs),'failedRequests':sum(r['stats']['failedRequests'] for r in runs),'totalObservationSeconds':sum(r['stats']['windowSeconds'] for r in runs),'noFailureCensoredRuns':sum(r['stats']['mttfRightCensored'] for r in runs)})
(root/'reliability.json').write_text(json.dumps(summary,indent=2)+'\n')
with (root/'reliability.csv').open('w',newline='') as file:
    w=csv.DictWriter(file,fieldnames=list(summary[0]),lineterminator='\n');w.writeheader();w.writerows(summary)
for x in summary:print(x['namespace'],x['scenario'],f"availability {x['requestAvailabilityMean']:.3%}",f"MTTR {x['mttrSecondsMean']}")
