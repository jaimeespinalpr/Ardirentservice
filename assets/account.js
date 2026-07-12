(() => {
  const API = "https://pay.ardirentservice.com/accounts_api.php";
  const ACCOUNT_URL = new URL("account.html", window.location.href).toString();
  const LEGACY_ACCOUNT_URL = new URL("account-legacy.html", window.location.href).toString();
  const RECOVERY_HASH = "type=recovery";
  const panels = ["guest", "verify", "recovery", "profile"];
  const text = {
    en: {
      loading: "Loading account…",
      configError: "We could not load the account service settings.",
      signUpSuccess: "Check your email to verify your account.",
      signInSuccess: "You are signed in.",
      signOutSuccess: "You have been signed out.",
      profileSaved: "Profile saved.",
      resetSent: "Password reset email sent.",
      recoveryReady: "Set a new password to finish recovery.",
      duplicate: "An account already exists with that email.",
      shortPassword: "Use a password with at least 10 characters.",
      invalid: "Please check your information and try again.",
      expired: "That link expired. Request a new verification or reset email.",
      loginFailed: "The email or password is incorrect.",
      requiredTerms: "You must accept the terms and privacy policy.",
      available: "Available: your $5 welcome discount is ready to use.",
      reserved: "Reserved: your $5 welcome discount is already reserved for an in-progress checkout.",
      used: "Used: your $5 welcome discount has already been applied.",
      notAdded: "Not added",
      profilePanelTitle: "Your account",
      verifyPanelTitle: "Verify your email",
      recoveryPanelTitle: "Password recovery",
      guestPanelTitle: "Customer account",
      fullName: "Full name",
      email: "Email",
      phone: "Phone number",
      saveProfile: "Save profile",
      resetPassword: "Email password reset",
      marketingLabel: "I want to receive promotions, discounts, and news by email.",
      termsHelp: "Marketing is optional and separate from the terms.",
    },
    es: {
      loading: "Cargando la cuenta…",
      configError: "No pudimos cargar la configuración del servicio de cuenta.",
      signUpSuccess: "Revisa tu correo para verificar tu cuenta.",
      signInSuccess: "Has iniciado sesión.",
      signOutSuccess: "Has cerrado sesión.",
      profileSaved: "Perfil guardado.",
      resetSent: "Se envió el correo para restablecer la contraseña.",
      recoveryReady: "Elige una nueva contraseña para terminar la recuperación.",
      duplicate: "Ya existe una cuenta con ese correo.",
      shortPassword: "Usa una contraseña de al menos 10 caracteres.",
      invalid: "Verifica la información e inténtalo nuevamente.",
      expired: "Ese enlace expiró. Solicita un nuevo correo de verificación o restablecimiento.",
      loginFailed: "El correo o la contraseña no son correctos.",
      requiredTerms: "Debes aceptar los términos y la política de privacidad.",
      available: "Disponible: tu descuento de bienvenida de $5 está listo para usarse.",
      reserved: "Reservado: tu descuento de bienvenida de $5 ya está reservado para un pago en curso.",
      used: "Usado: tu descuento de bienvenida de $5 ya fue aplicado.",
      notAdded: "No añadido",
      profilePanelTitle: "Tu cuenta",
      verifyPanelTitle: "Verifica tu correo",
      recoveryPanelTitle: "Recuperación de contraseña",
      guestPanelTitle: "Cuenta de cliente",
      fullName: "Nombre completo",
      email: "Correo electrónico",
      phone: "Número de teléfono",
      saveProfile: "Guardar perfil",
      resetPassword: "Enviar restablecimiento de contraseña",
      marketingLabel: "Quiero recibir promociones, descuentos y novedades por email.",
      termsHelp: "El marketing es opcional y es independiente de los términos.",
    },
  };

  const recoveryFromUrl = () => {
    const hash = new URLSearchParams(window.location.hash.replace(/^#/, ""));
    const query = new URLSearchParams(window.location.search);
    return hash.get("type") === "recovery" || query.get("type") === "recovery";
  };

  const state = {
    supabase: null,
    csrf: "",
    view: "guest",
    activeTab: "register",
    currentUser: null,
    currentProfile: null,
    recovering: false,
    bootstrapped: false,
  };

  const lang = () => (document.documentElement.lang === "es" ? "es" : "en");
  const copy = () => text[lang()];
  const accountPanelNodes = () => Array.from(document.querySelectorAll("[data-account-panel]"));

  const translateLiveStatus = () => {
    const node = document.querySelector("[data-account-status]");
    if (!node || !node.textContent) return;
    const current = node.textContent;
    const key = Object.keys(text.en).find((candidate) => text.en[candidate] === current || text.es[candidate] === current);
    if (key && copy()[key]) node.textContent = copy()[key];
  };

  const translateStaticCopy = () => {
    document.querySelectorAll("[data-i18n-en]").forEach((node) => {
      const key = lang() === "es" ? "data-i18n-es" : "data-i18n-en";
      node.textContent = node.getAttribute(key) || node.textContent || "";
    });
    const profileButton = document.querySelector("[data-account-reset-password]");
    if (profileButton) profileButton.textContent = copy().resetPassword;
    const saveButton = document.querySelector("[data-account-form=profile] [type=submit]");
    if (saveButton) saveButton.textContent = copy().saveProfile;
    const termsNote = document.querySelector("[data-account-profile-note]");
    if (termsNote) termsNote.textContent = copy().termsHelp;
    const help = document.querySelector("[data-account-recovery-copy]");
    if (help && state.recovering) help.textContent = copy().recoveryReady;
    const tabs = document.querySelector('[role="tablist"]');
    if (tabs) tabs.setAttribute("aria-label", lang() === "es" ? "Formularios de cuenta" : "Account forms");
    translateLiveStatus();
  };

  const showStatus = (message, kind = "info") => {
    const node = document.querySelector("[data-account-status]");
    if (!node) return;
    node.textContent = message;
    node.dataset.kind = kind;
  };

  const showPanel = (view) => {
    state.view = view;
    accountPanelNodes().forEach((node) => {
      node.hidden = node.getAttribute("data-account-panel") !== view;
    });
    document.querySelectorAll("[data-account-tab]").forEach((button) => {
      const isActive = view === "guest" && button.dataset.accountTab === state.activeTab;
      button.setAttribute("aria-selected", String(isActive));
    });
    if (view === "guest") {
      document.querySelectorAll('[data-account-form="register"]').forEach((form) => (form.hidden = state.activeTab !== "register"));
      document.querySelectorAll('[data-account-form="login"]').forEach((form) => (form.hidden = state.activeTab !== "login"));
    }
  };

  const renderBenefit = () => {
    const benefitBox = document.querySelector("[data-account-benefit]");
    const benefitState = document.querySelector("[data-account-benefit-state]");
    if (!benefitBox || !benefitState) return;
    const profile = state.currentProfile;
    if (!state.currentUser || !profile) {
      benefitBox.hidden = true;
      benefitBox.textContent = "";
      benefitState.textContent = "";
      return;
    }
    const used = !!profile.welcome_discount_used_at;
    const reserved = !used && !!profile.welcome_discount_reserved_at;
    const message = used ? copy().used : reserved ? copy().reserved : copy().available;
    benefitBox.hidden = false;
    benefitBox.textContent = message;
    benefitState.textContent = used ? copy().used : reserved ? copy().reserved : copy().available;
  };

  const renderProfileForm = () => {
    const profile = state.currentProfile || {};
    const user = state.currentUser;
    const fullName = String(profile.full_name || user?.user_metadata?.full_name || user?.user_metadata?.name || "");
    const phone = String(profile.phone || user?.user_metadata?.phone || "");
    const marketing = Boolean(profile.marketing_opt_in ?? user?.user_metadata?.marketing_opt_in ?? false);
    const email = String(user?.email || "");
    document.querySelectorAll("[data-account-name]").forEach((node) => (node.textContent = fullName.split(" ").filter(Boolean)[0] || email.split("@")[0] || ""));
    document.querySelectorAll("[data-account-email]").forEach((node) => (node.textContent = email));
    document.querySelectorAll("[data-account-phone]").forEach((node) => (node.textContent = phone || copy().notAdded));
    const form = document.querySelector('[data-account-form="profile"]');
    if (form) {
      const fullNameInput = form.querySelector('[name="full_name"]');
      const emailInput = form.querySelector('[name="email"]');
      const phoneInput = form.querySelector('[name="phone"]');
      const marketingInput = form.querySelector('[name="marketing_opt_in"]');
      if (fullNameInput) fullNameInput.value = fullName;
      if (emailInput) emailInput.value = email;
      if (phoneInput) phoneInput.value = phone;
      if (marketingInput) marketingInput.checked = marketing;
    }
  };

  const applyUi = () => {
    translateStaticCopy();
    showPanel(state.view);
    renderProfileForm();
    renderBenefit();
    const loginTab = document.querySelector('[data-account-tab="login"]');
    const registerTab = document.querySelector('[data-account-tab="register"]');
    if (loginTab) loginTab.setAttribute("aria-selected", String(state.activeTab === "login" && state.view === "guest"));
    if (registerTab) registerTab.setAttribute("aria-selected", String(state.activeTab === "register" && state.view === "guest"));
  };

  const fieldErrorMessage = (error) => {
    const message = `${error?.message || ""} ${(error?.error_description || "")}`.toLowerCase();
    const code = String(error?.code || error?.status || "").toLowerCase();
    if (code === "409" || message.includes("already") || message.includes("exists") || message.includes("duplicate") || message.includes("registered")) return copy().duplicate;
    if (message.includes("password") && (message.includes("10") || message.includes("short") || message.includes("minimum"))) return copy().shortPassword;
    if (message.includes("expired") || message.includes("otp") || message.includes("recovery")) return copy().expired;
    if (message.includes("invalid login") || message.includes("invalid credentials") || message.includes("incorrect") || message.includes("bad login")) return copy().loginFailed;
    if (message.includes("invalid email") || message.includes("email")) return copy().invalid;
    return copy().invalid;
  };

  const setConfigError = () => {
    showStatus(copy().configError, "error");
  };

  const readForm = (form) => Object.fromEntries(new FormData(form).entries());

  const ensureSupabase = async () => {
    if (state.supabase) return state.supabase;
    const response = await fetch(`${API}?action=config`, { credentials: "include", headers: { Accept: "application/json" } });
    const config = await response.json();
    if (config.account_backend === "sqlite") {
      window.location.replace(LEGACY_ACCOUNT_URL);
      return null;
    }
    state.csrf = config.csrf_token || "";
    if (!response.ok || !config.supabase_url || !config.supabase_publishable_key || !window.supabase?.createClient) {
      throw new Error("config_failed");
    }
    state.supabase = window.supabase.createClient(config.supabase_url, config.supabase_publishable_key);
    window.ardiSupabaseClient = state.supabase;
    state.supabase.auth.onAuthStateChange(async (event, session) => {
      state.currentUser = session?.user || null;
      state.recovering = event === "PASSWORD_RECOVERY" || recoveryFromUrl();
      await refreshProfile();
      if (state.currentUser) {
        showPanel(state.recovering ? "recovery" : "profile");
        if (state.recovering) {
          showStatus(copy().recoveryReady, "info");
        }
      } else {
        showPanel(state.view === "verify" ? "verify" : "guest");
      }
      applyUi();
    });
    return state.supabase;
  };

  const refreshProfile = async () => {
    if (!state.supabase || !state.currentUser) {
      state.currentProfile = null;
      renderBenefit();
      renderProfileForm();
      return;
    }
    const { data, error } = await state.supabase
      .from("customer_profiles")
      .select("id, full_name, phone, marketing_opt_in, welcome_discount_cents, welcome_discount_used_at, welcome_discount_reserved_at")
      .eq("id", state.currentUser.id)
      .maybeSingle();
    if (!error && data) {
      state.currentProfile = data;
      return;
    }
    state.currentProfile = {
      id: state.currentUser.id,
      full_name: state.currentUser.user_metadata?.full_name || state.currentUser.user_metadata?.name || "",
      phone: state.currentUser.user_metadata?.phone || "",
      marketing_opt_in: Boolean(state.currentUser.user_metadata?.marketing_opt_in),
      welcome_discount_cents: 500,
      welcome_discount_used_at: null,
      welcome_discount_reserved_at: null,
    };
  };

  const redirectToAccount = () => ACCOUNT_URL;

  const register = async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const values = readForm(form);
    const password = String(values.password || "");
    if (password.length < 10) {
      showStatus(copy().shortPassword, "error");
      return;
    }
    if (!values.accept_terms) {
      showStatus(copy().requiredTerms, "error");
      return;
    }
    try {
      await ensureSupabase();
      const { error, data } = await state.supabase.auth.signUp({
        email: String(values.email || ""),
        password,
        options: {
          emailRedirectTo: redirectToAccount(),
          data: {
            full_name: String(values.full_name || ""),
            phone: String(values.phone || ""),
            marketing_opt_in: values.marketing_opt_in === "on",
          },
        },
      });
      if (error) throw error;
      state.currentUser = data?.user || null;
      state.currentProfile = null;
      state.view = data?.session ? "profile" : "verify";
      showStatus(copy().signUpSuccess, "success");
      applyUi();
    } catch (error) {
      showStatus(fieldErrorMessage(error), "error");
    }
  };

  const login = async (event) => {
    event.preventDefault();
    const values = readForm(event.currentTarget);
    try {
      await ensureSupabase();
      const { error, data } = await state.supabase.auth.signInWithPassword({
        email: String(values.email || ""),
        password: String(values.password || ""),
      });
      if (error) throw error;
      state.currentUser = data.session?.user || data.user || null;
      state.view = "profile";
      await refreshProfile();
      showStatus(copy().signInSuccess, "success");
      applyUi();
    } catch (error) {
      showStatus(fieldErrorMessage(error), "error");
    }
  };

  const logout = async () => {
    try {
      await ensureSupabase();
      await state.supabase.auth.signOut();
      state.currentUser = null;
      state.currentProfile = null;
      state.recovering = false;
      state.view = "guest";
      showStatus(copy().signOutSuccess, "success");
      applyUi();
    } catch (error) {
      showStatus(fieldErrorMessage(error), "error");
    }
  };

  const requestReset = async () => {
    const form = document.querySelector('[data-account-form="login"]');
    const email = String(form?.querySelector('[name="email"]')?.value || "").trim();
    if (!email) {
      showStatus(copy().invalid, "error");
      return;
    }
    try {
      await ensureSupabase();
      const { error } = await state.supabase.auth.resetPasswordForEmail(email, { redirectTo: redirectToAccount() });
      if (error) throw error;
      showStatus(copy().resetSent, "success");
      state.view = "verify";
      applyUi();
    } catch (error) {
      showStatus(fieldErrorMessage(error), "error");
    }
  };

  const submitRecovery = async (event) => {
    event.preventDefault();
    const values = readForm(event.currentTarget);
    const password = String(values.password || "");
    if (password.length < 10) {
      showStatus(copy().shortPassword, "error");
      return;
    }
    try {
      await ensureSupabase();
      const { error } = await state.supabase.auth.updateUser({ password });
      if (error) throw error;
      state.recovering = false;
      state.view = "profile";
      showStatus(copy().signInSuccess, "success");
      await refreshProfile();
      applyUi();
    } catch (error) {
      showStatus(fieldErrorMessage(error), "error");
    }
  };

  const saveProfile = async (event) => {
    event.preventDefault();
    const values = readForm(event.currentTarget);
    try {
      await ensureSupabase();
      const payload = {
        full_name: String(values.full_name || ""),
        phone: String(values.phone || ""),
        marketing_opt_in: values.marketing_opt_in === "on",
      };
      const { error } = await state.supabase.from("customer_profiles").update(payload).eq("id", state.currentUser.id);
      if (error) throw error;
      state.currentProfile = {
        ...(state.currentProfile || {}),
        ...payload,
      };
      showStatus(copy().profileSaved, "success");
      await refreshProfile();
      applyUi();
    } catch (error) {
      showStatus(fieldErrorMessage(error), "error");
    }
  };

  const loadInitialState = async () => {
    try {
      await ensureSupabase();
      if (!state.supabase) return;
      const { data } = await state.supabase.auth.getSession();
      state.currentUser = data.session?.user || null;
      state.recovering = recoveryFromUrl();
      if (state.currentUser) {
        await refreshProfile();
        state.view = state.recovering ? "recovery" : "profile";
        if (state.recovering) {
          showStatus(copy().recoveryReady, "info");
        }
      } else if (state.recovering) {
        state.view = "recovery";
      } else {
        state.view = "guest";
      }
      state.bootstrapped = true;
      applyUi();
    } catch (_error) {
      setConfigError();
      state.view = "guest";
      applyUi();
    }
  };

  document.querySelectorAll("[data-account-tab]").forEach((button) => {
    button.addEventListener("click", () => {
      state.activeTab = button.dataset.accountTab || "register";
      state.view = "guest";
      applyUi();
    });
  });

  document.querySelector('[data-account-form="register"]')?.addEventListener("submit", register);
  document.querySelector('[data-account-form="login"]')?.addEventListener("submit", login);
  document.querySelector('[data-account-form="recovery"]')?.addEventListener("submit", submitRecovery);
  document.querySelector('[data-account-form="profile"]')?.addEventListener("submit", saveProfile);
  document.querySelector("[data-account-logout]")?.addEventListener("click", logout);
  document.querySelector("[data-account-reset-password]")?.addEventListener("click", requestReset);
  document.querySelector("[data-account-switch-to-login]")?.addEventListener("click", () => {
    state.activeTab = "login";
    state.view = "guest";
    applyUi();
  });
  document.querySelector("[data-account-forgot-password]")?.addEventListener("click", requestReset);

  document.addEventListener("ardi:lang", applyUi);
  new MutationObserver(applyUi).observe(document.documentElement, { attributes: true, attributeFilter: ["lang"] });
  applyUi();
  showStatus(copy().loading, "info");
  void loadInitialState();
})();
