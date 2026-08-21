(function () {
  "use strict";

  const form = document.querySelector("[data-registration-form]");
  if (!form) return;

  const packages = {
    corporate_sponsor: {
      name: "Corporate sponsor",
      apiName: "Corporate Sponsor",
      price: null,
      previewPrice: 100000,
      includes: "Four-player team with cart and a hole sign",
      participantMin: 1,
      participantMax: 4,
      team: true,
      sponsor: true
    },
    contest_sponsor: {
      name: "Contest sponsor",
      apiName: "Contest Sponsor",
      price: null,
      previewPrice: 50000,
      includes: "Signage for one tournament contest",
      participantMin: 0,
      participantMax: 0,
      sponsor: true,
      contest: true
    },
    drink_cart_sponsor: {
      name: "Drink-cart sponsor",
      apiName: "Drink-Cart Sponsor",
      price: null,
      previewPrice: 50000,
      includes: "Recognition on the tournament drink cart",
      participantMin: 0,
      participantMax: 0,
      sponsor: true
    },
    team_sponsor: {
      name: "Team sponsor",
      apiName: "Team Sponsor",
      price: null,
      previewPrice: 40000,
      includes: "Four-player team with cart",
      participantMin: 1,
      participantMax: 4,
      team: true,
      sponsor: true
    },
    hole_sponsor: {
      name: "Hole sponsor",
      apiName: "Hole Sponsor",
      price: null,
      previewPrice: 25000,
      includes: "Recognition with signage at one hole",
      participantMin: 0,
      participantMax: 0,
      sponsor: true
    },
    individual_player: {
      name: "Individual player",
      apiName: "Individual Player (Advance Registration)",
      price: null,
      previewPrice: 10000,
      includes: "Advance registration for one player",
      participantMin: 1,
      participantMax: 1,
      individual: true
    }
  };

  const previewMulliganConfig = {
    code: "team_mulligans",
    name: "Eight Team Mulligans",
    price: 4000,
    includes: "Eight team mulligans"
  };

  const packageRadios = Array.from(form.querySelectorAll("[data-package-radio]"));
  const teamSection = form.querySelector("[data-team-section]");
  const golferSection = form.querySelector("[data-golfer-section]");
  const sponsorSection = form.querySelector("[data-sponsor-section]");
  const contestField = form.querySelector("[data-contest-field]");
  const teamCount = form.querySelector("#team-count");
  const teamList = form.querySelector("[data-team-list]");
  const sponsorDisplay = form.querySelector("#sponsor-display");
  const contestChoice = form.querySelector("#contest-choice");
  const individualPlayers = Array.from(form.querySelectorAll("[data-participant]"));
  const golferFields = Array.from(form.querySelectorAll("[data-golfer-field]"));
  const golferCount = form.querySelector("#golfer-count");
  const golferCountHelp = form.querySelector("[data-golfer-count-help]");
  const purchaserPlaying = form.querySelector("[data-purchaser-playing]");
  const purchaserPlayingField = form.querySelector("[data-purchaser-playing-field]");
  const payerFirstName = form.querySelector("#payer-first-name");
  const payerLastName = form.querySelector("#payer-last-name");
  const noGolfers = form.querySelector("[data-no-golfers]");
  const consent = form.querySelector("#registration-consent");
  const honeypot = form.querySelector("#registration-website");
  const finalStep = form.querySelector("[data-final-step]");
  const teamStep = form.querySelector("[data-team-step]");
  const golferStep = form.querySelector("[data-golfer-step]");
  const sponsorStep = form.querySelector("[data-sponsor-step]");
  const errorAlert = document.querySelector("[data-form-error]");
  const submitButton = form.querySelector("[data-submit-button]");
  const submitLabel = form.querySelector("[data-submit-label]");
  const submitSpinner = form.querySelector("[data-submit-spinner]");
  const summaryPackage = document.querySelector("[data-summary-package]");
  const summaryIncludes = document.querySelector("[data-summary-includes]");
  const summaryTeamRow = document.querySelector("[data-summary-team-row]");
  const summaryTeams = document.querySelector("[data-summary-teams]");
  const summaryGolfers = document.querySelector("[data-summary-golfers]");
  const summaryPrice = document.querySelector("[data-summary-price]");
  const summaryMulliganRow = document.querySelector("[data-summary-mulligan-row]");
  const summaryMulligans = document.querySelector("[data-summary-mulligans]");
  const summaryCalculationRow = document.querySelector("[data-summary-calculation-row]");
  const summaryCalculation = document.querySelector("[data-summary-calculation]");
  const mobileSummaryPackage = form.querySelector("[data-mobile-summary-package]");
  const mobileSummaryTeamRow = form.querySelector("[data-mobile-summary-team-row]");
  const mobileSummaryTeams = form.querySelector("[data-mobile-summary-teams]");
  const mobileSummaryGolfers = form.querySelector("[data-mobile-summary-golfers]");
  const mobileSummaryPrice = form.querySelector("[data-mobile-summary-price]");
  const mobileSummaryMulliganRow = form.querySelector("[data-mobile-summary-mulligan-row]");
  const mobileSummaryMulligans = form.querySelector("[data-mobile-summary-mulligans]");
  const mobileSummaryCalculationRow = form.querySelector("[data-mobile-summary-calculation-row]");
  const mobileSummaryCalculation = form.querySelector("[data-mobile-summary-calculation]");
  const configStatus = form.querySelector("[data-config-status]");
  const taxDisclosure = form.querySelector("[data-tax-disclosure]");
  const taxPrompt = form.querySelector("[data-tax-prompt]");
  const taxDetails = form.querySelector("[data-tax-details]");
  const taxBenefits = form.querySelector("[data-tax-benefits]");
  const taxPayment = form.querySelector("[data-tax-payment]");
  const taxFmv = form.querySelector("[data-tax-fmv]");
  const taxDeductible = form.querySelector("[data-tax-deductible]");
  const paymentConfirmationNotice = form.querySelector("[data-payment-confirmation-notice]");

  const money = new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: "USD",
    maximumFractionDigits: 0
  });
  const idempotencyKey = createIdempotencyKey();
  const requestedPackage = new URLSearchParams(window.location.search).get("package");
  const benefitFmvMode = "benefit_fmv";
  const paymentConfirmationMode = "payment_confirmation_only";
  let publicConfigLoaded = false;
  let previewMode = false;
  let disclosureMode = "";
  let mulliganConfig = null;
  let individualManualPlayerValue = "";
  let currentSummaryTotal = "—";
  let submitting = false;
  const teamStates = [];

  const purchaserPlayingCopy = purchaserPlayingField && purchaserPlayingField.querySelector("span");
  if (purchaserPlayingCopy) purchaserPlayingCopy.textContent = "Use the purchaser’s name for Golfer 1.";

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

  function validMoney(value) {
    return Number.isInteger(value) && value >= 0;
  }

  function validBenefitOption(option) {
    return option
      && typeof option.name === "string"
      && option.name.trim() !== ""
      && typeof option.benefit_description === "string"
      && option.benefit_description.trim() !== ""
      && validMoney(option.amount_cents)
      && validMoney(option.fair_market_value_cents)
      && validMoney(option.max_deductible_cents)
      && option.max_deductible_cents === Math.max(0, option.amount_cents - option.fair_market_value_cents);
  }

  function hasNullTaxFields(option) {
    return option
      && option.benefit_description === null
      && option.fair_market_value_cents === null
      && option.max_deductible_cents === null;
  }

  function exactStringList(value, expected) {
    return Array.isArray(value)
      && value.length === expected.length
      && value.every(function (item, index) { return item === expected[index]; });
  }

  function validPaymentPackageOption(option, code, packageData) {
    const expectedMin = packageData.team || packageData.individual ? 1 : 0;
    const expectedMax = packageData.team ? 4 : packageData.individual ? 1 : 0;
    const expectedAddons = packageData.team ? ["team_mulligans"] : [];
    return option
      && option.code === code
      && option.name === packageData.apiName
      && option.amount_cents === packageData.previewPrice
      && option.participant_min === expectedMin
      && option.participant_max === expectedMax
      && exactStringList(option.allowed_addons, expectedAddons)
      && hasNullTaxFields(option);
  }

  function validPackageOption(option, code, packageData, mode) {
    if (mode === paymentConfirmationMode) {
      return validPaymentPackageOption(option, code, packageData);
    }
    if (mode !== benefitFmvMode
        || !validBenefitOption(option)
        || !Array.isArray(option.allowed_addons)
        || !Number.isInteger(option.participant_min)
        || !Number.isInteger(option.participant_max)) return false;
    const expectedMin = packageData.team || packageData.individual ? 1 : 0;
    const expectedMax = packageData.team ? 4 : packageData.individual ? 1 : 0;
    const rangeMatches = option.participant_min === expectedMin && option.participant_max === expectedMax;
    const teamAddonConfigured = !packageData.team || option.allowed_addons.includes("team_mulligans");
    return rangeMatches && teamAddonConfigured;
  }

  function validPaymentAddon(option) {
    return option
      && option.code === previewMulliganConfig.code
      && option.name === previewMulliganConfig.name
      && option.amount_cents === previewMulliganConfig.price
      && hasNullTaxFields(option);
  }

  function updatePackageCard(code) {
    const packageData = packages[code];
    const radio = packageRadios.find(function (item) { return item.value === code; });
    const optionElement = radio && radio.closest(".package-option");
    if (!optionElement) return;
    optionElement.querySelector(".package-option__top strong").textContent = packageData.name;
    optionElement.querySelector("[data-package-price]").textContent = money.format(packageData.price / 100)
      + (packageData.team ? " / team" : "");
    optionElement.querySelector("[data-package-benefit]").textContent = packageData.includes;
  }

  function updateTeamMulliganLabels() {
    if (!mulliganConfig) return;
    teamList.querySelectorAll("[data-team-addon-name]").forEach(function (node) {
      node.textContent = mulliganConfig.name;
    });
    teamList.querySelectorAll("[data-team-addon-price]").forEach(function (node) {
      node.textContent = "+" + money.format(mulliganConfig.price / 100);
    });
  }

  function applyRequestedPackage() {
    if (!requestedPackage || !packages[requestedPackage]) return;
    const requestedRadio = packageRadios.find(function (radio) { return radio.value === requestedPackage; });
    if (requestedRadio) requestedRadio.checked = true;
  }

  function previewEnvironment() {
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
    if (previewEnvironment()) {
      return {
        status: "Preview only — package and golfer controls are available for review. Payment is unavailable until the website’s server API is set up.",
        button: "Preview only — payment unavailable"
      };
    }
    return {
      status: "Online payment is temporarily unavailable. You may review package and golfer options, but checkout is disabled while server setup is completed.",
      button: "Online payment temporarily unavailable"
    };
  }

  function failPublicConfig(message) {
    enablePreviewMode(message);
  }

  function enablePreviewMode(detail) {
    const copy = unavailableCopy();
    const status = detail ? copy.status + " " + detail : copy.status;
    publicConfigLoaded = false;
    previewMode = true;
    submitting = false;
    disclosureMode = "";
    Object.keys(packages).forEach(function (code) {
      const packageData = packages[code];
      packageData.price = packageData.previewPrice;
      packageData.allowedAddons = packageData.team ? ["team_mulligans"] : [];
      updatePackageCard(code);
    });
    mulliganConfig = Object.assign({}, previewMulliganConfig);
    updateTeamMulliganLabels();
    packageRadios.forEach(function (radio) { radio.disabled = false; });
    submitButton.disabled = true;
    submitLabel.textContent = copy.button;
    form.setAttribute("aria-busy", "false");
    configStatus.textContent = status;
    taxDisclosure.hidden = false;
    taxDisclosure.setAttribute("aria-busy", "false");
    taxPrompt.hidden = false;
    taxPrompt.textContent = status;
    taxDetails.hidden = true;
    paymentConfirmationNotice.hidden = true;
    clearError();
    applyRequestedPackage();
    updatePackage();
  }

  async function loadPublicConfig() {
    form.setAttribute("aria-busy", "true");
    const controller = new AbortController();
    const timeout = window.setTimeout(function () { controller.abort(); }, 15000);
    let response;
    try {
      response = await fetch("api/event-options.php?event_code=golf-2026", {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers: { "Accept": "application/json" },
        signal: controller.signal
      });
    } catch (error) {
      window.clearTimeout(timeout);
      enablePreviewMode();
      return;
    }
    window.clearTimeout(timeout);

    let data = null;
    try {
      data = await response.json();
    } catch (error) {
      enablePreviewMode();
      return;
    }

    if (!response.ok || !data || data.ok !== true) {
      const missingBenefits = data && data.error && data.error.code === "benefit_configuration_required";
      if (missingBenefits) {
        failPublicConfig("Online checkout is unavailable until COMEC completes the required benefit and tax configuration. Please call 901-222-0700.");
      } else {
        enablePreviewMode();
      }
      return;
    }

    const mode = data.disclosure_mode;
    if ((mode !== paymentConfirmationMode && mode !== benefitFmvMode)
        || data.event_code !== "golf-2026"
        || data.currency !== "USD"
        || !Array.isArray(data.packages)
        || !Array.isArray(data.addons)) {
      failPublicConfig("Current registration prices and benefit details could not be verified. Online checkout is unavailable; please call 901-222-0700.");
      return;
    }

    const packageOptions = {};
    if (mode === paymentConfirmationMode
        && (data.packages.length !== Object.keys(packages).length || data.addons.length !== 1)) {
      failPublicConfig("Current registration options could not be verified. Online checkout is unavailable; please call 901-222-0700.");
      return;
    }
    for (const code of Object.keys(packages)) {
      const option = data.packages.find(function (item) { return item && item.code === code; });
      if (!validPackageOption(option, code, packages[code], mode)) {
        failPublicConfig("A required package benefit, attendance, or tax value is not configured. Online checkout is unavailable; please call 901-222-0700.");
        return;
      }
      packageOptions[code] = option;
    }

    const addon = data.addons.find(function (item) { return item && item.code === "team_mulligans"; });
    if (mode === paymentConfirmationMode ? !validPaymentAddon(addon) : !validBenefitOption(addon)) {
      failPublicConfig("The team mulligan benefit or tax value is not configured. Online checkout is unavailable; please call 901-222-0700.");
      return;
    }

    Object.keys(packages).forEach(function (code) {
      const option = packageOptions[code];
      const packageData = packages[code];
      packageData.name = option.name.trim();
      packageData.price = option.amount_cents;
      if (mode === benefitFmvMode) {
        packageData.includes = option.benefit_description.trim();
        packageData.fairMarketValue = option.fair_market_value_cents;
        packageData.maxDeductible = option.max_deductible_cents;
      }
      packageData.participantMin = option.participant_min;
      packageData.participantMax = option.participant_max;
      packageData.allowedAddons = option.allowed_addons.slice();
      updatePackageCard(code);
    });

    mulliganConfig = {
      code: "team_mulligans",
      name: addon.name.trim(),
      price: addon.amount_cents,
      includes: mode === benefitFmvMode ? addon.benefit_description.trim() : previewMulliganConfig.includes,
      fairMarketValue: mode === benefitFmvMode ? addon.fair_market_value_cents : null,
      maxDeductible: mode === benefitFmvMode ? addon.max_deductible_cents : null
    };
    updateTeamMulliganLabels();

    previewMode = false;
    publicConfigLoaded = true;
    disclosureMode = mode;
    packageRadios.forEach(function (radio) { radio.disabled = false; });
    submitButton.disabled = false;
    syncReadySubmitLabel();
    form.setAttribute("aria-busy", "false");
    configStatus.textContent = mode === paymentConfirmationMode
      ? "Current price and registration information loaded."
      : "Current price and benefit information loaded.";
    taxDisclosure.hidden = mode === paymentConfirmationMode;
    taxDisclosure.setAttribute("aria-busy", "false");
    paymentConfirmationNotice.hidden = mode !== paymentConfirmationMode;
    clearError();
    applyRequestedPackage();
    updatePackage();
  }

  function selectedPackageCode() {
    const checked = form.querySelector("[data-package-radio]:checked");
    return checked ? checked.value : "";
  }

  function readySubmitLabel() {
    const total = currentSummaryTotal && currentSummaryTotal !== "—"
      ? " — " + currentSummaryTotal
      : "";
    return "Continue to secure payment" + total;
  }

  function syncReadySubmitLabel() {
    if (publicConfigLoaded && !submitting) submitLabel.textContent = readySubmitLabel();
  }

  function setSummaryText(desktopNode, mobileNode, text) {
    if (desktopNode) desktopNode.textContent = text;
    if (mobileNode) mobileNode.textContent = text;
  }

  function setSummaryRowHidden(desktopNode, mobileNode, hidden) {
    if (desktopNode) desktopNode.hidden = hidden;
    if (mobileNode) mobileNode.hidden = hidden;
  }

  function setSummaryTotal(text) {
    currentSummaryTotal = text;
    setSummaryText(summaryPrice, mobileSummaryPrice, text);
    syncReadySubmitLabel();
  }

  function setSection(section, active) {
    if (!section) return;
    section.hidden = !active;
    section.querySelectorAll("input, select, textarea").forEach(function (control) {
      control.disabled = !active;
    });
  }

  function displayConfigReady() {
    return publicConfigLoaded || previewMode;
  }

  function selectedGolferCount() {
    const count = Number.parseInt(golferCount.value, 10);
    return Number.isInteger(count) ? count : 0;
  }

  function selectedTeamCount() {
    const count = Number.parseInt(teamCount.value, 10);
    return Number.isInteger(count) && count >= 1 && count <= 10 ? count : 0;
  }

  function purchaserName() {
    return [payerFirstName.value.trim(), payerLastName.value.trim()].filter(Boolean).join(" ");
  }

  function newTeamState() {
    return {
      name: "",
      golferCount: "",
      participants: ["", "", "", ""],
      mulligans: false,
      purchaserPlaying: false,
      manualPlayerOne: ""
    };
  }

  function ensureTeamStates(count) {
    while (teamStates.length < count) teamStates.push(newTeamState());
  }

  function allowsTeamMulligans(selected) {
    return Boolean(
      displayConfigReady()
      && selected
      && selected.team
      && Array.isArray(selected.allowedAddons)
      && selected.allowedAddons.includes("team_mulligans")
      && mulliganConfig
    );
  }

  function syncPurchaserPlayers() {
    const name = purchaserName();
    if (purchaserPlaying.checked && individualPlayers[0]) {
      individualPlayers[0].value = name;
    }
    if (teamStates[0] && teamStates[0].purchaserPlaying) {
      teamStates[0].participants[0] = name;
      const teamPlayer = teamList.querySelector('[data-team-index="0"] [data-team-player-index="0"]');
      if (teamPlayer) teamPlayer.value = name;
    }
  }

  function configureGolferCount(selected) {
    const isIndividual = Boolean(selected && selected.individual);
    golferSection.hidden = !isIndividual;
    golferCount.disabled = !isIndividual;
    golferCount.required = isIndividual;

    Array.from(golferCount.options).forEach(function (option) {
      const value = Number.parseInt(option.value, 10);
      if (!isIndividual) {
        option.hidden = false;
        option.disabled = false;
      } else if (!option.value) {
        option.hidden = true;
        option.disabled = true;
      } else {
        const allowed = value >= selected.participantMin && value <= selected.participantMax;
        option.hidden = !allowed;
        option.disabled = !allowed;
      }
    });

    if (!isIndividual) {
      golferCount.value = "";
      golferCountHelp.textContent = "Choose the number of golfers attending.";
    } else {
      golferCount.value = "1";
      golferCountHelp.textContent = "This package registers one golfer.";
    }
  }

  function updateGolferFields(selected) {
    const isIndividual = Boolean(selected && selected.individual);
    const count = isIndividual ? selectedGolferCount() : 0;
    golferFields.forEach(function (field, index) {
      const active = isIndividual && index < count;
      field.hidden = !active;
      const player = individualPlayers[index];
      if (player) {
        player.disabled = !active;
        player.required = active;
      }
    });

    const hasFirstGolfer = isIndividual && count > 0;
    purchaserPlayingField.hidden = !hasFirstGolfer;
    purchaserPlaying.disabled = !hasFirstGolfer;
    if (individualPlayers[0]) {
      individualPlayers[0].readOnly = hasFirstGolfer && purchaserPlaying.checked;
    }
    if (hasFirstGolfer) syncPurchaserPlayers();
  }

  function renderTeamParticipants(card, teamIndex) {
    const state = teamStates[teamIndex];
    const container = card.querySelector("[data-team-participants]");
    const count = Number.parseInt(state.golferCount, 10) || 0;
    container.replaceChildren();
    for (let playerIndex = 0; playerIndex < count; playerIndex += 1) {
      const field = document.createElement("div");
      field.className = "form-field";
      const label = document.createElement("label");
      const inputId = "team-" + (teamIndex + 1) + "-player-" + (playerIndex + 1);
      label.htmlFor = inputId;
      label.append(document.createTextNode("Golfer " + (playerIndex + 1) + " "));
      const requiredMarker = document.createElement("span");
      requiredMarker.setAttribute("aria-hidden", "true");
      requiredMarker.textContent = "*";
      label.append(requiredMarker);
      const input = document.createElement("input");
      input.id = inputId;
      input.name = "team_" + (teamIndex + 1) + "_player_" + (playerIndex + 1);
      input.type = "text";
      input.autocomplete = "off";
      input.setAttribute("autocapitalize", "words");
      input.maxLength = 120;
      input.required = true;
      input.dataset.teamParticipant = "";
      input.dataset.teamPlayerIndex = String(playerIndex);
      if (teamIndex === 0 && playerIndex === 0 && state.purchaserPlaying) {
        state.participants[0] = purchaserName();
        input.readOnly = true;
      }
      input.value = state.participants[playerIndex] || "";
      field.append(label, input);
      container.append(field);
    }
    const purchaserField = card.querySelector("[data-team-purchaser-field]");
    if (purchaserField) purchaserField.hidden = count < 1;
  }

  function createTeamCard(teamIndex, selected, teamTotal) {
    const state = teamStates[teamIndex];
    const card = document.createElement("fieldset");
    card.className = "form-section registration-unit-card";
    card.dataset.teamCard = "";
    card.dataset.teamIndex = String(teamIndex);
    const teamNumber = teamIndex + 1;
    card.innerHTML = '<legend>Team ' + teamNumber + ' of ' + teamTotal + '</legend>'
      + '<div class="form-grid form-grid--2">'
      + '<div class="form-field"><label for="team-' + teamNumber + '-name">Team ' + teamNumber + ' name <span aria-hidden="true">*</span></label>'
      + '<input id="team-' + teamNumber + '-name" name="team_' + teamNumber + '_name" type="text" autocapitalize="words" maxlength="120" required data-team-name></div>'
      + '<div class="form-field"><label for="team-' + teamNumber + '-golfer-count">Number of golfers <span aria-hidden="true">*</span></label>'
      + '<select id="team-' + teamNumber + '-golfer-count" name="team_' + teamNumber + '_golfer_count" required data-team-golfer-count>'
      + '<option value="">Choose 1–4 golfers</option><option value="1">1 golfer</option><option value="2">2 golfers</option><option value="3">3 golfers</option><option value="4">4 golfers</option></select></div></div>'
      + (teamIndex === 0
        ? '<label class="consent-field" data-team-purchaser-field hidden><input id="team-1-purchaser-is-playing" type="checkbox" data-team-purchaser-playing><span>Use the purchaser&rsquo;s name for Team 1, Golfer 1.</span></label>'
        : '')
      + '<div class="participant-grid" data-team-participants></div>'
      + '<label class="addon-option" data-team-addon-option><input id="team-' + teamNumber + '-mulligans" name="team_' + teamNumber + '_mulligans" type="checkbox" value="team_mulligans" data-team-mulligans>'
      + '<span><span><strong data-team-addon-name>Eight team mulligans</strong><small>Optional add-on for Team ' + teamNumber + '</small></span><b data-team-addon-price>Loading…</b></span></label>';

    card.querySelector("[data-team-name]").value = state.name;
    card.querySelector("[data-team-golfer-count]").value = state.golferCount;
    const purchaserControl = card.querySelector("[data-team-purchaser-playing]");
    if (purchaserControl) purchaserControl.checked = state.purchaserPlaying;
    const allowsMulligans = allowsTeamMulligans(selected);
    const addonOption = card.querySelector("[data-team-addon-option]");
    const addonControl = card.querySelector("[data-team-mulligans]");
    addonOption.hidden = !allowsMulligans;
    addonControl.disabled = !allowsMulligans;
    if (!allowsMulligans) state.mulligans = false;
    addonControl.checked = state.mulligans;
    renderTeamParticipants(card, teamIndex);
    return card;
  }

  function renderTeams(selected) {
    const count = selected && selected.team ? selectedTeamCount() : 0;
    ensureTeamStates(count);
    teamList.replaceChildren();
    for (let teamIndex = 0; teamIndex < count; teamIndex += 1) {
      teamList.append(createTeamCard(teamIndex, selected, count));
    }
    updateTeamMulliganLabels();
  }

  function teamMetrics(selected) {
    const count = selected && selected.team ? selectedTeamCount() : 0;
    ensureTeamStates(count);
    const activeStates = teamStates.slice(0, count);
    const golferCounts = activeStates.map(function (state) {
      const value = Number.parseInt(state.golferCount, 10);
      return Number.isInteger(value) && value >= 1 && value <= 4 ? value : 0;
    });
    return {
      count: count,
      golfersComplete: count > 0 && golferCounts.every(function (value) { return value > 0; }),
      golfers: golferCounts.reduce(function (total, value) { return total + value; }, 0),
      mulligans: activeStates.filter(function (state) { return state.mulligans; }).length
    };
  }

  function updateTaxDisclosure(selected, teamTotal, mulliganTeams) {
    if (publicConfigLoaded && disclosureMode === paymentConfirmationMode) {
      taxDisclosure.hidden = true;
      paymentConfirmationNotice.hidden = false;
      return;
    }
    taxDisclosure.hidden = false;
    paymentConfirmationNotice.hidden = true;
    taxDisclosure.setAttribute("aria-busy", String(!displayConfigReady()));
    if (previewMode) {
      taxPrompt.hidden = false;
      taxPrompt.textContent = unavailableCopy().status;
      taxDetails.hidden = true;
      return;
    }
    if (!publicConfigLoaded) {
      taxPrompt.hidden = false;
      taxPrompt.textContent = "Current benefit information must load before checkout can begin.";
      taxDetails.hidden = true;
      return;
    }
    if (!selected) {
      taxPrompt.hidden = false;
      taxPrompt.textContent = "Choose a registration option to review its benefit and tax information.";
      taxDetails.hidden = true;
      return;
    }

    if (selected.team && teamTotal < 1) {
      taxPrompt.hidden = false;
      taxPrompt.textContent = "Choose the number of teams to review the total benefit and tax information.";
      taxDetails.hidden = true;
      return;
    }

    const packageUnits = selected.team ? teamTotal : 1;
    const benefits = [(packageUnits > 1 ? packageUnits + " × " : "") + selected.includes];
    let payment = selected.price * packageUnits;
    let fairMarketValue = selected.fairMarketValue * packageUnits;
    if (mulliganTeams > 0) {
      benefits.push((mulliganTeams > 1 ? mulliganTeams + " × " : "") + mulliganConfig.includes);
      payment += mulliganConfig.price * mulliganTeams;
      fairMarketValue += mulliganConfig.fairMarketValue * mulliganTeams;
    }
    const maxDeductible = Math.max(0, payment - fairMarketValue);
    taxBenefits.textContent = benefits.join("; ");
    taxPayment.textContent = money.format(payment / 100);
    taxFmv.textContent = money.format(fairMarketValue / 100);
    taxDeductible.textContent = money.format(maxDeductible / 100);
    taxPrompt.hidden = true;
    taxDetails.hidden = false;
  }

  function updateRegistrationSummary(selected) {
    if (selected) {
      const hasTeam = Boolean(selected.team);
      const isIndividual = Boolean(selected.individual);
      const metrics = teamMetrics(selected);
      setSummaryText(summaryPackage, mobileSummaryPackage, selected.name);
      summaryIncludes.textContent = selected.includes;
      setSummaryRowHidden(summaryTeamRow, mobileSummaryTeamRow, !hasTeam);
      setSummaryRowHidden(summaryMulliganRow, mobileSummaryMulliganRow, !hasTeam);
      setSummaryRowHidden(summaryCalculationRow, mobileSummaryCalculationRow, !hasTeam);
      setSummaryText(summaryTeams, mobileSummaryTeams, hasTeam && metrics.count ? String(metrics.count) : "—");
      setSummaryText(summaryGolfers, mobileSummaryGolfers, hasTeam
        ? (metrics.golfersComplete ? String(metrics.golfers) : "—")
        : isIndividual ? "1" : "0");
      setSummaryText(summaryMulligans, mobileSummaryMulligans, String(metrics.mulligans));
      if (hasTeam && metrics.count && Number.isInteger(selected.price)) {
        const packageText = money.format(selected.price / 100) + " × " + metrics.count + (metrics.count === 1 ? " team" : " teams");
        const addonText = metrics.mulligans && mulliganConfig
          ? " + " + money.format(mulliganConfig.price / 100) + " × " + metrics.mulligans + (metrics.mulligans === 1 ? " mulligan team" : " mulligan teams")
          : "";
        setSummaryText(summaryCalculation, mobileSummaryCalculation, packageText + addonText);
      } else {
        setSummaryText(summaryCalculation, mobileSummaryCalculation, "—");
      }
      if (!displayConfigReady() || !Number.isInteger(selected.price) || (hasTeam && !metrics.count)) {
        setSummaryTotal("—");
      } else {
        const packageTotal = selected.price * (hasTeam ? metrics.count : 1);
        const addonTotal = metrics.mulligans && mulliganConfig ? mulliganConfig.price * metrics.mulligans : 0;
        setSummaryTotal(money.format((packageTotal + addonTotal) / 100));
      }
      updateTaxDisclosure(selected, metrics.count, metrics.mulligans);
      return;
    }

    setSummaryText(summaryPackage, mobileSummaryPackage, "Choose an option");
    summaryIncludes.textContent = "—";
    setSummaryRowHidden(summaryTeamRow, mobileSummaryTeamRow, true);
    setSummaryRowHidden(summaryMulliganRow, mobileSummaryMulliganRow, true);
    setSummaryRowHidden(summaryCalculationRow, mobileSummaryCalculationRow, true);
    setSummaryText(summaryTeams, mobileSummaryTeams, "—");
    setSummaryText(summaryGolfers, mobileSummaryGolfers, "—");
    setSummaryTotal("—");
    setSummaryText(summaryMulligans, mobileSummaryMulligans, "0");
    setSummaryText(summaryCalculation, mobileSummaryCalculation, "—");
    updateTaxDisclosure(null, 0, 0);
  }

  function updatePackage() {
    const code = selectedPackageCode();
    const selected = packages[code];
    const hasTeam = Boolean(selected && selected.team);
    const isIndividual = Boolean(selected && selected.individual);
    const isSponsor = Boolean(selected && selected.sponsor);
    const isContest = Boolean(selected && selected.contest);
    const hasGolfers = hasTeam || isIndividual;

    setSection(teamSection, hasTeam);
    setSection(sponsorSection, isSponsor);
    configureGolferCount(selected);
    updateGolferFields(selected);
    teamCount.required = hasTeam;
    renderTeams(selected);

    if (contestField) {
      contestField.hidden = !isContest;
      contestField.querySelectorAll("input, select, textarea").forEach(function (control) {
        control.disabled = !isContest;
      });
    }

    sponsorDisplay.required = isSponsor;
    contestChoice.required = isContest;
    noGolfers.hidden = !(selected && !hasGolfers);

    let nextStep = 3;
    if (hasTeam && teamStep) teamStep.textContent = String(nextStep++);
    if (isIndividual && golferStep) golferStep.textContent = String(nextStep++);
    if (isSponsor && sponsorStep) sponsorStep.textContent = String(nextStep++);
    if (finalStep) finalStep.textContent = String(nextStep);

    packageRadios.forEach(function (radio) {
      const option = radio.closest(".package-option");
      if (option) option.classList.toggle("is-selected", radio.checked);
    });

    updateRegistrationSummary(selected);
  }

  function focusImmediately(control) {
    if (!control || typeof control.focus !== "function") return;
    const root = document.documentElement;
    const previousScrollBehavior = root.style.scrollBehavior;
    root.style.scrollBehavior = "auto";
    try {
      try {
        control.focus({ preventScroll: true });
      } catch (error) {
        control.focus();
      }
      if (typeof control.scrollIntoView === "function") {
        control.scrollIntoView({ behavior: "auto", block: "center", inline: "nearest" });
      }
    } finally {
      root.style.scrollBehavior = previousScrollBehavior;
    }
  }

  function showError(message) {
    errorAlert.textContent = message;
    errorAlert.hidden = false;
    focusImmediately(errorAlert);
  }

  function clearError() {
    errorAlert.hidden = true;
    errorAlert.textContent = "";
  }

  function fieldForError(key) {
    const fields = {
      package_code: "[data-package-radio]",
      "payer.first_name": "#payer-first-name",
      "payer.last_name": "#payer-last-name",
      "payer.company": "#payer-company",
      "payer.email": "#payer-email",
      "payer.phone": "#payer-phone",
      "payer.address_line1": "#payer-address-line1",
      "payer.city": "#payer-city",
      "payer.state": "#payer-state",
      "payer.postal_code": "#payer-postal-code",
      "registration.team_name": "[data-team-name]",
      "registration.sponsor_display": "#sponsor-display",
      "registration.contest_choice": "#contest-choice",
      "registration.notes": "#registration-notes",
      team_count: "#team-count",
      golfer_count: "#golfer-count",
      consent: "#registration-consent"
    };
    if (/^teams(?:\.|$)/.test(key)) {
      const teamMatch = key.match(/^teams\.(\d+)(?:\.(.*))?$/);
      const teamIndex = teamMatch ? Number(teamMatch[1]) : 0;
      const suffix = teamMatch && teamMatch[2] ? teamMatch[2] : "";
      const card = teamList.querySelector('[data-team-index="' + teamIndex + '"]');
      if (!card) return teamCount;
      if (suffix === "name" || suffix === "") return card.querySelector("[data-team-name]");
      if (suffix === "golfer_count") return card.querySelector("[data-team-golfer-count]");
      if (/^addons(?:\.|$)/.test(suffix)) return card.querySelector("[data-team-mulligans]");
      const participantMatch = suffix.match(/^participants\.(\d+)\.name$/);
      if (participantMatch) {
        return card.querySelector('[data-team-player-index="' + Number(participantMatch[1]) + '"]')
          || card.querySelector("[data-team-golfer-count]");
      }
      return card.querySelector("[data-team-name]");
    }
    if (/^participants(?:\.|$)/.test(key)) {
      const match = key.match(/^participants\.(\d+)\.name$/);
      if (match && individualPlayers[Number(match[1])]) return individualPlayers[Number(match[1])];
      return individualPlayers[0];
    }
    return fields[key] ? form.querySelector(fields[key]) : null;
  }

  function clearServerFieldErrors() {
    form.querySelectorAll("[data-server-field-error]").forEach(function (node) {
      node.remove();
    });
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
      const errorId = "registration-field-error-" + index;
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
        const describedBy = [item.dataset.serverOriginalDescribedby, errorId].filter(Boolean).join(" ");
        item.setAttribute("aria-describedby", describedBy);
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
    submitting = isSubmitting;
    form.setAttribute("aria-busy", String(isSubmitting));
    submitButton.disabled = isSubmitting || !publicConfigLoaded;
    submitLabel.textContent = isSubmitting
      ? "Opening secure checkout…"
      : publicConfigLoaded
        ? readySubmitLabel()
        : previewMode ? unavailableCopy().button : "Checkout temporarily unavailable";
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

  packageRadios.forEach(function (radio) {
    radio.addEventListener("change", updatePackage);
  });
  teamCount.addEventListener("change", function () {
    const selected = packages[selectedPackageCode()];
    renderTeams(selected);
    updateRegistrationSummary(selected);
  });
  golferCount.addEventListener("change", function () {
    const selected = packages[selectedPackageCode()];
    updateGolferFields(selected);
    updateRegistrationSummary(selected);
  });
  purchaserPlaying.addEventListener("change", function () {
    if (!individualPlayers[0]) return;
    if (purchaserPlaying.checked) {
      individualManualPlayerValue = individualPlayers[0].value;
      individualPlayers[0].readOnly = true;
      syncPurchaserPlayers();
    } else {
      individualPlayers[0].readOnly = false;
      individualPlayers[0].value = individualManualPlayerValue;
    }
  });
  [payerFirstName, payerLastName].forEach(function (field) {
    field.addEventListener("input", syncPurchaserPlayers);
  });
  if (individualPlayers[0]) {
    individualManualPlayerValue = individualPlayers[0].value;
    individualPlayers[0].addEventListener("input", function () {
      if (!purchaserPlaying.checked) individualManualPlayerValue = individualPlayers[0].value;
    });
  }

  teamList.addEventListener("input", function (event) {
    const target = event.target;
    const card = target.closest("[data-team-card]");
    if (!card) return;
    const teamIndex = Number(card.dataset.teamIndex);
    const state = teamStates[teamIndex];
    if (!state) return;
    if (target.matches("[data-team-name]")) {
      state.name = target.value;
      return;
    }
    if (target.matches("[data-team-participant]")) {
      const playerIndex = Number(target.dataset.teamPlayerIndex);
      state.participants[playerIndex] = target.value;
      if (teamIndex === 0 && playerIndex === 0 && !state.purchaserPlaying) {
        state.manualPlayerOne = target.value;
      }
    }
  });

  teamList.addEventListener("change", function (event) {
    const target = event.target;
    const card = target.closest("[data-team-card]");
    if (!card) return;
    const teamIndex = Number(card.dataset.teamIndex);
    const state = teamStates[teamIndex];
    if (!state) return;
    if (target.matches("[data-team-golfer-count]")) {
      state.golferCount = target.value;
      renderTeamParticipants(card, teamIndex);
      updateRegistrationSummary(packages[selectedPackageCode()]);
      return;
    }
    if (target.matches("[data-team-mulligans]")) {
      state.mulligans = target.checked;
      updateRegistrationSummary(packages[selectedPackageCode()]);
      return;
    }
    if (target.matches("[data-team-purchaser-playing]")) {
      const player = card.querySelector('[data-team-player-index="0"]');
      if (target.checked) {
        state.manualPlayerOne = player ? player.value : state.participants[0];
        state.purchaserPlaying = true;
        state.participants[0] = purchaserName();
      } else {
        state.purchaserPlaying = false;
        state.participants[0] = state.manualPlayerOne;
      }
      renderTeamParticipants(card, teamIndex);
    }
  });

  form.noValidate = true;
  form.addEventListener("submit", async function (event) {
    event.preventDefault();
    clearError();
    clearServerFieldErrors();
    form.classList.add("was-validated");

    if (previewMode) {
      showError(unavailableCopy().status);
      return;
    }

    if (!publicConfigLoaded) {
      showError("Current price and benefit information is unavailable. Checkout cannot begin; please refresh the page or call COMEC at 901-222-0700.");
      return;
    }

    if (!form.checkValidity()) {
      showError("Please complete the required fields before continuing.");
      form.reportValidity();
      const firstInvalid = form.querySelector(":invalid");
      focusImmediately(firstInvalid);
      return;
    }

    const code = selectedPackageCode();
    const selected = packages[code];
    if (!selected) {
      showError("Please choose a registration option before continuing.");
      return;
    }
    if (!idempotencyKey) {
      showError("This browser cannot securely start checkout. Please update your browser or call COMEC at 901-222-0700.");
      return;
    }

    let participants = [];
    let teams = [];
    if (selected.team) {
      const totalTeams = selectedTeamCount();
      if (totalTeams < 1 || totalTeams > 10) {
        showError("Please choose how many teams you are registering.");
        focusImmediately(teamCount);
        return;
      }
      ensureTeamStates(totalTeams);
      for (let teamIndex = 0; teamIndex < totalTeams; teamIndex += 1) {
        const state = teamStates[teamIndex];
        const name = state.name.trim();
        const totalGolfers = Number.parseInt(state.golferCount, 10);
        const card = teamList.querySelector('[data-team-index="' + teamIndex + '"]');
        if (!name) {
          showError("Please enter a name for every team.");
          const nameField = card && card.querySelector("[data-team-name]");
          focusImmediately(nameField);
          return;
        }
        if (!Number.isInteger(totalGolfers) || totalGolfers < 1 || totalGolfers > 4) {
          showError("Please choose 1–4 golfers for every team.");
          const countField = card && card.querySelector("[data-team-golfer-count]");
          focusImmediately(countField);
          return;
        }
        const names = state.participants.slice(0, totalGolfers).map(function (playerName) {
          return playerName.trim();
        });
        const blankPlayer = names.findIndex(function (playerName) { return !playerName; });
        if (blankPlayer !== -1) {
          showError("Please enter the name of every golfer attending.");
          const playerField = card && card.querySelector('[data-team-player-index="' + blankPlayer + '"]');
          focusImmediately(playerField);
          return;
        }
        teams.push({
          name: name,
          participants: names.map(function (playerName) { return { name: playerName }; }),
          addons: state.mulligans && allowsTeamMulligans(selected) ? ["team_mulligans"] : []
        });
      }
    } else if (selected.individual) {
      const golferTotal = selectedGolferCount();
      if (golferTotal !== 1) {
        showError("Please confirm the golfer attending.");
        focusImmediately(golferCount);
        return;
      }
      const individualName = individualPlayers[0].value.trim();
      if (!individualName) {
        showError("Please enter the golfer’s name.");
        focusImmediately(individualPlayers[0]);
        return;
      }
      participants = [{ name: individualName }];
    }

    const payload = {
      event_code: "golf-2026",
      package_code: code,
      payer: {
        first_name: value("#payer-first-name"),
        last_name: value("#payer-last-name"),
        company: value("#payer-company"),
        email: value("#payer-email"),
        phone: value("#payer-phone"),
        address_line1: value("#payer-address-line1"),
        city: value("#payer-city"),
        state: value("#payer-state").toUpperCase(),
        postal_code: value("#payer-postal-code")
      },
      registration: {
        team_name: "",
        sponsor_display: selected.sponsor ? sponsorDisplay.value.trim() : "",
        contest_choice: selected.contest ? contestChoice.value : "",
        notes: value("#registration-notes")
      },
      participants: participants,
      addons: [],
      teams: teams,
      consent: consent.checked,
      website: honeypot.value
    };

    setSubmitting(true);
    const controller = new AbortController();
    const timeout = window.setTimeout(function () {
      controller.abort();
    }, 25000);

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
      if (!destination) {
        throw new Error("Checkout could not be opened. Please try again or call COMEC at 901-222-0700.");
      }

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

  updatePackage();
  loadPublicConfig();
})();
