import * as React from "react"
import { loadGoogleScript } from "./GoogleScriptLoader"

interface GoogleSignInButtonProps {
  clientId: string
  onCredentialReceived: (credential: string) => void
  onError: (errorMsg: string) => void
}

interface GoogleIdentityApi {
  initialize: (options: { client_id: string; callback: (response: { credential: string }) => void }) => void
  renderButton: (element: HTMLElement, options: { theme: string; size: string; width: number }) => void
}

export function GoogleSignInButton({ clientId, onCredentialReceived, onError }: GoogleSignInButtonProps) {
  const containerRef = React.useRef<HTMLDivElement>(null)

  React.useEffect(() => {
    let active = true

    async function init() {
      try {
        await loadGoogleScript()
        if (!active) return

        const globalWindow = window as typeof window & {
          google?: { accounts?: { id?: GoogleIdentityApi } }
        }
        if (!globalWindow.google?.accounts?.id) {
          throw new Error("Google API not loaded")
        }

        globalWindow.google.accounts.id.initialize({
          client_id: clientId,
          callback: (response: { credential: string }) => {
            if (active) {
              onCredentialReceived(response.credential)
            }
          },
        })

        if (containerRef.current) {
          globalWindow.google.accounts.id.renderButton(containerRef.current, {
            theme: "outline",
            size: "large",
            width: 280,
          })
        }
      } catch (err: unknown) {
        if (active) {
          onError(err instanceof Error ? err.message : "Błąd podczas inicjalizacji logowania Google.")
        }
      }
    }

    void init()

    return () => {
      active = false
    }
  }, [clientId, onCredentialReceived, onError])

  return (
    <div
      ref={containerRef}
      id="google-signin-button-container"
      className="min-h-[40px] flex items-center justify-center"
    />
  )
}
