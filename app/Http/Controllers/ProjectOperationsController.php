<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Support\Decimal;
use App\Support\ProjectAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectOperationsController extends Controller
{
    private const MONEY = ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/D'];

    public function members(Request $r, Project $project)
    {
        abort_unless(ProjectAccess::role($r, $project) === 'founder', 403);
        $founder = User::findOrFail($project->user_id);

        return [
            'founder' => $founder->only(['id', 'name', 'email']),
            'members' => DB::table('project_members')->join('users', 'users.id', '=', 'project_members.user_id')
                ->where('project_id', $project->id)->select('users.id as user_id', 'users.name', 'users.email', 'project_members.role')->orderBy('users.name')->get(),
        ];
    }

    public function saveMember(Request $r, Project $project)
    {
        return ProjectAccess::write($r, $project, ['founder'], function () use ($r, $project) {
            $v = $r->validate(['email' => 'required|email|max:255', 'role' => ['required', Rule::in(['professional', 'member'])]]);
            $user = User::where('email', $v['email'])->first();
            if (! $user || $user->id === $project->user_id) {
                throw ValidationException::withMessages(['email' => 'Choose an existing account other than the project founder.']);
            }
            $old = DB::table('project_members')->where('project_id', $project->id)->where('user_id', $user->id)->first();
            if ($old && $old->role === $v['role']) {
                return response()->json(['user_id' => $user->id, 'name' => $user->name, 'role' => $old->role]);
            }
            DB::table('project_members')->updateOrInsert(['project_id' => $project->id, 'user_id' => $user->id], ['role' => $v['role'], 'updated_at' => now(), 'created_at' => $old?->created_at ?? now()]);
            ProjectAccess::audit($r, $project, $old ? 'member.role_changed' : 'member.added', 'user', $user->id, ['name' => $user->name, 'previous_role' => $old?->role, 'role' => $v['role']]);

            return response()->json(['user_id' => $user->id, 'name' => $user->name, 'role' => $v['role']], $old ? 200 : 201);
        });
    }

    public function removeMember(Request $r, Project $project, int $user)
    {
        return ProjectAccess::write($r, $project, ['founder'], function () use ($r, $project, $user) {
            abort_if($user === $project->user_id, 422, 'The project founder cannot be removed.');
            $row = DB::table('project_members')->where('project_id', $project->id)->where('user_id', $user)->first();
            abort_unless($row, 404);
            DB::table('project_members')->where('id', $row->id)->delete();
            ProjectAccess::audit($r, $project, 'member.removed', 'user', $user, ['previous_role' => $row->role]);

            return response()->noContent();
        });
    }

    public function worker(Request $r, Project $project)
    {
        return ProjectAccess::write($r, $project, ['founder', 'professional'], function () use ($r, $project) {
            $v = $r->validate(['name' => 'required|string|max:120', 'trade' => 'required|string|max:80', 'daily_rate' => self::MONEY]);
            $rate = Decimal::scaled($v['daily_rate'], 2);
            abort_unless($rate > 0, 422, 'Daily rate must be positive.');
            $id = DB::table('workers')->insertGetId(['project_id' => $project->id, 'name' => $v['name'], 'trade' => $v['trade'], 'daily_rate_paisa' => $rate, 'created_at' => now(), 'updated_at' => now()]);
            ProjectAccess::audit($r, $project, 'worker.created', 'worker', $id, ['name' => $v['name'], 'trade' => $v['trade'], 'daily_rate_paisa' => $rate]);

            return response()->json(DB::table('workers')->find($id), 201);
        });
    }

    public function attendance(Request $r, Project $project)
    {
        return ProjectAccess::write($r, $project, ['founder', 'professional'], function () use ($r, $project) {
            $v = $r->validate([
                'worker_id' => ['required', 'integer', Rule::exists('workers', 'id')->where('project_id', $project->id)],
                'recorded_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Kathmandu')->toDateString()],
                'day' => ['required', Rule::in(['full', 'half', 'absent'])],
                'notes' => 'nullable|string|max:500',
            ]);
            $worker = DB::table('workers')->where('project_id', $project->id)->find($v['worker_id']);
            $units = ['full' => 1000, 'half' => 500, 'absent' => 0][$v['day']];
            $data = ['worker_id' => $worker->id, 'recorded_on' => $v['recorded_on'], 'day_milli' => $units, 'rate_paisa' => (int) $worker->daily_rate_paisa, 'total_paisa' => Decimal::total($units, (int) $worker->daily_rate_paisa), 'notes' => $v['notes'] ?? ''];
            $existing = DB::table('attendance')->where('worker_id', $worker->id)->where('recorded_on', $v['recorded_on'])->first();
            if ($existing) {
                $this->same($existing, $data, 'Attendance already exists with different content for this worker and date.');

                return response()->json($existing);
            }
            $id = DB::table('attendance')->insertGetId(['project_id' => $project->id, ...$data, 'created_at' => now(), 'updated_at' => now()]);
            ProjectAccess::audit($r, $project, 'attendance.created', 'attendance', $id, ['worker_name' => $worker->name, ...$data]);

            return response()->json(DB::table('attendance')->find($id), 201);
        });
    }

    public function bill(Request $r, Project $project)
    {
        return ProjectAccess::write($r, $project, ['founder'], function () use ($r, $project) {
            $v = $r->validate(['client_uuid' => 'required|uuid', 'reference' => 'required|string|max:80', 'payee' => 'required|string|max:120', 'category' => ['required', Rule::in(['material', 'labor', 'other'])], 'description' => 'required|string|max:500', 'amount' => self::MONEY, 'issued_on' => 'required|date_format:Y-m-d']);
            $amount = Decimal::scaled($v['amount'], 2);
            abort_unless($amount > 0, 422, 'Bill amount must be positive.');
            unset($v['amount']);
            $data = [...$v, 'amount_paisa' => $amount];
            $existing = DB::table('bills')->where('project_id', $project->id)->where('client_uuid', $v['client_uuid'])->first();
            if ($existing) {
                $this->same($existing, $data, 'This bill request already exists with different content.');

                return response()->json($existing);
            }
            if (DB::table('bills')->where('project_id', $project->id)->where('reference', $v['reference'])->exists()) {
                throw ValidationException::withMessages(['reference' => 'A bill with this reference already exists in this project.']);
            }
            $id = DB::table('bills')->insertGetId(['project_id' => $project->id, ...$data, 'created_at' => now(), 'updated_at' => now()]);
            ProjectAccess::audit($r, $project, 'bill.created', 'bill', $id, $data);

            return response()->json(DB::table('bills')->find($id), 201);
        });
    }

    public function payment(Request $r, Project $project)
    {
        return ProjectAccess::write($r, $project, ['founder'], function () use ($r, $project) {
            $v = $r->validate(['client_uuid' => 'required|uuid', 'bill_id' => ['required', 'integer', Rule::exists('bills', 'id')->where('project_id', $project->id)], 'amount' => self::MONEY, 'method' => ['required', Rule::in(['cash', 'bank', 'other'])], 'reference' => 'nullable|string|max:120', 'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Kathmandu')->toDateString()]]);
            $amount = Decimal::scaled($v['amount'], 2);
            abort_unless($amount > 0, 422, 'Payment amount must be positive.');
            $data = ['client_uuid' => $v['client_uuid'], 'bill_id' => (int) $v['bill_id'], 'amount_paisa' => $amount, 'method' => $v['method'], 'reference' => $v['reference'] ?? '', 'paid_on' => $v['paid_on']];
            $existing = DB::table('payments')->where('project_id', $project->id)->where('client_uuid', $v['client_uuid'])->first();
            if ($existing) {
                $this->same($existing, $data, 'This payment request already exists with different content.');

                return response()->json($existing);
            }
            $bill = DB::table('bills')->where('project_id', $project->id)->where('id', $v['bill_id'])->lockForUpdate()->first();
            $paid = (int) DB::table('payments')->where('bill_id', $bill->id)->sum('amount_paisa');
            if ($amount > (int) $bill->amount_paisa - $paid) {
                throw ValidationException::withMessages(['amount' => 'Payment exceeds the outstanding bill balance.']);
            }
            if ($v['paid_on'] < $bill->issued_on) {
                throw ValidationException::withMessages(['paid_on' => 'Payment date cannot be before the bill date.']);
            }
            $id = DB::table('payments')->insertGetId(['project_id' => $project->id, ...$data, 'created_at' => now(), 'updated_at' => now()]);
            ProjectAccess::audit($r, $project, 'payment.created', 'payment', $id, $data);

            return response()->json(DB::table('payments')->find($id), 201);
        });
    }

    public function audit(Request $r, Project $project)
    {
        ProjectAccess::role($r, $project);
        $v = $r->validate(['before' => 'sometimes|integer|min:1']);
        $q = DB::table('audit_events')->where('project_id', $project->id);
        if (isset($v['before'])) {
            $q->where('id', '<', $v['before']);
        }
        $rows = $q->orderByDesc('id')->limit(51)->get();
        $hasMore = $rows->count() > 50;
        $events = $rows->take(50)->map(function ($row) {
            $row->details = json_decode($row->details, true, 512, JSON_THROW_ON_ERROR);
            // SQL timestamps are UTC; return explicit offsets for Nepal display.
            $row->created_at = Carbon::parse($row->created_at, 'UTC')->toIso8601String();

            return $row;
        })->values();

        return ['events' => $events, 'next_before' => $hasMore ? $events->last()->id : null];
    }

    private function same(object $row, array $data, string $message): void
    {
        foreach ($data as $key => $value) {
            abort_if((string) $row->$key !== (string) $value, 409, $message);
        }
    }
}
