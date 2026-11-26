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
use Pterodactyl\Models\Server;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Contracts\Servers\UpdatesServerBuild;
use Pterodactyl\Http\Controllers\Api\Admin\Servers\BuildController;
use Pterodactyl\Http\Requests\Api\Admin\Servers\UpdateServerBuildConfigurationRequest;

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
    public function networkOptions(Request $request): JsonResponse {
        $this->admin($request);
        $input=$request->validate(['node_id'=>'required|integer|exists:nodes,id','egg_id'=>'nullable|integer|exists:eggs,id']);
        $node=Node::query()->findOrFail($input['node_id']);
        $egg=isset($input['egg_id']) ? Egg::query()->findOrFail($input['egg_id']) : null;
        return response()->json(['node'=>$this->nodeOptions($node),'port_preset'=>$egg ? $this->presetFor($egg) : null])->header('Cache-Control','no-store');
    }
    private function snapshot(Server $server): array {
        $rows=Allocation::query()->where('server_id',$server->id)->orderBy('id')->get(['id','ip','port']);
        return NetworkPlan::snapshot((int)$server->allocation_id,$rows->map(fn($r)=>['id'=>(int)$r->id,'ip'=>$r->ip,'port'=>(int)$r->port])->all());
    }
    public function serverNetwork(Request $request, Server $server): JsonResponse {
        $this->admin($request);
        return response()->json(['node'=>$this->nodeOptions(Node::query()->findOrFail($server->node_id)), ...$this->snapshot($server)])->header('Cache-Control','no-store');
    }
    private function withNodeLock(Node $node, callable $callback): mixed {
        $db=DB::connection();
        $lock='dynamic-allocations-node-'.$node->id;
        // Keep the advisory lock on the WRITE PDO even in a read/replica configuration.
        if ((int)($db->selectOne('SELECT GET_LOCK(?, 10) AS acquired',[$lock],false)->acquired??0)!==1) abort(409,'Another dynamic operation is in progress.');
        try { return $callback(); }
        finally { $db->selectOne('SELECT RELEASE_LOCK(?) AS released',[$lock],false); }
    }
    private function desiredIds(Node $node, string $ip, array $ports, ?Server $server=null): array {
        return DB::transaction(function() use($node,$ip,$ports,$server) {
            $ids=[];
            foreach($ports as $port) {
                $rows=Allocation::query()->where('node_id',$node->id)->where('port',$port)->lockForUpdate()->get();
                foreach($rows as $row) {
                    if ($row->server_id!==null && (int)$row->server_id!==($server === null ? null : (int)$server->id)) throw ValidationException::withMessages(['ports'=>"Port {$port} is already assigned on this node."]);
                }
                $row=$rows->first(fn($row)=>$row->ip===$ip);
                if (!$row) $row=Allocation::query()->create(['node_id'=>$node->id,'ip'=>$ip,'port'=>$port,'server_id'=>null,'ip_alias'=>null]);
                $ids[]=(int)$row->id;
            }
            return $ids;
        },3);
    }
    private function validateNetwork(Node $node, array $input): array {
        try { $ports=Ports::parseList($input['ports']); $ip=Ports::normalizeIp($input['ip']); }
        catch(InvalidArgumentException $e) { throw ValidationException::withMessages(['ports'=>$e->getMessage()]); }
        if ($node->isUnderMaintenance()) throw ValidationException::withMessages(['node_id'=>'Node is under maintenance.']);
        if (!in_array($ip,$this->nodeOptions($node)['ips'],true)) throw ValidationException::withMessages(['ip'=>'Select the current node IPv4 or localhost. Reload if the node address changed.']);
        return [$ip,$ports];
    }
    public function create(Request $request): JsonResponse {
        $this->admin($request);
        $input=$request->validate(['node_id'=>'required|integer|exists:nodes,id','ip'=>'required|string|max:15','ports'=>'required|string|max:512','server'=>'required|array']);
        $node=Node::query()->findOrFail($input['node_id']);
        return $this->withNodeLock($node,function() use($request,$input,$node) {
            $node->refresh();
            [$ip,$ports]=$this->validateNetwork($node,$input);
            $payload=$input['server'];
            // Never allow a deployment object to override our chosen node/ports.
            unset($payload['deploy']);
            $ids=$this->desiredIds($node,$ip,$ports);
            $payload['primary_allocation_id']=array_shift($ids);
            $payload['secondary_allocations_ids']=$ids;
            $request->replace($payload);
            $native=app(StoreServerRequest::class);
            return app(ServerController::class)->store($native,app(CreatesServers::class));
        });
    }
    public function update(Request $request, Server $server): JsonResponse {
        $this->admin($request);
        $input=$request->validate(['ip'=>'required|string|max:15','ports'=>'required|string|max:512','revision'=>'required|string|size:64','replace_mixed'=>'sometimes|boolean','build'=>'required|array']);
        $node=Node::query()->findOrFail($server->node_id);
        return $this->withNodeLock($node,function() use($request,$server,$input,$node) {
            $server->refresh(); $node->refresh();
            abort_if((int)$server->node_id!==(int)$node->id,409,'Server moved to another node. Reload.');
            abort_unless($server->isInstalled(),409,'Server is not installed.');
            $current=$this->snapshot($server);
            abort_unless(hash_equals($current['revision'],$input['revision']),409,'Allocations changed since the form was loaded. Reload.');
            abort_if($current['mixed'] && !($input['replace_mixed']??false),422,'Explicitly confirm replacing all existing bindings with one IP.');
            // Unchanged legacy bindings are permitted when updating resource settings only.
            if (!$current['mixed'] && $input['ip']===$current['ip'] && $input['ports']===$current['ports']) {
                $ids=array_column($current['bindings'],'id');
            } else {
                [$ip,$ports]=$this->validateNetwork($node,$input);
                // Never release a binding while its old container is still running.
                // Require an explicit live offline state, not cached/missing data.
                $details=Daemon::server($server)->details();
                abort_unless(($details['state']??null)==='offline',409,'Stop the server before changing port bindings.');
                $ids=$this->desiredIds($node,$ip,$ports,$server);
            }
            $diff=NetworkPlan::diff(array_column($current['bindings'],'id'),$ids);
            $payload=$input['build'];
            $payload['allocation_id']=$diff['primary'];
            $payload['add_allocation_ids']=$diff['add'];
            $payload['remove_allocation_ids']=$diff['remove'];
            $request->replace($payload);
            // The native request validates the route's Server model and native resource fields.
            $native=app(UpdateServerBuildConfigurationRequest::class);
            $resource=app(BuildController::class)($native,app(UpdatesServerBuild::class),$server);
            return $resource->respond();
        });
    }
}
