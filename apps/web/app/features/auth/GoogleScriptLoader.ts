interface GoogleIdentityApi {
  accounts?: { id?: object }
}

let loadPromise: Promise<void> | null = null

export function loadGoogleScript(): Promise<void> {
  if (typeof window === "undefined") {
    return Promise.resolve()
  }

  const globalWindow = window as typeof window & { google?: GoogleIdentityApi }
  if (globalWindow.google?.accounts?.id) {
    return Promise.resolve()
  }

  const id = "google-gsi-client"
  if (document.getElementById(id)) {
    return Promise.resolve()
  }

  if (loadPromise) {
    return loadPromise
  }

  loadPromise = new Promise<void>((resolve, reject) => {
    const script = document.createElement("script")
    script.id = id
    script.src = "https://accounts.google.com/gsi/client"
    script.async = true
    script.defer = true
    script.onload = () => {
      resolve()
    }
    script.onerror = () => {
      loadPromise = null
      reject(new Error("Nie udało się załadować skryptu Google Sign-In."))
    }
    document.body.appendChild(script)
  })

  return loadPromise
}
