import * as React from "react"

interface SessionUser {
  id: string
  displayName: string
  initials: string
  roles: string[]
}

export interface SessionState {
  authenticated: boolean
  user: SessionUser | null
  csrfToken: string | null
}

interface SessionContextType {
  session: SessionState
  login: (idToken: string) => Promise<{ success: boolean; error?: string; code?: string }>
  logout: () => Promise<void>
  refresh: () => Promise<void>
}

const SessionContext = React.createContext<SessionContextType | undefined>(undefined)

export function SessionProvider({
  children,
  initialSession,
}: {
  children: React.ReactNode
  initialSession: SessionState
}) {
  const [session, setSession] = React.useState<SessionState>(initialSession)

  const refresh = React.useCallback(async () => {
    try {
      const res = await fetch("/resources/session")
      if (res.ok) {
        const data = await res.json()
        setSession(data)
      }
    } catch {
      // Ignored fallback
    }
  }, [])

  const login = React.useCallback(async (idToken: string) => {
    try {
      const res = await fetch("/resources/auth/google", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ credential: idToken }),
      })

      const data = await res.json()

      if (res.ok) {
        setSession({
          authenticated: true,
          user: data.user,
          csrfToken: data.csrfToken,
        })
        return { success: true }
      } else {
        return { success: false, error: data.detail || "Login failed", code: data.code }
      }
    } catch (err: unknown) {
      return { success: false, error: err instanceof Error ? err.message : "Login failed" }
    }
  }, [])

  const handleLogout = React.useCallback(async () => {
    try {
      await fetch("/resources/auth/logout", {
        method: "POST",
        headers: {
          "X-CSRF-Token": session.csrfToken || "",
        },
      })
    } catch {
      // Ignored fallback
    } finally {
      setSession({ authenticated: false, user: null, csrfToken: null })
    }
  }, [session.csrfToken])

  return (
    <SessionContext.Provider value={{ session, login, logout: handleLogout, refresh }}>
      {children}
    </SessionContext.Provider>
  )
}

export function useSession() {
  const context = React.useContext(SessionContext)
  if (context === undefined) {
    throw new Error("useSession must be used within a SessionProvider")
  }
  return context
}
