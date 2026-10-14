import { execFileSync } from "node:child_process";
import { test, expect, Page } from "@playwright/test";
async function signIn(page: Page, email = "demo@ghar.test") {
    await page.goto("/");
    await page.getByLabel("Email", { exact: true }).fill(email);
    await page.getByLabel("Password", { exact: true }).fill("LocalDemo!2026");
    await page.getByRole("button", { name: "Sign in", exact: true }).click();
    await expect(page.locator("main h1")).toContainText(
        "Your home, one clear picture.",
    );
}
async function request(
    page: Page,
    path: string,
    method = "GET",
    body?: unknown,
) {
    return page.evaluate(
        async ({ path, method, body }) => {
            const csrf = decodeURIComponent(
                document.cookie
                    .split("; ")
                    .find((v) => v.startsWith("XSRF-TOKEN="))
                    ?.slice(11) || "",
            );
            const r = await fetch(path, {
                method,
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-XSRF-TOKEN": csrf,
                },
                body: body === undefined ? undefined : JSON.stringify(body),
            });
            return {
                status: r.status,
                data: r.status === 204 ? null : await r.json(),
            };
        },
        { path, method, body },
    );
}
async function project(page: Page) {
    const r = await request(page, "/api/projects", "POST", {
        name: "Operations " + Date.now(),
        location: "Patan",
        budget: "1000.01",
    });
    expect(r.status).toBe(201);
    await page.reload();
    await expect(page.locator("main h1")).toContainText(
        "Your home, one clear picture.",
    );
    return r.data.id as number;
}
async function tab(page: Page, name: string) {
    await page.getByRole("button", { name, exact: true }).click();
    await expect(page.locator("main h1")).toHaveText(name);
}
async function bill(page: Page) {
    await page.getByRole("button", { name: "Add bill" }).click();
    await page.getByLabel("Bill reference", { exact: true }).fill("INV-UI-001");
    await page.getByLabel("Payee", { exact: true }).fill("Test Supplier");
    await page
        .getByLabel("Description", { exact: true })
        .fill("Cement supplied");
    await page.getByLabel("Amount (NPR)", { exact: true }).fill("600.01");
    await page.getByRole("button", { name: "Save", exact: true }).click();
    await expect(page.getByRole("dialog")).not.toBeVisible();
    await expect(
        page.getByRole("cell", { name: "INV-UI-001", exact: false }).first(),
    ).toBeVisible();
}
async function payment(page: Page, amount: string) {
    await page
        .getByRole("button", { name: "Record payment", exact: true })
        .click();
    await page.getByLabel("Amount (NPR)", { exact: true }).fill(amount);
    await page.getByRole("button", { name: "Save", exact: true }).click();
}

