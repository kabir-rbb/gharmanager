<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\Decimal;
use App\Support\ProjectAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GharController extends Controller
{
    public function projects(Request $r)
    {
        $members = DB::table('project_members')->where('user_id', $r->user()->id)->pluck('role', 'project_id');

        return Project::where(function ($q) use ($r, $members) {
            $q->where('user_id', $r->user()->id)->orWhereIn('id', $members->keys());
        })->latest('id')->get()->map(function ($p) use ($r, $members) {
            $p->setAttribute('role', $p->user_id === $r->user()->id ? 'founder' : $members[$p->id]);

            return $p;
        });
    }

    public function createProject(Request $r)
    {
        $v = $r->validate(['name' => 'required|string|max:120', 'location' => 'required|string|max:160', 'budget' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/D']]);

        return DB::transaction(function () use ($r, $v) {
            $p = new Project(['name' => $v['name'], 'location' => $v['location'], 'budget_paisa' => Decimal::scaled($v['budget'], 2)]);
            $p->user_id = $r->user()->id;
            $p->save();
            ProjectAccess::audit($r, $p, 'project.created', 'project', $p->id, $p->only(['name', 'location', 'budget_paisa', 'status']));
            $p->setAttribute('role', 'founder');

            return response()->json($p, 201);
        });
    }

    public function detail(Request $r, Project $project)
    {
        return DB::transaction(function () use ($r, $project) {
            $role = ProjectAccess::role($r, $project);
            $materials = DB::table('materials')->where('project_id', $project->id)->orderByDesc('id')->get();
            $workers = DB::table('workers')->where('project_id', $project->id)->orderBy('name')->get();
            $attendance = DB::table('attendance')->join('workers', 'workers.id', '=', 'attendance.worker_id')
                ->where('attendance.project_id', $project->id)->select('attendance.*', 'workers.name as worker_name', 'workers.trade')
                ->orderByDesc('recorded_on')->orderByDesc('attendance.id')->get();
            $bills = DB::table('bills')->where('project_id', $project->id)->orderByDesc('id')->get();
            $payments = DB::table('payments')->where('project_id', $project->id)->orderByDesc('id')->get();
            foreach ($bills as $bill) {
                $bill->paid_paisa = (int) $payments->where('bill_id', $bill->id)->sum('amount_paisa');
                $bill->outstanding_paisa = (int) $bill->amount_paisa - $bill->paid_paisa;
            }
            $billed = (int) $bills->sum('amount_paisa');
            $paid = (int) $payments->sum('amount_paisa');
            $project->setAttribute('role', $role);

            return [
                'project' => $project, 'role' => $role,
                'permissions' => ['manage_team' => $role === 'founder', 'manage_finance' => $role === 'founder', 'write_site' => in_array($role, ['founder', 'professional'], true)],
                'materials' => $materials, 'material_total_paisa' => (int) $materials->sum('total_paisa'),
                'logs' => DB::table('daily_logs')->where('project_id', $project->id)->orderByDesc('recorded_on')->get(),
                'consultations' => DB::table('consultations')->where('project_id', $project->id)->orderBy('requested_at')->get(),
                'workers' => $workers, 'attendance' => $attendance, 'labor_total_paisa' => (int) $attendance->sum('total_paisa'),
                'bills' => $bills, 'payments' => $payments,
                'ledger' => [
                    'billed_paisa' => $billed, 'paid_paisa' => $paid, 'outstanding_paisa' => $billed - $paid,
                    'budget_remaining_paisa' => $project->budget_paisa - $billed,
                    'payees' => $bills->groupBy('payee')->map(fn ($rows, $payee) => [
                        'payee' => (string) $payee, 'billed_paisa' => (int) $rows->sum('amount_paisa'),
                        'paid_paisa' => (int) $rows->sum('paid_paisa'), 'outstanding_paisa' => (int) $rows->sum('outstanding_paisa'),
                    ])->sortBy('payee')->values(),
                ],
            ];
        });
    }

    public function material(Request $r, Project $project)
    {
        return ProjectAccess::write($r, $project, ['founder', 'professional'], function () use ($r, $project) {
            $v = $r->validate(['name' => 'required|string|max:100', 'supplier' => 'required|string|max:120', 'unit' => ['required', Rule::in(['bag', 'kg', 'tonne', 'piece', 'cft', 'sqft', 'm3'])], 'quantity' => ['required', 'string', 'regex:/^\d{1,6}(?:\.\d{1,3})?$/D'], 'rate' => ['required', 'string', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/D'], 'recorded_on' => 'required|date_format:Y-m-d']);
            $q = Decimal::scaled($v['quantity'], 3);
            $rate = Decimal::scaled($v['rate'], 2);
            abort_if($q === 0 || $rate === 0, 422, 'Quantity and rate must be positive.');
            $data = ['project_id' => $project->id, 'name' => $v['name'], 'supplier' => $v['supplier'], 'unit' => $v['unit'], 'quantity_milli' => $q, 'rate_paisa' => $rate, 'total_paisa' => Decimal::total($q, $rate), 'recorded_on' => $v['recorded_on'], 'created_at' => now(), 'updated_at' => now()];
            $id = DB::table('materials')->insertGetId($data);
            ProjectAccess::audit($r, $project, 'material.created', 'material', $id, ['name' => $v['name'], 'total_paisa' => $data['total_paisa']]);

            return response()->json(DB::table('materials')->find($id), 201);
        });
    }

    public function log(Request $r, Project $project)
    {
        return ProjectAccess::write($r, $project, ['founder', 'professional'], function () use ($r, $project) {
            $v = $r->validate(['client_uuid' => 'required|uuid', 'recorded_on' => 'required|date_format:Y-m-d', 'notes' => 'required|string|max:5000']);
            $row = DB::table('daily_logs')->where('project_id', $project->id)->where('client_uuid', $v['client_uuid'])->first();
            if ($row) {
                abort_if($row->notes !== $v['notes'] || $row->recorded_on !== $v['recorded_on'], 409, 'This offline entry already exists with different content.');

                return response()->json($row);
            }
            $id = DB::table('daily_logs')->insertGetId(['project_id' => $project->id, ...$v, 'created_at' => now(), 'updated_at' => now()]);
            ProjectAccess::audit($r, $project, 'diary.created', 'diary', $id, ['recorded_on' => $v['recorded_on']]);

            return response()->json(DB::table('daily_logs')->find($id), 201);
        });
    }

    public function consultation(Request $r, Project $project)
    {
        return ProjectAccess::write($r, $project, ['founder', 'professional'], function () use ($r, $project) {
            $v = $r->validate(['topic' => 'required|string|max:160', 'mode' => ['required', Rule::in(['video', 'in-person'])], 'requested_at' => 'required|date|after:now']);
            $v['requested_at'] = Carbon::parse($v['requested_at'])->utc()->toIso8601String();
            $id = DB::table('consultations')->insertGetId(['project_id' => $project->id, ...$v, 'status' => 'requested', 'created_at' => now(), 'updated_at' => now()]);
            ProjectAccess::audit($r, $project, 'consultation.requested', 'consultation', $id, $v);

            return response()->json(DB::table('consultations')->find($id), 201);
        });
    }
}
