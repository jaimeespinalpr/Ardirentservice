(() => {
  const API = "https://pay.ardirentservice.com/accounts_api.php";
  const spanish = () => document.documentElement.lang === "es";
  let csrf = "";
  let currentUser = null;
  const text = {
    en: {
      promoTitle: "Create an account and get $5 off",
      promoText: "Your welcome discount is applied automatically to your first rental.",
      promoButton: "Create account",
      nav: "Account",
      available: "Your $5 welcome discount will be applied automatically at checkout.",
      reserved: "Your $5 welcome discount is reserved for a checkout already in progress.",
      created: "Account created. Your $5 welcome discount is ready.",
      logged: "Welcome back. You are signed in.",
      invalid: "Please check your information and try again.",
      exists: "An account already exists with that email.",
      badLogin: "The email or password is incorrect.",
    },
    es: {
      promoTitle: "Crea una cuenta y recibe $5 de descuento",
      promoText: "Tu descuento de bienvenida se aplica automáticamente en tu primer alquiler.",
      promoButton: "Crear cuenta",
      nav: "Cuenta",
      available: "Tu descuento de bienvenida de $5 se aplicará automáticamente al pagar.",
      reserved: "Tu descuento de $5 está reservado para un pago que ya comenzaste.",
      created: "Cuenta creada. Tu descuento de bienvenida de $5 está listo.",
      logged: "Bienvenido nuevamente. Tu sesión está activa.",
      invalid: "Verifica la información e inténtalo nuevamente.",
      exists: "Ya existe una cuenta con ese correo.",
      badLogin: "El correo o la contraseña no son correctos.",
    },
  };
  const copy = () => text[spanish() ? "es" : "en"];

  const translatePromos = () => {
    document.querySelectorAll("[data-account-promo-title]").forEach((n) => n.textContent = copy().promoTitle);
    document.querySelectorAll("[data-account-promo-text]").forEach((n) => n.textContent = copy().promoText);
    document.querySelectorAll("[data-account-promo-button]").forEach((n) => n.textContent = copy().promoButton);
    document.querySelectorAll("[data-account-nav]").forEach((n) => n.textContent = copy().nav);
  };

  const status = async () => {
    try {
      const response = await fetch(API + "?action=status", {credentials:"include", headers:{Accept:"application/json"}});
      const data = await response.json();
      csrf = data.csrf_token || "";
      currentUser = data.authenticated ? data.user : null;
      updateUi();
      return data;
    } catch (_) {
      return null;
    }
  };

  const post = async (action, payload = {}) => {
    const response = await fetch(API + "?action=" + encodeURIComponent(action), {
      method: "POST",
      credentials: "include",
      headers: {"Content-Type":"application/json", Accept:"application/json"},
      body: JSON.stringify({...payload, csrf_token: csrf}),
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || "request_failed");
    currentUser = data.user || null;
    return data;
  };

  const updateUi = () => {
    translatePromos();
    document.querySelectorAll("[data-account-auth-only]").forEach((n) => n.hidden = !currentUser);
    document.querySelectorAll("[data-account-guest-only]").forEach((n) => n.hidden = !!currentUser);
    document.querySelectorAll("[data-account-name]").forEach((n) => n.textContent = currentUser?.name?.split(" ")[0] || "");
    document.querySelectorAll("[data-account-email]").forEach((n) => n.textContent = currentUser?.email || "");
    document.querySelectorAll("[data-account-phone]").forEach((n) => n.textContent = currentUser?.phone || (spanish() ? "No añadido" : "Not added"));
    document.querySelectorAll("[data-account-benefit]").forEach((n) => {
      if (!currentUser) { n.hidden = true; return; }
      n.hidden = false;
      n.textContent = currentUser.welcome_discount_available ? copy().available :
        (currentUser.welcome_discount_reserved ? copy().reserved :
          (spanish() ? "El descuento de bienvenida ya fue utilizado." : "The welcome discount has already been used."));
    });
    const form = document.querySelector("[data-rental-checkout]");
    if (form && currentUser) {
      const name = form.querySelector('[name="name"]');
      const email = form.querySelector('[name="email"]');
      const phone = form.querySelector('[name="phone"]');
      if (name && !name.value) name.value = currentUser.name;
      if (email && !email.value) email.value = currentUser.email;
      if (phone && !phone.value) phone.value = currentUser.phone || "";
      let note = form.querySelector(".rental-account-benefit");
      if (!note && currentUser.welcome_discount_available) {
        note = document.createElement("div");
        note.className = "rental-account-benefit";
        form.prepend(note);
      }
      if (note) note.textContent = copy().available;
    }
  };

  const setMessage = (message, kind) => {
    const node = document.querySelector("[data-account-status]");
    if (!node) return;
    node.textContent = message;
    node.dataset.kind = kind;
  };

  document.querySelector("[data-account-register]")?.addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const values = new FormData(form);
    try {
      await post("register", {
        name: values.get("name"), email: values.get("email"), phone: values.get("phone"),
        password: values.get("password"), marketing_opt_in: values.get("marketing_opt_in") === "on",
        accept_terms: values.get("accept_terms") === "on",
      });
      setMessage(copy().created, "success");
      updateUi();
    } catch (error) {
      setMessage(error.message === "email_exists" ? copy().exists : copy().invalid, "error");
    }
  });

  document.querySelector("[data-account-login]")?.addEventListener("submit", async (event) => {
    event.preventDefault();
    const values = new FormData(event.currentTarget);
    try {
      await post("login", {email: values.get("email"), password: values.get("password")});
      setMessage(copy().logged, "success");
      updateUi();
    } catch (_) {
      setMessage(copy().badLogin, "error");
    }
  });

  document.querySelector("[data-account-logout]")?.addEventListener("click", async () => {
    await post("logout");
    location.reload();
  });

  document.querySelectorAll("[data-account-tab]").forEach((button) => {
    button.addEventListener("click", () => {
      document.querySelectorAll("[data-account-tab]").forEach((b) => b.setAttribute("aria-selected", String(b === button)));
      document.querySelectorAll("[data-account-panel]").forEach((p) => p.hidden = p.dataset.accountPanel !== button.dataset.accountTab);
    });
  });

  new MutationObserver(() => updateUi()).observe(document.documentElement, {attributes:true, attributeFilter:["lang"]});
  translatePromos();
  void status();
})();