test("founder records labor, settles bills, and sees persisted audit history", async ({
    page,
}) => {
    await signIn(page);
    const id = await project(page);
    await tab(page, "Labor");
    await page.getByRole("button", { name: "Add worker" }).click();
    await page.getByLabel("Worker name", { exact: true }).fill("UI Mason");
    await page.getByLabel("Trade", { exact: true }).fill("Masonry");
    await page.getByLabel("Daily rate (NPR)", { exact: true }).fill("1250.01");
    await page.getByRole("button", { name: "Save", exact: true }).click();
    await expect(page.getByRole("heading", { name: "UI Mason" })).toBeVisible();
    await page.getByRole("button", { name: "Record attendance" }).click();
    await page
        .getByRole("combobox", { name: "Attendance", exact: true })
        .selectOption("half");
    await page.getByLabel("Notes", { exact: true }).fill("Morning work");
    await page.getByRole("button", { name: "Save", exact: true }).click();
    await expect(page.getByRole("cell", { name: /625.01/ })).toBeVisible();
    await tab(page, "Bills & payments");
    await bill(page);
    await payment(page, "700");
    await expect(page.getByRole("alert")).toContainText("exceeds");
    await page.getByLabel("Amount (NPR)", { exact: true }).fill("200.01");
    await page.getByRole("button", { name: "Save", exact: true }).click();
    await expect(page.getByRole("dialog")).not.toBeVisible();
    await expect(
        page.getByRole("cell", { name: /400.00/ }).first(),
    ).toBeVisible();
    await payment(page, "400");
    await expect(page.getByRole("dialog")).not.toBeVisible();
    await expect(
        page.getByRole("button", { name: "Record payment", exact: true }),
    ).toBeDisabled();
    const d = await request(page, `/api/projects/${id}`);
    expect(d.data.ledger).toMatchObject({
        billed_paisa: 60001,
        paid_paisa: 60001,
        outstanding_paisa: 0,
        budget_remaining_paisa: 40000,
    });
    expect(d.data.labor_total_paisa).toBe(62501);
    await page.reload();
    await expect(page.locator("main h1")).toContainText(
        "Your home, one clear picture.",
    );
    await tab(page, "Activity");
    await expect(
        page.getByText("Attendance recorded", { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText("Payment recorded", { exact: true }),
    ).toHaveCount(2);
    await page.screenshot({
        path: "artifacts/activity-desktop.png",
        fullPage: true,
    });
});

test("founder assigns professional and member roles and revokes access", async ({
    page,
}) => {
    await signIn(page);
    const id = await project(page);
    await tab(page, "Team");
    for (const [email, role] of [
        ["professional@ghar.test", "professional"],
        ["member@ghar.test", "member"],
    ]) {
        await page
            .getByRole("button", { name: "Add or update member" })
            .click();
        await page.getByLabel("Account email", { exact: true }).fill(email!);
        await page
            .getByRole("combobox", { name: "Role", exact: true })
            .selectOption(role!);
        await page.getByRole("button", { name: "Save", exact: true }).click();
        await expect(page.getByRole("dialog")).not.toBeVisible();
        await expect(page.getByText(email!, { exact: true })).toBeVisible();
    }
    await page.getByRole("button", { name: "Sign out", exact: true }).click();
    await signIn(page, "professional@ghar.test");
    await tab(page, "Labor");
    await expect(
        page.getByRole("button", { name: "Add worker" }),
    ).toBeVisible();
    await tab(page, "Bills & payments");
    await expect(page.getByRole("button", { name: "Add bill" })).toHaveCount(0);
    expect(
        (await request(page, `/api/projects/${id}/bills`, "POST", {})).status,
    ).toBe(403);
    await page.getByRole("button", { name: "Sign out", exact: true }).click();
    await signIn(page, "member@ghar.test");
    await tab(page, "Labor");
    await expect(page.getByRole("button", { name: "Add worker" })).toHaveCount(
        0,
    );
    expect(
        (await request(page, `/api/projects/${id}/workers`, "POST", {})).status,
    ).toBe(403);
    await expect(
        page.getByText("Read-only access", { exact: false }),
    ).toBeVisible();
    await page.getByRole("button", { name: "Sign out", exact: true }).click();
    await signIn(page);
    await tab(page, "Team");
    const row = page
        .locator(".team-row")
        .filter({ hasText: "member@ghar.test" });
    await row.getByRole("button", { name: "Remove access" }).click();
    await row.getByRole("button", { name: "Confirm removal" }).click();
    await expect(row).toHaveCount(0);
    await page.getByRole("button", { name: "Sign out", exact: true }).click();
    await signIn(page, "member@ghar.test");
    expect((await request(page, `/api/projects/${id}`)).status).toBe(404);
});

test("payment retry after lost response does not create duplicate records", async ({
    page,
}) => {
    await signIn(page);
    const id = await project(page);
    await tab(page, "Bills & payments");
    await bill(page);
    let first = true;
    await page.route(`**/api/projects/${id}/payments`, async (route) => {
        if (first) {
            first = false;
            await route.fetch();
            await route.abort("failed");
        } else await route.continue();
    });
    await payment(page, "200.01");
    await expect(page.getByRole("alert")).toBeVisible();
    await expect(page.getByRole("dialog")).toBeVisible();
    await page.getByRole("button", { name: "Save", exact: true }).click();
    await expect(page.getByRole("dialog")).not.toBeVisible();
    const d = await request(page, `/api/projects/${id}`);
    expect(d.data.payments.length).toBe(1);
    expect(d.data.ledger.paid_paisa).toBe(20001);
    const a = await request(page, `/api/projects/${id}/audit`);
    expect(
        a.data.events.filter((e: any) => e.action === "payment.created").length,
    ).toBe(1);
});

test("new web screens fit narrow viewports in English and Nepali", async ({
    page,
}) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page);
    const id = await project(page);
    await request(page, `/api/projects/${id}/workers`, "POST", {
        name: "Very long fictional worker name for wrapping validation",
        trade: "Masonry and finishing",
        daily_rate: "1250.01",
    });
    await page.reload();
    await expect(page.locator("main h1")).toContainText(
        "Your home, one clear picture.",
    );
    const pageErrors: string[] = [];
    page.on("pageerror", (e) => pageErrors.push(e.message));
    for (const name of ["Labor", "Bills & payments", "Team", "Activity"]) {
        await tab(page, name);
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
        await page.screenshot({
            path: `artifacts/${name.replace(/[^a-z]/gi, "-").toLowerCase()}-mobile.png`,
            fullPage: true,
        });
    }
    await page.getByRole("button", { name: "नेपाली", exact: true }).click();
    await page.getByRole("button", { name: "श्रम", exact: true }).click();
    await expect(
        page.getByRole("heading", { name: "उपस्थिति विवरण" }),
    ).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    await page.screenshot({
        path: "artifacts/labor-nepali-mobile.png",
        fullPage: true,
    });
    expect(pageErrors).toEqual([]);
});

