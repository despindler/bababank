const { test, expect } = require("@playwright/test");
const { execFileSync } = require("node:child_process");
const path = require("node:path");

const projectRoot = path.resolve(__dirname, "..");
let fixture;

test.describe.configure({ mode: "serial" });

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

async function loginCustomer(page, username) {
	await page.goto("/");
	await page.getByLabel("Benutzername").fill(username);
	await page.getByLabel("Passwort").fill(fixture.password);
	await page.getByRole("button", { name: "Anmelden" }).click();
	await expect(page).toHaveURL(/\/customer\/$/);
	await expect(page.getByRole("heading", { name: "Uebersicht" })).toBeVisible();
}

function projectionPayload(overrides = {}) {
	const projection = {
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
		...(overrides.monthly_interest || {}),
	};
	return {
		success: true,
		result: {
			balance: 199.6,
			nofpigs: 1,
			nofins: 1,
			nofouts: 0,
			...overrides,
			monthly_interest: projection,
		},
	};
}

async function useKpiPayload(page, payload) {
	await page.route("**/backend/customers/me/kpis", async (route) => {
		await route.fulfill({ json: payload });
	});
	await page.reload();
	await expect(page.locator("#monthly-interest-card")).not.toHaveAttribute("data-state", "loading");
}

async function settleVisuals(page) {
	await page.evaluate(async () => {
		if (document.fonts && document.fonts.ready) {
			await document.fonts.ready;
		}
	});
}

test.beforeAll(({}, testInfo) => {
	const baseYear = testInfo.project.name === "mobile-chrome" ? 2031 : 2030;
	const code = `
require_once getcwd() . '/site/backend/database.php';
require_once getcwd() . '/site/backend/monthly_interest_processor.php';
$stamp = date('YmdHis') . '_' . random_int(1000, 9999);
$password = 'CodexTest123!';
$hash = password_hash($password, PASSWORD_DEFAULT);
$realm = random_int(990000, 999999);
$baseYear = ${baseYear};
$periods = array(
	$baseYear . '-01-01' => '0.01',
	$baseYear . '-02-01' => '0.02',
	$baseYear . '-03-01' => '0.005',
	$baseYear . '-04-01' => '0',
);
foreach ($periods as $period => $rate) {
	dbScheduleMonthlyInterestRate(substr($period, 0, 7), $rate);
}

$projectionUsername = 'visual_interest_' . $stamp;
dbExecute('INSERT INTO customers (fullname, username, userpassword, boss, realm) VALUES (:fullname, :username, :password, 0, :realm)', array('fullname' => 'Visual Interest Customer', 'username' => $projectionUsername, 'password' => $hash, 'realm' => $realm));
$projectionId = (int) getDB()->lastInsertId();
dbExecute('INSERT INTO transactions (customer, datetime, amount, balance, approved, undone) VALUES (:customer, "2026-08-10 10:00:00", 199.60, 199.60, 1, 0)', array('customer' => $projectionId));
dbOpenInterestEligibility($projectionId, '2026-08');

$catchupUsername = 'catchup_interest_' . $stamp;
dbExecute('INSERT INTO customers (fullname, username, userpassword, boss, realm) VALUES (:fullname, :username, :password, 0, :realm)', array('fullname' => 'Catch-up Interest Customer', 'username' => $catchupUsername, 'password' => $hash, 'realm' => $realm));
$catchupId = (int) getDB()->lastInsertId();
dbExecute('INSERT INTO transactions (customer, datetime, amount, balance, approved, undone) VALUES (:customer, :datetime, 100.00, 100.00, 1, 0)', array('customer' => $catchupId, 'datetime' => $baseYear . '-01-10 10:00:00'));
dbOpenInterestEligibility($catchupId, $baseYear . '-01');

$disabledUsername = 'disabled_interest_' . $stamp;
dbExecute('INSERT INTO customers (fullname, username, userpassword, boss, realm) VALUES (:fullname, :username, :password, 0, :realm)', array('fullname' => 'Disabled Interest Customer', 'username' => $disabledUsername, 'password' => $hash, 'realm' => $realm));
$disabledId = (int) getDB()->lastInsertId();
dbExecute('INSERT INTO transactions (customer, datetime, amount, balance, approved, undone) VALUES (:customer, :datetime, 100.00, 100.00, 1, 0)', array('customer' => $disabledId, 'datetime' => $baseYear . '-04-10 10:00:00'));
dbOpenInterestEligibility($disabledId, $baseYear . '-04');

$processor = new MonthlyInterestProcessor(getDB());
$asOf = new DateTimeImmutable($baseYear . '-04-15 12:00:00', new DateTimeZone('Europe/Zurich'));
$catchup = $processor->processCustomer($catchupId, $asOf);
$repeat = $processor->processCustomer($catchupId, $asOf);
$disabled = $processor->processCustomer(
	$disabledId,
	new DateTimeImmutable($baseYear . '-05-15 12:00:00', new DateTimeZone('Europe/Zurich'))
);
$postingRows = dbFetchAll(
	'SELECT p.period_start, p.interest_rate, p.balance_basis, p.amount, t.datetime, t.balance
	FROM monthly_interest_postings p
	LEFT JOIN transactions t ON t.id = p.transaction_id
	WHERE p.customer = :customer
	ORDER BY p.period_start',
	array('customer' => $catchupId)
);
$disabledRows = dbFetchAll(
	'SELECT period_start, amount, transaction_id, reward_event_id
	FROM monthly_interest_postings
	WHERE customer = :customer',
	array('customer' => $disabledId)
);
echo json_encode(array(
	'baseYear' => $baseYear,
	'password' => $password,
	'projectionUsername' => $projectionUsername,
	'projectionId' => $projectionId,
	'catchupUsername' => $catchupUsername,
	'catchupId' => $catchupId,
	'disabledUsername' => $disabledUsername,
	'disabledId' => $disabledId,
	'ratePeriods' => array_keys($periods),
	'catchup' => $catchup,
	'repeat' => $repeat,
	'disabled' => $disabled,
	'postingRows' => $postingRows,
	'disabledRows' => $disabledRows,
));
`;
	fixture = JSON.parse(runPhp(code));
});

