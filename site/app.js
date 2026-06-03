const Bank = (() => {
	let currentUser = null;
	let customerTransactions = [];
	let bossTransactions = [];
	let bossOverviewData = null;
	let bossRewardsData = null;
	let pendingDeleteUser = null;
	let rewardQueue = [];
	let rewardIndex = 0;
	let rewardOpened = false;
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

	function signedMoney(value) {
		const number = Number(value || 0);
		return `${number < 0 ? "-" : ""}${money(number)}`;
	}

	function rewardImage(variant, opened = false) {
		if (!opened) {
			return "../assets/rewards/chest-closed.png";
		}
		return variant === "crystals" ? "../assets/rewards/chest-open-crystals.png" : "../assets/rewards/chest-open-gold.png";
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
			initRewardModal();
			await loadCustomerKpis();
			await loadCustomerTransactions();
			await loadDailyRewards();
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

	async function loadDailyRewards() {
		const payload = await api("/customers/me/rewards/daily");
		if (Array.isArray(payload.result) && payload.result.length) {
			showRewardQueue(payload.result);
		}
	}

	function initRewardModal() {
		qs("#reward-chest-button")?.addEventListener("click", openCurrentReward);
		qs("#reward-next-button")?.addEventListener("click", nextReward);
	}

	function showRewardQueue(events) {
		rewardQueue = events;
		rewardIndex = 0;
		renderCurrentReward();
		bootstrapModal("#reward-modal")?.show();
	}

	function currentReward() {
		return rewardQueue[rewardIndex] || null;
	}

	function renderCurrentReward() {
		const reward = currentReward();
		if (!reward) {
			return;
		}
		rewardOpened = false;
		setText("#reward-step", `${rewardIndex + 1} / ${rewardQueue.length}`);
		setText("#reward-title", reward.title || "Pocket Bonus");
		setText("#reward-description", "Tippe auf die Kiste.");
		setText("#reward-amount", "");
		const image = qs("#reward-chest-image");
		const button = qs("#reward-chest-button");
		qs(".reward-stage")?.classList.remove("is-open");
		if (image) {
			image.src = rewardImage(reward.chest_variant, false);
		}
		if (button) {
			button.disabled = false;
			button.classList.remove("is-open");
		}
		hide("#reward-next-button");
	}

	async function openCurrentReward() {
		const reward = currentReward();
		if (!reward || rewardOpened) {
			return;
		}
		rewardOpened = true;
		const button = qs("#reward-chest-button");
		const image = qs("#reward-chest-image");
		if (button) {
			button.disabled = true;
			button.classList.add("is-open");
		}
		qs(".reward-stage")?.classList.add("is-open");
		if (image) {
			image.src = rewardImage(reward.chest_variant, true);
		}

		setText("#reward-description", reward.description || "Belohnung erhalten.");
		setText("#reward-amount", `+ ${money(reward.amount)}`);
		animateBalance(Number(reward.balance_before), Number(reward.balance_after));

		try {
			await api(`/customers/me/rewards/${reward.id}/open`, { method: "POST" });
		} catch (error) {
			toast(error.message, "danger");
		}
		show("#reward-next-button");
	}

	async function nextReward() {
		rewardIndex += 1;
		if (rewardIndex < rewardQueue.length) {
			renderCurrentReward();
			return;
		}
		bootstrapModal("#reward-modal")?.hide();
		rewardQueue = [];
		await Promise.all([loadCustomerKpis(), loadCustomerTransactions()]);
	}

	function animateBalance(from, to) {
		const element = qs("#kpi-balance");
		if (!element || !Number.isFinite(from) || !Number.isFinite(to)) {
			return;
		}
		const start = performance.now();
		const duration = 950;
		const easeOut = (value) => 1 - Math.pow(1 - value, 3);

		function frame(now) {
			const progress = Math.min(1, (now - start) / duration);
			const value = from + (to - from) * easeOut(progress);
			element.textContent = money(value);
			qs("#kpi-balance-sign").innerHTML = value < 0 ? icon("dash-lg") : icon("plus-lg");
			if (progress < 1) {
				window.requestAnimationFrame(frame);
				return;
			}
			element.textContent = money(to);
		}

		window.requestAnimationFrame(frame);
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
		qs("#delete-user-form")?.addEventListener("submit", submitDeleteUser);
		qsa("[data-boss-view]").forEach((button) => {
			button.addEventListener("click", () => showBossView(button.dataset.bossView));
		});
	}

	async function renderBoss(user) {
		currentUser = user;
		hide("#boss-login");
		show("#boss-app-nav");
		show("#boss-app");
		setText("#boss-name", user.fullname);
		initSortControls("boss", renderBossTransactions);
		await loadBossData();
		showBossView("operations");
	}

	async function loadBossData() {
		await Promise.all([loadBossOverview(), loadBossCustomers(), loadBossTransactions(), loadBossRewards()]);
	}

	function showBossView(view) {
		qsa(".boss-view").forEach((section) => {
			section.classList.toggle("is-hidden", section.id !== `boss-view-${view}`);
		});
		qsa("[data-boss-view]").forEach((button) => {
			button.classList.toggle("is-active", button.dataset.bossView === view);
		});
	}

	async function loadBossOverview() {
		const payload = await api("/boss/overview");
		bossOverviewData = payload.result;
		const metrics = bossOverviewData.metrics || {};
		const activeCustomers = (bossOverviewData.customers || []).filter((customer) => !customer.deleted_at);
		setText("#boss-total-assets", signedMoney(metrics.total_assets));
		setText("#boss-active-customers", metrics.active_customers || 0);
		setText("#boss-manual-in", metrics.manual_in || 0);
		setText("#boss-manual-out", metrics.manual_out || 0);
		setText("#boss-unopened-rewards", metrics.unopened_rewards || 0);
		setText("#nav-banking-count", activeCustomers.length);
		setText("#nav-users-count", activeCustomers.length);
		setText("#nav-rewards-count", metrics.unopened_rewards || 0);
		renderBossBalanceList(activeCustomers);
		renderBossUsers();
	}

	function renderBossBalanceList(customers) {
		const container = qs("#boss-balance-list");
		if (!container) {
			return;
		}
		if (!customers.length) {
			container.innerHTML = `<div class="text-secondary small">Keine aktiven User.</div>`;
			return;
		}
		container.innerHTML = customers
			.map((customer) => `
				<div class="balance-list-item">
					<span>${escapeHtml(customer.fullname)}</span>
					<strong>${signedMoney(customer.balance)}</strong>
				</div>
			`)
			.join("");
	}

	function renderBossUsers() {
		const container = qs("#boss-users-list");
		if (!container || !bossOverviewData) {
			return;
		}
		const users = bossOverviewData.customers || [];
		if (!users.length) {
			container.innerHTML = `<div class="p-4 text-secondary">Keine User.</div>`;
			return;
		}
		container.innerHTML = users.map((user) => {
			const archived = Boolean(user.deleted_at);
			return `
				<form class="user-management-item ${archived ? "is-archived" : ""}" data-user-form="${user.id}">
					<div class="user-management-heading">
						<div>
							<div class="fw-bold">${escapeHtml(user.fullname)}</div>
							<div class="text-secondary small">${escapeHtml(user.username)} · ${signedMoney(user.balance)}${archived ? " · archiviert" : ""}</div>
						</div>
						<div class="d-flex gap-2">
							${archived ? `<button type="button" class="btn btn-sm btn-outline-primary" data-restore-user="${user.id}">${icon("arrow-counterclockwise")} Wiederherstellen</button>` : `<button type="button" class="btn btn-sm btn-outline-danger" data-delete-user="${user.id}">${icon("archive")} Archivieren</button>`}
						</div>
					</div>
					<div class="user-management-grid">
						<label class="form-floating">
							<input name="fullname" class="form-control" value="${escapeHtml(user.fullname)}" placeholder="Name" ${archived ? "disabled" : ""}>
							<span>Name</span>
						</label>
						<label class="form-floating">
							<input name="username" class="form-control" value="${escapeHtml(user.username)}" placeholder="Username" ${archived ? "disabled" : ""}>
							<span>Username</span>
						</label>
						<label class="form-floating">
							<input name="email" type="email" class="form-control" value="${escapeHtml(user.email || "")}" placeholder="Google E-Mail" ${archived ? "disabled" : ""}>
							<span>Google E-Mail</span>
						</label>
						<label class="form-floating">
							<input name="userpassword" type="text" class="form-control" value="" placeholder="Neues Passwort" ${archived ? "disabled" : ""}>
							<span>Neues Passwort</span>
						</label>
					</div>
					<div class="d-flex justify-content-end">
						<button type="submit" class="btn btn-sm btn-primary" ${archived ? "disabled" : ""}>Speichern</button>
					</div>
				</form>
			`;
		}).join("");

		qsa("[data-user-form]").forEach((form) => {
			form.addEventListener("submit", submitUpdateUser);
		});
		qsa("[data-delete-user]").forEach((button) => {
			button.addEventListener("click", () => openDeleteUser(button.dataset.deleteUser));
		});
		qsa("[data-restore-user]").forEach((button) => {
			button.addEventListener("click", () => restoreUser(button.dataset.restoreUser));
		});
	}

	async function loadBossCustomers() {
		const payload = await api("/customers");
		for (const selector of ["#deposit-customer", "#payout-customer"]) {
			const select = qs(selector);
			select.innerHTML = `<option selected disabled value="">Kunde waehlen</option>`;
			for (const customer of payload.result) {
				const option = document.createElement("option");
				option.value = customer.id;
				option.textContent = `${customer.fullname} (${signedMoney(customer.balance)})`;
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
		await loadBossData();
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
		await loadBossData();
	}

	async function submitUpdateUser(event) {
		event.preventDefault();
		const form = event.currentTarget;
		const id = form.dataset.userForm;
		const data = formData(form);
		await api(`/customers/${id}`, {
			method: "PUT",
			body: JSON.stringify(data),
		});
		toast("User gespeichert.", "success");
		await loadBossData();
	}

	function openDeleteUser(id) {
		const user = (bossOverviewData?.customers || []).find((customer) => String(customer.id) === String(id));
		if (!user) {
			return;
		}
		pendingDeleteUser = user;
		setText("#delete-user-name", user.fullname);
		const confirm = qs("#delete-user-confirm");
		if (confirm) {
			confirm.value = "";
		}
		bootstrapModal("#delete-user-modal")?.show();
	}

	async function submitDeleteUser(event) {
		event.preventDefault();
		if (!pendingDeleteUser) {
			return;
		}
		const data = formData(event.currentTarget);
		await api(`/customers/${pendingDeleteUser.id}/archive`, {
			method: "POST",
			body: JSON.stringify({ confirm: data.confirm }),
		});
		bootstrapModal("#delete-user-modal")?.hide();
		toast("User archiviert.", "success");
		pendingDeleteUser = null;
		await loadBossData();
	}

	async function restoreUser(id) {
		await api(`/customers/${id}/restore`, { method: "POST" });
		toast("User wiederhergestellt.", "success");
		await loadBossData();
	}

	async function loadBossRewards() {
		const payload = await api("/boss/rewards");
		bossRewardsData = payload.result;
		renderRewardConfig();
		renderRewardOverview();
	}

	function renderRewardConfig() {
		const form = qs("#reward-config-form");
		if (!form || !bossRewardsData) {
			return;
		}
		const configs = bossRewardsData.config || [];
		form.innerHTML = configs.map((config) => {
			const key = escapeHtml(config.config_key);
			if (config.value_type === "boolean") {
				const checked = config.config_value === "true" || config.config_value === "1";
				return `
					<label class="reward-config-row">
						<span>
							<strong>${escapeHtml(config.label)}</strong>
							<small>${escapeHtml(config.description)}</small>
						</span>
						<input class="form-check-input" type="checkbox" name="${key}" ${checked ? "checked" : ""}>
					</label>
				`;
			}
			return `
				<label class="form-floating">
					<input class="form-control" type="number" min="0" step="0.0001" name="${key}" value="${escapeHtml(config.config_value)}" placeholder="${escapeHtml(config.label)}">
					<span>${escapeHtml(config.label)}</span>
					<small class="text-secondary d-block mt-1">${escapeHtml(config.description)}</small>
				</label>
			`;
		}).join("") + `
			<button type="submit" class="btn btn-primary d-inline-flex align-items-center justify-content-center gap-2">
				${icon("sliders")}<span>Speichern</span>
			</button>
		`;
		form.onsubmit = submitRewardConfig;
	}

	function renderRewardOverview() {
		const unopened = qs("#reward-unopened-body");
		const events = qs("#reward-events-body");
		if (!bossRewardsData || !unopened || !events) {
			return;
		}
		unopened.innerHTML = (bossRewardsData.unopened || []).map((row) => `
			<tr>
				<td>${escapeHtml(row.fullname)}</td>
				<td><strong>${row.unopened_rewards}</strong></td>
				<td>${signedMoney(row.unopened_amount)}</td>
				<td>${escapeHtml(dateTime(row.latest_reward_at || ""))}</td>
			</tr>
		`).join("") || `<tr><td colspan="4" class="text-center text-secondary py-4">Keine offenen Kisten</td></tr>`;

		events.innerHTML = (bossRewardsData.recent || []).map((event) => `
			<tr>
				<td>${escapeHtml(event.fullname)}</td>
				<td>${escapeHtml(event.title)}</td>
				<td>${signedMoney(event.amount)}</td>
				<td>${event.opened_at ? "Geoeffnet" : "Offen"}</td>
			</tr>
		`).join("") || `<tr><td colspan="4" class="text-center text-secondary py-4">Keine Rewards</td></tr>`;
	}

	async function submitRewardConfig(event) {
		event.preventDefault();
		const form = event.currentTarget;
		const data = formData(form);
		qsa('input[type="checkbox"]', form).forEach((input) => {
			data[input.name] = input.checked;
		});
		await api("/boss/rewards/config", {
			method: "PUT",
			body: JSON.stringify(data),
		});
		toast("Reward Settings gespeichert.", "success");
		await loadBossRewards();
		await loadBossOverview();
	}

	async function deleteTransaction(id) {
		if (!window.confirm("Transaktion loeschen?")) {
			return;
		}
		await api(`/transactions/${id}`, { method: "DELETE" });
		toast("Transaktion geloescht.", "success");
		await loadBossData();
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
