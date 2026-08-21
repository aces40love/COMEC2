(function () {
  "use strict";

  const form = document.querySelector("[data-gala-registration-form]");
  if (!form) return;

  const packages = {
    gala_single: {
      name: "Single admission",
      includes: "One attendee per ticket",
      amountCents: 13500,
      participantMin: 1,
      participantMax: 1,
      kind: "single",
      config: null
    },
    gala_couple: {
      name: "Couple admission",
      includes: "Up to two attendees per couple package",
      amountCents: 25000,
      participantMin: 1,
      participantMax: 2,
      kind: "couple",
      config: null
    },
    gala_vip_single: {
      name: "VIP single admission",
      includes: "VIP admission for one attendee per ticket",
      amountCents: 17500,
      participantMin: 1,
      participantMax: 1,
      kind: "single",
      config: null
    },
    gala_vip_couple: {
      name: "VIP couple admission",
      includes: "VIP admission for up to two attendees per couple package",
      amountCents: 30000,
      participantMin: 1,
      participantMax: 2,
      kind: "couple",
      config: null
    }
  };

  const packageRadios = Array.from(form.querySelectorAll("[data-package-radio]"));
  const ticketGroupSection = form.querySelector("[data-ticket-group-section]");
  const packageQuantity = form.querySelector("[data-package-quantity]");
  const quantityLabel = form.querySelector("[data-quantity-label]");
  const quantityHelp = form.querySelector("[data-quantity-help]");
  const ticketGroupList = form.querySelector("[data-ticket-group-list]");
  const payerFirstName = form.querySelector("#payer-first-name");
  const payerLastName = form.querySelector("#payer-last-name");
  const consent = form.querySelector("#registration-consent");
  const honeypot = form.querySelector("#registration-website");
  const configStatus = document.querySelector("[data-config-status]");
  const errorAlert = document.querySelector("[data-form-error]");
  const submitButton = form.querySelector("[data-submit-button]");
  const submitLabel = form.querySelector("[data-submit-label]");
  const submitSpinner = form.querySelector("[data-submit-spinner]");
  const summaryPackage = document.querySelector("[data-summary-package]");
  const summaryIncludes = document.querySelector("[data-summary-includes]");
  const summaryQuantity = document.querySelector("[data-summary-quantity]");
  const summaryAttendees = document.querySelector("[data-summary-attendees]");
  const summaryCalculation = document.querySelector("[data-summary-calculation]");
  const summaryPrice = document.querySelector("[data-summary-price]");
  const taxDisclosure = form.querySelector("[data-tax-disclosure]");
  const benefitDescription = form.querySelector("[data-benefit-description]");
  const fairMarketValue = form.querySelector("[data-fair-market-value]");
  const maximumDeductible = form.querySelector("[data-maximum-deductible]");

  const money = new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: "USD",
    minimumFractionDigits: 0,
    maximumFractionDigits: 2
  });
  const idempotencyKey = createIdempotencyKey();
  const requestedPackage = new URLSearchParams(window.location.search).get("package");
  const statesByKind = { single: [], couple: [] };
  const quantityByKind = { single: "", couple: "" };
  let configurationReady = false;
  let reviewOnly = false;
  let activeKind = "";

  function createIdempotencyKey() {
    if (!window.crypto || typeof window.crypto.getRandomValues !== "function") return "";
    if (typeof window.crypto.randomUUID === "function") return window.crypto.randomUUID();
    const bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, function (byte) {
      return byte.toString(16).padStart(2, "0");
    }).join("");
    return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join("-");
  }

  function selectedPackageCode() {
    const checked = form.querySelector("[data-package-radio]:checked");
    return checked ? checked.value : "";
  }

  function selectedPackage() {
    return packages[selectedPackageCode()];
  }

  function isPreviewEnvironment() {
    const host = window.location.hostname.toLowerCase();
    return window.location.protocol === "file:"
      || host === "localhost"
      || host === "127.0.0.1"
      || host === "0.0.0.0"
      || host === "::1"
      || host === "[::1]"
      || host === "github.com"
      || host.endsWith(".github.io")
      || host.endsWith(".githubusercontent.com")
      || host.endsWith(".githack.com")
      || host.includes("pcloud")
      || host === "filedn.com"
      || host.endsWith(".filedn.com");
  }

  function unavailableCopy() {
    if (isPreviewEnvironment()) {
      return {
        status: "Preview only: admission, quantity, and attendee controls are available for review. Secure payment is disabled until the website’s server API is set up.",
        button: "Preview only — payment unavailable"
      };
    }
    return {
      status: "Online payment is temporarily unavailable. You may review admission and attendee options, but checkout is disabled while server setup is completed. Please try again later or call COMEC at 901-222-0700.",
      button: "Online payment temporarily unavailable"
    };
  }

  function selectedQuantity() {
    const quantity = Number.parseInt(packageQuantity.value, 10);
    return Number.isInteger(quantity) && quantity >= 1 && quantity <= 10 ? quantity : 0;
  }

  function newGroupState(kind) {
    return {
      attendeeCount: kind === "single" ? "1" : "",
      participants: ["", ""],
      purchaserAttending: false,
      manualAttendeeOne: ""
    };
  }

  function ensureGroupStates(kind, quantity) {
    const states = statesByKind[kind];
    while (states.length < quantity) states.push(newGroupState(kind));
  }

  function purchaserFullName() {
    return [payerFirstName.value.trim(), payerLastName.value.trim()].filter(Boolean).join(" ");
  }

  function syncPurchaserAttendees() {
    const purchaserName = purchaserFullName();
    Object.keys(statesByKind).forEach(function (kind) {
      const firstGroup = statesByKind[kind][0];
      if (!firstGroup || !firstGroup.purchaserAttending) return;
      firstGroup.participants[0] = purchaserName;
      if (activeKind === kind) {
        const input = ticketGroupList.querySelector('[data-group-index="0"] [data-attendee-index="0"]');
        if (input) input.value = purchaserName;
      }
    });
  }

  function groupAttendeeCount(state, kind) {
    if (kind === "single") return 1;
    const count = Number.parseInt(state.attendeeCount, 10);
    return Number.isInteger(count) && count >= 1 && count <= 2 ? count : 0;
  }

  function renderGroupParticipants(card, groupIndex, selected) {
    const state = statesByKind[selected.kind][groupIndex];
    const count = groupAttendeeCount(state, selected.kind);
    const container = card.querySelector("[data-group-participants]");
    container.replaceChildren();
    for (let attendeeIndex = 0; attendeeIndex < count; attendeeIndex += 1) {
      const field = document.createElement("div");
      field.className = "form-field";
      const label = document.createElement("label");
      const inputId = "gala-group-" + (groupIndex + 1) + "-attendee-" + (attendeeIndex + 1);
      label.htmlFor = inputId;
      label.append(document.createTextNode("Attendee " + (attendeeIndex + 1) + " "));
      const marker = document.createElement("span");
      marker.setAttribute("aria-hidden", "true");
      marker.textContent = "*";
      label.appendChild(marker);
      const input = document.createElement("input");
      input.id = inputId;
      input.name = "group_" + (groupIndex + 1) + "_attendee_" + (attendeeIndex + 1);
      input.type = "text";
      input.autocomplete = "off";
      input.maxLength = 120;
      input.required = true;
      input.dataset.groupParticipant = "";
      input.dataset.attendeeIndex = String(attendeeIndex);
      if (groupIndex === 0 && attendeeIndex === 0 && state.purchaserAttending) {
        state.participants[0] = purchaserFullName();
        input.readOnly = true;
      }
      input.value = state.participants[attendeeIndex] || "";
      field.append(label, input);
      container.appendChild(field);
    }
    const purchaserField = card.querySelector("[data-group-purchaser-field]");
    if (purchaserField) purchaserField.hidden = count < 1;
  }

  function createGroupCard(groupIndex, selected) {
    const state = statesByKind[selected.kind][groupIndex];
    const card = document.createElement("fieldset");
    card.className = "form-section";
    card.dataset.ticketGroupCard = "";
    card.dataset.groupIndex = String(groupIndex);
    const groupNumber = groupIndex + 1;
    const unitLabel = selected.kind === "single" ? "Single ticket" : "Couple package";
    const legend = document.createElement("legend");
    legend.textContent = unitLabel + " " + groupNumber;
    card.appendChild(legend);

    if (selected.kind === "couple") {
      const countField = document.createElement("div");
      countField.className = "form-field";
      const countLabel = document.createElement("label");
      const countId = "gala-group-" + groupNumber + "-attendee-count";
      countLabel.htmlFor = countId;
      countLabel.append(document.createTextNode("Number attending for this couple package "));
      const marker = document.createElement("span");
      marker.setAttribute("aria-hidden", "true");
      marker.textContent = "*";
      countLabel.appendChild(marker);
      const select = document.createElement("select");
      select.id = countId;
      select.name = "group_" + groupNumber + "_attendee_count";
      select.required = true;
      select.dataset.groupAttendeeCount = "";
      [{ value: "", label: "Choose 1 or 2 attendees" }, { value: "1", label: "1 attendee" }, { value: "2", label: "2 attendees" }]
        .forEach(function (optionData) {
          const option = document.createElement("option");
          option.value = optionData.value;
          option.textContent = optionData.label;
          select.appendChild(option);
        });
      select.value = state.attendeeCount;
      countField.append(countLabel, select);
      card.appendChild(countField);
    } else {
      const fixedCount = document.createElement("p");
      fixedCount.className = "form-help";
      fixedCount.textContent = "This ticket admits one attendee.";
      card.appendChild(fixedCount);
    }

    if (groupIndex === 0) {
      const purchaserField = document.createElement("label");
      purchaserField.className = "consent-field";
      purchaserField.dataset.groupPurchaserField = "";
      purchaserField.hidden = groupAttendeeCount(state, selected.kind) < 1;
      const purchaserControl = document.createElement("input");
      purchaserControl.id = "gala-first-purchaser-is-attending";
      purchaserControl.type = "checkbox";
      purchaserControl.dataset.groupPurchaserAttending = "";
      purchaserControl.checked = state.purchaserAttending;
      const purchaserText = document.createElement("span");
      purchaserText.textContent = "The purchaser is attending — use their first and last name for Attendee 1 in the first "
        + (selected.kind === "single" ? "ticket." : "couple package.");
      purchaserField.append(purchaserControl, purchaserText);
      card.appendChild(purchaserField);
    }

    const participants = document.createElement("div");
    participants.className = "participant-grid";
    participants.dataset.groupParticipants = "";
    card.appendChild(participants);
    renderGroupParticipants(card, groupIndex, selected);
    return card;
  }

  function renderTicketGroups(selected) {
    const quantity = selected ? selectedQuantity() : 0;
    ticketGroupList.replaceChildren();
    if (!selected || !quantity) return;
    ensureGroupStates(selected.kind, quantity);
    for (let groupIndex = 0; groupIndex < quantity; groupIndex += 1) {
      ticketGroupList.appendChild(createGroupCard(groupIndex, selected));
    }
  }

  function groupMetrics(selected) {
    const quantity = selected ? selectedQuantity() : 0;
    if (!selected || !quantity) return { quantity: 0, complete: false, attendees: 0 };
    ensureGroupStates(selected.kind, quantity);
    const states = statesByKind[selected.kind].slice(0, quantity);
    const counts = states.map(function (state) { return groupAttendeeCount(state, selected.kind); });
    return {
      quantity: quantity,
      complete: counts.every(function (count) { return count > 0; }),
      attendees: counts.reduce(function (total, count) { return total + count; }, 0)
    };
  }

  function quantityUnitText(selected, quantity) {
    if (!selected) return "";
    if (selected.kind === "single") return quantity === 1 ? "single ticket" : "single tickets";
    return quantity === 1 ? "couple package" : "couple packages";
  }

  function updateSummary(selected) {
    if (!selected) {
      summaryPackage.textContent = "Choose an option";
      summaryIncludes.textContent = "—";
      summaryQuantity.textContent = "—";
      summaryAttendees.textContent = "—";
      summaryCalculation.textContent = "—";
      summaryPrice.textContent = "—";
      taxDisclosure.hidden = true;
      return;
    }

    const metrics = groupMetrics(selected);
    const config = configurationReady ? selected.config : null;
    const unitAmount = config ? config.amount_cents : selected.amountCents;
    summaryPackage.textContent = config ? config.name : selected.name;
    summaryIncludes.textContent = selected.includes;
    summaryQuantity.textContent = metrics.quantity ? String(metrics.quantity) : "—";
    summaryAttendees.textContent = metrics.complete ? String(metrics.attendees) : "—";
    if (metrics.quantity) {
      summaryCalculation.textContent = money.format(unitAmount / 100) + " × " + metrics.quantity + " "
        + quantityUnitText(selected, metrics.quantity);
      summaryPrice.textContent = money.format((unitAmount * metrics.quantity) / 100);
    } else {
      summaryCalculation.textContent = "—";
      summaryPrice.textContent = "—";
    }

    if (config && metrics.quantity) {
      taxDisclosure.hidden = false;
      benefitDescription.textContent = (metrics.quantity > 1 ? metrics.quantity + " × " : "") + config.benefit_description;
      fairMarketValue.textContent = money.format((config.fair_market_value_cents * metrics.quantity) / 100);
      maximumDeductible.textContent = money.format((config.max_deductible_cents * metrics.quantity) / 100);
    } else {
      taxDisclosure.hidden = true;
    }
  }

  function updatePackage() {
    if (activeKind) quantityByKind[activeKind] = packageQuantity.value;
    const selected = selectedPackage();
    activeKind = selected ? selected.kind : "";

    ticketGroupSection.hidden = !selected;
    packageQuantity.disabled = !selected;
    packageQuantity.required = Boolean(selected);
    if (selected) {
      packageQuantity.value = quantityByKind[selected.kind] || "";
      quantityLabel.textContent = selected.kind === "single"
        ? "Number of single tickets"
        : "Number of couple packages";
      quantityHelp.textContent = "The selected admission price applies to each "
        + (selected.kind === "single" ? "ticket." : "couple package.")
        + " Purchase up to 10 in this payment.";
    } else {
      packageQuantity.value = "";
      quantityLabel.textContent = "Number of admissions";
      quantityHelp.textContent = "The selected admission price applies to each unit.";
    }

    packageRadios.forEach(function (radio) {
      const option = radio.closest(".package-option");
      if (option) option.classList.toggle("is-selected", radio.checked);
    });
    renderTicketGroups(selected);
    updateSummary(selected);
  }

  function validPublicPackage(item, code, participantMin, participantMax) {
    return item
      && item.code === code
      && typeof item.name === "string" && Boolean(item.name.trim())
      && Number.isInteger(item.amount_cents) && item.amount_cents > 0
      && item.participant_min === participantMin
      && item.participant_max === participantMax
      && Array.isArray(item.allowed_addons) && item.allowed_addons.length === 0
      && typeof item.benefit_description === "string" && Boolean(item.benefit_description.trim())
      && Number.isInteger(item.fair_market_value_cents) && item.fair_market_value_cents >= 0
      && Number.isInteger(item.max_deductible_cents) && item.max_deductible_cents >= 0
      && item.max_deductible_cents === Math.max(0, item.amount_cents - item.fair_market_value_cents);
  }

  function installPublicConfiguration(data) {
    if (!data || data.ok !== true || data.event_code !== "gala-2026" || data.currency !== "USD" || !Array.isArray(data.packages)) {
      throw new Error("The admission configuration is incomplete.");
    }
    const validated = {};
    Object.keys(packages).forEach(function (code) {
      const item = data.packages.find(function (candidate) { return candidate && candidate.code === code; });
      const packageData = packages[code];
      if (!validPublicPackage(item, code, packageData.participantMin, packageData.participantMax)) {
        throw new Error("The admission configuration is incomplete.");
      }
      validated[code] = item;
    });
    Object.keys(packages).forEach(function (code) {
      const item = validated[code];
      const packageData = packages[code];
      packageData.config = item;
      packageData.name = item.name.trim();
      const nameNode = document.querySelector('[data-package-name="' + code + '"]');
      const priceNode = document.querySelector('[data-package-price="' + code + '"]');
      if (nameNode) nameNode.textContent = item.name.trim();
      if (priceNode) priceNode.textContent = money.format(item.amount_cents / 100);
    });
  }

  async function loadPublicConfiguration() {
    const controller = new AbortController();
    const timeout = window.setTimeout(function () { controller.abort(); }, 15000);
    try {
      const response = await fetch("api/event-options.php?event_code=gala-2026", {
        method: "GET",
        credentials: "same-origin",
        headers: { "Accept": "application/json" },
        cache: "no-store",
        signal: controller.signal
      });
      let data = null;
      try {
        data = await response.json();
      } catch (error) {
        data = null;
      }
      if (!response.ok) throw new Error("The admission configuration is not available.");
      installPublicConfiguration(data);
      configurationReady = true;
      reviewOnly = false;
      configStatus.hidden = true;
      configStatus.textContent = "";
      submitLabel.textContent = "Continue to secure payment";
      submitButton.disabled = false;
      updatePackage();
    } catch (error) {
      const copy = unavailableCopy();
      configurationReady = false;
      reviewOnly = true;
      configStatus.hidden = false;
      configStatus.classList.remove("form-alert--error");
      configStatus.setAttribute("role", "status");
      configStatus.textContent = copy.status;
      submitLabel.textContent = copy.button;
      submitButton.disabled = true;
      updatePackage();
    } finally {
      window.clearTimeout(timeout);
    }
  }

  function showError(message) {
    errorAlert.textContent = message;
    errorAlert.hidden = false;
    errorAlert.focus();
  }

  function clearError() {
    errorAlert.hidden = true;
    errorAlert.textContent = "";
  }

  function fieldForError(key) {
    const fields = {
      package_code: "[data-package-radio]",
      package_quantity: "[data-package-quantity]",
      ticket_group_count: "[data-package-quantity]",
      "payer.first_name": "#payer-first-name",
      "payer.last_name": "#payer-last-name",
      "payer.company": "#payer-company",
      "payer.email": "#payer-email",
      "payer.phone": "#payer-phone",
      "payer.address_line1": "#payer-address-line1",
      "payer.city": "#payer-city",
      "payer.state": "#payer-state",
      "payer.postal_code": "#payer-postal-code",
      "registration.notes": "#registration-notes",
      consent: "#registration-consent"
    };
    if (/^ticket_groups(?:\.|$)/.test(key)) {
      const groupMatch = key.match(/^ticket_groups\.(\d+)(?:\.(.*))?$/);
      const groupIndex = groupMatch ? Number(groupMatch[1]) : 0;
      const suffix = groupMatch && groupMatch[2] ? groupMatch[2] : "";
      const card = ticketGroupList.querySelector('[data-group-index="' + groupIndex + '"]');
      if (!card) return packageQuantity;
      if (suffix === "attendee_count") return card.querySelector("[data-group-attendee-count]") || packageQuantity;
      const participantMatch = suffix.match(/^participants\.(\d+)\.name$/);
      if (participantMatch) {
        return card.querySelector('[data-attendee-index="' + Number(participantMatch[1]) + '"]')
          || card.querySelector("[data-group-attendee-count]")
          || packageQuantity;
      }
      return card.querySelector("[data-group-attendee-count], [data-group-participant]") || packageQuantity;
    }
    if (/^participants(?:\.|$)/.test(key)) {
      return ticketGroupList.querySelector("[data-group-participant]") || packageQuantity;
    }
    return fields[key] ? form.querySelector(fields[key]) : null;
  }

  function clearServerFieldErrors() {
    form.querySelectorAll("[data-server-field-error]").forEach(function (node) { node.remove(); });
    form.querySelectorAll("[data-server-invalid]").forEach(function (control) {
      control.removeAttribute("aria-invalid");
      const original = control.dataset.serverOriginalDescribedby || "";
      if (original) control.setAttribute("aria-describedby", original);
      else control.removeAttribute("aria-describedby");
      delete control.dataset.serverOriginalDescribedby;
      delete control.dataset.serverInvalid;
    });
  }

  function applyServerFieldErrors(fields) {
    if (!fields || typeof fields !== "object") return;
    let index = 0;
    Object.keys(fields).forEach(function (key) {
      const control = fieldForError(key);
      const fieldMessage = fields[key];
      if (!control || typeof fieldMessage !== "string" || !fieldMessage.trim()) return;
      index += 1;
      const errorId = "gala-registration-field-error-" + index;
      const errorNode = document.createElement("small");
      errorNode.id = errorId;
      errorNode.className = "form-field-error";
      errorNode.dataset.serverFieldError = "true";
      errorNode.textContent = fieldMessage.trim();
      const controls = key === "package_code" ? packageRadios : [control];
      controls.forEach(function (item) {
        if (!Object.prototype.hasOwnProperty.call(item.dataset, "serverOriginalDescribedby")) {
          item.dataset.serverOriginalDescribedby = item.getAttribute("aria-describedby") || "";
        }
        item.setAttribute("aria-describedby", [item.dataset.serverOriginalDescribedby, errorId].filter(Boolean).join(" "));
        item.setAttribute("aria-invalid", "true");
        item.dataset.serverInvalid = "true";
      });
      const container = key === "package_code"
        ? form.querySelector(".package-grid")
        : control.closest(".form-field, .consent-field") || control.parentElement;
      if (container) container.insertAdjacentElement("afterend", errorNode);
    });
  }

  function setSubmitting(isSubmitting) {
    form.setAttribute("aria-busy", String(isSubmitting));
    submitButton.disabled = isSubmitting || !configurationReady;
    submitLabel.textContent = isSubmitting
      ? "Opening secure checkout…"
      : configurationReady ? "Continue to secure payment" : unavailableCopy().button;
    submitSpinner.hidden = !isSubmitting;
  }

  function value(selector) {
    const field = form.querySelector(selector);
    return field ? field.value.trim() : "";
  }

  function serverMessage(data, response) {
    if (data && typeof data.message === "string" && data.message.trim()) return data.message.trim();
    if (data && data.error && typeof data.error.message === "string" && data.error.message.trim()) return data.error.message.trim();
    if (data && typeof data.error === "string" && data.error.trim()) return data.error.trim();
    if (response.status === 429) return "Too many attempts were received. Please wait a moment and try again.";
    if (response.status >= 500) return "Registration is temporarily unavailable. Please try again in a few minutes.";
    return "We couldn’t start checkout. Please review your information and try again.";
  }

  function checkoutUrl(value) {
    if (typeof value !== "string" || !value.trim()) return null;
    try {
      const url = new URL(value, window.location.href);
      const host = url.hostname.toLowerCase();
      const squareHost = ["square.link", "square.site", "squareup.com"].some(function (domain) {
        return host === domain || host.endsWith("." + domain);
      });
      if (url.protocol !== "https:" || !squareHost || url.username || url.password || url.port) return null;
      return url.href;
    } catch (error) {
      return null;
    }
  }

  packageRadios.forEach(function (radio) { radio.addEventListener("change", updatePackage); });
  packageQuantity.addEventListener("change", function () {
    if (activeKind) quantityByKind[activeKind] = packageQuantity.value;
    const selected = selectedPackage();
    renderTicketGroups(selected);
    updateSummary(selected);
  });

  ticketGroupList.addEventListener("input", function (event) {
    const target = event.target;
    const card = target.closest("[data-ticket-group-card]");
    const selected = selectedPackage();
    if (!card || !selected) return;
    const groupIndex = Number(card.dataset.groupIndex);
    const state = statesByKind[selected.kind][groupIndex];
    if (!state || !target.matches("[data-group-participant]")) return;
    const attendeeIndex = Number(target.dataset.attendeeIndex);
    state.participants[attendeeIndex] = target.value;
    if (groupIndex === 0 && attendeeIndex === 0 && !state.purchaserAttending) {
      state.manualAttendeeOne = target.value;
    }
  });

  ticketGroupList.addEventListener("change", function (event) {
    const target = event.target;
    const card = target.closest("[data-ticket-group-card]");
    const selected = selectedPackage();
    if (!card || !selected) return;
    const groupIndex = Number(card.dataset.groupIndex);
    const state = statesByKind[selected.kind][groupIndex];
    if (!state) return;
    if (target.matches("[data-group-attendee-count]")) {
      state.attendeeCount = target.value;
      renderGroupParticipants(card, groupIndex, selected);
      updateSummary(selected);
      return;
    }
    if (target.matches("[data-group-purchaser-attending]")) {
      const attendeeOne = card.querySelector('[data-attendee-index="0"]');
      if (target.checked) {
        state.manualAttendeeOne = attendeeOne ? attendeeOne.value : state.participants[0];
        state.purchaserAttending = true;
        state.participants[0] = purchaserFullName();
      } else {
        state.purchaserAttending = false;
        state.participants[0] = state.manualAttendeeOne;
      }
      renderGroupParticipants(card, groupIndex, selected);
    }
  });

  [payerFirstName, payerLastName].forEach(function (input) {
    input.addEventListener("input", syncPurchaserAttendees);
  });

  form.noValidate = true;
  form.addEventListener("submit", async function (event) {
    event.preventDefault();
    clearError();
    clearServerFieldErrors();
    form.classList.add("was-validated");

    if (!form.checkValidity()) {
      showError("Please complete the required fields before continuing.");
      form.reportValidity();
      const firstInvalid = form.querySelector(":invalid");
      if (firstInvalid) firstInvalid.focus();
      return;
    }
    if (!configurationReady) {
      showError(reviewOnly
        ? unavailableCopy().status
        : "Online checkout is not available until COMEC’s approved admission benefit values can be displayed.");
      return;
    }

    const code = selectedPackageCode();
    const selected = packages[code];
    const selectedConfig = selected && selected.config;
    if (!selected || !selectedConfig) {
      showError("Please choose an admission option before continuing.");
      return;
    }
    if (!idempotencyKey) {
      showError("This browser cannot securely start checkout. Please update your browser or call COMEC at 901-222-0700.");
      return;
    }

    const quantity = selectedQuantity();
    if (quantity < 1 || quantity > 10) {
      showError("Please choose how many admission units you are purchasing.");
      packageQuantity.focus();
      return;
    }
    ensureGroupStates(selected.kind, quantity);
    const ticketGroups = [];
    for (let groupIndex = 0; groupIndex < quantity; groupIndex += 1) {
      const state = statesByKind[selected.kind][groupIndex];
      const attendeeCount = groupAttendeeCount(state, selected.kind);
      const card = ticketGroupList.querySelector('[data-group-index="' + groupIndex + '"]');
      if (attendeeCount < selectedConfig.participant_min || attendeeCount > selectedConfig.participant_max) {
        showError("Please choose how many guests are attending for every couple package.");
        const countField = card && card.querySelector("[data-group-attendee-count]");
        if (countField) countField.focus();
        return;
      }
      const names = state.participants.slice(0, attendeeCount).map(function (name) { return name.trim(); });
      const blankName = names.findIndex(function (name) { return !name; });
      if (blankName !== -1) {
        showError("Please enter the name of every attendee.");
        const nameField = card && card.querySelector('[data-attendee-index="' + blankName + '"]');
        if (nameField) nameField.focus();
        return;
      }
      ticketGroups.push({
        participants: names.map(function (name) { return { name: name }; })
      });
    }

    const payload = {
      event_code: "gala-2026",
      package_code: code,
      payer: {
        first_name: value("#payer-first-name"),
        last_name: value("#payer-last-name"),
        company: value("#payer-company"),
        email: value("#payer-email"),
        phone: value("#payer-phone"),
        address_line1: value("#payer-address-line1"),
        city: value("#payer-city"),
        state: value("#payer-state"),
        postal_code: value("#payer-postal-code")
      },
      registration: {
        notes: value("#registration-notes")
      },
      participants: [],
      addons: [],
      teams: [],
      ticket_groups: ticketGroups,
      consent: consent.checked,
      website: honeypot.value
    };

    setSubmitting(true);
    const controller = new AbortController();
    const timeout = window.setTimeout(function () { controller.abort(); }, 25000);
    try {
      const response = await fetch(form.action, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "Idempotency-Key": idempotencyKey
        },
        body: JSON.stringify(payload),
        signal: controller.signal
      });
      let data = null;
      try {
        data = await response.json();
      } catch (error) {
        data = null;
      }
      if (!response.ok) {
        const requestError = new Error(serverMessage(data, response));
        requestError.fields = data && data.error && data.error.fields ? data.error.fields : null;
        throw requestError;
      }
      const destination = checkoutUrl(data && data.checkout_url);
      if (!destination) throw new Error("Checkout could not be opened. Please try again or call COMEC at 901-222-0700.");
      window.location.assign(destination);
    } catch (error) {
      if (error && error.name === "AbortError") {
        showError("The request took too long. Please check your connection and try again.");
      } else if (error && (error.name === "TypeError" || /failed to fetch|networkerror|load failed/i.test(error.message || ""))) {
        showError("We couldn’t reach secure checkout. Please check your connection and try again.");
      } else {
        showError(error && error.message ? error.message : "We couldn’t start checkout. Please try again.");
        if (error && error.fields) applyServerFieldErrors(error.fields);
      }
      setSubmitting(false);
    } finally {
      window.clearTimeout(timeout);
    }
  });

  if (requestedPackage && packages[requestedPackage]) {
    const requestedRadio = packageRadios.find(function (radio) { return radio.value === requestedPackage; });
    if (requestedRadio) requestedRadio.checked = true;
  }

  updatePackage();
  loadPublicConfiguration();
})();