test.afterAll(() => {
	if (!fixture) {
		return;
	}
	const ids = [fixture.projectionId, fixture.catchupId, fixture.disabledId].join(",");
	const periods = fixture.ratePeriods.map((period) => `'${period}'`).join(",");
	runPhp(`
require_once getcwd() . '/site/backend/database.php';
dbExecute('DELETE FROM customers WHERE id IN (${ids})');
dbExecute("DELETE FROM monthly_interest_rates WHERE effective_period IN (${periods})");
`);
});

test("automatic catch-up uses historical rates, exact dates, and idempotent retries @smoke", async () => {
	expect(fixture.catchup.settlements.map((row) => row.period)).toEqual([
		`${fixture.baseYear}-01`,
		`${fixture.baseYear}-02`,
		`${fixture.baseYear}-03`,
	]);
	expect(fixture.postingRows).toEqual([
		{
			period_start: `${fixture.baseYear}-01-01`,
			interest_rate: "0.01000000",
			balance_basis: "100.00",
			amount: "1.00",
			datetime: `${fixture.baseYear}-01-31 23:00:00`,
			balance: "101.00",
		},
		{
			period_start: `${fixture.baseYear}-02-01`,
			interest_rate: "0.02000000",
			balance_basis: "101.00",
			amount: "2.02",
			datetime: `${fixture.baseYear}-02-28 23:00:00`,
			balance: "103.02",
		},
		{
			period_start: `${fixture.baseYear}-03-01`,
			interest_rate: "0.00500000",
			balance_basis: "103.02",
			amount: "0.52",
			datetime: `${fixture.baseYear}-03-31 22:00:00`,
			balance: "103.54",
		},
	]);
	expect(fixture.repeat.settlements).toEqual([]);
	expect(fixture.disabledRows).toEqual([{
		period_start: `${fixture.baseYear}-04-01`,
		amount: "0.00",
		transaction_id: null,
		reward_event_id: null,
	}]);
	expect(fixture.disabled.settlements).toHaveLength(1);
	expect(fixture.disabled.settlements[0].amount).toBe("0.00");
});

