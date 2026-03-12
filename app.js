async function postJson(url, payload) {
    const response = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
    });

    const body = await response.json().catch(() => ({}));
    return { ok: response.ok, status: response.status, body };
}

function showResult(target, text, tone) {
    target.textContent = text;
    target.classList.remove("ok", "warn");
    if (tone) {
        target.classList.add(tone);
    }
}

const pingForm = document.getElementById("ping-form");
const statusForm = document.getElementById("status-form");
const preferenceForm = document.getElementById("preference-form");
const preferenceCard = document.getElementById("preference-card");

pingForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    const resultEl = document.getElementById("ping-result");
    const selfName = document.getElementById("ping-self-name").value.trim();
    const self = document.getElementById("ping-self").value.trim();
    const target = document.getElementById("ping-target").value.trim();

    showResult(resultEl, "Submitting ping...", null);
    const response = await postJson("api/ping", { self_name: selfName, self, target });

    if (!response.ok) {
        const err = response.body.error || "Could not submit ping.";
        showResult(resultEl, err, "warn");
        return;
    }

    showResult(
        resultEl,
        response.body.message || "Ping recorded.",
        "ok"
    );
});

statusForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    const resultEl = document.getElementById("status-result");
    const self = document.getElementById("status-self").value.trim();
    const target = document.getElementById("status-target").value.trim();

    showResult(resultEl, "Checking status...", null);
    const response = await postJson("api/status", {
        self,
        target
    });

    if (!response.ok) {
        const err = response.body.error || "Could not check status.";
        showResult(resultEl, err, "warn");
        return;
    }

    if (response.body.matched === true) {
        preferenceCard.hidden = false;
        document.getElementById("preference-self").value = self;
        document.getElementById("preference-target").value = target;
        showResult(resultEl, response.body.message || "Matched.", "ok");
        return;
    }

    preferenceCard.hidden = true;
    showResult(resultEl, response.body.message || "No mutual match yet.", null);
});

preferenceForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (preferenceCard.hidden) {
        return;
    }

    const resultEl = document.getElementById("preference-result");
    const self = document.getElementById("preference-self").value.trim();
    const target = document.getElementById("preference-target").value.trim();
    const preference = document.getElementById("preference-choice").value;

    showResult(resultEl, "Submitting preference...", null);
    const response = await postJson("api/preference", {
        self,
        target,
        preference
    });

    if (!response.ok) {
        const err = response.body.error || "Could not submit preference.";
        showResult(resultEl, err, "warn");
        return;
    }

    if (response.body.resolved) {
        showResult(resultEl, response.body.message, "ok");
        return;
    }

    showResult(resultEl, response.body.message || "Preference recorded.", null);
});
