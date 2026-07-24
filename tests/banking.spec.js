const { test, expect } = require("@playwright/test");
const { execFileSync } = require("node:child_process");
const path = require("node:path");

const projectRoot = path.resolve(__dirname, "..");
let fixture;

function runPhp(code) {
	return execFileSync("php", ["-r", code], {
		cwd: projectRoot,
		encoding: "utf8",
		env: {
			...process.env,
			BABABANK_ENV_FILE: process.env.BABABANK_ENV_FILE || ".env.test",
		},
	});
}

async function loginCustomer(page) {
	await page.goto("/");
	await page.getByLabel("Benutzername").fill(fixture.customerUsername);
	await page.getByLabel("Passwort").fill(fixture.password);
	await page.getByRole("button", { name: "Anmelden" }).click();
	await expect(page).toHaveURL(/\/customer\/$/);
	await expect(page.getByRole("heading", { name: "Uebersicht" })).toBeVisible();
}

test.beforeAll(() => {
	const code = `
require_once getcwd() . '/site/backend/database.php';
$stamp = date('YmdHis') . '_' . random_int(1000, 9999);
$password = 'CodexTest123!';
$realm = random_int(990000, 999999);
$customerUsername = 'banking_customer_' . $stamp;
$hash = password_hash($password, PASSWORD_DEFAULT);
dbExecute('INSERT INTO customers (fullname, username, userpassword, boss, realm) VALUES (:fullname, :username, :userpassword, 0, :realm)', array('fullname' => 'Banking Test Customer', 'username' => $customerUsername, 'userpassword' => $hash, 'realm' => $realm));
$customerId = (int) getDB()->lastInsertId();
dbExecute('INSERT INTO transactions (customer, amount, balance, approved, undone) VALUES (:customer, 150.00, 150.00, 1, 0)', array('customer' => $customerId));
dbExecute('INSERT INTO transactions (customer, amount, balance, approved, undone) VALUES (:customer, 75.00, 225.00, 1, 0)', array('customer' => $customerId));
dbExecute('INSERT INTO transactions (customer, amount, balance, approved, undone) VALUES (:customer, -25.40, 199.60, 1, 0)', array('customer' => $customerId));
dbExecute('INSERT INTO transactions (customer, amount, balance, approved, undone) VALUES (:customer, 100.00, 299.60, 0, 0)', array('customer' => $customerId));
dbExecute('INSERT INTO transactions (customer, amount, balance, approved, undone) VALUES (:customer, -100.00, 99.60, 1, 1)', array('customer' => $customerId));
dbExecute('INSERT INTO customer_reward_state (customer, state_key, state_value) VALUES (:customer, :state_key, :state_value)', array('customer' => $customerId, 'state_key' => 'savings_level', 'state_value' => '1'));
dbExecute('INSERT INTO customer_reward_state (customer, state_key, state_value) VALUES (:customer, :state_key, :state_value)', array('customer' => $customerId, 'state_key' => 'input_lead_active', 'state_value' => '1'));
dbOpenInterestEligibility($customerId, '2026-08');
echo json_encode(array('customerUsername' => $customerUsername, 'password' => $password, 'realm' => $realm, 'customerId' => $customerId));
`;
	fixture = JSON.parse(runPhp(code));
});

test.afterAll(() => {
	if (!fixture) {
		return;
	}
	const code = `
require_once getcwd() . '/site/backend/database.php';
dbExecute('DELETE FROM reward_events WHERE customer = :customer', array('customer' => ${fixture.customerId}));
dbExecute('DELETE FROM customer_reward_state WHERE customer = :customer', array('customer' => ${fixture.customerId}));
dbExecute('DELETE FROM transactions WHERE customer = :customer', array('customer' => ${fixture.customerId}));
dbExecute('DELETE FROM leases WHERE customer = :customer', array('customer' => ${fixture.customerId}));
dbExecute('DELETE FROM customers WHERE id = :customer', array('customer' => ${fixture.customerId}));
`;
	runPhp(code);
});

test("computes balance from approved non-undone transactions", async ({ request }) => {
	await request.post("/backend/auth/login", {
		data: {
			username: fixture.customerUsername,
			userpassword: fixture.password,
		},
	});
	const response = await request.get("/backend/customers/me/kpis");
	const payload = await response.json();

	expect(payload.success).toBe(true);
	expect(payload.result.balance).toBe(199.6);
});

test("counts inbound and outbound approved non-undone transactions", async ({ request }) => {
	await request.post("/backend/auth/login", {
		data: {
			username: fixture.customerUsername,
			userpassword: fixture.password,
		},
	});
	const response = await request.get("/backend/customers/me/kpis");
	const payload = await response.json();

	expect(payload.success).toBe(true);
	expect(payload.result.nofins).toBe(2);
	expect(payload.result.nofouts).toBe(1);
});

