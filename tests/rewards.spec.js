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
$customerUsername = 'reward_customer_' . $stamp;
$monthlyUsername = 'reward_monthly_' . $stamp;
$bossUsername = 'reward_boss_' . $stamp;

dbExecute('INSERT INTO customers (fullname, username, userpassword, boss, realm) VALUES (:fullname, :username, :userpassword, 0, :realm)', array('fullname' => 'Reward Test Customer', 'username' => $customerUsername, 'userpassword' => $hash, 'realm' => $realm));
$customerId = (int) getDB()->lastInsertId();
dbExecute('INSERT INTO transactions (customer, amount, balance, approved, undone) VALUES (:customer, -5.00, -5.00, 1, 0)', array('customer' => $customerId));
dbExecute('INSERT INTO customer_reward_state (customer, state_key, state_value) VALUES (:customer, :state_key, :state_value)', array('customer' => $customerId, 'state_key' => 'savings_level', 'state_value' => '0'));
dbExecute('INSERT INTO customer_reward_state (customer, state_key, state_value) VALUES (:customer, :state_key, :state_value)', array('customer' => $customerId, 'state_key' => 'input_lead_active', 'state_value' => '0'));

dbExecute('INSERT INTO customers (fullname, username, userpassword, boss, realm) VALUES (:fullname, :username, :userpassword, 0, :realm)', array('fullname' => 'Monthly Reward Customer', 'username' => $monthlyUsername, 'userpassword' => $hash, 'realm' => $realm));
$monthlyCustomerId = (int) getDB()->lastInsertId();
dbExecute('INSERT INTO transactions (customer, amount, balance, approved, undone) VALUES (:customer, 100.00, 100.00, 1, 0)', array('customer' => $monthlyCustomerId));
dbExecute('INSERT INTO customer_reward_state (customer, state_key, state_value) VALUES (:customer, :state_key, :state_value)', array('customer' => $monthlyCustomerId, 'state_key' => 'savings_level', 'state_value' => '1'));
dbExecute('INSERT INTO customer_reward_state (customer, state_key, state_value) VALUES (:customer, :state_key, :state_value)', array('customer' => $monthlyCustomerId, 'state_key' => 'input_lead_active', 'state_value' => '1'));

dbExecute('INSERT INTO customers (fullname, username, userpassword, boss, realm) VALUES (:fullname, :username, :userpassword, 1, :realm)', array('fullname' => 'Reward Test Boss', 'username' => $bossUsername, 'userpassword' => $hash, 'realm' => $realm));
$bossId = (int) getDB()->lastInsertId();

echo json_encode(array('customerUsername' => $customerUsername, 'monthlyUsername' => $monthlyUsername, 'bossUsername' => $bossUsername, 'password' => $password, 'customerId' => $customerId, 'monthlyCustomerId' => $monthlyCustomerId, 'bossId' => $bossId));
`;
	fixture = JSON.parse(runPhp(code));
});

test.afterAll(() => {
	if (!fixture) {
		return;
	}
	const code = `
require_once getcwd() . '/site/backend/database.php';
dbExecute('DELETE FROM reward_events WHERE customer IN (:customer, :monthly, :boss)', array('customer' => ${fixture.customerId}, 'monthly' => ${fixture.monthlyCustomerId}, 'boss' => ${fixture.bossId}));
dbExecute('DELETE FROM customer_reward_state WHERE customer IN (:customer, :monthly, :boss)', array('customer' => ${fixture.customerId}, 'monthly' => ${fixture.monthlyCustomerId}, 'boss' => ${fixture.bossId}));
dbExecute('DELETE FROM transactions WHERE customer IN (:customer, :monthly, :boss)', array('customer' => ${fixture.customerId}, 'monthly' => ${fixture.monthlyCustomerId}, 'boss' => ${fixture.bossId}));
dbExecute('DELETE FROM leases WHERE customer IN (:customer, :monthly, :boss)', array('customer' => ${fixture.customerId}, 'monthly' => ${fixture.monthlyCustomerId}, 'boss' => ${fixture.bossId}));
dbExecute('DELETE FROM customers WHERE id IN (:customer, :monthly, :boss)', array('customer' => ${fixture.customerId}, 'monthly' => ${fixture.monthlyCustomerId}, 'boss' => ${fixture.bossId}));
`;
	runPhp(code);
});

test("creates deposit and achievement reward events from bottom-up movements", async ({ request }) => {
	await request.post("/backend/auth/login", {
		data: {
			username: fixture.bossUsername,
			userpassword: fixture.password,
			boss: true,
		},
	});

	await request.post(`/backend/customers/${fixture.customerId}/cashin`, { data: { value: 105 } });
	await request.post(`/backend/customers/${fixture.customerId}/cashin`, { data: { value: 1 } });
	await request.post("/backend/auth/logout");

	await request.post("/backend/auth/login", {
		data: {
			username: fixture.customerUsername,
			userpassword: fixture.password,
		},
	});
	const response = await request.get("/backend/customers/me/rewards/daily");
	const payload = await response.json();

	expect(payload.success).toBe(true);
	expect(payload.result.map((reward) => reward.reward_key)).toEqual([
		"deposit",
		"savings_milestone",
		"deposit",
		"input_lead",
	]);
	expect(payload.result.map((reward) => reward.chest_variant)).toEqual([
		"gold",
		"crystals",
		"gold",
		"crystals",
	]);
	expect(Number(payload.result[1].amount)).toBe(0.08);
	expect(Number(payload.result[3].amount)).toBe(0.08);

	const openResponse = await request.post(`/backend/customers/me/rewards/${payload.result[0].id}/open`);
	const openPayload = await openResponse.json();
	expect(openPayload.result).toBe(true);

	const secondDailyResponse = await request.get("/backend/customers/me/rewards/daily");
	const secondDailyPayload = await secondDailyResponse.json();
	expect(secondDailyPayload.result.map((reward) => reward.reward_key)).toEqual([
		"savings_milestone",
		"deposit",
		"input_lead",
	]);
});

test("customer reads do not trigger monthly interest posting", async ({ request }) => {
	await request.post("/backend/auth/login", {
		data: {
			username: fixture.monthlyUsername,
			userpassword: fixture.password,
		},
	});

	const kpiResponse = await request.get("/backend/customers/me/kpis");
	const kpiPayload = await kpiResponse.json();
	expect(kpiPayload.success).toBe(true);
	expect(kpiPayload.result.balance).toBe(100);

	const dailyResponse = await request.get("/backend/customers/me/rewards/daily");
	const dailyPayload = await dailyResponse.json();
	expect(dailyPayload.result).toHaveLength(0);

	const secondKpiResponse = await request.get("/backend/customers/me/kpis");
	const secondKpiPayload = await secondKpiResponse.json();
	expect(secondKpiPayload.result.balance).toBe(100);
});

test("does not suppress rewards created after an earlier same-day empty check", async ({ request }) => {
	const code = `
