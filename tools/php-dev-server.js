const { spawn } = require("node:child_process");

const host = process.env.PLAYWRIGHT_HOST || "127.0.0.1";
const port = process.env.PLAYWRIGHT_PORT || "8787";
const envFile = process.env.BABABANK_ENV_FILE || ".env.test";

const child = spawn(
	"php",
	["-S", `${host}:${port}`, "-t", "site", "site/router.php"],
	{
		env: {
			...process.env,
			BABABANK_ENV_FILE: envFile,
			MONTHLY_INTEREST_TEST_NOW:
				process.env.MONTHLY_INTEREST_TEST_NOW || "2026-07-24T12:00:00+02:00",
		},
		stdio: "inherit",
	}
);

function stop(signal) {
	if (!child.killed) {
		child.kill(signal);
	}
}

process.on("SIGINT", () => stop("SIGINT"));
process.on("SIGTERM", () => stop("SIGTERM"));

child.on("exit", (code) => {
	process.exit(code ?? 0);
});
