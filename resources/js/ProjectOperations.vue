<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from "vue";
import { api } from "./api";
import { money, bsDate, nepalTime } from "./domain";
import type { OperationsDetail, AuditEvent } from "./operations";
const props = defineProps<{
    detail: OperationsDetail;
    tab: string;
    t: (s: string) => string;
    refresh: () => Promise<void>;
}>();
const emit = defineEmits<{ busy: [value: boolean] }>();
const modal = ref(""),
    busy = ref(false),
    loading = ref(false),
    error = ref(""),
    notice = ref(""),
    removeUser = ref<number | null>(null);
type Member = { user_id: number; name: string; email: string; role: string };
const team = ref<{
    founder: { id: number; name: string; email: string };
    members: Member[];
} | null>(null);
const events = ref<AuditEvent[]>([]),
    nextBefore = ref<number | null>(null);
const today = () =>
    new Intl.DateTimeFormat("en-CA", {
        timeZone: "Asia/Kathmandu",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).format(new Date());
const form = reactive({
    name: "",
    trade: "",
    daily_rate: "",
    worker_id: "",
    day: "full",
    recorded_on: today(),
    notes: "",
    reference: "",
    payee: "",
    category: "material",
    description: "",
    amount: "",
    bill_id: "",
    method: "cash",
    email: "",
    role: "member",
    client_uuid: "",
});
const base = computed(() => `/api/projects/${props.detail.project.id}`);
const titles: Record<string, string> = {
    worker: "Add worker",
    attendance: "Record attendance",
    bill: "Add bill",
    payment: "Record payment",
    member: "Add or update member",
};
const selectedBill = computed(() =>
    props.detail.bills.find((b) => b.id === Number(form.bill_id)),
);
const selectedWorker = computed(() =>
    props.detail.workers.find((w) => w.id === Number(form.worker_id)),
);
let generation = 0;
async function loadExtra(append = false) {
    const gen = ++generation,
        url = base.value,
        tab = props.tab;
    loading.value = true;
    try {
        if (tab === "Team" && props.detail.permissions.manage_team) {
            const data = await api(`${url}/members`);
            if (gen === generation) team.value = data;
        }
        if (tab === "Activity") {
            const data = await api(
                `${url}/audit${append && nextBefore.value ? `?before=${nextBefore.value}` : ""}`,
            );
            if (gen === generation) {
                events.value = append
                    ? [...events.value, ...data.events]
                    : data.events;
                nextBefore.value = data.next_before;
            }
        }
    } catch (e) {
        if (gen === generation)
            error.value = e instanceof Error ? e.message : "Request failed";
    } finally {
        if (gen === generation) loading.value = false;
    }
}
watch(
    () => [props.detail.project.id, props.tab],
    () => {
        generation++;
        team.value = null;
        events.value = [];
        nextBefore.value = null;
        modal.value = "";
        removeUser.value = null;
        error.value = "";
        notice.value = "";
        void loadExtra();
    },
    { immediate: true },
);
async function run(fn: () => Promise<void>) {
    if (busy.value) return;
    busy.value = true;
    emit("busy", true);
    error.value = "";
    try {
        await fn();
    } catch (e) {
        error.value = e instanceof Error ? e.message : "Request failed";
    } finally {
        busy.value = false;
        emit("busy", false);
    }
}
function open(kind: string) {
    Object.assign(form, {
        name: "",
        trade: "",
        daily_rate: "",
        worker_id: String(props.detail.workers[0]?.id || ""),
        day: "full",
        recorded_on: today(),
        notes: "",
        reference: "",
        payee: "",
        category: "material",
        description: "",
        amount: "",
        bill_id: String(
            props.detail.bills.find((b) => b.outstanding_paisa > 0)?.id || "",
        ),
        method: "cash",
        email: "",
        role: "member",
        client_uuid: crypto.randomUUID(),
    });
    error.value = "";
    modal.value = kind;
}
function editMember(m: Member) {
    open("member");
    form.email = m.email;
    form.role = m.role;
}
async function save() {
    await run(async () => {
        const url = base.value,
            kind = modal.value;
        if (kind === "worker")
            await api(`${url}/workers`, "POST", {
                name: form.name,
                trade: form.trade,
                daily_rate: form.daily_rate,
            });
        if (kind === "attendance")
            await api(`${url}/attendance`, "POST", {
                worker_id: Number(form.worker_id),
                day: form.day,
                recorded_on: form.recorded_on,
                notes: form.notes,
            });
        if (kind === "bill")
            await api(`${url}/bills`, "POST", {
                client_uuid: form.client_uuid,
                reference: form.reference,
                payee: form.payee,
                category: form.category,
                description: form.description,
                amount: form.amount,
                issued_on: form.recorded_on,
            });
        if (kind === "payment")
            await api(`${url}/payments`, "POST", {
                client_uuid: form.client_uuid,
                bill_id: Number(form.bill_id),
                amount: form.amount,
                method: form.method,
                reference: form.reference,
                paid_on: form.recorded_on,
            });
        if (kind === "member")
            await api(`${url}/members`, "POST", {
                email: form.email,
                role: form.role,
            });
        modal.value = "";
        notice.value = props.t("Saved successfully.");
        await props.refresh();
        await loadExtra();
    });
}
async function remove(id: number) {
    await run(async () => {
        await api(`${base.value}/members/${id}`, "DELETE");
        removeUser.value = null;
        notice.value = props.t("Member access removed.");
        await loadExtra();
    });
}
let previousFocus: HTMLElement | null = null,
    previousOverflow = "";
const dialog = ref<HTMLElement | null>(null);
watch(modal, async (value) => {
    if (value) {
        previousFocus = document.activeElement as HTMLElement;
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = "hidden";
        await nextTick();
        dialog.value
            ?.querySelector<HTMLElement>("input,select,textarea")
            ?.focus();
    } else {
        document.body.style.overflow = previousOverflow;
        previousFocus?.focus();
    }
});
function keyboard(e: KeyboardEvent) {
    if (e.key === "Escape" && !busy.value) {
        modal.value = "";
        return;
    }
    if (e.key !== "Tab") return;
    const controls = Array.from(
        dialog.value?.querySelectorAll<HTMLElement>(
            "button:not(:disabled),input,select,textarea",
        ) || [],
    );
    const first = controls[0],
        last = controls.at(-1);
    if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last?.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first?.focus();
    }
}
onBeforeUnmount(() => {
    generation++;
    if (modal.value) document.body.style.overflow = previousOverflow;
});
const actionLabel = (action: string) =>
    props.t(
        {
            "project.created": "Project created",
            "material.created": "Material recorded",
            "diary.created": "Site log recorded",
            "consultation.requested": "Consultation requested",
            "worker.created": "Worker added",
            "attendance.created": "Attendance recorded",
            "bill.created": "Bill recorded",
            "payment.created": "Payment recorded",
            "member.added": "Member added",
            "member.role_changed": "Member role changed",
            "member.removed": "Member removed",
        }[action] || action,
    );
