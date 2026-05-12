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
    if (!text || text.trim() === '') {
        target.style.display = 'none';
        return;
    }

    target.style.display = 'block';
    target.textContent = text;
    target.classList.remove("ok", "warn");
    if (tone) {
        target.classList.add(tone);
    }

    // Scroll to result for better UX on mobile
    target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Form validation helpers
function validatePhone(phone) {
    const trimmed = phone.trim();
    const digits = trimmed.replace(/\D/g, '');

    if (digits.length < 8 || digits.length > 15) {
        return false;
    }

    if (trimmed.startsWith('+')) {
        return /^\+[1-9][\d\s().-]{7,20}$/.test(trimmed);
    }

    if (digits.startsWith('00')) {
        return digits.length >= 10;
    }

    return /^[0-9\s().-]+$/.test(trimmed);
}

function validateName(name) {
    return /^[a-zA-Z\s\-\'\.]+$/.test(name) && name.trim().length >= 2;
}

const pingForm = document.getElementById("ping-form");
const matchInfo = document.getElementById("match-info");
const matchMessage = document.getElementById("match-message");
const contactInfo = document.getElementById("contact-info");
const contactDetails = document.getElementById("contact-details");

// Add input validation for phone field
const phoneInput = document.getElementById("ping-target");
if (phoneInput) {
    phoneInput.addEventListener('input', debounce(function (e) {
        const value = e.target.value;
        if (value && !validatePhone(value)) {
            e.target.setCustomValidity('Use 07xxx xxxxxx, +447xxx xxxxxx, or +[country code][number].');
        } else {
            e.target.setCustomValidity('');
        }
    }, 300));
}

pingForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    const resultEl = document.getElementById("ping-result");
    const target = document.getElementById("ping-target").value.trim();

    // Client-side validation
    if (!target) {
        showResult(resultEl, "Please enter a phone number.", "warn");
        return;
    }

    if (!validatePhone(target)) {
        showResult(resultEl, "Please enter a valid mobile number. Use 07xxx xxxxxx, +447xxx xxxxxx, or +[country code][number]. Spaces are fine.", "warn");
        return;
    }

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
            // Show match found with success message
            matchInfo.hidden = false;
            matchMessage.textContent = "🎉 Match found! Check your SMS for the private preference link.";

            showResult(resultEl, "🎉 Match found! Check your SMS for the private link.", "ok");
        } else {
            showResult(resultEl, "Peace Ping sent. You can track it from your dashboard; if they also ping you, both of you receive secure links for preferences.", "ok");
        }
    } catch (error) {
        showResult(resultEl, "Error checking login status.", "warn");
    }
});
