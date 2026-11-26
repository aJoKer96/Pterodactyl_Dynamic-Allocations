<?php
declare(strict_types=1);
namespace DynamicAllocations;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Extensions\ExtensionSettings;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Contracts\Servers\CreatesServers;
use Pterodactyl\Http\Controllers\Api\Admin\Servers\ServerController;
use Pterodactyl\Http\Requests\Api\Admin\Servers\StoreServerRequest;
use InvalidArgumentException;

final class Controller {
    private function admin(Request $request): void {
        abort_unless($request->user()?->root_admin, 403);
    }
    public function options(Request $request): JsonResponse {
        $this->admin($request);
        $nodes = Node::query()->orderBy('name')->get(['id','name','fqdn','behind_proxy']);
        return response()->json([
            'nodes' => $nodes->map(fn($n) => $this->nodeOptions($n)),
            'eggs' => Egg::query()->with('variables')->orderBy('name')->get()->map(fn($e)=>['id'=>$e->id,'name'=>$e->name,'startup'=>$e->startup,'images'=>array_values($e->docker_images??[]),'port_preset'=>$this->presetFor($e), 'variables'=>$e->variables->map(fn($v)=>['name'=>$v->name,'key'=>$v->env_variable,'default'=>$v->default_value??'','description'=>$v->description])]),
            'users' => User::query()->orderBy('username')->get(['id','username']),
        ])->header('Cache-Control','no-store');
    }
    private function nodeSettings(Node $node): ExtensionSettings {
        return (new ExtensionSettings('dynamic-allocations'))->for($node);
    }
    private function nodeOptions(Node $node): array {
        $stored = $this->nodeSettings($node)->get('bind_ipv4');
        $address = NodeAddress::resolve(is_string($stored) ? $stored : null, $node->fqdn, (bool)$node->behind_proxy, fn(string $host) => gethostbynamel($host));
        return ['id'=>$node->id,'name'=>$node->name,'fqdn'=>$node->fqdn,'node_ip'=>$address['ip'],'ip_source'=>$address['source'],
            'ips'=>array_values(array_filter([$address['ip'],NodeAddress::LOCAL]))];
    }
    public function saveNodeAddress(Request $request, Node $node): JsonResponse {
        $this->admin($request);
        $input=$request->validate(['ip'=>'required|string|max:15']);
        try { $ip=NodeAddress::normalize($input['ip']); }
        catch(InvalidArgumentException $e) { throw ValidationException::withMessages(['ip'=>$e->getMessage()]); }
        $this->nodeSettings($node)->set('bind_ipv4',$ip);
        return response()->json($this->nodeOptions($node))->header('Cache-Control','no-store');
    }
    public function resetNodeAddress(Request $request, Node $node): JsonResponse {
        $this->admin($request);
        $this->nodeSettings($node)->forget('bind_ipv4');
        return response()->json($this->nodeOptions($node))->header('Cache-Control','no-store');
    }
    private function settingsFor(Egg $egg): ExtensionSettings {
        return (new ExtensionSettings('dynamic-allocations'))->for($egg);
    }
    private function presetFor(Egg $egg): ?array {
        return Presets::fromStored($this->settingsFor($egg)->get('port_preset'));
    }
    public function savePreset(Request $request, Egg $egg): JsonResponse {
        $this->admin($request);
        $input=$request->validate(['ports'=>'required|string|max:512']);
        try { $ports=Ports::parseList($input['ports']); $preset=Presets::fromInput((string)array_shift($ports),implode(',',$ports)); }
        catch(InvalidArgumentException $e) { throw ValidationException::withMessages(['ports'=>$e->getMessage()]); }
        $this->settingsFor($egg)->set('port_preset',$preset);
        return response()->json(['port_preset'=>$preset])->header('Cache-Control','no-store');
    }
    public function deletePreset(Request $request, Egg $egg): JsonResponse {
        $this->admin($request);
        $this->settingsFor($egg)->forget('port_preset');
        return response()->json(['port_preset'=>null])->header('Cache-Control','no-store');
    }
    public function create(Request $request): JsonResponse {
        $this->admin($request);
        $input=$request->validate([
            'node_id'=>'required|integer|exists:nodes,id','ip'=>'required|string|max:15','ports'=>'required|string|max:512',
            'server'=>'required|array:name,owner_id,egg_id,docker_image,startup,environment,memory,disk,cpu,swap,io,database_limit,backup_limit,allocation_limit,description,threads,start_on_completion',
            'server.name'=>'required|string|max:191','server.owner_id'=>'required|integer|exists:users,id','server.egg_id'=>'required|integer|exists:eggs,id',
            'server.docker_image'=>'required|string|max:191','server.startup'=>'required|string','server.environment'=>'present|array',
            'server.memory'=>'required|integer|min:0','server.disk'=>'required|integer|min:0','server.cpu'=>'required|integer|min:0','server.swap'=>'required|integer|min:-1','server.io'=>'required|integer|between:10,1000',
            'server.database_limit'=>'required|integer|min:0','server.backup_limit'=>'required|integer|min:0','server.allocation_limit'=>'required|integer|min:0',
            'server.description'=>'nullable|string','server.threads'=>'nullable|string','server.start_on_completion'=>'required|boolean',
        ]);
        try { $ports=Ports::parseList($input['ports']); $ip=Ports::normalizeIp($input['ip']); }
        catch(InvalidArgumentException $e) { throw ValidationException::withMessages(['ports'=>$e->getMessage()]); }
        $node=Node::query()->findOrFail($input['node_id']);
        $egg=Egg::query()->findOrFail($input['server']['egg_id']);
        if (!$node->acceptsEgg($egg)) throw ValidationException::withMessages(['node_id'=>'This node does not accept the selected egg tags.']);
        if ($node->isUnderMaintenance()) throw ValidationException::withMessages(['node_id'=>'Node is under maintenance.']);
        // Derive the allowlist from the node configuration, never its port inventory.
        if (!in_array($ip,$this->nodeOptions($node)['ips'],true)) throw ValidationException::withMessages(['ip'=>'Select the current node IPv4 or localhost. Reload if the node address changed.']);
        // Advisory lock serializes this extension on this node across HTTP workers. The
        // native UI and other API clients do not take it; do not run parallel provisioning.
        $lock='dynamic-allocations-node-'.$node->id;
        $db=DB::connection();
        if ((int)($db->selectOne('SELECT GET_LOCK(?, 10) AS acquired',[$lock])->acquired??0)!==1) abort(409,'Another dynamic creation is in progress.');
        try {
            $ids=$db->transaction(function() use($node,$ip,$ports) {
                $ids=[];
                foreach($ports as $port) {
                    $rows=Allocation::query()->where('node_id',$node->id)->where('port',$port)->lockForUpdate()->get();
                    foreach($rows as $row) {
                        // Free rows on other addresses are not reservations. Assigned rows
                        // are conservatively treated as conflicts even on different IPs.
                        if ($row->server_id!==null) throw ValidationException::withMessages(['ports'=>"Port {$port} is already assigned on this node."]);
                    }
                    $row=$rows->first(fn($row)=>$row->ip===$ip);
                    if (!$row) {
                        $row=Allocation::query()->create(['node_id'=>$node->id,'ip'=>$ip,'port'=>$port,'server_id'=>null,'ip_alias'=>null]);
                    }
                    $ids[]=$row->id;
                }
                return $ids;
            },3);
            // Native FormRequest and controller: preserves native validation, egg tags,
            // permission checks, activity log, and Wings creation/cleanup semantics.
            // Do not hold an outer DB transaction during the Wings request: Wings needs
            // to see the committed server record from the native action.
            $payload=$input['server'];
            $payload['primary_allocation_id']=array_shift($ids);
            $payload['secondary_allocations_ids']=$ids;
            $request->replace($payload);
            $native=app(StoreServerRequest::class);
            return app(ServerController::class)->store($native, app(CreatesServers::class));
        } finally {
            $db->selectOne('SELECT RELEASE_LOCK(?) AS released',[$lock]);
        }
    }
}
