<?php
declare(strict_types=1);
namespace DynamicAllocations;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Models\Node;
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
        $nodes = Node::query()->orderBy('name')->get(['id','name']);
        $ips = Allocation::query()->select('node_id','ip')->distinct()->get()->groupBy('node_id');
        return response()->json([
            'nodes' => $nodes->map(fn($n) => ['id'=>$n->id,'name'=>$n->name,'ips'=>($ips->get($n->id,collect()))->pluck('ip')->filter(fn($ip)=>filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)&&$ip!=='0.0.0.0')->values()]),
            'eggs' => Egg::query()->with('variables')->orderBy('name')->get()->map(fn($e)=>['id'=>$e->id,'name'=>$e->name,'startup'=>$e->startup,'images'=>array_values($e->docker_images??[]),'variables'=>$e->variables->map(fn($v)=>['name'=>$v->name,'key'=>$v->env_variable,'default'=>$v->default_value??'','description'=>$v->description])]),
            'users' => User::query()->orderBy('username')->get(['id','username']),
        ])->header('Cache-Control','no-store');
    }
    public function create(Request $request): JsonResponse {
        $this->admin($request);
        $input=$request->validate([
            'node_id'=>'required|integer|exists:nodes,id','ip'=>'required|string|max:15','primary'=>'required|string|max:5','additional'=>'nullable|string|max:512',
            'server'=>'required|array:name,owner_id,egg_id,docker_image,startup,environment,memory,disk,cpu,swap,io,database_limit,backup_limit,allocation_limit,description,threads,start_on_completion',
            'server.name'=>'required|string|max:191','server.owner_id'=>'required|integer|exists:users,id','server.egg_id'=>'required|integer|exists:eggs,id',
            'server.docker_image'=>'required|string|max:191','server.startup'=>'required|string','server.environment'=>'present|array',
            'server.memory'=>'required|integer|min:0','server.disk'=>'required|integer|min:0','server.cpu'=>'required|integer|min:0','server.swap'=>'required|integer|min:-1','server.io'=>'required|integer|between:10,1000',
            'server.database_limit'=>'required|integer|min:0','server.backup_limit'=>'required|integer|min:0','server.allocation_limit'=>'required|integer|min:0',
            'server.description'=>'nullable|string','server.threads'=>'nullable|string','server.start_on_completion'=>'required|boolean',
        ]);
        try { $ports=Ports::parse($input['primary'],$input['additional']??''); $ip=Ports::normalizeIp($input['ip']); }
        catch(InvalidArgumentException $e) { throw ValidationException::withMessages(['ports'=>$e->getMessage()]); }
        $node=Node::query()->findOrFail($input['node_id']);
        $egg=Egg::query()->findOrFail($input['server']['egg_id']);
        if (!$node->acceptsEgg($egg)) throw ValidationException::withMessages(['node_id'=>'This node does not accept the selected egg tags.']);
        if ($node->isUnderMaintenance()) throw ValidationException::withMessages(['node_id'=>'Node is under maintenance.']);
        // Keep the native IP inventory as the allowlist. No arbitrary host addresses.
        if (!Allocation::query()->where('node_id',$node->id)->where('ip',$ip)->exists()) throw ValidationException::withMessages(['ip'=>'Select an IPv4 address already registered on the node.']);
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
                        // Conservative: do not allow duplicate numeric ports on other IPs,
                        // avoiding wildcard and Wings loopback-to-bridge alias collisions.
                        if ($row->ip!==$ip || $row->server_id!==null) throw ValidationException::withMessages(['ports'=>"Port {$port} already exists on another IP or is assigned."]);
                    }
                    $row=$rows->first();
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
