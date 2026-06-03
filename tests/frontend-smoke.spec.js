const { test, expect } = require("@playwright/test");
const { execFileSync } = require("node:child_process");
const path = require("node:path");

const projectRoot = path.resolve(__dirname, "..");
let fixture;

function screenshotPath(testInfo, name) {
	return `test-results/screenshots/${testInfo.project.name}-${name}.png`;
}

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

async function columnTexts(page, selector) {
	return page.locator(selector).evaluateAll((elements) => elements.map((element) => element.textContent.trim()));
}

function sortedText(values, direction = "asc") {
	const sorted = [...values].sort((left, right) => left.localeCompare(right, "de-CH", {
		numeric: true,
		sensitivity: "base",
	}));
	return direction === "asc" ? sorted : sorted.reverse();
}

test.beforeAll(() => {
	const code = `
require_once getcwd() . '/site/backend/database.php';
$stamp = date('YmdHis') . '_' . random_int(1000, 9999);
$password = 'CodexTest123!';
$realm = 990002;
$customerUsername = 'pw_customer_' . $stamp;
$bossUsername = 'pw_boss_' . $stamp;
$hash = password_hash($password, PASSWORD_DEFAULT);
dbExecute('INSERT INTO customers (fullname, username, userpassword, boss, realm) VALUES (:fullname, :username, :userpassword, 0, :realm)', array('fullname' => 'Playwright Test Customer', 'username' => $customerUsername, 'userpassword' => $hash, 'realm' => $realm));
$customerId = (int) getDB()->lastInsertId();
dbExecute("INSERT INTO transactions (customer, datetime, amount, balance, approved, undone) VALUES (:customer, '2026-01-01 10:00:00', 125.50, 125.50, 1, 0)", array('customer' => $customerId));
dbExecute("INSERT INTO transactions (customer, datetime, amount, balance, approved, undone) VALUES (:customer, '2026-01-01 11:00:00', -20.00, 105.50, 1, 0)", array('customer' => $customerId));
dbExecute("INSERT INTO transactions (customer, datetime, amount, balance, approved, undone) VALUES (:customer, '2026-01-01 12:00:00', 20.00, 125.50, 1, 0)", array('customer' => $customerId));
dbExecute('INSERT INTO customer_reward_state (customer, state_key, state_value) VALUES (:customer, :state_key, :state_value)', array('customer' => $customerId, 'state_key' => 'monthly_interest_period', 'state_value' => date('Y-m')));
dbExecute('INSERT INTO customer_reward_state (customer, state_key, state_value) VALUES (:customer, :state_key, :state_value)', array('customer' => $customerId, 'state_key' => 'savings_level', 'state_value' => '1'));
dbExecute('INSERT INTO customer_reward_state (customer, state_key, state_value) VALUES (:customer, :state_key, :state_value)', array('customer' => $customerId, 'state_key' => 'input_lead_active', 'state_value' => '1'));
dbExecute('INSERT INTO customers (fullname, username, userpassword, boss, realm) VALUES (:fullname, :username, :userpassword, 1, :realm)', array('fullname' => 'Playwright Test Boss', 'username' => $bossUsername, 'userpassword' => $hash, 'realm' => $realm));
$bossId = (int) getDB()->lastInsertId();
echo json_encode(array('customerUsername' => $customerUsername, 'bossUsername' => $bossUsername, 'password' => $password, 'realm' => $realm, 'customerId' => $customerId, 'bossId' => $bossId));
`;
	fixture = JSON.parse(runPhp(code));
});

test.afterAll(() => {
	if (!fixture) {
		return;
	}
	const code = `
require_once getcwd() . '/site/backend/database.php';
dbExecute('DELETE FROM reward_events WHERE customer IN (:customer, :boss)', array('customer' => ${fixture.customerId}, 'boss' => ${fixture.bossId}));
dbExecute('DELETE FROM customer_reward_state WHERE customer IN (:customer, :boss)', array('customer' => ${fixture.customerId}, 'boss' => ${fixture.bossId}));
dbExecute('DELETE FROM transactions WHERE customer IN (:customer, :boss)', array('customer' => ${fixture.customerId}, 'boss' => ${fixture.bossId}));
dbExecute('DELETE FROM leases WHERE customer IN (:customer, :boss)', array('customer' => ${fixture.customerId}, 'boss' => ${fixture.bossId}));
dbExecute('DELETE FROM customers WHERE id IN (:customer, :boss)', array('customer' => ${fixture.customerId}, 'boss' => ${fixture.bossId}));
`;
	runPhp(code);
});

test("renders the public login page", async ({ page }, testInfo) => {
	await page.goto("/");
	await expect(page.getByRole("heading", { name: "Ba Ba Bank" })).toBeVisible();
	await expect(page.getByRole("button", { name: "Anmelden" })).toBeVisible();
	await page.screenshot({ path: screenshotPath(testInfo, "home"), fullPage: true });
});

