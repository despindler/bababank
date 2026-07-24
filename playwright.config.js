const { defineConfig, devices } = require("@playwright/test");

const baseURL = process.env.PLAYWRIGHT_BASE_URL || "http://127.0.0.1:8787";

module.exports = defineConfig({
	testDir: "./tests",
	timeout: 30000,
	expect: {
		timeout: 5000,
		toHaveScreenshot: {
			animations: "disabled",
			caret: "hide",
			maxDiffPixelRatio: 0.005,
		},
	},
	use: {
		baseURL,
		trace: "retain-on-failure",
		screenshot: "only-on-failure",
	},
	projects: [
		{
			name: "chromium",
			use: { ...devices["Desktop Chrome"] },
		},
		{
			name: "mobile-chrome",
			use: { ...devices["Pixel 5"] },
		},
	],
	webServer: {
		command: "node tools/php-dev-server.js",
		url: `${baseURL}/backend/auth/config`,
		reuseExistingServer: true,
		timeout: 15000,
	},
	reporter: [["list"], ["html", { open: "never" }]],
});
