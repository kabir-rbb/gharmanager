export type Role = "founder" | "professional" | "member";
export type Worker = {
    id: number;
    name: string;
    trade: string;
    daily_rate_paisa: number;
};
export type Attendance = {
    id: number;
    worker_name: string;
    trade: string;
    recorded_on: string;
    day_milli: number;
    rate_paisa: number;
    total_paisa: number;
    notes: string;
};
export type Bill = {
    id: number;
    reference: string;
    payee: string;
    category: string;
    description: string;
    amount_paisa: number;
    issued_on: string;
    paid_paisa: number;
    outstanding_paisa: number;
};
export type Payment = {
    id: number;
    bill_id: number;
    amount_paisa: number;
    method: string;
    reference: string;
    paid_on: string;
};
export type OperationsDetail = {
    project: { id: number; budget_paisa: number };
    role: Role;
    permissions: {
        manage_team: boolean;
        manage_finance: boolean;
        write_site: boolean;
    };
    workers: Worker[];
    attendance: Attendance[];
    labor_total_paisa: number;
    material_total_paisa: number;
    bills: Bill[];
    payments: Payment[];
    ledger: {
        billed_paisa: number;
        paid_paisa: number;
        outstanding_paisa: number;
        budget_remaining_paisa: number;
        payees: {
            payee: string;
            billed_paisa: number;
            paid_paisa: number;
            outstanding_paisa: number;
        }[];
    };
};
export type AuditEvent = {
    id: number;
    actor_name: string;
    action: string;
    subject_type: string;
    subject_id: number;
    created_at: string;
    details: Record<string, unknown>;
};
