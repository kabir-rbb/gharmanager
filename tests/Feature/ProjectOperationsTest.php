<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjectOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $founder;

    private User $professional;

    private User $member;

    private Project $project;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->founder = User::factory()->create();
        $this->professional = User::factory()->create();
        $this->member = User::factory()->create();
        $this->project = new Project(['name' => 'Test house', 'location' => 'Patan', 'budget_paisa' => 100000]);
        $this->project->user_id = $this->founder->id;
        $this->project->save();
        foreach ([$this->professional->id => 'professional', $this->member->id => 'member'] as $user => $role) {
            DB::table('project_members')->insert(['project_id' => $this->project->id, 'user_id' => $user, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->base = '/api/projects/'.$this->project->id;
        $this->actingAs($this->founder);
    }

    private function billData(array $overrides = []): array
    {
        return array_replace(['client_uuid' => (string) Str::uuid(), 'reference' => 'INV-001', 'payee' => 'Supplier A', 'category' => 'material', 'description' => 'Cement delivery', 'amount' => '1000.01', 'issued_on' => '2026-01-01'], $overrides);
    }

    private function makeBill(array $overrides = []): array
    {
        return $this->postJson($this->base.'/bills', $this->billData($overrides))->assertCreated()->json();
    }

    private function paymentData(int $bill, array $overrides = []): array
    {
        return array_replace(['client_uuid' => (string) Str::uuid(), 'bill_id' => $bill, 'amount' => '200.01', 'method' => 'cash', 'reference' => '', 'paid_on' => '2026-01-02'], $overrides);
    }

    private function makeWorker(): array
    {
        return $this->postJson($this->base.'/workers', ['name' => 'Mason A', 'trade' => 'Masonry', 'daily_rate' => '1250.01'])->assertCreated()->json();
    }

    public function test_shared_projects_are_listed_with_the_correct_role(): void
    {
        foreach ([$this->founder->id => 'founder', $this->professional->id => 'professional', $this->member->id => 'member'] as $id => $role) {
            $this->actingAs(User::findOrFail($id))->getJson('/api/projects')->assertOk()->assertJsonCount(1)->assertJsonPath('0.role', $role);
            $this->getJson($this->base)->assertOk()->assertJsonPath('role', $role);
        }
    }

    public function test_member_is_read_only_on_every_mutation(): void
    {
        $this->actingAs($this->member);
        foreach (['materials', 'logs', 'consultations', 'workers', 'attendance', 'bills', 'payments', 'members'] as $path) {
            $this->postJson($this->base.'/'.$path, [])->assertForbidden();
        }
        $this->deleteJson($this->base.'/members/'.$this->professional->id)->assertForbidden();
        $this->getJson($this->base.'/members')->assertForbidden();
        $this->getJson($this->base.'/audit')->assertOk();
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_professional_can_record_site_work_but_not_finances_or_members(): void
    {
        $this->actingAs($this->professional);
        $this->makeWorker();
        $this->postJson($this->base.'/materials', ['name' => 'Cement', 'supplier' => 'A', 'unit' => 'bag', 'quantity' => '1', 'rate' => '10', 'recorded_on' => '2026-01-01'])->assertCreated();
        $this->postJson($this->base.'/logs', ['client_uuid' => (string) Str::uuid(), 'recorded_on' => '2026-01-01', 'notes' => 'Work complete'])->assertCreated();
        $this->postJson($this->base.'/consultations', ['topic' => 'Review', 'mode' => 'video', 'requested_at' => now()->addDay()->toIso8601String()])->assertCreated();
        foreach (['bills', 'payments', 'members'] as $path) {
            $this->postJson($this->base.'/'.$path, [])->assertForbidden();
        }
        $this->getJson($this->base.'/members')->assertForbidden();
        $this->assertDatabaseCount('audit_events', 4);
    }

    public function test_outsider_cannot_read_or_mutate_operations(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['', '/members', '/audit'] as $path) {
            $this->getJson($this->base.$path)->assertNotFound();
        }
        foreach (['workers', 'attendance', 'bills', 'payments', 'members'] as $path) {
            $this->postJson($this->base.'/'.$path, [])->assertNotFound();
        }
        $this->deleteJson($this->base.'/members/'.$this->member->id)->assertNotFound();
        $this->getJson('/api/projects')->assertJsonCount(0);
    }

    public function test_founder_adds_changes_and_revokes_existing_account_access(): void
    {
        $u = User::factory()->create();
        $data = ['email' => $u->email, 'role' => 'member'];
        $this->postJson($this->base.'/members', $data)->assertCreated();
        $this->postJson($this->base.'/members', $data)->assertOk();
        $this->postJson($this->base.'/members', [...$data, 'role' => 'professional'])->assertOk();
        $this->assertDatabaseCount('audit_events', 2);
        $this->actingAs($u)->getJson($this->base)->assertOk()->assertJsonPath('permissions.write_site', true);
        $this->actingAs($this->founder)->deleteJson($this->base.'/members/'.$u->id)->assertNoContent();
        $this->actingAs($u)->getJson($this->base)->assertNotFound();
        $this->postJson($this->base.'/workers', [])->assertNotFound();
        $this->assertDatabaseCount('audit_events', 3);
    }

    public function test_founder_cannot_be_removed_replaced_or_promoted_by_request_data(): void
    {
        $this->postJson($this->base.'/members', ['email' => $this->founder->email, 'role' => 'member'])->assertUnprocessable();
        foreach (['founder', 'admin', 'owner'] as $role) {
            $this->postJson($this->base.'/members', ['email' => $this->member->email, 'role' => $role])->assertUnprocessable();
        }
        $this->deleteJson($this->base.'/members/'.$this->founder->id)->assertUnprocessable();
        $this->postJson($this->base.'/members', ['email' => 'missing@example.test', 'role' => 'member'])->assertUnprocessable();
        $this->assertDatabaseHas('projects', ['id' => $this->project->id, 'user_id' => $this->founder->id]);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_exact_attendance_costs_and_duplicate_conflict(): void
    {
        $worker = $this->makeWorker();
        $data = ['worker_id' => $worker['id'], 'recorded_on' => '2026-01-01', 'day' => 'half', 'notes' => 'Morning shift'];
        $this->actingAs($this->professional)->postJson($this->base.'/attendance', $data)->assertCreated()->assertJsonPath('total_paisa', 62501)->assertJsonPath('rate_paisa', 125001);
        $this->postJson($this->base.'/attendance', $data)->assertOk();
        $this->postJson($this->base.'/attendance', [...$data, 'day' => 'full'])->assertConflict();
        $this->postJson($this->base.'/attendance', [...$data, 'day' => 'absent', 'recorded_on' => '2026-01-02'])->assertCreated()->assertJsonPath('total_paisa', 0);
        $this->postJson($this->base.'/attendance', [...$data, 'day' => 'full', 'recorded_on' => '2026-01-03'])->assertCreated()->assertJsonPath('total_paisa', 125001);
        $this->getJson($this->base)->assertJsonPath('labor_total_paisa', 187502);
        $this->assertDatabaseCount('attendance', 3);
        $this->assertDatabaseCount('audit_events', 4);
    }

    public function test_attendance_validates_project_scope_days_and_dates(): void
    {
        $worker = $this->makeWorker();
        foreach (['quarter', '-1', '1.5'] as $day) {
            $this->postJson($this->base.'/attendance', ['worker_id' => $worker['id'], 'recorded_on' => '2026-01-01', 'day' => $day])->assertUnprocessable();
        }
        $this->postJson($this->base.'/attendance', ['worker_id' => $worker['id'], 'recorded_on' => '2099-01-01', 'day' => 'full'])->assertUnprocessable();
        $this->postJson($this->base.'/attendance', ['worker_id' => 999, 'recorded_on' => '2026-01-01', 'day' => 'full'])->assertUnprocessable();
        $other = $this->postJson('/api/projects', ['name' => 'Other', 'location' => 'Patan', 'budget' => '1'])->assertCreated()->json();
        $this->postJson('/api/projects/'.$other['id'].'/attendance', ['worker_id' => $worker['id'], 'recorded_on' => '2026-01-01', 'day' => 'full'])->assertUnprocessable();
    }

    public function test_invalid_monetary_values_and_categories_never_write_records(): void
    {
        foreach (['0', '-1', '1e3', '0.001', '100000000', 'NaN'] as $amount) {
            $this->postJson($this->base.'/bills', $this->billData(['amount' => $amount]))->assertUnprocessable();
            $this->postJson($this->base.'/workers', ['name' => 'A', 'trade' => 'Mason', 'daily_rate' => $amount])->assertUnprocessable();
        }
        $this->postJson($this->base.'/bills', $this->billData(['category' => 'unknown']))->assertUnprocessable();
        $this->assertDatabaseCount('bills', 0);
        $this->assertDatabaseCount('workers', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_bill_uuid_replay_reference_uniqueness_and_conflicts(): void
    {
        $data = $this->billData();
        $bill = $this->postJson($this->base.'/bills', $data)->assertCreated()->assertJsonPath('amount_paisa', 100001)->json();
        $this->postJson($this->base.'/bills', $data)->assertOk()->assertJsonPath('id', $bill['id']);
        $this->postJson($this->base.'/bills', [...$data, 'amount' => '1000.02'])->assertConflict();
        $this->postJson($this->base.'/bills', $this->billData())->assertUnprocessable();
        $this->assertDatabaseCount('bills', 1);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_partial_and_final_payments_settle_bill_without_double_counting(): void
    {
        $bill = $this->makeBill();
        $first = $this->paymentData($bill['id']);
        $this->postJson($this->base.'/payments', $first)->assertCreated()->assertJsonPath('amount_paisa', 20001);
        $this->postJson($this->base.'/payments', $first)->assertOk();
        $this->postJson($this->base.'/payments', [...$first, 'amount' => '200.02'])->assertConflict();
        $this->getJson($this->base)->assertJsonPath('ledger.billed_paisa', 100001)->assertJsonPath('ledger.paid_paisa', 20001)->assertJsonPath('ledger.outstanding_paisa', 80000)->assertJsonPath('ledger.budget_remaining_paisa', -1);
        $this->postJson($this->base.'/payments', $this->paymentData($bill['id'], ['amount' => '800']))->assertCreated();
        $this->postJson($this->base.'/payments', $this->paymentData($bill['id'], ['amount' => '0.01']))->assertUnprocessable();
        $this->getJson($this->base)->assertJsonPath('bills.0.outstanding_paisa', 0)->assertJsonPath('ledger.paid_paisa', 100001)->assertJsonPath('ledger.outstanding_paisa', 0);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('audit_events', 3);
    }

    public function test_payment_validation_rejects_invalid_dates_methods_amounts_and_foreign_bill(): void
    {
        $bill = $this->makeBill();
        foreach ([['amount' => '1000.02'], ['amount' => '0'], ['amount' => '-1'], ['amount' => '1.001'], ['method' => 'esewa'], ['paid_on' => '2025-12-31'], ['paid_on' => '2099-01-01'], ['client_uuid' => 'bad']] as $v) {
            $this->postJson($this->base.'/payments', $this->paymentData($bill['id'], $v))->assertUnprocessable();
        }
        $other = $this->postJson('/api/projects', ['name' => 'Other', 'location' => 'Patan', 'budget' => '1'])->assertCreated()->json();
        $this->postJson('/api/projects/'.$other['id'].'/payments', $this->paymentData($bill['id']))->assertUnprocessable();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payee_balances_group_bills_and_payments_without_adding_operational_costs(): void
    {
        $a = $this->makeBill();
        $this->makeBill(['reference' => 'INV-002', 'amount' => '50', 'category' => 'labor']);
        $this->makeBill(['reference' => 'INV-003', 'amount' => '75', 'payee' => 'Supplier B']);
        $this->postJson($this->base.'/payments', $this->paymentData($a['id']))->assertCreated();
        $worker = $this->makeWorker();
        $this->postJson($this->base.'/attendance', ['worker_id' => $worker['id'], 'recorded_on' => '2026-01-01', 'day' => 'full'])->assertCreated();
        $this->postJson($this->base.'/materials', ['name' => 'Cement', 'supplier' => 'A', 'unit' => 'bag', 'quantity' => '1', 'rate' => '10', 'recorded_on' => '2026-01-01'])->assertCreated();
        $this->getJson($this->base)->assertJsonPath('ledger.billed_paisa', 112501)->assertJsonPath('ledger.outstanding_paisa', 92500)->assertJsonPath('ledger.payees.0.billed_paisa', 105001)->assertJsonPath('ledger.payees.0.paid_paisa', 20001)->assertJsonPath('ledger.payees.0.outstanding_paisa', 85000)->assertJsonPath('labor_total_paisa', 125001)->assertJsonPath('material_total_paisa', 1000);
    }

    public function test_audit_is_atomic_paged_and_does_not_include_credentials(): void
    {
        for ($i = 0; $i < 52; $i++) {
            $this->postJson($this->base.'/workers', ['name' => 'Worker '.$i, 'trade' => 'Masonry', 'daily_rate' => '1', 'actor_id' => $this->member->id, 'project_id' => 999])->assertCreated();
        }
        $page = $this->actingAs($this->member)->getJson($this->base.'/audit')->assertOk()->assertJsonCount(50, 'events')->assertJsonPath('events.0.actor_id', $this->founder->id)->json();
        $this->getJson($this->base.'/audit?before='.$page['next_before'])->assertOk()->assertJsonCount(2, 'events')->assertJsonPath('next_before', null);
        $this->assertStringNotContainsString('password', json_encode($page));
        $this->assertStringNotContainsString($this->founder->email, json_encode($page));
        $this->assertStringContainsString('+00:00', $page['events'][0]['created_at']);
        $this->getJson($this->base.'/audit?before=-1')->assertUnprocessable();
        $this->assertDatabaseHas('workers', ['project_id' => $this->project->id, 'name' => 'Worker 0']);
    }

    public function test_failed_audit_insert_rolls_back_the_domain_write(): void
    {
        DB::statement('CREATE TRIGGER fail_audit BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, "audit unavailable"); END;');
        $this->postJson($this->base.'/workers', ['name' => 'Rollback worker', 'trade' => 'Masonry', 'daily_rate' => '1'])->assertStatus(500);
        $this->assertDatabaseCount('workers', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }
}
