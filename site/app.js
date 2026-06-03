const Bank = (() => {
	let currentUser = null;
	let customerTransactions = [];
	let bossTransactions = [];
	const transactionSort = {
		customer: { key: "datetime", direction: "desc" },
		boss: { key: "datetime", direction: "desc" },
	};

	function apiBase() {
		return document.body.dataset.apiBase || "backend";
	}

	function path(route) {
		return `${apiBase()}${route}`;
	}

	async function api(route, options = {}) {
		const response = await fetch(path(route), {
			credentials: "same-origin",
			headers: {
				Accept: "application/json",
				"Content-Type": "application/json",
				...(options.headers || {}),
			},
			...options,
		});
		const payload = await response.json().catch(() => ({
			success: false,
			result: "Ungueltige Serverantwort.",
		}));
		if (!response.ok || payload.success === false) {
			const error = new Error(payload.result || "Anfrage fehlgeschlagen.");
			error.code = payload.code || "";
			error.status = response.status;
			error.payload = payload;
			throw error;
		}
		return payload;
	}

	function qs(selector, root = document) {
		return root.querySelector(selector);
	}

	function qsa(selector, root = document) {
		return Array.from(root.querySelectorAll(selector));
	}

	function setText(selector, value) {
		const element = qs(selector);
		if (element) {
			element.textContent = value;
		}
	}

	function show(selector) {
		const element = qs(selector);
		if (element) {
			element.classList.remove("is-hidden");
		}
	}

	function hide(selector) {
		const element = qs(selector);
		if (element) {
			element.classList.add("is-hidden");
		}
	}

	function icon(name, extra = "") {
		return `<i class="bi bi-${name}${extra ? ` ${extra}` : ""}" aria-hidden="true"></i>`;
	}

	function money(value) {
		const number = Number(value || 0);
		return new Intl.NumberFormat("de-CH", {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2,
		}).format(Math.abs(number));
	}

	function dateTime(value) {
		if (!value) {
			return "";
		}
		return value.substring(0, value.lastIndexOf(":"));
	}

	function toast(message, level = "info") {
		const existing = qs("#app-toast");
		if (existing) {
			existing.remove();
		}
		const tone = {
			success: "alert-success",
			danger: "alert-danger",
			warning: "alert-warning",
			info: "alert-primary",
			primary: "alert-primary",
		}[level] || "alert-primary";
		const element = document.createElement("div");
		element.id = "app-toast";
		element.className = `status-toast alert ${tone} shadow-sm mb-0`;
		element.setAttribute("role", "status");
		element.innerHTML = `<div class="d-flex align-items-center gap-2">${icon("info-circle")}<span>${escapeHtml(message)}</span></div>`;
		document.body.appendChild(element);
		window.setTimeout(() => element.remove(), 3200);
	}

	function escapeHtml(value) {
		return String(value ?? "")
			.replaceAll("&", "&amp;")
			.replaceAll("<", "&lt;")
			.replaceAll(">", "&gt;")
			.replaceAll('"', "&quot;")
			.replaceAll("'", "&#039;");
	}

	function formData(form) {
		return Object.fromEntries(new FormData(form).entries());
	}

	function sortValue(item, key) {
		if (key === "amount" || key === "balance" || key === "id") {
			return Number(item[key] || 0);
		}
		if (key === "type") {
			return Number(item.amount || 0) < 0 ? 0 : 1;
		}
		return String(item[key] || "").toLocaleLowerCase("de-CH");
	}

	function sortedTransactions(items, table) {
		const sort = transactionSort[table];
		const direction = sort.direction === "asc" ? 1 : -1;
		return items
			.map((item, index) => ({ item, index }))
			.sort((left, right) => {
				const leftValue = sortValue(left.item, sort.key);
				const rightValue = sortValue(right.item, sort.key);
				let result = 0;
				if (typeof leftValue === "number" && typeof rightValue === "number") {
					result = leftValue - rightValue;
				} else {
					result = String(leftValue).localeCompare(String(rightValue), "de-CH", {
						numeric: true,
						sensitivity: "base",
					});
				}
				return result === 0 ? left.index - right.index : result * direction;
			})
			.map(({ item }) => item);
	}

	function initSortControls(table, render) {
		qsa(`[data-sort-table="${table}"]`).forEach((button) => {
			button.addEventListener("click", () => {
				const key = button.dataset.sortKey;
				if (transactionSort[table].key === key) {
					transactionSort[table].direction = transactionSort[table].direction === "asc" ? "desc" : "asc";
				} else {
					transactionSort[table] = { key, direction: "asc" };
				}
				render();
			});
		});
		updateSortButtons(table);
	}

	function updateSortButtons(table) {
		const sort = transactionSort[table];
		qsa(`[data-sort-table="${table}"]`).forEach((button) => {
			const active = button.dataset.sortKey === sort.key;
			button.classList.toggle("is-active", active);
			button.setAttribute("aria-pressed", active ? "true" : "false");
			button.innerHTML = icon(active ? (sort.direction === "asc" ? "sort-up" : "sort-down") : "arrow-down-up");
		});
	}

	async function me() {
		const payload = await api("/auth/me");
		currentUser = payload.user || null;
		return currentUser;
	}

	async function login(credentials) {
		const payload = await api("/auth/login", {
			method: "POST",
			body: JSON.stringify(credentials),
		});
		currentUser = payload.user || null;
		return payload;
	}

	async function logout(redirectTo = "/") {
		await api("/auth/logout", { method: "POST" });
		currentUser = null;
		window.location.href = redirectTo;
	}

	function bootstrapModal(selector) {
		const element = qs(selector);
		if (!element || !window.bootstrap) {
			return null;
		}
		return bootstrap.Modal.getOrCreateInstance(element);
	}

	function loadScript(src) {
		return new Promise((resolve, reject) => {
			if (document.querySelector(`script[src="${src}"]`)) {
				resolve();
				return;
			}
			const script = document.createElement("script");
			script.src = src;
			script.async = true;
			script.defer = true;
			script.onload = resolve;
			script.onerror = reject;
			document.head.appendChild(script);
		});
	}

	async function setupGoogleLogin(containerId, onSuccess, onError) {
		const container = qs(`#${containerId}`);
		if (!container) {
			return;
		}
		const config = await api("/auth/config");
		if (!config.google_client_id) {
			return;
		}
		await loadScript("https://accounts.google.com/gsi/client");
		window.google.accounts.id.initialize({
			client_id: config.google_client_id,
			callback: async (response) => {
				try {
					const payload = await api("/auth/google", {
						method: "POST",
						body: JSON.stringify({ credential: response.credential }),
					});
					await onSuccess(payload.user);
				} catch (error) {
					onError(error);
				}
			},
		});
		container.classList.remove("is-hidden");
		window.google.accounts.id.renderButton(container, {
			theme: "outline",
			size: "large",
			width: 280,
		});
	}

	function initIndex() {
		const form = qs("#login-form");
		const submit = qs("#login-submit");
		const status = qs("#login-status");

		me().then((user) => {
			if (user) {
				setText("#signed-in-name", user.fullname);
				show("#signed-in-actions");
			}
		}).catch(() => {});

		form?.addEventListener("submit", async (event) => {
			event.preventDefault();
			status.textContent = "";
			submit.disabled = true;
			try {
				const data = formData(form);
				const payload = await login({
					username: data.username,
					userpassword: data.userpassword,
				});
				if (payload.result !== true) {
					status.textContent = "Benutzername oder Passwort stimmt nicht.";
					return;
				}
				window.location.href = "customer/";
			} catch (error) {
				status.textContent = error.message;
			} finally {
				submit.disabled = false;
			}
		});

		setupGoogleLogin("google-login", async () => {
			window.location.href = "customer/";
		}, (error) => {
			status.textContent = error.message;
		});
	}

	async function initCustomer() {
		try {
			const user = await me();
			if (!user) {
				show("#signed-out");
				return;
			}
			currentUser = user;
			setText("#customer-name", user.fullname);
			show("#customer-app-nav");
			show("#customer-app");
			initSortControls("customer", renderCustomerTransactions);
			await Promise.all([loadCustomerKpis(), loadCustomerTransactions()]);
		} catch (error) {
			show("#signed-out");
		}

		qs("#logout-button")?.addEventListener("click", () => logout("../"));
		qs("#twint-amount")?.addEventListener("input", updateTwintLink);
		qs("#twint-link")?.addEventListener("click", () => bootstrapModal("#twint-modal")?.hide());
	}

	async function loadCustomerKpis() {
		const payload = await api("/customers/me/kpis");
		const kpis = payload.result;
		setText("#kpi-balance", money(kpis.balance));
		setText("#kpi-pigs", kpis.nofpigs);
		setText("#kpi-ins", kpis.nofins);
		setText("#kpi-outs", kpis.nofouts);
		qs("#kpi-balance-sign").innerHTML = Number(kpis.balance) < 0 ? icon("dash-lg") : icon("plus-lg");
		const progress = qs("#savings-progress");
		if (progress) {
			const balance = Math.max(0, Number(kpis.balance) || 0);
			progress.style.setProperty("--progress", `${Math.min(100, balance % 100)}%`);
		}
	}

	async function loadCustomerTransactions() {
		const payload = await api("/customers/me/transactions");
		customerTransactions = payload.result;
		renderCustomerTransactions();
	}

	function renderCustomerTransactions() {
		const body = qs("#transactions-body");
		body.innerHTML = "";
		updateSortButtons("customer");
		if (!customerTransactions.length) {
			body.innerHTML = `<tr><td colspan="4" class="text-center text-secondary py-4">Keine Transaktionen</td></tr>`;
			return;
		}
		for (const item of sortedTransactions(customerTransactions, "customer")) {
			const amount = Number(item.amount);
			const row = document.createElement("tr");
			row.innerHTML = `
				<td>${escapeHtml(dateTime(item.datetime))}</td>
				<td>${amount < 0 ? icon("dash-lg", "text-danger") : icon("plus-lg", "text-success")}</td>
				<td>${money(amount)}</td>
				<th scope="row">${money(item.balance)}</th>
			`;
			body.appendChild(row);
		}
	}

	function updateTwintLink() {
		const amount = qs("#twint-amount")?.value;
		const link = qs("#twint-link");
		if (!link) {
			return;
		}
		link.href = amount ? `https://wa.me/41792567909/?text=CHF${encodeURIComponent(amount)}` : "#";
		link.classList.toggle("disabled", !amount);
	}

	async function initBoss() {
		const loginPanel = qs("#boss-login");
		const appPanel = qs("#boss-app");
		const loginForm = qs("#boss-login-form");
		const loginStatus = qs("#boss-login-status");

		loginForm?.addEventListener("submit", async (event) => {
			event.preventDefault();
			loginStatus.textContent = "";
			try {
				const data = formData(loginForm);
				const payload = await login({
					username: data.username,
					userpassword: data.userpassword,
					boss: true,
				});
				if (payload.result !== true || !payload.user || Number(payload.user.boss) < 1) {
					loginStatus.textContent = "Keine Boss-Berechtigung.";
					return;
				}
				await renderBoss(payload.user);
			} catch (error) {
				loginStatus.textContent = error.message;
			}
		});

		setupGoogleLogin("google-login", async (user) => {
			if (!user || Number(user.boss) < 1) {
				await api("/auth/logout", { method: "POST" });
				loginStatus.textContent = "Keine Boss-Berechtigung.";
				return;
			}
			await renderBoss(user);
		}, (error) => {
			loginStatus.textContent = error.message;
		});

		try {
			const user = await me();
			if (user && Number(user.boss) >= 1) {
				await renderBoss(user);
			} else {
				loginPanel.classList.remove("is-hidden");
			}
		} catch (error) {
			loginPanel.classList.remove("is-hidden");
		}

		qs("#boss-logout-button")?.addEventListener("click", () => logout("../"));
		qs("#deposit-form")?.addEventListener("submit", (event) => submitMovement(event, 1));
		qs("#payout-form")?.addEventListener("submit", (event) => submitMovement(event, -1));
		qs("#create-customer-form")?.addEventListener("submit", submitCreateCustomer);
	}

	async function renderBoss(user) {
		currentUser = user;
		hide("#boss-login");
		show("#boss-app-nav");
		show("#boss-app");
		setText("#boss-name", user.fullname);
		initSortControls("boss", renderBossTransactions);
		await Promise.all([loadBossCustomers(), loadBossTransactions()]);
	}

	async function loadBossCustomers() {
		const payload = await api("/customers");
		for (const selector of ["#deposit-customer", "#payout-customer"]) {
			const select = qs(selector);
			select.innerHTML = `<option selected disabled value="">Kunde waehlen</option>`;
			for (const customer of payload.result) {
				const option = document.createElement("option");
				option.value = customer.id;
				option.textContent = customer.fullname;
				select.appendChild(option);
			}
		}
	}

	async function loadBossTransactions() {
		const payload = await api("/transactions");
		bossTransactions = payload.result;
		renderBossTransactions();
	}

	function renderBossTransactions() {
		const body = qs("#boss-transactions-body");
		body.innerHTML = "";
		updateSortButtons("boss");
		if (!bossTransactions.length) {
			body.innerHTML = `<tr><td colspan="6" class="text-center text-secondary py-4">Keine Transaktionen</td></tr>`;
			return;
		}
		for (const item of sortedTransactions(bossTransactions, "boss")) {
			const amount = Number(item.amount);
			const row = document.createElement("tr");
			row.innerHTML = `
				<td>${escapeHtml(item.fullname)}</td>
				<td>${escapeHtml(dateTime(item.datetime))}</td>
				<td>${amount < 0 ? icon("dash-lg", "text-danger") : icon("plus-lg", "text-success")}</td>
				<td>${money(amount)}</td>
				<th scope="row">${money(item.balance)}</th>
				<td><button type="button" class="btn btn-sm btn-outline-danger icon-button" data-delete-transaction="${item.id}" title="Loeschen">${icon("trash")}</button></td>
			`;
			body.appendChild(row);
		}
		qsa("[data-delete-transaction]").forEach((button) => {
			button.addEventListener("click", () => deleteTransaction(button.dataset.deleteTransaction));
		});
	}

	async function submitMovement(event, direction) {
		event.preventDefault();
		const form = event.currentTarget;
		const data = formData(form);
		const customer = data.customer;
		const value = Number(data.value || 0);
		if (!customer || value <= 0) {
			toast("Kunde und Betrag ausfuellen.", "warning");
			return;
		}
		await api(`/customers/${customer}/cashin`, {
			method: "POST",
			body: JSON.stringify({ value: value * direction }),
		});
		form.reset();
		toast(direction > 0 ? "Einzahlung gespeichert." : "Auszahlung gespeichert.", "success");
		await loadBossTransactions();
	}

	async function submitCreateCustomer(event) {
		event.preventDefault();
		const form = event.currentTarget;
		const data = formData(form);
		if (!data.fullname || !data.username || !data.userpassword) {
			toast("Name, Benutzername und Passwort ausfuellen.", "warning");
			return;
		}
		await api("/customers", {
			method: "POST",
			body: JSON.stringify({
				fullname: data.fullname,
				username: data.username,
				userpassword: data.userpassword,
				email: data.email || "",
			}),
		});
		form.reset();
		toast("Kunde erstellt.", "success");
		await loadBossCustomers();
	}

	async function deleteTransaction(id) {
		if (!window.confirm("Transaktion loeschen?")) {
			return;
		}
		await api(`/transactions/${id}`, { method: "DELETE" });
		toast("Transaktion geloescht.", "success");
		await loadBossTransactions();
	}

	function init() {
		const page = document.body.dataset.page;
		if (page === "home") {
			initIndex();
		}
		if (page === "customer") {
			initCustomer();
		}
		if (page === "boss") {
			initBoss();
		}
	}

	return { init };
})();

document.addEventListener("DOMContentLoaded", Bank.init);
