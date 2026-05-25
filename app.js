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
const confirmPhoneInput = document.getElementById("ping-target-confirm");
const pingResult = document.getElementById("ping-result");
const pingTargetConfirmError = document.getElementById("ping-target-confirm-error");
const pingSubmitButton = pingForm?.querySelector('button[type="submit"]');
const normalizePhone = (value) => {
    const trimmed = value.trim();
    const digits = trimmed.replace(/\D/g, '');
    if (trimmed.startsWith('+')) return '+' + digits;
    if (digits.startsWith('00')) return '+' + digits.slice(2);
    if (digits.startsWith('0')) return '+44' + digits.slice(1);
    return '+' + digits;
};

const updatePingValidation = () => {
    if (!pingSubmitButton) {
        return;
    }

    let message = '';
    const targetValue = phoneInput?.value.trim() ?? '';
    const confirmTargetValue = confirmPhoneInput?.value.trim() ?? '';

    if (targetValue && !validatePhone(targetValue)) {
        message = 'Please enter a valid mobile number. Use 07xxx xxxxxx, +447xxx xxxxxx, or +[country code][number].';
    } else if (targetValue && confirmTargetValue && normalizePhone(targetValue) !== normalizePhone(confirmTargetValue)) {
        message = 'The recipient numbers do not match. Please check both entries before sending.';
    }

    if (pingTargetConfirmError) {
        if (targetValue && confirmTargetValue && normalizePhone(targetValue) !== normalizePhone(confirmTargetValue)) {
            pingTargetConfirmError.textContent = 'The recipient numbers do not match. Please check both entries before sending.';
        } else {
            pingTargetConfirmError.textContent = '';
        }
    }

    if (message) {
        showResult(pingResult, message, 'warn');
        pingSubmitButton.disabled = true;
    } else if (pingTargetConfirmError?.textContent) {
        showResult(pingResult, '', null);
        pingSubmitButton.disabled = true;
    } else {
        showResult(pingResult, '', null);
        pingSubmitButton.disabled = false;
    }
};

if (phoneInput) {
    phoneInput.addEventListener('input', debounce(function (e) {
        const value = e.target.value;
        if (value && !validatePhone(value)) {
            e.target.setCustomValidity('Use 07xxx xxxxxx, +447xxx xxxxxx, or +[country code][number].');
        } else {
            e.target.setCustomValidity('');
        }
        updatePingValidation();
    }, 300));
}

if (phoneInput && confirmPhoneInput) {
    const validateRecipientMatch = () => {
        if (phoneInput.value.trim() && confirmPhoneInput.value.trim() && normalizePhone(phoneInput.value) !== normalizePhone(confirmPhoneInput.value)) {
            confirmPhoneInput.setCustomValidity('The recipient numbers do not match.');
        } else {
            confirmPhoneInput.setCustomValidity('');
        }
        updatePingValidation();
    };

    phoneInput.addEventListener('input', validateRecipientMatch);
    confirmPhoneInput.addEventListener('input', validateRecipientMatch);
}

if (pingForm) {
    pingForm.addEventListener("submit", async (event) => {
        event.preventDefault();

        const resultEl = document.getElementById("ping-result");
        const target = document.getElementById("ping-target").value.trim();
        const confirmTarget = document.getElementById("ping-target-confirm").value.trim();
        const recipientName = document.getElementById("recipient-name").value.trim();

        // Client-side validation
        if (!target) {
            showResult(resultEl, "Please enter a phone number.", "warn");
            return;
        }

        if (!validatePhone(target)) {
            showResult(resultEl, "Please enter a valid mobile number. Use 07xxx xxxxxx, +447xxx xxxxxx, or +[country code][number]. Spaces are fine.", "warn");
            return;
        }

        if (!confirmTarget) {
            showResult(resultEl, "Please confirm the recipient number.", "warn");
            return;
        }

        if (normalizePhone(target) !== normalizePhone(confirmTarget)) {
            showResult(resultEl, "The recipient numbers do not match. Please check both entries before sending.", "warn");
            return;
        }

        // Hide previous match info
        matchInfo.hidden = true;

        // Get user data from embedded JSON
        const userDataEl = document.getElementById('user-data');
        if (!userDataEl) {
            showResult(resultEl, "You are not logged in. Please register or log in.", "warn");
            return;
        }
        let userData;
        try {
            userData = JSON.parse(userDataEl.textContent);
        } catch (e) {
            showResult(resultEl, "You are not logged in. Please register or log in.", "warn");
            return;
        }
        if (!userData || !userData.user_id) {
            showResult(resultEl, "You are not logged in. Please register or log in.", "warn");
            return;
        }

        const userId = userData.user_id;
        showResult(resultEl, "Sending Peace Ping...", null);

        // Submit ping with user_id
        try {
            const response = await postJson("api/ping", {
                user_id: userId,
                target,
                confirm_target: confirmTarget,
                recipient_name: recipientName
            });

            if (!response.ok) {
                const err = response.body.error || "Could not send ping.";
                showResult(resultEl, err, "warn");
                return;
            }

            if (response.body.matched) {
                matchInfo.hidden = false;
                matchMessage.textContent = "Match found! Check your SMS for the private preference link.";
                showResult(resultEl, "Match found! Check your SMS for the private link.", "ok");
            } else {
                showResult(resultEl, "Peace Ping sent. You can track it from your dashboard. If they also ping you, both of you receive secure preference links.", "ok");
            }
        } catch (error) {
            showResult(resultEl, "Error sending Peace Ping.", "warn");
        }
    });
}