test("authenticated API exposes three separate chronological monthly chests @smoke", async ({ request }) => {
	await request.post("/backend/auth/login", {
		data: {
			username: fixture.catchupUsername,
			userpassword: fixture.password,
		},
	});
	const rewardResponse = await request.get("/backend/customers/me/rewards/daily");
	const rewardPayload = await rewardResponse.json();
	const kpiResponse = await request.get("/backend/customers/me/kpis");
	const kpiPayload = await kpiResponse.json();

	expect(rewardPayload.result.map((reward) => reward.reward_key)).toEqual([
		"monthly_interest",
		"monthly_interest",
		"monthly_interest",
	]);
	expect(rewardPayload.result.map((reward) => reward.trigger_value)).toEqual([
		`${fixture.baseYear}-01`,
		`${fixture.baseYear}-02`,
		`${fixture.baseYear}-03`,
	]);
	expect(rewardPayload.result.map((reward) => reward.title)).toEqual([
		`Monatszins Januar ${fixture.baseYear}`,
		`Monatszins Februar ${fixture.baseYear}`,
		`Monatszins März ${fixture.baseYear}`,
	]);
	expect(rewardPayload.result.map((reward) => reward.chest_variant)).toEqual(["gold", "gold", "gold"]);
	expect(kpiPayload.result.balance).toBe(103.54);
});

test("positive monthly-interest projection visual @visual", async ({ page }) => {
	await loginCustomer(page, fixture.projectionUsername);
	await settleVisuals(page);

	const card = page.locator("#monthly-interest-card");
	await expect(card).toHaveAttribute("data-state", "active");
	await expect(card).toHaveScreenshot("positive-interest-card.png");
});

test("zero monthly-interest projection visual @visual", async ({ page }) => {
	await loginCustomer(page, fixture.projectionUsername);
	await useKpiPayload(page, projectionPayload({
		balance: 0,
		nofpigs: 0,
		monthly_interest: {
			balance_basis_estimate: "0.00",
			estimated_amount: "0.00",
		},
	}));
	await settleVisuals(page);

	const card = page.locator("#monthly-interest-card");
	await expect(card).toHaveAttribute("data-state", "zero");
	await expect(card).toHaveScreenshot("zero-interest-card.png");
});

test("disabled monthly-interest projection visual @visual", async ({ page }) => {
	await loginCustomer(page, fixture.projectionUsername);
	await useKpiPayload(page, projectionPayload({
		monthly_interest: {
			enabled: false,
			status: "disabled",
			rate: "0",
			rate_percent: "0",
			estimated_amount: "0.00",
		},
	}));
	await settleVisuals(page);

	const card = page.locator("#monthly-interest-card");
	await expect(card).toHaveAttribute("data-state", "disabled");
	await expect(card).toHaveScreenshot("disabled-interest-card.png");
});

test("one tap builds pressure and opens one chest exactly once @smoke", async ({ page }) => {
	let openCalls = 0;
	await page.route("**/backend/customers/me/rewards/*/open", async (route) => {
		openCalls += 1;
		await route.fulfill({ json: { success: true, result: true } });
	});
	await loginCustomer(page, fixture.catchupUsername);

	const stage = page.locator(".reward-stage");
	const button = page.locator("#reward-chest-button");
	const image = page.locator("#reward-chest-image");
	await button.click();

	await expect(stage).toHaveClass(/is-charging/);
	await expect(stage).toHaveAttribute("aria-busy", "true");
	await expect(image).toHaveAttribute("src", "../assets/rewards/chest-closed.png");
	await expect(page.locator("#reward-description")).toHaveText("Gleich platzt die Kiste auf ...");
	await expect(button).toHaveCSS("animation-name", "chestPressure");
	await page.evaluate(() => document.querySelector("#reward-chest-button").click());

	await expect(stage).toHaveClass(/is-bursting/);
	await expect(button).toHaveCSS("animation-name", "chestBurst");
	await expect(page.locator(".reward-sparkles span").first()).toHaveCSS("animation-name", "sparkleBurst");
	await expect(image).toHaveAttribute("src", "../assets/rewards/chest-open-gold.png");
	await expect(page.locator("#reward-amount")).toHaveClass(/is-revealed/);
	await expect(stage).toHaveClass(/is-open/);
	await expect(stage).not.toHaveAttribute("aria-busy");
	await expect(page.locator("#reward-next-button")).toBeVisible();
	await expect.poll(() => openCalls).toBe(1);
});