test("returns the server-calculated August monthly interest projection", async ({ request }) => {
	await request.post("/backend/auth/login", {
		data: {
			username: fixture.customerUsername,
			userpassword: fixture.password,
		},
	});
	const response = await request.get("/backend/customers/me/kpis");
	const payload = await response.json();

	expect(payload.result.monthly_interest).toEqual({
		enabled: true,
		status: "active",
		period: "2026-08",
		balance_basis_estimate: "199.60",
		rate: "0.0008",
		rate_percent: "0.08",
		estimated_amount: "0.16",
		posting_date: "2026-09-01",
		timezone: "Europe/Zurich",
		is_estimate: true,
	});
});

test("prevents a customer from reading another customer projection", async ({ request }) => {
	await request.post("/backend/auth/login", {
		data: {
			username: fixture.customerUsername,
			userpassword: fixture.password,
		},
	});
	const response = await request.get(`/backend/customers/${fixture.customerId + 1000000}/kpis`);
	const payload = await response.json();

	expect(response.status()).toBe(403);
	expect(payload.code).toBe("CUSTOMER_MISMATCH");
});

test("shows the balance in the Vermoegen card", async ({ page }) => {
	await loginCustomer(page);

	await expect(page.locator("#kpi-balance")).toHaveText("199.60");
});

test("shows the estimated interest rate date and assumption", async ({ page }) => {
	await loginCustomer(page);

	const card = page.locator("#monthly-interest-card");
	await expect(card).toHaveAttribute("data-state", "active");
	await expect(card.getByText("Voraussichtlicher Monatszins")).toBeVisible();
	await expect(page.locator("#monthly-interest-amount")).toHaveText("0.16");
	await expect(page.locator("#monthly-interest-rate")).toHaveText("0.08 % pro Monat");
	await expect(page.locator("#monthly-interest-posting")).toHaveText("Voraussichtliche Gutschrift am 1. September 2026.");
	await expect(page.locator("#monthly-interest-explanation")).toContainText("bis zum Monatsende unverändert bleibt");
});

for (const state of [
	{ name: "zero", balance: "0.00" },
	{ name: "negative", balance: "-25.00" },
]) {
	test(`renders the ${state.name} balance interest state`, async ({ page }) => {
		await loginCustomer(page);
		await page.route("**/backend/customers/me/kpis", async (route) => {
			await route.fulfill({
				json: {
					success: true,
					result: {
						balance: Number(state.balance),
						nofpigs: 0,
						nofins: 0,
						nofouts: state.name === "negative" ? 1 : 0,
						monthly_interest: {
							enabled: true,
							status: "active",
							period: "2026-08",
							balance_basis_estimate: state.balance,
							rate: "0.0008",
							rate_percent: "0.08",
							estimated_amount: "0.00",
							posting_date: "2026-09-01",
							timezone: "Europe/Zurich",
							is_estimate: true,
						},
					},
				},
			});
		});
		await page.reload();

		await expect(page.locator("#monthly-interest-card")).toHaveAttribute("data-state", "zero");
		await expect(page.locator("#monthly-interest-amount")).toHaveText("0.00");
		await expect(page.locator("#monthly-interest-explanation")).toContainText("null oder darunter");
	});
}

test("renders the disabled interest state", async ({ page }) => {
	await loginCustomer(page);
	await page.route("**/backend/customers/me/kpis", async (route) => {
		await route.fulfill({
			json: {
				success: true,
				result: {
					balance: 199.6,
					nofpigs: 1,
					nofins: 2,
					nofouts: 1,
					monthly_interest: {
						enabled: false,
						status: "disabled",
						period: "2026-08",
						balance_basis_estimate: "199.60",
						rate: "0",
						rate_percent: "0",
						estimated_amount: "0.00",
						posting_date: "2026-09-01",
						timezone: "Europe/Zurich",
						is_estimate: true,
					},
				},
			},
		});
	});
	await page.reload();

	await expect(page.locator("#monthly-interest-card")).toHaveAttribute("data-state", "disabled");
	await expect(page.locator("#monthly-interest-amount")).toHaveText("–");
	await expect(page.locator("#monthly-interest-rate")).toHaveText("Monatszins deaktiviert");
});

test("shows the same latest saldo in the transaction table as the balance card", async ({ page }) => {
	await loginCustomer(page);

	await expect(page.locator("#kpi-balance")).toHaveText("199.60");
	await page.locator("#customer-transactions-heading .accordion-button").click();
	await expect(page.locator("#customer-transactions-panel")).toBeVisible();
	await expect(page.locator("#transactions-body tr").first().getByRole("rowheader")).toHaveText("199.60");
});

test("shows savings count and progress toward the next 100", async ({ page }) => {
	await loginCustomer(page);

	await expect(page.locator("#kpi-pigs")).toHaveText("1");
	const progress = await page.locator("#savings-progress").evaluate((element) => element.style.getPropertyValue("--progress"));
	expect(progress).toBe("99.6%");
});

test("shows inbound and outbound counts in the Ein/Aus card", async ({ page }) => {
	await loginCustomer(page);

	await expect(page.locator("#kpi-ins")).toHaveText("2");
	await expect(page.locator("#kpi-outs")).toHaveText("1");
});
