(function () {
  "use strict";

  const card = document.querySelector("[data-status-card]");
  if (!card) return;

  const title = card.querySelector("[data-status-title]");
  const eventName = card.querySelector("[data-event-name]");
  const message = card.querySelector("[data-status-message]");
  const indicator = card.querySelector("[data-status-indicator]");
  const details = card.querySelector("[data-registration-details]");
  const reference = card.querySelector("[data-detail-reference]");
  const packageName = card.querySelector("[data-detail-package]");
  const amount = card.querySelector("[data-detail-amount]");
  const payer = card.querySelector("[data-detail-payer]");
  const email = card.querySelector("[data-detail-email]");
  const emailRow = card.querySelector("[data-detail-email-row]");
  const participantSection = card.querySelector("[data-participant-section]");
  const participantTitle = card.querySelector("[data-participant-title]");
  const participantList = card.querySelector("[data-detail-participants]");
  const paymentConfirmationNotice = card.querySelector("[data-payment-confirmation-notice]");
  const receiptLink = card.querySelector("[data-receipt-link]");
  const retryButton = card.querySelector("[data-status-retry]");
  const params = new URLSearchParams(window.location.hash.replace(/^#/, ""));
  const token = params.get("token") || "";
  const referenceFromUrl = params.get("reference") || "";
  const validToken = /^[A-Za-z0-9_-]{43}$/.test(token);
  const pendingStatuses = new Set(["created", "open", "pending", "processing", "pending_checkout", "pending_payment", "checkout_pending", "payment_pending", "unpaid"]);
  const finalStatuses = new Set(["paid", "completed", "complete", "refunded", "partially_refunded", "failed", "error", "checkout_error", "expired", "canceled", "cancelled"]);
  const benefitFmvMode = "benefit_fmv";
  const paymentConfirmationMode = "payment_confirmation_only";
  const currency = new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" });
  let timer = null;
  let pollCount = 0;
  let loading = false;
  let currentStatus = "loading";

  function plainMessage(data, fallback) {
    if (data && typeof data.message === "string" && data.message.trim()) return data.message.trim();
    if (data && data.error && typeof data.error.message === "string" && data.error.message.trim()) return data.error.message.trim();
    if (data && typeof data.error === "string" && data.error.trim()) return data.error.trim();
    return fallback;
  }

  function normalizeStatus(status) {
    return typeof status === "string" ? status.trim().toLowerCase() : "pending";
  }

  function setIndicator(state) {
    indicator.className = "status-card__indicator status-card__indicator--" + state;
  }

  function safeUrl(value) {
    if (typeof value !== "string" || !value.trim()) return null;
    try {
      const url = new URL(value, window.location.href);
      const host = url.hostname.toLowerCase();
      const squareHost = host === "squareup.com" || host.endsWith(".squareup.com")
        || host === "squareupsandbox.com" || host.endsWith(".squareupsandbox.com");
      if (url.protocol !== "https:" || !squareHost || url.username || url.password || url.port) return null;
      return url.href;
    } catch (error) {
      return null;
    }
  }

  function formatAmount(cents) {
    const numeric = Number(cents);
    return Number.isFinite(numeric) ? currency.format(numeric / 100) : "—";
  }

  function positiveInteger(value, maximum) {
    const numeric = typeof value === "number"
      ? value
      : typeof value === "string" && /^\d+$/.test(value.trim()) ? Number(value) : NaN;
    return Number.isSafeInteger(numeric) && numeric > 0 && numeric <= maximum ? numeric : 0;
  }

  function nonNegativeInteger(value, maximum) {
    const numeric = typeof value === "number"
      ? value
      : typeof value === "string" && /^\d+$/.test(value.trim()) ? Number(value) : NaN;
    return Number.isSafeInteger(numeric) && numeric >= 0 && numeric <= maximum ? numeric : null;
  }

  function textValue(value) {
    return typeof value === "string" ? value.trim() : "";
  }

  function validDisclosure(data) {
    if (!data) return false;
    if (data.disclosure_mode === benefitFmvMode) return true;
    return data.disclosure_mode === paymentConfirmationMode
      && data.benefit_description === null
      && data.fair_market_value_cents === null
      && data.max_deductible_cents === null;
  }

  function orderedRecords(records, maximum) {
    if (!Array.isArray(records)) return [];
    return records
      .filter(function (record) { return record && typeof record === "object" && !Array.isArray(record); })
      .slice(0, maximum)
      .map(function (record, index) {
        return { record: record, index: index, position: positiveInteger(record.position, maximum) || index + 1 };
      })
      .sort(function (left, right) {
        return left.position === right.position ? left.index - right.index : left.position - right.position;
      });
  }

  function addonText(addon) {
    if (typeof addon === "string") {
      return addon.trim() === "team_mulligans" ? "Eight team mulligans" : "";
    }
    if (!addon || typeof addon !== "object" || Array.isArray(addon)) return "";
    const code = textValue(addon.code);
    const name = textValue(addon.name) || (code === "team_mulligans" ? "Team mulligans" : code) || "Add-on";
    const quantity = positiveInteger(addon.quantity, 1000);
    const unitAmount = nonNegativeInteger(addon.unit_amount_cents, Number.MAX_SAFE_INTEGER);
    const suppliedTotal = nonNegativeInteger(addon.total_amount_cents, Number.MAX_SAFE_INTEGER);
    const calculatedTotal = unitAmount !== null && quantity ? unitAmount * quantity : null;
    const total = suppliedTotal !== null ? suppliedTotal : calculatedTotal;
    const quantityText = quantity > 1 ? " × " + quantity : "";
    return name + quantityText + (Number.isSafeInteger(total) ? " (" + formatAmount(total) + ")" : "");
  }

  function renderTeamParticipants(teams, data) {
    const orderedTeams = orderedRecords(teams, 50);
    const packageQuantity = positiveInteger(data.package_quantity, 1000);
    const displayedTeamCount = packageQuantity || orderedTeams.length;
    let totalGolfers = 0;
    let totalCapacity = 0;
    let allTeamsHaveCapacity = orderedTeams.length > 0;

    participantList.replaceChildren();
    participantSection.style.overflowWrap = "anywhere";
    orderedTeams.forEach(function (teamEntry) {
      const team = teamEntry.record;
      const item = document.createElement("li");
      const heading = document.createElement("strong");
      const teamName = textValue(team.name);
      heading.textContent = "Team " + teamEntry.position + (teamName ? " — " + teamName : "");
      item.appendChild(heading);

      const participants = orderedRecords(team.participants, 100).filter(function (participantEntry) {
        return Boolean(textValue(participantEntry.record.name));
      });
      totalGolfers += participants.length;
      const suppliedCapacity = nonNegativeInteger(team.participant_capacity, 1000);
      const capacity = suppliedCapacity !== null && suppliedCapacity >= participants.length ? suppliedCapacity : null;
      if (capacity !== null) {
        totalCapacity += capacity;
      } else {
        allTeamsHaveCapacity = false;
      }

      const teamSummary = document.createElement("p");
      teamSummary.className = "small muted";
      teamSummary.textContent = capacity !== null
        ? participants.length + (participants.length === 1 ? " golfer" : " golfers") + " listed; capacity " + capacity
        : participants.length + (participants.length === 1 ? " golfer" : " golfers") + " listed";
      item.appendChild(teamSummary);

      if (participants.length) {
        const names = document.createElement("ol");
        participants.forEach(function (participantEntry) {
          const person = document.createElement("li");
          person.textContent = textValue(participantEntry.record.name);
          names.appendChild(person);
        });
        item.appendChild(names);
      } else {
        const empty = document.createElement("p");
        empty.className = "small muted";
        empty.textContent = "No golfer names are listed yet.";
        item.appendChild(empty);
      }

      const addons = (Array.isArray(team.addons) ? team.addons.slice(0, 25) : [])
        .map(addonText)
        .filter(Boolean);
      if (addons.length) {
        const addonSummary = document.createElement("p");
        addonSummary.className = "small";
        addonSummary.textContent = "Add-ons: " + addons.join("; ");
        item.appendChild(addonSummary);
      }
      participantList.appendChild(item);
    });

    if (!allTeamsHaveCapacity) {
      const topLevelCapacity = nonNegativeInteger(data.participant_capacity, 50000);
      if (topLevelCapacity !== null && topLevelCapacity >= totalGolfers) {
        totalCapacity = topLevelCapacity;
        allTeamsHaveCapacity = true;
      }
    }
    participantTitle.textContent = "Golf teams: " + displayedTeamCount
      + " · Golfers attending: " + totalGolfers
      + (allTeamsHaveCapacity ? " · Package capacity: " + totalCapacity : "");
    participantSection.hidden = false;
    return displayedTeamCount;
  }

  function ticketGroupLabel(data) {
    const code = textValue(data.package_code).toLowerCase();
    if (code === "gala_couple" || code === "gala_vip_couple") return "Couple package";
    if (code === "gala_single" || code === "gala_vip_single") return "Ticket";
    const name = textValue(data.package_name).toLowerCase();
    if (name.includes("couple")) return "Couple package";
    if (name.includes("single")) return "Ticket";
    return data.event_code === "gala-2026" ? "Ticket" : "Ticket group";
  }

  function renderTicketGroupParticipants(ticketGroups, data) {
    const orderedGroups = orderedRecords(ticketGroups, 50);
    const packageQuantity = positiveInteger(data.package_quantity, 1000);
    const displayedGroupCount = packageQuantity || orderedGroups.length;
    const groupLabel = ticketGroupLabel(data);
    let totalGuests = 0;
    let totalCapacity = 0;
    let allGroupsHaveCapacity = orderedGroups.length > 0;

    participantList.replaceChildren();
    participantSection.style.overflowWrap = "anywhere";
    orderedGroups.forEach(function (groupEntry) {
      const group = groupEntry.record;
      const item = document.createElement("li");
      const heading = document.createElement("strong");
      heading.textContent = groupLabel + " " + groupEntry.position;
      item.appendChild(heading);

      const participants = orderedRecords(group.participants, 100).filter(function (participantEntry) {
        return Boolean(textValue(participantEntry.record.name));
      });
      totalGuests += participants.length;
      const suppliedCapacity = nonNegativeInteger(group.participant_capacity, 1000);
      const capacity = suppliedCapacity !== null && suppliedCapacity >= participants.length ? suppliedCapacity : null;
      if (capacity !== null) totalCapacity += capacity;
      else allGroupsHaveCapacity = false;

      const groupSummary = document.createElement("p");
      groupSummary.className = "small muted";
      groupSummary.textContent = capacity !== null
        ? participants.length + (participants.length === 1 ? " guest" : " guests") + " listed; capacity " + capacity
        : participants.length + (participants.length === 1 ? " guest" : " guests") + " listed";
      item.appendChild(groupSummary);

      if (participants.length) {
        const names = document.createElement("ol");
        participants.forEach(function (participantEntry) {
          const person = document.createElement("li");
          person.textContent = textValue(participantEntry.record.name);
          names.appendChild(person);
        });
        item.appendChild(names);
      } else {
        const empty = document.createElement("p");
        empty.className = "small muted";
        empty.textContent = "No guest names are listed yet.";
        item.appendChild(empty);
      }
      participantList.appendChild(item);
    });

    if (!allGroupsHaveCapacity) {
      const topLevelCapacity = nonNegativeInteger(data.participant_capacity, 50000);
      if (topLevelCapacity !== null && topLevelCapacity >= totalGuests) {
        totalCapacity = topLevelCapacity;
        allGroupsHaveCapacity = true;
      }
    }
    participantTitle.textContent = "Guests attending: " + totalGuests
      + (allGroupsHaveCapacity ? " · Package capacity: " + totalCapacity : "");
    participantSection.hidden = false;
    return displayedGroupCount;
  }

  function renderFlatParticipants(data) {
    participantList.replaceChildren();
    participantSection.style.overflowWrap = "anywhere";
    const participants = Array.isArray(data.participants) ? data.participants.slice(0, 500) : [];
    participants.forEach(function (participant) {
      const name = typeof participant === "string" ? participant : participant && participant.name;
      if (typeof name !== "string" || !name.trim()) return;
      const item = document.createElement("li");
      item.textContent = name.trim();
      participantList.appendChild(item);
    });
    const participantCount = participantList.children.length;
    const capacity = nonNegativeInteger(data.participant_capacity, 50000);
    const attendeeLabel = data.event_code === "gala-2026"
      ? "Guests attending"
      : data.event_code === "golf-2026" ? "Golfers attending" : "People attending";
    const capacityNote = capacity !== null && capacity > participantCount
      ? "; package includes up to " + capacity
      : "";
    participantTitle.textContent = attendeeLabel + ": " + participantCount + capacityNote;
    participantSection.hidden = participantCount === 0;
  }

  function renderDetails(data) {
    if (typeof data.event_name === "string" && data.event_name.trim()) {
      const name = data.event_name.trim();
      eventName.textContent = name;
      document.title = name + " Registration Status | COMEC";
    } else {
      eventName.textContent = "Event registration";
      document.title = "Registration Status | COMEC";
    }
    const displayReference = typeof data.reference === "string" && data.reference.trim()
      ? data.reference.trim()
      : referenceFromUrl.trim();
    reference.textContent = displayReference || "—";
    const basePackageName = typeof data.package_name === "string" && data.package_name.trim() ? data.package_name.trim() : "—";
    const teams = orderedRecords(data.teams, 50).map(function (entry) { return entry.record; });
    const ticketGroups = orderedRecords(data.ticket_groups, 50).map(function (entry) { return entry.record; });
    const groupedCount = teams.length
      ? positiveInteger(data.package_quantity, 1000) || teams.length
      : ticketGroups.length ? positiveInteger(data.package_quantity, 1000) || ticketGroups.length : 0;
    packageName.textContent = basePackageName !== "—" && groupedCount
      ? basePackageName + " × " + groupedCount
      : basePackageName;
    amount.textContent = formatAmount(data.amount_cents);
    payer.textContent = typeof data.payer_name === "string" && data.payer_name.trim() ? data.payer_name.trim() : "—";

    if (typeof data.payer_email === "string" && data.payer_email.trim()) {
      email.textContent = data.payer_email.trim();
      emailRow.hidden = false;
    } else {
      emailRow.hidden = true;
    }

    if (teams.length) renderTeamParticipants(teams, data);
    else if (ticketGroups.length) renderTicketGroupParticipants(ticketGroups, data);
    else renderFlatParticipants(data);

    const receipt = safeUrl(data.receipt_url);
    if (receipt) {
      receiptLink.href = receipt;
      receiptLink.hidden = false;
    } else {
      receiptLink.hidden = true;
      receiptLink.removeAttribute("href");
    }

    paymentConfirmationNotice.hidden = data.disclosure_mode !== paymentConfirmationMode;

    details.hidden = false;
  }

  function renderState(data) {
    const status = normalizeStatus(data.status);
    currentStatus = status;
    renderDetails(data);
    retryButton.hidden = true;
    card.classList.remove("status-card--success", "status-card--warning", "status-card--error", "status-card--pending");

    if (status === "paid" || status === "completed" || status === "complete") {
      card.classList.add("status-card--success");
      setIndicator("success");
      title.textContent = "You’re registered.";
      message.textContent = plainMessage(data, "Your payment is confirmed. COMEC will send a detailed confirmation to the email shown below.");
    } else if (status === "refunded" || status === "partially_refunded") {
      card.classList.add("status-card--warning");
      setIndicator("warning");
      title.textContent = status === "partially_refunded" ? "Payment partially refunded" : "Payment refunded";
      message.textContent = plainMessage(data, "This registration’s payment has been refunded. Contact COMEC if you have questions.");
    } else if (status === "failed" || status === "error" || status === "checkout_error" || status === "expired" || status === "canceled" || status === "cancelled") {
      card.classList.add("status-card--error");
      setIndicator("error");
      title.textContent = status === "expired" ? "Checkout expired" : status === "canceled" || status === "cancelled" ? "Checkout canceled" : "Payment not completed";
      message.textContent = plainMessage(data, "We could not confirm payment for this registration. Please try registering again or contact COMEC.");
      retryButton.hidden = false;
    } else {
      card.classList.add("status-card--pending");
      setIndicator("loading");
      title.textContent = "Confirming your payment…";
      message.textContent = plainMessage(data, "Payment confirmation can take a few moments. This page will update automatically.");
    }

    card.setAttribute("aria-busy", "false");
  }

  function renderRequestError(data, responseStatus) {
    currentStatus = "request_error";
    card.setAttribute("aria-busy", "false");
    card.classList.remove("status-card--success", "status-card--warning", "status-card--pending");
    card.classList.add("status-card--error");
    setIndicator("error");
    paymentConfirmationNotice.hidden = true;
    title.textContent = responseStatus === 404 ? "Registration not found" : "We couldn’t check your registration";
    message.textContent = plainMessage(data, responseStatus === 404
      ? "This status link is invalid or has expired. Use the link from checkout or contact COMEC."
      : "Please check your connection and try again.");
    if (referenceFromUrl) {
      reference.textContent = referenceFromUrl;
      packageName.textContent = "—";
      amount.textContent = "—";
      payer.textContent = "—";
      emailRow.hidden = true;
      participantSection.hidden = true;
      details.hidden = false;
    }
    retryButton.hidden = responseStatus === 404;
  }

  function schedulePoll() {
    window.clearTimeout(timer);
    if (pollCount >= 120) {
      retryButton.hidden = false;
      message.textContent = "Confirmation is taking longer than expected. You can check again or call COMEC for help.";
      return;
    }
    const delay = pollCount < 15 ? 4000 : 10000;
    timer = window.setTimeout(loadStatus, delay);
  }

  async function loadStatus() {
    if (loading || !validToken) return;
    loading = true;
    pollCount += 1;
    card.setAttribute("aria-busy", "true");
    retryButton.hidden = true;
    const controller = new AbortController();
    const timeout = window.setTimeout(function () {
      controller.abort();
    }, 15000);

    try {
      const response = await fetch("api/registration-status.php", {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          "Accept": "application/json",
          "Authorization": "Bearer " + token
        },
        signal: controller.signal
      });
      let data = null;
      try {
        data = await response.json();
      } catch (error) {
        data = null;
      }
      if (!response.ok || !data || data.ok === false) {
        renderRequestError(data, response.status);
        return;
      }

      if (!validDisclosure(data)) {
        renderRequestError({ message: "The registration details could not be verified. Please try again or contact COMEC." }, 0);
        return;
      }

      renderState(data);
      if (pendingStatuses.has(currentStatus) || !finalStatuses.has(currentStatus)) schedulePoll();
    } catch (error) {
      renderRequestError(null, 0);
    } finally {
      window.clearTimeout(timeout);
      loading = false;
    }
  }

  retryButton.addEventListener("click", function () {
    window.clearTimeout(timer);
    loadStatus();
  });

  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible" && pendingStatuses.has(currentStatus)) {
      window.clearTimeout(timer);
      loadStatus();
    }
  });

  if (!validToken) {
    renderRequestError({ message: "This status link is incomplete or invalid. Use the link from checkout or contact COMEC for help." }, 404);
  } else {
    loadStatus();
  }
})();