test("worker and ledger script payloads are escaped", async ({ page }) => {
    await signIn(page);
    const id = await project(page);
    const payload = '<img src=x onerror="window.__operationsXss=1">';
    expect(
        (
            await request(page, `/api/projects/${id}/workers`, "POST", {
                name: payload,
                trade: "Mason",
                daily_rate: "1",
            })
        ).status,
    ).toBe(201);
    expect(
        (
            await request(page, `/api/projects/${id}/bills`, "POST", {
                client_uuid: crypto.randomUUID(),
                reference: "XSS-001",
                payee: payload,
                category: "other",
                description: payload,
                amount: "1",
                issued_on: "2026-01-01",
            })
        ).status,
    ).toBe(201);
    await page.reload();
    await expect(page.locator("main h1")).toContainText(
        "Your home, one clear picture.",
    );
    await tab(page, "Labor");
    await expect(
        page.getByRole("heading", { name: payload, exact: true }),
    ).toBeVisible();
    await tab(page, "Bills & payments");
    await expect(
        page.getByText(payload, { exact: true }).first(),
    ).toBeVisible();
    await tab(page, "Activity");
    await expect(
        page.getByText(payload, { exact: true }).first(),
    ).toBeVisible();
    expect(
        await page.evaluate(() => (window as any).__operationsXss),
    ).toBeUndefined();
});

// Isolated local E2E fixture: clear counters between tests, retain real rate limits.
test.beforeEach(() => {
    execFileSync("php", ["artisan", "cache:clear"], { stdio: "ignore" });
});

test("session writes to new modules require CSRF protection", async ({
    page,
}) => {
    await signIn(page);
    const id = await project(page);
    for (const path of [
        "members",
        "workers",
        "attendance",
        "bills",
        "payments",
    ]) {
        const status = await page.evaluate(
            async ({ id, path }) => {
                const r = await fetch(`/api/projects/${id}/${path}`, {
                    method: "POST",
                    headers: {
                        Accept: "application/json",
                        "Content-Type": "application/json",
                    },
                    body: "{}",
                });
                return r.status;
            },
            { id, path },
        );
        expect(status).toBe(419);
    }
});
