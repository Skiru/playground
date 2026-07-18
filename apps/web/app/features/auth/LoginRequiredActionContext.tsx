import * as React from "react"
import { useSession } from "~/lib/session-context"
import { LoginDialog } from "./LoginDialog"

interface LoginRequiredActionContextType {
  requireLogin: (action: () => void) => void
}

const LoginRequiredActionContext = React.createContext<LoginRequiredActionContextType | undefined>(undefined)

export function LoginRequiredActionProvider({ children }: { children: React.ReactNode }) {
  const { session } = useSession()
  const [isDialogOpen, setIsLoginDialogOpen] = React.useState(false)
  const pendingActionRef = React.useRef<(() => void) | null>(null)

  // Execute the pending action exactly once when the session changes to authenticated
  React.useEffect(() => {
    if (session.authenticated && pendingActionRef.current) {
      const action = pendingActionRef.current
      pendingActionRef.current = null
      setIsLoginDialogOpen(false)
      setTimeout(() => {
        action()
      }, 0)
    }
  }, [session.authenticated])

  const requireLogin = React.useCallback((action: () => void) => {
    if (session.authenticated) {
      action()
    } else {
      pendingActionRef.current = action
      setIsLoginDialogOpen(true)
    }
  }, [session.authenticated])

  const handleCloseDialog = React.useCallback(() => {
    setIsLoginDialogOpen(false)
    pendingActionRef.current = null
  }, [])

  return (
    <LoginRequiredActionContext.Provider value={{ requireLogin }}>
      {children}
      <LoginDialog isOpen={isDialogOpen} onOpenChange={handleCloseDialog} />
    </LoginRequiredActionContext.Provider>
  )
}

export function useLoginRequiredAction() {
  const context = React.useContext(LoginRequiredActionContext)
  if (context === undefined) {
    throw new Error("useLoginRequiredAction must be used within a LoginRequiredActionProvider")
  }
  return context
}