require_once getcwd() . '/site/backend/database.php';
$stamp = date('YmdHis') . '_' . random_int(1000, 9999);
$password = 'CodexTest123!';
$realm = random_int(990000, 999999);
$hash = password_hash($password, PASSWORD_DEFAULT);
$customerUsername = 'late_reward_customer_' . $stamp;
$bossUsername = 'late_reward_boss_' . $stamp;
dbExecute('INSERT INTO customers (fullname, username, userpassword, boss, realm) VALUES (:fullname, :username, :userpassword, 0, :realm)', array('fullname' => 'Late Reward Customer', 'username' => $customerUsername, 'userpassword' => $hash, 'realm' => $realm));
$customerId = (int) getDB()->lastInsertId();
dbExecute('INSERT INTO customer_reward_state (customer, state_key, state_value) VALUES (:customer, :state_key, :state_value)', array('customer' => $customerId, 'state_key' => 'savings_level', 'state_value' => '0'));
dbExecute('INSERT INTO customer_reward_state (customer, state_key, state_value) VALUES (:customer, :state_key, :state_value)', array('customer' => $customerId, 'state_key' => 'input_lead_active', 'state_value' => '0'));
dbExecute('INSERT INTO customer_reward_state (customer, state_key, state_value) VALUES (:customer, :state_key, :state_value)', array('customer' => $customerId, 'state_key' => 'last_daily_chest_date', 'state_value' => date('Y-m-d')));
dbExecute('INSERT INTO customers (fullname, username, userpassword, boss, realm) VALUES (:fullname, :username, :userpassword, 1, :realm)', array('fullname' => 'Late Reward Boss', 'username' => $bossUsername, 'userpassword' => $hash, 'realm' => $realm));
$bossId = (int) getDB()->lastInsertId();
echo json_encode(array('customerUsername' => $customerUsername, 'bossUsername' => $bossUsername, 'password' => $password, 'customerId' => $customerId, 'bossId' => $bossId));
`;
	const lateFixture = JSON.parse(runPhp(code));

	try {
		await request.post("/backend/auth/login", {
			data: {
				username: lateFixture.bossUsername,
				userpassword: lateFixture.password,
				boss: true,
			},
		});
		await request.post(`/backend/customers/${lateFixture.customerId}/cashin`, { data: { value: 20 } });
		await request.post("/backend/auth/logout");

		await request.post("/backend/auth/login", {
			data: {
				username: lateFixture.customerUsername,
				userpassword: lateFixture.password,
			},
		});
		const response = await request.get("/backend/customers/me/rewards/daily");
		const payload = await response.json();

		expect(payload.success).toBe(true);
		expect(payload.result.map((reward) => reward.reward_key)).toEqual([
			"deposit",
			"input_lead",
		]);
	} finally {
		const cleanup = `
require_once getcwd() . '/site/backend/database.php';
dbExecute('DELETE FROM reward_events WHERE customer IN (:customer, :boss)', array('customer' => ${lateFixture.customerId}, 'boss' => ${lateFixture.bossId}));
dbExecute('DELETE FROM customer_reward_state WHERE customer IN (:customer, :boss)', array('customer' => ${lateFixture.customerId}, 'boss' => ${lateFixture.bossId}));
dbExecute('DELETE FROM transactions WHERE customer IN (:customer, :boss)', array('customer' => ${lateFixture.customerId}, 'boss' => ${lateFixture.bossId}));
dbExecute('DELETE FROM leases WHERE customer IN (:customer, :boss)', array('customer' => ${lateFixture.customerId}, 'boss' => ${lateFixture.bossId}));
dbExecute('DELETE FROM customers WHERE id IN (:customer, :boss)', array('customer' => ${lateFixture.customerId}, 'boss' => ${lateFixture.bossId}));
`;
		runPhp(cleanup);
	}
});
