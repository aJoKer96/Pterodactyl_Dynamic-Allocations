import { useEffect, useState } from 'react';
import http, { httpErrorToHuman } from '@/api/http';

const base = '/api/admin/extensions/dynamic-allocations';
interface NodeInfo { id: number; name: string; node_ip: string | null; ips: string[]; }
interface Preset { primary: string; additional: string; }
interface Binding { id: number; ip: string; port: number; }
interface Config { node: NodeInfo; port_preset?: Preset | null; ip?: string; ports?: string; mixed?: boolean; bindings?: Binding[]; revision?: string; }
export interface DynamicNetworkPayload { node_id: number; ip: string; ports: string; revision?: string; replace_mixed?: boolean; }
const presetText = (p?: Preset | null) => p ? [p.primary, p.additional].filter(Boolean).join(', ') : '';
export function useDynamicNetwork(nodeId: number, eggId: number, serverId?: number) {
    const [generation, setGeneration] = useState(0);
    const key = `${nodeId}:${eggId}:${serverId ?? 0}:${generation}`;
    const [state, setState] = useState<{ key: string; config: Config; ip: string; ports: string; replaceMixed: boolean } | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    useEffect(() => {
        let active = true;
        setError('');
        if (nodeId <= 0) return;
        const url = serverId ? `${base}/servers/${serverId}/network` : `${base}/network/options`;
        http.get<Config>(url, { params: serverId ? {} : { node_id: nodeId, ...(eggId > 0 ? { egg_id: eggId } : {}) } })
            .then(({ data }) => { if (active) setState({ key, config: data, ip: data.ip ?? data.node.node_ip ?? '127.0.0.1', ports: data.ports ?? presetText(data.port_preset), replaceMixed: false }); })
            .catch(e => { if (active) setError(httpErrorToHuman(e)); });
        return () => { active = false; };
    }, [key, nodeId, eggId, serverId]);
    const current = state?.key === key ? state : null;
    const update = (field: 'ip' | 'ports', value: string) => { setError(''); setState(old => old?.key === key ? { ...old, [field]: value } : old); };
    const payload = (): DynamicNetworkPayload | undefined => {
        const fail = (message: string): never => { setError(message); throw new Error(message); };
        if (!current || busy) return fail('Network configuration is not ready.');
        // Legacy multi-address servers: preserve all allocations, only save other native settings.
        if (current.config.mixed && !current.replaceMixed) return undefined;
        const entries = current.ports.split(',').map(x => x.trim());
        if (entries.length > 32 || entries.some(x => !/^\d{1,5}$/.test(x) || Number(x) < 1 || Number(x) > 65535) || new Set(entries.map(Number)).size !== entries.length) return fail('Enter 1–32 distinct ports (1–65535), separated by commas.');
        const unchanged = !current.config.mixed && current.ip === current.config.ip && current.ports === current.config.ports;
        if (!unchanged && !current.config.node.ips.includes(current.ip)) return fail('Select the node IPv4 or localhost.');
        if (serverId && !unchanged && !window.confirm('Stop the server before changing bindings. Continue only if it is stopped.')) return fail('Network change cancelled.');
        return { node_id: nodeId, ip: current.ip, ports: current.ports, revision: current.config.revision, replace_mixed: current.replaceMixed };
    };
    async function savePreset(remove = false) {
        if (!current || eggId <= 0 || busy) return;
        setBusy(true); setError('');
        try {
            const url = `${base}/eggs/${eggId}/port-preset`;
            const { data } = remove ? await http.delete(url) : await http.put(url, { ports: current.ports });
            setState(old => old?.key === key ? { ...old, config: { ...old.config, port_preset: data.port_preset } } : old);
        } catch (e) { setError(httpErrorToHuman(e)); } finally { setBusy(false); }
    }
    const replaceMixed = () => {
        if (!current || !current.config.mixed) return;
        if (!window.confirm('Replace all existing IP/port bindings with one bind IP? Removed assignments will be released.')) return;
        setState(old => old?.key === key ? { ...old, replaceMixed: true, ip: old.config.node.node_ip ?? '127.0.0.1' } : old);
    };
    return { current, error, busy, update, payload, savePreset, replaceMixed, refresh: () => setGeneration(n => n + 1), eggId, serverId };
}
export type DynamicNetworkState = ReturnType<typeof useDynamicNetwork>;
const inputClass = 'w-full rounded border border-border bg-input px-3 py-2 text-foreground';
export function DynamicNetworkFields({ network }: { network: DynamicNetworkState }) {
    const { current, error, busy, update, eggId, serverId } = network;
    return <div className='space-y-3'>
        {error && <p role='alert' className='text-destructive'>{error}</p>}
        {!current ? <p className='input-help'>Select a node, or wait for network configuration. If the extension is disabled, restore the stock frontend.</p> : current.config.mixed && !current.replaceMixed ? <div>
            <p className='input-help'>This server has multiple bind IPs. All bindings are preserved unless you explicitly choose to consolidate them.</p>
            {current.config.bindings?.map(b => <p key={b.id}>{b.ip}:{b.port}</p>)}
            <button type='button' className='rounded border px-2 py-1' onClick={network.replaceMixed}>Replace all bindings with one IP</button>
        </div> : <>
            <label className='block'>Bind IP<select className={inputClass} value={current.ip} disabled={busy} onChange={e => update('ip', e.target.value)}>
                {current.config.node.node_ip && <option value={current.config.node.node_ip}>Node IPv4 ({current.config.node.node_ip})</option>}
                <option value='127.0.0.1'>127.0.0.1 (Localhost / internal)</option>
                {!current.config.node.ips.includes(current.ip) && <option value={current.ip}>Existing binding ({current.ip})</option>}
            </select></label>
            {current.replaceMixed && <p className='text-destructive'>All previous IP bindings will be replaced by this list. Check the desired ports carefully.</p>}
            {!current.config.node.node_ip && <p className='input-help'>Set the actual node IPv4 in Dynamic Allocations Settings if DNS cannot provide it.</p>}
            <label className='block'>Ports<input className={inputClass} value={current.ports} disabled={busy} onChange={e => update('ports', e.target.value)} placeholder='80, 443, 8080' maxLength={512} /></label>
            <p className='input-help'>Comma-separated ports, first port is primary. Ports 1–65535. Allocation rows are managed automatically.</p>
            {serverId ? <p className='input-help'>Stop the server before changing bindings. Start it again after saving to apply Docker port mappings.</p> : eggId > 0 && <div className='flex flex-wrap gap-2'>
                <button className='rounded border px-2 py-1' type='button' disabled={busy || !current.ports} onClick={() => void network.savePreset()}>Save egg defaults</button>
                <button className='rounded border px-2 py-1' type='button' disabled={busy || !current.config.port_preset} onClick={() => update('ports', presetText(current.config.port_preset))}>Apply defaults</button>
                <button className='rounded border px-2 py-1' type='button' disabled={busy || !current.config.port_preset} onClick={() => void network.savePreset(true)}>Remove defaults</button>
            </div>}
        </>}
    </div>;
}
