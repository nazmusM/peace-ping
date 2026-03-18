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

// Store current user ID after registration
let currentUserId = null;

// User registration function
async function registerUser(contact) {
    showResult(document.getElementById("ping-result"), "Registering user...", null);
    
    const response = await postJson("api/register", { contact });
    
    if (!response.ok) {
        const err = response.body.error || "Registration failed.";
        showResult(document.getElementById("ping-result"), err, "warn");
        return null;
    }
    
    currentUserId = response.body.user_id;
    return currentUserId;
}

pingForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    const resultEl = document.getElementById("ping-result");
    const selfName = document.getElementById("ping-self-name").value.trim();
    const self = document.getElementById("ping-self").value.trim();
    const target = document.getElementById("ping-target").value.trim();

    // Register user first (or get existing user ID)
    const userId = await registerUser(self);
    if (!userId) {
        return;
    }

    showResult(resultEl, "Submitting ping...", null);
    
    // Try new API format first
    let response = await postJson("api/ping", { user_id: userId, target });
    
    // If new format fails, fall back to legacy format
    if (!response.ok && response.status === 400) {
        response = await postJson("api/ping", { self_name: selfName, self, target });
    }

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
    
    // Try to register user for new API format
    let userId = await registerUser(self);
    
    // Try new API format first if we have a user ID
    let response;
    if (userId) {
        response = await postJson("api/preference", {
            user_id: userId,
            target,
            preference
        });
    } else {
        // Fall back to legacy format
        response = await postJson("api/preference", {
            self,
            target,
            preference
        });
    }

    if (!response.ok) {
        const err = response.body.error || "Could not submit preference.";
        showResult(resultEl, err, "warn");
        return;
    }

    if (response.body.resolved) {
        let message = response.body.message;
        
        // Show contact information if available
        if (response.body.contacts) {
            const { your_contact, other_contact } = response.body.contacts;
            if (your_contact && other_contact) {
                message += `\n\nYour contact: ${your_contact}\nTheir contact: ${other_contact}`;
            }
        }
        
        showResult(resultEl, message, "ok");
        return;
    }

    showResult(resultEl, response.body.message || "Preference recorded.", null);
});
