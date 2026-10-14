<?php

namespace App\Support;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectAccess
{
    public static function role(Request $request, Project $project): string
    {
        if ($project->user_id === $request->user()->id) {
            return 'founder';
        }
        $role = DB::table('project_members')->where('project_id', $project->id)
            ->where('user_id', $request->user()->id)->value('role');
        abort_unless(in_array($role, ['professional', 'member'], true), 404);

        return $role;
    }

    // All writes take the same project lock, including membership revocation.
    // This serializes settlement and permission changes on transactional databases.
    public static function write(Request $request, Project $project, array $roles, callable $callback): mixed
    {
        return DB::transaction(function () use ($request, $project, $roles, $callback) {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array(self::role($request, $locked), $roles, true), 403);

            return $callback($locked);
        });
    }

    public static function audit(Request $request, Project $project, string $action, string $type, int $id, array $details): void
    {
        DB::table('audit_events')->insert([
            'project_id' => $project->id,
            'actor_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'action' => $action,
            'subject_type' => $type,
            'subject_id' => $id,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
