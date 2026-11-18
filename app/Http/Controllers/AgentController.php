<?php

namespace App\Http\Controllers;

use App\Models\DeploymentTarget;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgentController extends Controller
{
    public function claim(Request $request)
    {
        $serverId = $request->attributes->get('agent_server')->id;
        $tokenHash = $request->attributes->get('agent_token_hash');

        return DB::transaction(function () use ($serverId, $tokenHash) {
            $server = Server::whereKey($serverId)->lockForUpdate()->firstOrFail();
            if (! $server->agent_token_hash || ! hash_equals($server->agent_token_hash, $tokenHash)) {
                return response()->json(['error' => 'No autorizado.'], 401);
            }
            $server->last_seen_at = now();
            $server->save();
            if ($server->active_target_id !== null) {
                return response()->json(['state' => 'busy', 'target_id' => $server->active_target_id]);
            }
            $target = DeploymentTarget::where('server_id', $serverId)
                ->where('status', 'pending')
                ->whereHas('deployment', fn ($query) => $query->where('scheduled_at', '<=', now()))
                ->join('deployments', 'deployments.id', '=', 'deployment_targets.deployment_id')
                ->orderBy('deployments.scheduled_at')->orderBy('deployment_targets.id')
                ->select('deployment_targets.*')->lockForUpdate()->first();
            if (! $target) {
                return response()->json(['state' => 'idle']);
            }
            // The conditional update is also needed on databases without row-level locks.
            if (Server::whereKey($serverId)->whereNull('active_target_id')->update(['active_target_id' => $target->id]) !== 1) {
                return response()->json(['state' => 'busy']);
            }
            $target->status = 'running';
            $target->started_at = now();
            $target->save();
            $deployment = $target->deployment()->with('project')->firstOrFail();

            return response()->json([
                'state' => 'claimed',
                'target_id' => $target->id,
                'commit' => $deployment->commit,
                'path' => $target->path,
                'preserved_paths' => $target->preserved_paths,
                'repository_url' => $deployment->repository_url,
            ]);
        }, 3);
    }

    public function report(Request $request, int $target)
    {
        $data = $request->validate([
            'status' => ['required', 'in:succeeded,failed,uncertain'],
            'result' => ['required', 'string', 'max:4000'],
        ]);
        $serverId = $request->attributes->get('agent_server')->id;
        $tokenHash = $request->attributes->get('agent_token_hash');

        return DB::transaction(function () use ($serverId, $target, $data, $tokenHash) {
            $server = Server::whereKey($serverId)->lockForUpdate()->firstOrFail();
            if (! $server->agent_token_hash || ! hash_equals($server->agent_token_hash, $tokenHash)) {
                return response()->json(['error' => 'No autorizado.'], 401);
            }
            $job = DeploymentTarget::whereKey($target)->where('server_id', $serverId)->lockForUpdate()->firstOrFail();
            if (in_array($job->status, ['succeeded', 'failed', 'uncertain'], true)) {
                if ($job->status === $data['status'] && $job->result === $data['result']) {
                    return response()->json(['state' => 'recorded']);
                }

                return response()->json(['error' => 'El resultado ya fue registrado.'], 409);
            }
            if ($job->status !== 'running' || $server->active_target_id !== $job->id) {
                return response()->json(['error' => 'El trabajo no está asignado a este agente.'], 409);
            }
            $job->status = $data['status'];
            $job->result = $data['result'];
            $job->finished_at = now();
            $job->save();
            if ($data['status'] !== 'uncertain') {
                $server->active_target_id = null;
                DB::table('deployment_reservations')->where('deployment_target_id', $job->id)->delete();
            }
            $server->last_seen_at = now();
            $server->save();

            return response()->json(['state' => 'recorded']);
        }, 3);
    }
}
