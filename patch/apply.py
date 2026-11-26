#!/usr/bin/env python3
"""Patch the exact upstream beta.3 checkout; no changes to live panel files."""
import argparse, hashlib, pathlib, shutil
P=argparse.ArgumentParser();P.add_argument('checkout',type=pathlib.Path); args=P.parse_args()
root=args.checkout.resolve(); here=pathlib.Path(__file__).resolve().parent
base='resources/scripts/components/admin/servers/'
files={base+'CreateServerForm.tsx':'784b4f38d9c8b8296a0ae8a21b4fb831aeadde67',base+'ServerBuildTab.tsx':'06b7b913d3886eb4657cab4e717237f93ad1e512','resources/scripts/api/admin/servers/queries.ts':'77984baced59e262a997b4f8bba9525afd0a50da'}
def blob(b): return hashlib.sha1(f'blob {len(b)}\0'.encode()+b).hexdigest()
def once(s,a,b):
    if s.count(a)!=1: raise RuntimeError('Expected exactly one anchor: '+a[:100])
    return s.replace(a,b,1)
def span(s,a,b,replacement):
    if s.count(a)!=1: raise RuntimeError('Start anchor not unique: '+a)
    i=s.index(a); j=s.index(b,i+len(a)); return s[:i]+replacement+s[j:]
original={}
for path,expected in files.items():
    b=(root/path).read_bytes()
    if blob(b)!=expected: raise SystemExit(f'Unexpected source version: {path}; refusing to patch')
    original[path]=b.decode()
target=root/(base+'DynamicNetwork.tsx')
if target.exists(): raise SystemExit('DynamicNetwork.tsx already exists; use a clean checkout')
# Native create form: leave node selector, replace the two allocation pickers.
s=original[base+'CreateServerForm.tsx']
s=once(s,"import { type ReactNode, useRef, useState } from 'react';", "import { type ReactNode, useRef, useState } from 'react';\nimport { DynamicNetworkFields, useDynamicNetwork, type DynamicNetworkState } from './DynamicNetwork';")
s=once(s,'    useAdminServerUnassignedAllocations,\n','');s=once(s,'    allocationLabel,\n','')
s=once(s,'    onNodeChange,\n}: {','    onNodeChange,\n    network,\n}: {')
s=once(s,'    onNodeChange: (nodeId: number) => void;\n}) => {','    onNodeChange: (nodeId: number) => void;\n    network: DynamicNetworkState;\n}) => {')
s=span(s,'    const { data: allocationsResponse, isFetching: loading } = useAdminServerUnassignedAllocations(nodeId);','    return (\n        <TitledGreyBox title=\'Allocation Management\'>','')
s=once(s,"            <div className='relative grid grid-cols-1 md:grid-cols-3 gap-6'>\n                {loading && <Spinner size='small' centered />}\n","            <div className='grid grid-cols-1 md:grid-cols-2 gap-6'>\n")
s=span(s,"                <div>\n                    <form.AppField\n                        name='allocationId'",'            </div>\n        </TitledGreyBox>\n    );\n};',"                <DynamicNetworkFields network={network} />\n")
s=once(s,'                    createAdminServerInput({\n','                    { ...createAdminServerInput({\n')
s=once(s,'                        extensions: extensionFormPayload(value.extensions),\n                    })','                        extensions: extensionFormPayload(value.extensions),\n                    }), dynamic: network.payload() }')
s=once(s,'    const eggId = useStore(form.store, (state) => Number(state.values.eggId ?? 0));\n    const isSubmitting = useStore(form.store, (state) => state.isSubmitting);','    const eggId = useStore(form.store, (state) => Number(state.values.eggId ?? 0));\n    const network = useDynamicNetwork(nodeId, eggId);\n    const isSubmitting = useStore(form.store, (state) => state.isSubmitting);')
s=once(s,'<AllocationBox form={form} locations={locations} nodeId={nodeId} onNodeChange={setNodeId} />','<AllocationBox form={form} locations={locations} nodeId={nodeId} onNodeChange={setNodeId} network={network} />')
# Native build form: resources untouched; replace allocation inputs and submit through native mutation wrapper.
t=original[base+'ServerBuildTab.tsx']
t=once(t,"import { useStore } from '@tanstack/react-form';","import { DynamicNetworkFields, useDynamicNetwork } from './DynamicNetwork';")
t=once(t,'    useAdminServerBuildAllocations,\n','');t=once(t,'    useAdminServerUnassignedAllocations,\n','');t=once(t,'    allocationLabel,\n','')
t=span(t,'    const { data: assignedResponse } = useAdminServerBuildAllocations','    const form = useAppForm({','    const network = useDynamicNetwork(attributes.node, attributes.egg, attributes.id);\n\n')
t=once(t,'                await updateServerBuild.mutateAsync(\n                    updateAdminServerBuildInput(attributes.id, serverBuildBodyFromFormValues(payload))\n                );\n                form.reset({ ...value, addAllocations: [], removeAllocations: [] });','                const updated = await updateServerBuild.mutateAsync({ ...updateAdminServerBuildInput(attributes.id, serverBuildBodyFromFormValues(payload)), dynamic: network.payload() });\n                form.reset({ ...value, allocationId: updated.attributes.allocation, addAllocations: [], removeAllocations: [] });\n                network.refresh();')
t=span(t,'    const defaultAllocationId = useStore','    return (\n        <Form form={form}>','')
t=span(t,"                    <TitledGreyBox title='Allocation Management'>\n",'                    </TitledGreyBox>\n                </div>',"                    <TitledGreyBox title='Allocation Management'>\n                        <DynamicNetworkFields network={network} />\n")
# Native mutation handlers retain success/error notifications and query invalidation.
u=original['resources/scripts/api/admin/servers/queries.ts']
u=once(u,"import { notifyHttpError } from '@/plugins/notifications';","import { notifyHttpError } from '@/plugins/notifications';\nimport http from '@/api/http';\nimport type { DynamicNetworkPayload } from '@/components/admin/servers/DynamicNetwork';")
for action, data, endpoint, body in [('adminCreateServerMutation','AdminCreateServerData',"'/api/admin/extensions/dynamic-allocations/create'",'server'),('adminUpdateServerBuildMutation','AdminUpdateServerBuildData',"`/api/admin/extensions/dynamic-allocations/servers/${variables.path.server_admin_identifier}/network`",'build')]:
    method='post' if body=='server' else 'patch'
    old=f'        ...{action}(),'
    new=f'''        ...{action}(),
        mutationFn: async (variables: Options<{data}> & {{ dynamic?: DynamicNetworkPayload }}, context) => {{
            if (!variables.dynamic) return {action}().mutationFn!(variables, context);
            const {{ data }} = await http.{method}<AdminServer>({endpoint}, {{ ...variables.dynamic, {body}: variables.body }});
            return data;
        }},'''
    u=once(u,old,new)
for path, content in [(base+'CreateServerForm.tsx',s),(base+'ServerBuildTab.tsx',t),('resources/scripts/api/admin/servers/queries.ts',u)]:
    (root/path).write_text(content)
shutil.copyfile(here/'DynamicNetwork.tsx',target)
print('Patched the three verified beta.3 sources; added DynamicNetwork.tsx.')
