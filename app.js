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
const matchInfo = document.getElementById("match-info");
const matchMessage = document.getElementById("match-message");
const contactInfo = document.getElementById("contact-info");
const contactDetails = document.getElementById("contact-details");

// User registration function (no longer needed for ping page)
// Users must be logged in via session to send pings

pingForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    const resultEl = document.getElementById("ping-result");
    const target = document.getElementById("ping-target").value.trim();

    // Hide previous match info
    matchInfo.hidden = true;

    // Check if user is logged in
    try {
        const statusResponse = await postJson("api/register", { action: 'status' });
        if (!statusResponse.body.logged_in) {
            showResult(resultEl, "Please register and log in first.", "warn");
            return;
        }

        const userId = statusResponse.body.user.id;
        showResult(resultEl, "Sending Peace Ping...", null);

        // Submit ping with user_id
        const response = await postJson("api/ping", { user_id: userId, target });

        if (!response.ok) {
            const err = response.body.error || "Could not send ping.";
            showResult(resultEl, err, "warn");
            return;
        }

        if (response.body.matched) {
            // Show match found with flow-chart info
            matchInfo.hidden = false;
            matchMessage.textContent = "✨ Peace Ping Matched! Both of you will receive SMS messages with questions to help reconnect comfortably.";

            showResult(resultEl, "Match found! Check your SMS for the next steps.", "ok");
        } else {
            showResult(resultEl, "Peace Ping sent! If they also send you one, you'll both receive SMS questions to help reconnect.", "ok");
        }
    } catch (error) {
        showResult(resultEl, "Error checking login status.", "warn");
    }
});