test("reduced motion opens immediately without pressure or particles @smoke", async ({ page }) => {
	await page.emulateMedia({ reducedMotion: "reduce" });
	let openCalls = 0;
	await page.route("**/backend/customers/me/rewards/daily", async (route) => {
		await route.fulfill({
			json: {
				success: true,
				result: [{
					id: 999001,
					reward_key: "savings_milestone",
					chest_variant: "crystals",
					title: "Kristall-Belohnung",
					description: "Kristalle erhalten.",
					amount: "2.00",
					balance_before: "100.00",
					balance_after: "102.00",
				}],
			},
		});
	});
	await page.route("**/backend/customers/me/rewards/*/open", async (route) => {
		openCalls += 1;
		await route.fulfill({ json: { success: true, result: true } });
	});
	await loginCustomer(page, fixture.catchupUsername);

	const stage = page.locator(".reward-stage");
	await page.locator("#reward-chest-button").click();

	await expect(page.locator("#reward-chest-image")).toHaveAttribute("src", "../assets/rewards/chest-open-crystals.png");
	await expect(stage).toHaveAttribute("data-variant", "crystals");
	await expect(stage).toHaveClass(/is-open/);
	await expect(stage).not.toHaveClass(/is-charging|is-bursting/);
	await expect(page.locator(".reward-sparkles")).toHaveCSS("display", "none");
	await expect(page.locator("#reward-next-button")).toBeVisible();
	await expect.poll(() => openCalls).toBe(1);
});

test("monthly catch-up chest visual @visual", async ({ page }) => {
	await page.route("**/backend/customers/me/rewards/*/open", async (route) => {
		await route.fulfill({ json: { success: true, result: true } });
	});
	await loginCustomer(page, fixture.catchupUsername);
	await expect(page.locator("#reward-modal")).toHaveClass(/show/);
	await expect(page.locator("#reward-chest-image")).toHaveJSProperty("complete", true);
	await page.locator("#reward-chest-button").click();
	await expect(page.locator("#reward-chest-image")).toHaveAttribute("src", "../assets/rewards/chest-open-gold.png");
	await expect(page.locator("#reward-chest-image")).toHaveJSProperty("complete", true);
	await expect(page.locator(".reward-stage")).toHaveClass(/is-open/);
	await expect(page.locator(".reward-stage")).not.toHaveClass(/is-bursting/);
	await settleVisuals(page);

	await expect(page.locator("#reward-modal .modal-content")).toHaveScreenshot("monthly-interest-chest.png");
});

test("three caught-up chests open independently and refresh the account @smoke", async ({ page }) => {
	await loginCustomer(page, fixture.catchupUsername);
	const expected = [
		{ month: "Januar", amount: "1.00" },
		{ month: "Februar", amount: "2.02" },
		{ month: "März", amount: "0.52" },
	];

	for (let index = 0; index < expected.length; index += 1) {
		await expect(page.locator("#reward-step")).toHaveText(`Monatszins · ${index + 1} / 3`);
		await expect(page.locator("#reward-title")).toHaveText(`Monatszins ${expected[index].month} ${fixture.baseYear}`);
		await page.locator("#reward-chest-button").click();
		await expect(page.locator("#reward-chest-image")).toHaveAttribute("src", "../assets/rewards/chest-open-gold.png");
		await expect(page.locator("#reward-amount")).toHaveText(`+ ${expected[index].amount}`);
		await page.locator("#reward-next-button").click();
	}

	await expect(page.locator("#reward-modal")).not.toHaveClass(/show/);
	await expect(page.locator("#kpi-balance")).toHaveText("103.54");
	await page.locator("#customer-transactions-heading .accordion-button").click();
	await expect(page.locator("#transactions-body tr")).toHaveCount(4);
	await expect(page.locator("#transactions-body tr").first().locator("td").nth(2)).toHaveText("0.52");
	await expect(page.locator("#transactions-body tr").first().getByRole("rowheader")).toHaveText("103.54");

	const dailyResponse = await page.request.get("/backend/customers/me/rewards/daily");
	const dailyPayload = await dailyResponse.json();
	expect(dailyPayload.result).toEqual([]);
});