test("renders the signed-out customer state", async ({ page }, testInfo) => {
	await page.goto("/customer/");
	await expect(page.getByRole("heading", { name: "Nicht angemeldet" })).toBeVisible();
	await expect(page.getByRole("link", { name: "Anmelden" })).toBeVisible();
	await page.screenshot({ path: screenshotPath(testInfo, "customer-signed-out"), fullPage: true });
});

test("logs in and renders the customer dashboard", async ({ page }, testInfo) => {
	await page.goto("/");
	await page.getByLabel("Benutzername").fill(fixture.customerUsername);
	await page.getByLabel("Passwort").fill(fixture.password);
	await page.getByRole("button", { name: "Anmelden" }).click();

	await expect(page).toHaveURL(/\/customer\/$/);
	await expect(page.getByRole("heading", { name: "Uebersicht" })).toBeVisible();
	await expect(page.locator("#customer-name")).toHaveText("Playwright Test Customer");
	await expect(page.locator("#kpi-balance")).toHaveText("125.50");
	await expect(page.getByRole("heading", { name: "Transaktionen" })).toBeVisible();
	await expect(page.locator("#customer-transactions-panel")).toBeHidden();
	await expect(page.locator("#transactions-body tr")).toHaveCount(3);
	await page.locator("#customer-transactions-heading .accordion-button").click();
	await expect(page.locator("#customer-transactions-panel")).toBeVisible();
	await expect(page.locator("#transactions-body tr").first()).toBeVisible();
	await page.waitForTimeout(350);
	await page.screenshot({ path: screenshotPath(testInfo, "customer-dashboard"), fullPage: true });
});

test("sorts the customer transaction table by header icons", async ({ page }) => {
	await page.goto("/");
	await page.getByLabel("Benutzername").fill(fixture.customerUsername);
	await page.getByLabel("Passwort").fill(fixture.password);
	await page.getByRole("button", { name: "Anmelden" }).click();

	await expect(page).toHaveURL(/\/customer\/$/);
	await expect(page.locator('[data-sort-table="customer"]')).toHaveCount(4);
	await page.locator("#customer-transactions-heading .accordion-button").click();
	await expect(page.locator("#customer-transactions-panel")).toBeVisible();

	await page.getByLabel("Nach Wann sortieren").click();
	await expect(page.locator("#transactions-body tr").first().locator("td").first()).toHaveText("2026-01-01 10:00");
	await page.getByLabel("Nach Wann sortieren").click();
	await expect(page.locator("#transactions-body tr").first().locator("td").first()).toHaveText("2026-01-01 12:00");

	await page.getByLabel("Nach Betrag sortieren").click();
	await expect(page.locator("#transactions-body tr").first().locator(".bi-dash-lg")).toBeVisible();
	await page.getByLabel("Nach Betrag sortieren").click();
	await expect(page.locator("#transactions-body tr").first().locator("td").nth(2)).toHaveText("125.50");
});

test("logs in and renders the boss dashboard", async ({ page }, testInfo) => {
	await page.goto("/boss/");
	await page.locator("#boss-username").fill(fixture.bossUsername);
	await page.locator("#boss-password").fill(fixture.password);
	await page.getByRole("button", { name: "Anmelden" }).click();

	await expect(page.getByRole("heading", { name: "Konten verwalten" })).toBeVisible();
	await expect(page.locator("#boss-name")).toHaveText("Playwright Test Boss");
	await expect(page.getByRole("heading", { name: "Einzahlen" })).toBeVisible();
	await expect(page.getByRole("heading", { name: "Auszahlen" })).toBeVisible();
	await expect(page.getByRole("heading", { name: "Kunde", exact: true })).toBeVisible();
	await expect(page.locator("#boss-transactions-panel")).toBeHidden();
	await page.locator("#boss-transactions-heading .accordion-button").click();
	await expect(page.locator("#boss-transactions-panel")).toBeVisible();
	await expect(page.locator("#boss-transactions-body tr").first()).toBeVisible();
	await page.waitForTimeout(350);
	await page.screenshot({ path: screenshotPath(testInfo, "boss-dashboard"), fullPage: true });
});

test("sorts the boss transaction table by header icons", async ({ page }) => {
	await page.goto("/boss/");
	await page.locator("#boss-username").fill(fixture.bossUsername);
	await page.locator("#boss-password").fill(fixture.password);
	await page.getByRole("button", { name: "Anmelden" }).click();

	await expect(page.getByRole("heading", { name: "Konten verwalten" })).toBeVisible();
	await expect(page.locator('[data-sort-table="boss"]')).toHaveCount(6);
	await page.locator("#boss-transactions-heading .accordion-button").click();
	await expect(page.locator("#boss-transactions-panel")).toBeVisible();

	await page.getByLabel("Nach Kunde sortieren").click();
	const ascending = await columnTexts(page, "#boss-transactions-body tr td:first-child");
	expect(ascending).toEqual(sortedText(ascending, "asc"));

	await page.getByLabel("Nach Kunde sortieren").click();
	const descending = await columnTexts(page, "#boss-transactions-body tr td:first-child");
	expect(descending).toEqual(sortedText(descending, "desc"));
});
