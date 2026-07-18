import * as React from "react"
import { useSession } from "~/lib/session-context"
import { useRouteLoaderData } from "react-router"
import { Button } from "~/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "~/components/ui/dialog"
import { ShieldAlert, Sparkles } from "lucide-react"
import { toast } from "sonner"
import { authContent } from "./auth-content"
import { GoogleSignInButton } from "./GoogleSignInButton"

interface LoginDialogProps {
  isOpen: boolean
  onOpenChange: (open: boolean) => void
}

export function LoginDialog({ isOpen, onOpenChange }: LoginDialogProps) {
  const { login, loginDev } = useSession()
  const [isLoading, setIsLoading] = React.useState(false)
  const [errorMsg, setErrorMsg] = React.useState<string | null>(null)
  const [isLinkRequired, setIsLinkRequired] = React.useState(false)

  // Read config from root loader
  const rootData = useRouteLoaderData("root") as {
    publicRuntimeConfig?: {
      googleIdentityEnabled: boolean
      googleClientId: string | null
      devAuthEnabled: boolean
    }
  } | undefined

  const config = rootData?.publicRuntimeConfig
  const googleIdentityEnabled = config?.googleIdentityEnabled ?? false
  const googleClientId = config?.googleClientId ?? null
  const devAuthEnabled = config?.devAuthEnabled ?? false

  const handleGoogleCredential = React.useCallback(async (credential: string) => {
    setIsLoading(true)
    setErrorMsg(null)
    const res = await login(credential)
    setIsLoading(false)
    if (res.success) {
      onOpenChange(false)
      toast.success(authContent.loginSuccess)
    } else {
      if (res.code === "ACCOUNT_LINK_REQUIRED") {
        setIsLinkRequired(true)
      } else if (res.code === "ACCOUNT_INACTIVE") {
        setErrorMsg(authContent.notActive)
      } else if (res.code === "AUTH_RATE_LIMITED") {
        setErrorMsg(authContent.rateLimited)
      } else {
        setErrorMsg(res.error || authContent.loginFailed)
      }
    }
  }, [login, onOpenChange])

  const handleDevLogin = async () => {
    setIsLoading(true)
    setErrorMsg(null)
    const res = await loginDev()
    setIsLoading(false)
    if (res.success) {
      onOpenChange(false)
      toast.success(authContent.loginSuccessDev)
    } else {
      setErrorMsg(res.error || authContent.loginFailed)
    }
  }

  const handleGoogleError = React.useCallback((msg: string) => {
    setErrorMsg(msg)
  }, [])

  return (
    <Dialog open={isOpen} onOpenChange={(open) => {
      onOpenChange(open)
      if (!open) {
        setIsLinkRequired(false)
        setErrorMsg(null)
      }
    }}>
      <DialogContent className="sm:max-w-[360px] p-6 text-center">
        <DialogHeader className="text-center items-center">
          <DialogTitle className="font-serif text-2xl font-medium tracking-tight mb-2">
            {authContent.loginTitle}
          </DialogTitle>
          <DialogDescription className="text-sm text-muted-foreground leading-relaxed">
            {authContent.loginRequiredDesc}
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-4 items-center justify-center py-6">
          {isLoading && (
            <p className="text-sm text-muted-foreground animate-pulse">{authContent.connecting}</p>
          )}

          {isLinkRequired ? (
            <div className="flex flex-col gap-3 items-center text-center p-3 bg-amber-50 border border-amber-200 rounded-lg">
              <ShieldAlert className="size-8 text-amber-600" />
              <p className="text-xs font-semibold text-amber-900 leading-normal">
                {authContent.accountLinkRequired}
              </p>
            </div>
          ) : (
            <>
              {googleIdentityEnabled && googleClientId ? (
                <GoogleSignInButton
                  clientId={googleClientId}
                  onCredentialReceived={handleGoogleCredential}
                  onError={handleGoogleError}
                />
              ) : (
                <p className="text-xs text-muted-foreground italic">
                  {authContent.googleUnavailable}
                </p>
              )}

              {devAuthEnabled && (
                <div className="w-full flex flex-col gap-2 mt-4 border-t pt-4">
                  <p className="text-3xs uppercase tracking-widest text-muted-foreground font-mono font-bold">
                    {authContent.devModeTitle}
                  </p>
                  <Button
                    variant="secondary"
                    className="w-full font-mono text-xs font-bold"
                    onClick={handleDevLogin}
                    disabled={isLoading}
                  >
                    <Sparkles className="mr-1.5 size-3.5 text-accent" />
                    {authContent.devBypassButton}
                  </Button>
                </div>
              )}
            </>
          )}

          {errorMsg && (
            <div className="text-xs font-semibold text-destructive mt-2 leading-relaxed">
              {errorMsg}
            </div>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}
