import { afterEach, describe, it, expect, vi } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import ProjectOperations from "../../resources/js/ProjectOperations.vue";
import type { OperationsDetail } from "../../resources/js/operations";
const detail: OperationsDetail = {
    project: { id: 1, budget_paisa: 100000 },
    role: "founder",
    permissions: { manage_team: true, manage_finance: true, write_site: true },
    workers: [
        {
            id: 1,
            name: "Test Worker",
            trade: "Mason",
            daily_rate_paisa: 125001,
        },
    ],
    attendance: [],
    labor_total_paisa: 0,
    material_total_paisa: 0,
    bills: [
        {
            id: 1,
            reference: "INV-001",
            payee: "Supplier",
            category: "material",
            description: "Cement",
            amount_paisa: 100001,
            issued_on: "2026-01-01",
            paid_paisa: 0,
            outstanding_paisa: 100001,
        },
    ],
    payments: [],
    ledger: {
        billed_paisa: 100001,
        paid_paisa: 0,
        outstanding_paisa: 100001,
        budget_remaining_paisa: -1,
        payees: [],
    },
};
const mounts: ReturnType<typeof mount>[] = [];
function setup(tab: string, value = detail) {
    const wrapper = mount(ProjectOperations, {
        attachTo: document.body,
        props: {
            detail: structuredClone(value),
            tab,
            t: (s: string) => s,
            refresh: vi.fn().mockResolvedValue(undefined),
        },
    });
    mounts.push(wrapper);
    return wrapper;
}
afterEach(() => {
    mounts.splice(0).forEach((w) => w.unmount());
    vi.unstubAllGlobals();
    document.body.innerHTML = "";
    document.body.style.overflow = "";
});
describe("project operations", () => {
    it("renders labor and hides writes for a read-only member", () => {
        const w = setup("Labor", {
            ...detail,
            role: "member",
            permissions: {
                manage_team: false,
                manage_finance: false,
                write_site: false,
            },
        });
        expect(w.text()).toContain("Read-only access");
        expect(w.text()).toContain("Test Worker");
        expect(w.findAll("button").length).toBe(0);
    });
    it("shows over-budget ledger and explicitly separates bill cost from estimates", () => {
        const w = setup("Bills & payments");
        expect(w.text()).toContain("Over budget");
        expect(w.text()).toContain(
            "Material and attendance records are separate estimates",
        );
        expect(w.text()).toContain("Payments settle bills");
        expect(w.find(".over").text()).toMatch(/0.01/);
    });
    it("keeps a stable request UUID on payment retry and exposes errors", async () => {
        const fetch = vi
            .fn()
            .mockResolvedValue({
                ok: false,
                status: 422,
                json: async () => ({
                    errors: {
                        amount: [
                            "Payment exceeds the outstanding bill balance.",
                        ],
                    },
                }),
            });
        vi.stubGlobal("fetch", fetch);
        const w = setup("Bills & payments");
        await w
            .findAll("button")
            .find((b) => b.text() === "Record payment")!
            .trigger("click");
        const input = w.find("input[inputmode=decimal]");
        await input.setValue("1001");
        await w.get("form").trigger("submit");
        await flushPromises();
        expect(w.get("[role=alert]").text()).toContain("exceeds");
        const first = JSON.parse(fetch.mock.calls[0]![1].body);
        await input.setValue("200");
        await w.get("form").trigger("submit");
        await flushPromises();
        const second = JSON.parse(fetch.mock.calls[1]![1].body);
        expect(first.client_uuid).toBe(second.client_uuid);
        expect(second.amount).toBe("200");
        expect(w.emitted("busy")?.map((e) => e[0])).toEqual([
            true,
            false,
            true,
            false,
        ]);
    });
    it("clears member directory and ignores stale responses when project changes", async () => {
        let resolve: (value: unknown) => void = () => {};
        vi.stubGlobal(
            "fetch",
            vi
                .fn()
                .mockImplementationOnce(
                    () =>
                        new Promise((r) => {
                            resolve = r;
                        }),
                )
                .mockResolvedValue({
                    ok: true,
                    status: 200,
                    json: async () => ({
                        founder: {
                            id: 3,
                            name: "New founder",
                            email: "new@example.test",
                        },
                        members: [],
                    }),
                }),
        );
        const w = setup("Team");
        await w.setProps({
            detail: { ...detail, project: { id: 2, budget_paisa: 0 } },
        });
        await flushPromises();
        resolve({
            ok: true,
            status: 200,
            json: async () => ({
                founder: {
                    id: 1,
                    name: "Old founder",
                    email: "old@example.test",
                },
                members: [],
            }),
        });
        await flushPromises();
        expect(w.text()).toContain("New founder");
        expect(w.text()).not.toContain("Old founder");
    });
    it("does not load the directory for non-founders", async () => {
        const fetch = vi.fn();
        vi.stubGlobal("fetch", fetch);
        const w = setup("Team", {
            ...detail,
            role: "professional",
            permissions: {
                manage_team: false,
                manage_finance: false,
                write_site: true,
            },
        });
        await flushPromises();
        expect(w.text()).toContain("Only the founder");
        expect(fetch).not.toHaveBeenCalled();
    });
    it("traps modal focus and restores body scrolling on unmount", async () => {
        const w = setup("Labor");
        await w
            .findAll("button")
            .find((b) => b.text().includes("Add worker"))!
            .trigger("click");
        expect(document.body.style.overflow).toBe("hidden");
        const buttons = w.findAll("[role=dialog] button");
        const last = buttons.at(-1)!;
        (last.element as HTMLElement).focus();
        await last.trigger("keydown", { key: "Tab" });
        expect(document.activeElement).toBe(buttons[0]!.element);
        w.unmount();
        mounts.pop();
        expect(document.body.style.overflow).toBe("");
    });
});
