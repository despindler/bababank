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
dbExecute('INSERT INTO transactions (customer, amount, balance, approved, undone) VALUES (:customer, 125.50, 125.50, 1, 0)', array('customer' => $customerId));
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
dbExecute('DELETE FROM transactions WHERE customer IN (SELECT id FROM customers WHERE realm = :realm AND username LIKE :prefix)', array('realm' => ${fixture.realm}, 'prefix' => 'pw_%'));
dbExecute('DELETE FROM leases WHERE customer IN (SELECT id FROM customers WHERE realm = :realm AND username LIKE :prefix)', array('realm' => ${fixture.realm}, 'prefix' => 'pw_%'));
dbExecute('DELETE FROM customers WHERE realm = :realm AND username LIKE :prefix', array('realm' => ${fixture.realm}, 'prefix' => 'pw_%'));
`;
	runPhp(code);
});

test("renders the public login page", async ({ page }) => {
	await page.goto("/");
	await expect(page.getByRole("heading", { name: "Ba Ba Bank" })).toBeVisible();
	await expect(page.getByRole("button", { name: "Anmelden" })).toBeVisible();
	await page.screenshot({ path: "test-results/screenshots/home.png", fullPage: true });
});

test("renders the signed-out customer state", async ({ page }) => {
	await page.goto("/customer/");
	await expect(page.getByRole("heading", { name: "Nicht angemeldet" })).toBeVisible();
	await expect(page.getByRole("link", { name: "Anmelden" })).toBeVisible();
	await page.screenshot({ path: "test-results/screenshots/customer-signed-out.png", fullPage: true });
});

test("logs in and renders the customer dashboard", async ({ page }) => {
	await page.goto("/");
	await page.getByLabel("Benutzername").fill(fixture.customerUsername);
	await page.getByLabel("Passwort").fill(fixture.password);
	await page.getByRole("button", { name: "Anmelden" }).click();

	await expect(page).toHaveURL(/\/customer\/$/);
	await expect(page.getByRole("heading", { name: "Uebersicht" })).toBeVisible();
	await expect(page.getByText("Playwright Test Customer")).toBeVisible();
	await expect(page.locator("#kpi-balance")).toHaveText("125.50");
	await expect(page.getByRole("heading", { name: "Transaktionen" })).toBeVisible();
	await page.screenshot({ path: "test-results/screenshots/customer-dashboard.png", fullPage: true });
});

test("logs in and renders the boss dashboard", async ({ page }) => {
	await page.goto("/boss/");
	await page.locator("#boss-username").fill(fixture.bossUsername);
	await page.locator("#boss-password").fill(fixture.password);
	await page.getByRole("button", { name: "Anmelden" }).click();

	await expect(page.getByRole("heading", { name: "Konten verwalten" })).toBeVisible();
	await expect(page.getByText("Playwright Test Boss")).toBeVisible();
	await expect(page.getByRole("heading", { name: "Einzahlen" })).toBeVisible();
	await expect(page.getByRole("heading", { name: "Auszahlen" })).toBeVisible();
	await expect(page.getByRole("heading", { name: "Kunde" })).toBeVisible();
	await page.screenshot({ path: "test-results/screenshots/boss-dashboard.png", fullPage: true });
});
