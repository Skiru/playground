import { StrictMode, startTransition } from "react";
import { hydrateRoot } from "react-dom/client";
import { HydratedRouter } from "react-router/dom";

const isPrivateAccountRoute = () => window.location.pathname.startsWith("/konto")

const verifyPrivateSession = async () => {
  try {
    const response = await fetch("/resources/session", { cache: "no-store" })
    const session = response.ok ? await response.json() : null
    if (session?.authenticated) {
      document.documentElement.style.visibility = "visible"
      return
    }
  } catch {
    // A restored private document stays hidden when session verification fails.
  }
  window.location.replace("/?loginRequired=true")
}

window.addEventListener("pagehide", () => {
  if (isPrivateAccountRoute()) {
    document.documentElement.style.visibility = "hidden"
  }
})

window.addEventListener("pageshow", async () => {
  if (isPrivateAccountRoute()) {
    await verifyPrivateSession()
  }
})

window.addEventListener("popstate", () => {
  if (isPrivateAccountRoute()) {
    document.documentElement.style.visibility = "hidden"
    void verifyPrivateSession()
  }
})

if (isPrivateAccountRoute()) {
  document.documentElement.style.visibility = "hidden"
  void verifyPrivateSession()
}

startTransition(() => {
  hydrateRoot(document, <StrictMode><HydratedRouter /></StrictMode>);
});
