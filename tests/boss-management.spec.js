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
$realm = random_int(990000, 999999);
$hash = password_hash($password, PASSWORD_DEFAULT);
$customerUsername = 'mgmt_customer_' . $stamp;
$bossUsername = 'mgmt_boss_' . $stamp;
dbExecute('INSERT INTO customers (fullname, username, userpassword, boss, realm) VALUES (:fullname, :username, :userpassword, 0, :realm)', array('fullname' => 'Management Test Customer', 'username' => $customerUsername, 'userpassword' => $hash, 'realm' => $realm));
$customerId = (int) getDB()->lastInsertId();
dbExecute('INSERT INTO transactions (customer, amount, balance, approved, undone) VALUES (:customer, 50.00, 50.00, 1, 0)', array('customer' => $customerId));
dbExecute('INSERT INTO reward_events (customer, reward_key, reward_type, chest_variant, title, description, amount, balance_before, balance_after) VALUES (:customer, :reward_key, :reward_type, :chest_variant, :title, :description, 5.00, 50.00, 55.00)', array('customer' => $customerId, 'reward_key' => 'deposit', 'reward_type' => 'money', 'chest_variant' => 'gold', 'title' => 'Test Reward', 'description' => 'Test'));
dbExecute('INSERT INTO customers (fullname, username, userpassword, boss, realm) VALUES (:fullname, :username, :userpassword, 1, :realm)', array('fullname' => 'Management Test Boss', 'username' => $bossUsername, 'userpassword' => $hash, 'realm' => $realm));
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

async function loginBoss(request) {
	await request.post("/backend/auth/login", {
		data: {
			username: fixture.bossUsername,
			userpassword: fixture.password,
			boss: true,
		},
	});
}

async function currentManagedCustomer(request) {
	const overviewResponse = await request.get("/backend/boss/overview");
	const overview = await overviewResponse.json();
	expect(overview.success).toBe(true);

	return overview.result.customers.find((customer) => Number(customer.id) === Number(fixture.customerId));
}

test("returns boss overview metrics and reward summary", async ({ request }) => {
	await loginBoss(request);

	const overviewResponse = await request.get("/backend/boss/overview");
	const overview = await overviewResponse.json();
	expect(overview.success).toBe(true);
	expect(overview.result.metrics.active_customers).toBe(1);
	expect(Number(overview.result.metrics.total_assets)).toBe(50);
	expect(overview.result.metrics.unopened_rewards).toBe(1);
	expect(Number(overview.result.customers[0].balance)).toBe(50);

	const rewardsResponse = await request.get("/backend/boss/rewards");
	const rewards = await rewardsResponse.json();
	expect(rewards.success).toBe(true);
	expect(Number(rewards.result.unopened[0].unopened_rewards)).toBe(1);
	expect(rewards.result.config.length).toBeGreaterThan(0);
});

test("updates customer identity, password, and google email", async ({ request }) => {
	await loginBoss(request);
	const newPassword = "CodexChanged123!";
	const updatedUsername = `${fixture.customerUsername}_changed`;
	const updatedEmail = `management-edited-${fixture.customerId}@example.com`;

	const updateResponse = await request.put(`/backend/customers/${fixture.customerId}`, {
		data: {
			fullname: "Management Edited Customer",
			username: updatedUsername,
			email: updatedEmail,
			display_name: "Edited",
			userpassword: newPassword,
		},
	});
	const update = await updateResponse.json();
	expect(update.success).toBe(true);
	expect(update.result.email).toBe(updatedEmail);

	await request.post("/backend/auth/logout");
	const loginResponse = await request.post("/backend/auth/login", {
		data: {
			username: updatedUsername,
			userpassword: newPassword,
		},
	});
	const login = await loginResponse.json();
	expect(login.result).toBe(true);

	fixture.customerUsername = updatedUsername;
	fixture.passwordForCustomer = newPassword;
});

test("requires exact confirmation for soft delete and supports restore", async ({ request }) => {
	await loginBoss(request);
	const customer = await currentManagedCustomer(request);
	expect(customer).toBeTruthy();

	const badDeleteResponse = await request.post(`/backend/customers/${fixture.customerId}/archive`, {
		data: { confirm: "DELETE" },
	});
	const badDelete = await badDeleteResponse.json();
	expect(badDelete.success).toBe(false);

	const deleteResponse = await request.post(`/backend/customers/${fixture.customerId}/archive`, {
		data: { confirm: customer.fullname },
	});
	const deleted = await deleteResponse.json();
	expect(deleted.success).toBe(true);
	expect(deleted.result).toBe(true);

	const archived = await currentManagedCustomer(request);
	expect(archived.deleted_at).toBeTruthy();

	const restoreResponse = await request.post(`/backend/customers/${fixture.customerId}/restore`);
	const restored = await restoreResponse.json();
	expect(restored.result).toBe(true);

	const restoredCustomer = await currentManagedCustomer(request);
	expect(restoredCustomer.deleted_at).toBeNull();
});

test("updates reward configuration", async ({ request }) => {
	await loginBoss(request);

	const updateResponse = await request.put("/backend/boss/rewards/config", {
		data: {
			monthly_interest_rate: 0.0008,
			savings_milestone_step: 100,
		},
	});
	const update = await updateResponse.json();
	expect(update.success).toBe(true);

	const config = Object.fromEntries(update.result.map((item) => [item.config_key, item.config_value]));
	expect(config.monthly_interest_rate).toBe("0.0008");
});