</script>
<template>
    <div class="operations">
        <p class="role-note">
            {{ t("Your project role") }}: <strong>{{ t(detail.role) }}</strong
            ><span v-if="detail.role === 'member'">
                · {{ t("Read-only access") }}</span
            >
        </p>
        <p v-if="error && !modal" class="error" role="alert">{{ error }}</p>
        <p v-if="notice" class="notice" role="status">{{ notice }}</p>
        <template v-if="tab === 'Labor'">
            <div class="stats operations-stats">
                <article class="stat glass">
                    <span>{{ t("Workers") }}</span
                    ><strong>{{ detail.workers.length }}</strong>
                </article>
                <article class="stat glass">
                    <span>{{ t("Recorded labor cost") }}</span
                    ><strong class="money">{{
                        money(detail.labor_total_paisa)
                    }}</strong
                    ><small>{{ t("Attendance estimate, not a bill.") }}</small>
                </article>
                <article class="stat glass">
                    <span>{{ t("Attendance records") }}</span
                    ><strong>{{ detail.attendance.length }}</strong>
                </article>
            </div>
            <section class="panel glass">
                <div class="section-heading">
                    <h2>{{ t("Workers") }}</h2>
                    <button
                        v-if="detail.permissions.write_site"
                        class="primary"
                        @click="open('worker')"
                        :disabled="busy"
                    >
                        ＋ {{ t("Add worker") }}
                    </button>
                </div>
                <div class="worker-grid">
                    <article
                        class="worker-card"
                        v-for="w in detail.workers"
                        :key="w.id"
                    >
                        <h3>{{ w.name }}</h3>
                        <p class="muted">{{ w.trade }}</p>
                        <strong
                            >{{ money(w.daily_rate_paisa) }} /
                            {{ t("day") }}</strong
                        >
                    </article>
                </div>
                <p v-if="!detail.workers.length" class="empty">
                    {{ t("No workers yet.") }}
                </p>
            </section>
            <section class="panel glass">
                <div class="section-heading">
                    <h2>{{ t("Attendance register") }}</h2>
                    <button
                        v-if="detail.permissions.write_site"
                        class="primary"
                        @click="open('attendance')"
                        :disabled="busy || !detail.workers.length"
                    >
                        ＋ {{ t("Record attendance") }}
                    </button>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>{{ t("Worker") }}</th>
                                <th>{{ t("Date") }}</th>
                                <th>{{ t("Attendance") }}</th>
                                <th>{{ t("Rate (NPR)") }}</th>
                                <th>{{ t("Amount") }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="a in detail.attendance" :key="a.id">
                                <td>
                                    <strong>{{ a.worker_name }}</strong
                                    ><small class="wrap-text">{{
                                        a.notes
                                    }}</small>
                                </td>
                                <td :data-label="t('Date')">
                                    {{ bsDate(a.recorded_on) }} BS<small
                                        >{{ a.recorded_on }} AD</small
                                    >
                                </td>
                                <td :data-label="t('Attendance')">
                                    {{
                                        t(
                                            a.day_milli === 1000
                                                ? "Full day"
                                                : a.day_milli === 500
                                                  ? "Half day"
                                                  : "Absent",
                                        )
                                    }}
                                </td>
                                <td :data-label="t('Rate (NPR)')">
                                    {{ money(a.rate_paisa) }}
                                </td>
                                <td class="amount" :data-label="t('Amount')">
                                    {{ money(a.total_paisa) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="!detail.attendance.length" class="empty">
                    {{ t("No attendance yet.") }}
                </p>
            </section>
        </template>
        <template v-else-if="tab === 'Bills & payments'">
            <p class="ledger-explainer">
                {{
                    t(
                        "Budget uses billed costs only. Material and attendance records are separate estimates; record their bills here once. Payments settle bills and do not add to cost.",
                    )
                }}
            </p>
            <div class="stats operations-stats">
                <article class="stat glass">
                    <span>{{ t("Billed cost") }}</span
                    ><strong class="money">{{
                        money(detail.ledger.billed_paisa)
                    }}</strong
                    ><small
                        >{{ t("Planned budget") }} ·
                        {{ money(detail.project.budget_paisa) }}</small
                    >
                </article>
                <article class="stat glass">
                    <span>{{ t("Outstanding balance") }}</span
                    ><strong class="money">{{
                        money(detail.ledger.outstanding_paisa)
                    }}</strong
                    ><small
                        >{{ t("Paid") }} ·
                        {{ money(detail.ledger.paid_paisa) }}</small
                    >
                </article>
                <article class="stat glass">
                    <span>{{
                        t(
                            detail.ledger.budget_remaining_paisa < 0
                                ? "Over budget"
                                : "Budget remaining",
                        )
                    }}</span
                    ><strong
                        class="money"
                        :class="{
                            over: detail.ledger.budget_remaining_paisa < 0,
                        }"
                        >{{
                            money(
                                Math.abs(detail.ledger.budget_remaining_paisa),
                            )
                        }}</strong
                    ><small>{{ t("Budget minus billed cost") }}</small>
                </article>
            </div>
            <section class="panel glass">
                <div class="section-heading">
                    <h2>{{ t("Bill register") }}</h2>
                    <div
                        class="button-group"
                        v-if="detail.permissions.manage_finance"
                    >
                        <button
                            class="secondary"
                            @click="open('payment')"
                            :disabled="
                                busy ||
                                !detail.bills.some(
                                    (b) => b.outstanding_paisa > 0,
                                )
                            "
                        >
                            {{ t("Record payment") }}</button
                        ><button
                            class="primary"
                            @click="open('bill')"
                            :disabled="busy"
                        >
                            ＋ {{ t("Add bill") }}
                        </button>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>{{ t("Bill") }}</th>
                                <th>{{ t("Payee") }}</th>
                                <th>{{ t("Date") }}</th>
                                <th>{{ t("Amount") }}</th>
                                <th>{{ t("Outstanding balance") }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="b in detail.bills" :key="b.id">
                                <td>
                                    <strong>{{ b.reference }}</strong
                                    ><small>{{ t(b.category) }}</small
                                    ><small class="wrap-text">{{
                                        b.description
                                    }}</small>
                                </td>
                                <td :data-label="t('Payee')">{{ b.payee }}</td>
                                <td :data-label="t('Date')">
                                    {{ bsDate(b.issued_on) }} BS<small
                                        >{{ b.issued_on }} AD</small
                                    >
                                </td>
                                <td :data-label="t('Amount')">
                                    {{ money(b.amount_paisa) }}
                                </td>
                                <td
                                    class="amount"
                                    :data-label="t('Outstanding balance')"
                                >
                                    {{ money(b.outstanding_paisa)
                                    }}<small
                                        >{{ t("Paid") }} ·
                                        {{ money(b.paid_paisa) }}</small
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="!detail.bills.length" class="empty">
                    {{ t("No bills yet.") }}
                </p>
            </section>
            <section class="panel glass">
                <div class="section-heading">
                    <h2>{{ t("Payee balances") }}</h2>
                </div>
                <p class="muted">
                    {{
                        t(
                            "Use a consistent payee name to group supplier and worker balances.",
                        )
                    }}
                </p>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>{{ t("Payee") }}</th>
                                <th>{{ t("Billed cost") }}</th>
                                <th>{{ t("Paid") }}</th>
                                <th>{{ t("Outstanding balance") }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="p in detail.ledger.payees"
                                :key="p.payee"
                            >
                                <td>
                                    <strong>{{ p.payee }}</strong>
                                </td>
                                <td :data-label="t('Billed cost')">
                                    {{ money(p.billed_paisa) }}
                                </td>
                                <td :data-label="t('Paid')">
                                    {{ money(p.paid_paisa) }}
                                </td>
                                <td
                                    class="amount"
                                    :data-label="t('Outstanding balance')"
                                >
                                    {{ money(p.outstanding_paisa) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="!detail.ledger.payees.length" class="empty">
                    {{ t("No balances yet.") }}
                </p>
            </section>
            <section class="panel glass">
                <div class="section-heading">
                    <h2>{{ t("Payment history") }}</h2>
                </div>
                <p class="muted">
                    {{
                        t(
                            "Manual records only — no bank transfer or gateway is initiated.",
                        )
                    }}
                </p>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>{{ t("Bill") }}</th>
                                <th>{{ t("Date") }}</th>
                                <th>{{ t("Method") }}</th>
                                <th>{{ t("Reference") }}</th>
                                <th>{{ t("Amount") }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in detail.payments" :key="p.id">
                                <td>
                                    {{
                                        detail.bills.find(
                                            (b) => b.id === p.bill_id,
                                        )?.reference
                                    }}
                                </td>
                                <td :data-label="t('Date')">
                                    {{ bsDate(p.paid_on) }} BS<small
                                        >{{ p.paid_on }} AD</small
                                    >
                                </td>
                                <td :data-label="t('Method')">
                                    {{ t(p.method) }}
                                </td>
                                <td :data-label="t('Reference')">
                                    {{ p.reference || "—" }}
                                </td>
                                <td class="amount" :data-label="t('Amount')">
                                    {{ money(p.amount_paisa) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="!detail.payments.length" class="empty">
                    {{ t("No payments yet.") }}
                </p>
            </section>
        </template>
        <template v-else-if="tab === 'Team'">
            <section class="panel glass">
                <div class="section-heading">
                    <h2>{{ t("Project team") }}</h2>
                    <button
                        v-if="detail.permissions.manage_team"
                        class="primary"
                        @click="open('member')"
                        :disabled="busy"
                    >
                        ＋ {{ t("Add or update member") }}
                    </button>
                </div>
                <p class="ledger-explainer">
                    {{
                        t(
                            "Founder manages the team and finances. Professionals record site work. Members can read project records, including finances and activity. Add existing accounts only; invitations and approvals are not available yet.",
                        )
                    }}
                </p>
                <p v-if="!detail.permissions.manage_team" class="empty">
                    {{
                        t(
                            "Only the founder can view and manage the account directory.",
                        )
                    }}
                </p>
                <p v-else-if="loading" role="status">{{ t("Loading…") }}</p>
                <template v-else-if="team"
                    ><article class="team-row">
                        <div>
                            <h3>{{ team.founder.name }}</h3>
                            <p class="muted wrap-text">
                                {{ team.founder.email }}
                            </p>
                        </div>
                        <span class="badge">{{ t("founder") }}</span>
                    </article>
                    <article
                        class="team-row"
                        v-for="m in team.members"
                        :key="m.user_id"
                    >
                        <div>
                            <h3>{{ m.name }}</h3>
                            <p class="muted wrap-text">{{ m.email }}</p>
                            <span class="badge">{{ t(m.role) }}</span>
                        </div>
                        <div
                            class="button-group"
                            v-if="removeUser !== m.user_id"
                        >
                            <button
                                class="secondary"
                                @click="editMember(m)"
                                :disabled="busy"
                            >
                                {{ t("Change role") }}</button
                            ><button
                                class="text-button"
                                @click="removeUser = m.user_id"
                                :disabled="busy"
                            >
                                {{ t("Remove access") }}
                            </button>
                        </div>
                        <div v-else class="remove-confirm">
                            <p>{{ t("Remove this member’s access?") }}</p>
                            <div class="button-group">
                                <button
                                    class="secondary"
                                    @click="removeUser = null"
                                    :disabled="busy"
                                >
                                    {{ t("Cancel") }}</button
                                ><button
                                    class="primary"
                                    @click="remove(m.user_id)"
                                    :disabled="busy"
                                >
                                    {{ t("Confirm removal") }}
                                </button>
                            </div>
                        </div>
                    </article>
                    <p v-if="!team.members.length" class="empty">
                        {{ t("No members yet.") }}
                    </p></template
                >
            </section>
        </template>
        <template v-else-if="tab === 'Activity'"
            ><section class="panel glass">
                <div class="section-heading">
                    <h2>{{ t("Audit history") }}</h2>
                    <button
                        class="text-button"
                        @click="loadExtra()"
                        :disabled="loading || busy"
                    >
                        {{ t("Refresh") }}
                    </button>
                </div>
                <p class="muted">
                    {{
                        t(
                            "Activity is recorded from this upgrade onward. Earlier records are not backfilled.",
                        )
                    }}
                </p>
                <p v-if="loading && !events.length" role="status">
                    {{ t("Loading…") }}
                </p>
                <ol class="audit-list">
                    <li v-for="e in events" :key="e.id">
                        <div>
                            <strong>{{ actionLabel(e.action) }}</strong>
                            <p>
                                {{ e.actor_name }} · {{ t(e.subject_type) }} #{{
                                    e.subject_id
                                }}
                            </p>
                            <p class="muted">
                                {{ nepalTime(e.created_at) }} NPT
                            </p>
                            <p
                                v-if="
                                    e.details.name ||
                                    e.details.worker_name ||
                                    e.details.reference
                                "
                                class="muted wrap-text"
                            >
                                {{
                                    e.details.name ||
                                    e.details.worker_name ||
                                    e.details.reference
                                }}
                            </p>
                            <p
                                v-if="e.details.amount_paisa !== undefined"
                                class="muted"
                            >
                                {{ money(Number(e.details.amount_paisa)) }}
                            </p>
                        </div>
                    </li>
                </ol>
                <p v-if="!loading && !events.length" class="empty">
                    {{ t("No activity yet.") }}
                </p>
                <button
                    v-if="nextBefore"
                    class="secondary"
                    @click="loadExtra(true)"
                    :disabled="loading || busy"
                >
                    {{ t("Load older activity") }}
                </button>
            </section></template
        >
        <div
            v-if="modal"
            class="modal-backdrop"
            @click.self="!busy && (modal = '')"
        >
            <section
                ref="dialog"
                class="modal glass"
                role="dialog"
                aria-modal="true"
                aria-labelledby="operations-modal-title"
                @keydown="keyboard"
            >
                <div class="section-heading">
                    <h2 id="operations-modal-title">
                        {{ t(titles[modal] || "") }}
                    </h2>
                    <button
                        class="close"
                        type="button"
                        :aria-label="t('Close dialog')"
                        @click="modal = ''"
                        :disabled="busy"
                    >
                        ×
                    </button>
                </div>
                <form @submit.prevent="save">
                    <template v-if="modal === 'worker'"
                        ><label
                            >{{ t("Worker name")
                            }}<input
                                v-model="form.name"
                                required
                                maxlength="120" /></label
                        ><label
                            >{{ t("Trade")
                            }}<input
                                v-model="form.trade"
                                required
                                maxlength="80" /></label
                        ><label
                            >{{ t("Daily rate (NPR)")
                            }}<input
                                v-model="form.daily_rate"
                                inputmode="decimal"
                                pattern="[0-9]+(\.[0-9]{1,2})?"
                                required /></label
                    ></template>
                    <template v-if="modal === 'attendance'"
                        ><label
                            >{{ t("Worker")
                            }}<select v-model="form.worker_id" required>
                                <option
                                    v-for="w in detail.workers"
                                    :key="w.id"
                                    :value="String(w.id)"
                                >
                                    {{ w.name }} · {{ w.trade }}
                                </option>
                            </select></label
                        >
                        <p class="muted">
                            {{ t("Daily rate (NPR)") }}:
                            {{ money(selectedWorker?.daily_rate_paisa || 0) }}
                        </p>
                        <label
                            >{{ t("Attendance")
                            }}<select v-model="form.day">
                                <option value="full">
                                    {{ t("Full day") }}
                                </option>
                                <option value="half">
                                    {{ t("Half day") }}
                                </option>
                                <option value="absent">
                                    {{ t("Absent") }}
                                </option>
                            </select></label
                        ><label
                            >{{ t("Notes")
                            }}<textarea
                                v-model="form.notes"
                                maxlength="500"
                                rows="3"
                            ></textarea></label
                    ></template>
                    <template v-if="modal === 'bill'"
                        ><label
                            >{{ t("Bill reference")
                            }}<input
                                v-model="form.reference"
                                required
                                maxlength="80" /></label
                        ><label
                            >{{ t("Payee")
                            }}<input
                                v-model="form.payee"
                                required
                                maxlength="120" /></label
                        ><label
                            >{{ t("Category")
                            }}<select v-model="form.category">
                                <option
                                    v-for="c in ['material', 'labor', 'other']"
                                    :key="c"
                                    :value="c"
                                >
                                    {{ t(c) }}
                                </option>
                            </select></label
                        ><label
                            >{{ t("Description")
                            }}<textarea
                                v-model="form.description"
                                required
                                maxlength="500"
                                rows="3"
                            ></textarea></label
                    ></template>
                    <template v-if="modal === 'payment'"
                        ><label
                            >{{ t("Bill")
                            }}<select v-model="form.bill_id" required>
                                <option
                                    v-for="b in detail.bills.filter(
                                        (b) => b.outstanding_paisa > 0,
                                    )"
                                    :key="b.id"
                                    :value="String(b.id)"
                                >
                                    {{ b.reference }} · {{ b.payee }}
                                </option>
                            </select></label
                        >
                        <p class="muted">
                            {{ t("Outstanding balance") }}:
                            {{ money(selectedBill?.outstanding_paisa || 0) }}
                        </p>
                        <label
                            >{{ t("Method")
                            }}<select v-model="form.method">
                                <option
                                    v-for="m in ['cash', 'bank', 'other']"
                                    :key="m"
                                    :value="m"
                                >
                                    {{ t(m) }}
                                </option>
                            </select></label
                        ><label
                            >{{ t("Reference (optional)")
                            }}<input v-model="form.reference" maxlength="120"
                        /></label>
                        <p class="muted">
                            {{
                                t(
                                    "Manual records only — no bank transfer or gateway is initiated.",
                                )
                            }}
                        </p></template
                    >
                    <label v-if="['bill', 'payment'].includes(modal)"
                        >{{ t("Amount (NPR)")
                        }}<input
                            v-model="form.amount"
                            required
                            inputmode="decimal"
                            pattern="[0-9]+(\.[0-9]{1,2})?"
                    /></label>
                    <label
                        v-if="['attendance', 'bill', 'payment'].includes(modal)"
                        >{{ t("Date (AD)")
                        }}<input
                            v-model="form.recorded_on"
                            type="date"
                            required
                            :max="modal === 'bill' ? undefined : today()"
                            :min="
                                modal === 'payment'
                                    ? selectedBill?.issued_on
                                    : undefined
                            "
                        /><small
                            >{{ bsDate(form.recorded_on) }} BS</small
                        ></label
                    >
                    <template v-if="modal === 'member'"
                        ><label
                            >{{ t("Account email")
                            }}<input
                                v-model="form.email"
                                type="email"
                                autocomplete="off"
                                required
                                maxlength="255" /></label
                        ><label
                            >{{ t("Role")
                            }}<select v-model="form.role">
                                <option value="member">
                                    {{ t("member") }}
                                </option>
                                <option value="professional">
                                    {{ t("professional") }}
                                </option>
                            </select></label
                        >
                        <p class="muted">
                            {{
                                t(
                                    "Members can read financial records. Only add people you trust.",
                                )
                            }}
                        </p></template
                    >
                    <p v-if="error" class="error" role="alert">{{ error }}</p>
                    <div class="modal-actions">
                        <button
                            class="secondary"
                            type="button"
                            @click="modal = ''"
                            :disabled="busy"
                        >
                            {{ t("Cancel") }}</button
                        ><button class="primary" :disabled="busy">
                            {{ busy ? "…" : t("Save") }}
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</template>
