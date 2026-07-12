((root, factory) => {
  const selectAccountApiUrl = factory();
  if (typeof module === "object" && module.exports) {
    module.exports = selectAccountApiUrl;
  }
  if (root) {
    root.ardiAccountApiUrl = selectAccountApiUrl;
  }
})(typeof window !== "undefined" ? window : globalThis, () => {
  const productionApi = "https://pay.ardirentservice.com/accounts_api.php";

  const normalizedHostname = (rawHost) => {
    const host = String(rawHost || "").trim().toLowerCase();
    const match = host.match(/^([a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)(?::([0-9]{1,5}))?$/);
    if (!match) return "";
    if (match[2] && Number(match[2]) > 65535) return "";

    const hostname = match[1];
    const labels = hostname.split(".");
    if (labels.some((label) => !label || label.length > 63 || !/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test(label))) {
      return "";
    }
    return hostname;
  };

  return (rawHost) => {
    const hostname = normalizedHostname(rawHost);
    const productionHost = hostname === "ardirentservice.com"
      || hostname.endsWith(".ardirentservice.com");
    return productionHost ? productionApi : "accounts_api.php";
  };
});
