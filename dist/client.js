import React, {useEffect, useState} from 'react';
import {definePterodactylExtension, http} from '@pterodactyl/sdk';
const h=React.createElement;
const endpoint='/api/admin/extensions/dynamic-allocations';
const style={width:'100%',padding:'8px 10px',border:'1px solid #8793a2',borderRadius:6,background:'transparent',color:'inherit'};
const section={border:'1px solid #8793a2',borderRadius:8,padding:16,marginBottom:16};
const row={display:'grid',gridTemplateColumns:'repeat(auto-fit,minmax(210px,1fr))',gap:14};
const errorText=e=>Object.values(e?.response?.data?.errors??{}).flat().map(x=>typeof x==='string'?x:x?.detail??x?.message??'Validation error').join('; ')||e?.response?.data?.message||e?.message||'Request failed';
function field(label,props,choices){return h('label',{style:{display:'block',marginBottom:12}},h('span',{style:{display:'block',fontWeight:600,marginBottom:4}},label),choices?h('select',{...props,style},choices.map(([v,t])=>h('option',{key:v,value:v,style:{color:'#111',background:'#fff'}},t))):h('input',{...props,style}));}
function CreateScreen(){
 const [options,setOptions]=useState(null),[failure,setFailure]=useState(''),[result,setResult]=useState(null),[busy,setBusy]=useState(false);
 const [f,setF]=useState({node_id:'',ip:'',primary:'',additional:'',owner_id:'',egg_id:'',name:'',description:'',docker_image:'',startup:'',environment:{},memory:'512',disk:'1024',cpu:'100',swap:'0',io:'500',threads:'',database_limit:'0',backup_limit:'1',allocation_limit:'0',start_on_completion:false});
 const update=(key,value)=>setF(old=>({...old,[key]:value}));
 useEffect(()=>{let live=true;http.get(endpoint+'/options').then(({data})=>{if(live)setOptions(data);}).catch(e=>{if(live)setFailure(errorText(e));});return()=>{live=false;};},[]);
 const node=options?.nodes.find(n=>String(n.id)===f.node_id),egg=options?.eggs.find(n=>String(n.id)===f.egg_id);
 const text=(key,label,more={})=>field(label,{value:f[key],onChange:e=>update(key,e.target.value),...more});
 const num=(key,label,min=0)=>text(key,label,{type:'number',required:true,min,step:1});
 const choice=(key,label,choices,onChange)=>field(label,{value:f[key],required:true,onChange:onChange??(e=>update(key,e.target.value))},[['','Select…'],...choices]);
 async function submit(e){e.preventDefault();if(busy||result)return;setFailure('');setBusy(true);try{
  const server={name:f.name,description:f.description,owner_id:Number(f.owner_id),egg_id:Number(f.egg_id),docker_image:f.docker_image,startup:f.startup,environment:f.environment,memory:Number(f.memory),disk:Number(f.disk),cpu:Number(f.cpu),swap:Number(f.swap),io:Number(f.io),threads:f.threads||null,database_limit:Number(f.database_limit),backup_limit:Number(f.backup_limit),allocation_limit:Number(f.allocation_limit),start_on_completion:f.start_on_completion};
  const {data}=await http.post(endpoint+'/create',{node_id:Number(f.node_id),ip:f.ip,primary:f.primary,additional:f.additional,server});setResult(data?.attributes?.id??data?.data?.attributes?.id??true);
 }catch(e){setFailure(errorText(e)+' — Check the native server list before retrying if the outcome is uncertain.');}finally{setBusy(false);}}
 return h('div',{style:{maxWidth:1000,margin:'0 auto',padding:16}},
 h('h1',{style:{fontSize:26,fontWeight:700,marginBottom:8}},'Create server with dynamic allocations'),
 h('p',{style:{marginBottom:16}},'Choose an existing node IP and enter the ports directly. No extra host service. Preview version: use only on a test panel and avoid parallel provisioning.'),
 failure&&h('p',{role:'alert',style:{border:'1px solid #b66',padding:12,marginBottom:12}},failure),
 result&&h('p',{role:'status',style:{border:'1px solid #6a8',padding:12}},'Server creation accepted. ID: '+(result===true?'see server list':result)+'. Check installation in the native admin server list.'),
 !options?h('p',null,failure?'Could not load configuration.':'Loading…'):h('form',{onSubmit:submit},h('fieldset',{disabled:busy||!!result,style:{border:0,padding:0,minWidth:0}},
 h('section',{style:section},h('h2',null,'Server and egg'),h('div',{style:row},text('name','Server name',{required:true,maxLength:191}),choice('owner_id','Owner',options.users.map(u=>[String(u.id),u.username])),choice('egg_id','Egg',options.eggs.map(x=>[String(x.id),x.name]),e=>{const selected=options.eggs.find(x=>String(x.id)===e.target.value);setF(old=>({...old,egg_id:e.target.value,docker_image:selected?.images[0]??'',startup:selected?.startup??'',environment:Object.fromEntries((selected?.variables??[]).map(v=>[v.key,v.default]))}));})),text('description','Description'),text('docker_image','Docker image',{required:true}),egg?.images.length>1&&h('div',null,'Available images: '+egg.images.join(', ')),text('startup','Startup command',{required:true}),...((egg?.variables??[]).map(v=>field(v.name+' ('+v.key+')',{key:v.key,value:f.environment[v.key]??'',title:v.description,onChange:e=>setF(old=>({...old,environment:{...old.environment,[v.key]:e.target.value}}))})))),
 h('section',{style:section},h('h2',null,'Network'),h('div',{style:row},choice('node_id','Node',options.nodes.map(n=>[String(n.id),n.name]),e=>{const n=options.nodes.find(x=>String(x.id)===e.target.value);setF(old=>({...old,node_id:e.target.value,ip:n?.ips.length===1?n.ips[0]:''}));}),choice('ip','Bind IP',(node?.ips??[]).map(x=>[x,x])),text('primary','Primary port',{required:true,pattern:'[0-9]{1,5}',inputMode:'numeric',placeholder:'80'}),text('additional','Additional ports (comma separated)',{placeholder:'443, 25565'})),node&&node.ips.length===0&&h('p',null,'Register the first IP on this node using native allocation management.'),h('p',null,'Ports 1–65535. Same numeric port on another address of this node is conservatively treated as a conflict. Host listeners must be checked separately.')),
 h('section',{style:section},h('h2',null,'Resources'),h('div',{style:row},num('memory','RAM (MiB)'),num('disk','Disk (MiB)'),num('cpu','CPU (%)'),num('swap','Swap (MiB)',-1),num('io','Block IO weight',10),text('threads','CPU threads (optional)'),num('database_limit','Database limit'),num('backup_limit','Backup limit'),num('allocation_limit','User allocation limit')),h('label',null,h('input',{type:'checkbox',checked:f.start_on_completion,onChange:e=>update('start_on_completion',e.target.checked)}),' Start after installation')),
 h('button',{type:'submit',disabled:busy||!!result,style:{...style,width:'auto',fontWeight:700,cursor:'pointer'}},busy?'Creating…':'Create server'))));
}
export default definePterodactylExtension({setup({screens}){screens.register('create',()=>Promise.resolve({default:CreateScreen}));}});
